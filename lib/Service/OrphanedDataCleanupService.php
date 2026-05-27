<?php

/**
 * OrphanedDataCleanupService
 *
 * Top-level orchestrator for the orphaned-data-cleanup pipeline.
 * Walks the registered {@see CleanupCategoryInterface} implementations
 * in registration order, aggregates their results into a
 * {@see CleanupResult} DTO and applies the cross-cutting policy
 * required by REQ-CLN-001..010:
 *
 *  - skip categories whose `isAvailable()` returns `false` (instead of
 *    failing the whole run on a missing-table error),
 *  - cache scan results in the distributed cache for 5 minutes
 *    (REQ-CLN-010),
 *  - wrap dry-run purges in a transaction + rollback so categories
 *    can use the same delete path in either mode (REQ-CLN-003),
 *  - invalidate the cache on any successful (non-dry-run) purge,
 *  - emit a single Activity event per real purge (REQ-CLN-009).
 *
 * The CLI commands, the API controller and the background job all
 * route through this single entry point so the cache, transaction
 * and audit policies stay consistent.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\CleanupResult;
use OCA\MyDash\Service\Cleanup\CategoryRegistryService;
use OCP\Activity\Exceptions\IncompleteActivityException;
use OCP\Activity\IManager as IActivityManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrates scan + purge across all registered cleanup categories.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The orchestrator
 *                                                  legitimately
 *                                                  composes the
 *                                                  registry, cache,
 *                                                  DB transaction,
 *                                                  activity and
 *                                                  logger
 *                                                  collaborators in
 *                                                  one place.
 */
class OrphanedDataCleanupService
{
    /**
     * Cache TTL for scan results, in seconds (REQ-CLN-010).
     *
     * Five minutes — short enough that an admin who navigates to the
     * cleanup page after a manual purge sees fresh numbers, long
     * enough that repeated UI refreshes don't hammer the DB.
     *
     * @var int
     */
    public const CACHE_TTL_SECONDS = 300;

    /**
     * Cache key for the most recent scan result.
     *
     * @var string
     */
    public const CACHE_KEY_SCAN = 'mydash.cleanup.scan';

    /**
     * Activity event type emitted on every real (non-dry-run) purge.
     *
     * @var string
     */
    public const ACTIVITY_TYPE = 'mydash_cleanup_purge';

    /**
     * Cache instance, lazily resolved via {@see ICacheFactory}.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Constructor.
     *
     * @param CategoryRegistryService $registry        Category registry.
     * @param ICacheFactory           $cacheFactory    Distributed-cache
     *                                                 factory.
     * @param IDBConnection           $db              DB connection
     *                                                 (for dry-run
     *                                                 transactions).
     * @param IActivityManager        $activityManager Activity event
     *                                                 publisher.
     * @param LoggerInterface         $logger          PSR-3 logger
     *                                                 used for purge
     *                                                 audit lines.
     */
    public function __construct(
        private readonly CategoryRegistryService $registry,
        private readonly ICacheFactory $cacheFactory,
        private readonly IDBConnection $db,
        private readonly IActivityManager $activityManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Scan one or more categories for orphaned rows.
     *
     * When `$categoryNames` is empty the full registered set is
     * scanned. Categories whose `isAvailable()` returns `false` are
     * recorded under `skipped` instead of contributing a count.
     *
     * The result is cached under {@see self::CACHE_KEY_SCAN} for
     * {@see self::CACHE_TTL_SECONDS} seconds whenever the caller
     * asks for the full set; partial scans bypass the cache to avoid
     * polluting the full-set entry with category-filtered numbers.
     *
     * @param array<int, string> $categoryNames Names to scan, or [].
     *
     * @return CleanupResult The result.
     *
     * @spec openspec/specs/orphaned-data-cleanup/spec.md
     */
    public function scan(array $categoryNames=[]): CleanupResult
    {
        if (count(value: $categoryNames) === 0) {
            $cached = $this->getCachedScanResult();
            if ($cached !== null) {
                return $cached;
            }
        }

        $names = $this->resolveCategoryNames(requested: $categoryNames);

        $start      = (int) round(num: (microtime(as_float: true) * 1000));
        $byCategory = [];
        $skipped    = [];

        foreach ($names as $name) {
            $category = $this->registry->getCategoryByName(name: $name);
            if ($category === null) {
                $skipped[] = $name;
                continue;
            }

            if ($category->isAvailable() === false) {
                $skipped[] = $name;
                continue;
            }

            $byCategory[$name] = (int) $category->scan();
        }

        $duration = ((int) round(num: (microtime(as_float: true) * 1000)) - $start);
        $result   = CleanupResult::fromCounts(
            byCategory: $byCategory,
            durationMs: $duration,
            dryRun: false,
            skipped: $skipped,
        );

        if (count(value: $categoryNames) === 0) {
            $this->setCachedScanResult(result: $result);
        }

        return $result;
    }//end scan()

    /**
     * Purge orphaned rows in one or more categories.
     *
     * When `$categoryNames` is empty the full registered set is
     * purged. Categories whose `isAvailable()` returns `false` are
     * recorded under `skipped`.
     *
     * Dry-run mode wraps the entire walk in a single transaction and
     * rolls it back at the end so individual category implementations
     * can use one delete path for both modes (REQ-CLN-003).
     *
     * On a successful real purge the scan cache is invalidated
     * (REQ-CLN-010) and exactly one Activity event is emitted
     * (REQ-CLN-009).
     *
     * @param array<int, string> $categoryNames Names to purge, or [].
     * @param bool               $dryRun        True for simulation.
     * @param string|null        $userId        The actor for audit
     *                                          (null = system).
     * @param string             $source        Origin label for the
     *                                          activity event ('cli',
     *                                          'api', 'job').
     *
     * @return CleanupResult The result.
     *
     * @spec openspec/specs/orphaned-data-cleanup/spec.md
     */
    public function purge(
        array $categoryNames=[],
        bool $dryRun=false,
        ?string $userId=null,
        string $source='api'
    ): CleanupResult {
        $names      = $this->resolveCategoryNames(requested: $categoryNames);
        $start      = (int) round(num: (microtime(as_float: true) * 1000));
        $byCategory = [];
        $skipped    = [];

        if ($dryRun === true) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($names as $name) {
                $category = $this->registry->getCategoryByName(name: $name);
                if ($category === null) {
                    $skipped[] = $name;
                    continue;
                }

                if ($category->isAvailable() === false) {
                    $skipped[] = $name;
                    continue;
                }

                $byCategory[$name] = (int) $category->purge(dryRun: $dryRun);
            }

            if ($dryRun === true) {
                // REQ-CLN-003: rollback so the simulation has zero
                // persistent side effect.
                $this->db->rollBack();
            }
        } catch (Throwable $t) {
            if ($dryRun === true && $this->db->inTransaction() === true) {
                $this->db->rollBack();
            }

            throw $t;
        }//end try

        $duration = ((int) round(num: (microtime(as_float: true) * 1000)) - $start);
        $result   = CleanupResult::fromCounts(
            byCategory: $byCategory,
            durationMs: $duration,
            dryRun: $dryRun,
            skipped: $skipped,
        );

        if ($dryRun === false) {
            $this->invalidateCache();

            if ($result->getTotalRows() > 0) {
                $this->emitActivityEvent(
                    result: $result,
                    userId: $userId,
                    source: $source,
                );
            }

            $this->logger->info(
                message: sprintf(
                    'mydash.cleanup.purge source=%s user=%s rows=%d duration_ms=%d categories=%s',
                    $source,
                    ($userId ?? 'system'),
                    $result->getTotalRows(),
                    $result->getDurationMs(),
                    implode(separator: ',', array: array_keys(array: $byCategory)),
                )
            );
        }//end if

        return $result;
    }//end purge()

    /**
     * Read the most recent cached scan result.
     *
     * Returns `null` when the cache backend has no value (expired,
     * never written, or unavailable). The cache is constructed lazily
     * via {@see ICacheFactory::createDistributed()} so installs
     * without a memory cache silently fall through to fresh scans.
     *
     * @return CleanupResult|null The cached result or null.
     *
     * @spec openspec/specs/orphaned-data-cleanup/spec.md
     */
    public function getCachedScanResult(): ?CleanupResult
    {
        $payload = $this->cache()->get(key: self::CACHE_KEY_SCAN);
        if (is_array(value: $payload) === false) {
            return null;
        }

        return new CleanupResult(
            byCategory: (array) ($payload['byCategory'] ?? []),
            totalRows: (int) ($payload['totalRows'] ?? 0),
            durationMs: (int) ($payload['durationMs'] ?? 0),
            dryRun: (bool) ($payload['dryRun'] ?? false),
            scannedAt: (string) ($payload['scannedAt'] ?? ''),
            skipped: (array) ($payload['skipped'] ?? []),
        );
    }//end getCachedScanResult()

    /**
     * Persist a scan result into the distributed cache for
     * {@see self::CACHE_TTL_SECONDS} seconds.
     *
     * @param CleanupResult $result The result to cache.
     *
     * @return void
     *
     * @spec openspec/specs/orphaned-data-cleanup/spec.md
     */
    public function setCachedScanResult(CleanupResult $result): void
    {
        $this->cache()->set(
            key: self::CACHE_KEY_SCAN,
            value: $result->jsonSerialize(),
            ttl: self::CACHE_TTL_SECONDS,
        );
    }//end setCachedScanResult()

    /**
     * Invalidate the cached scan result.
     *
     * Called automatically after every successful real purge so the
     * next scan reflects the fresh state (REQ-CLN-010).
     *
     * @return void
     *
     * @spec openspec/specs/orphaned-data-cleanup/spec.md
     */
    public function invalidateCache(): void
    {
        $this->cache()->remove(key: self::CACHE_KEY_SCAN);
    }//end invalidateCache()

    /**
     * Resolve the requested category-name list, normalising the
     * "empty means all" convention.
     *
     * @param array<int, string> $requested The caller's list.
     *
     * @return array<int, string> The resolved list.
     */
    private function resolveCategoryNames(array $requested): array
    {
        if (count(value: $requested) === 0) {
            return $this->registry->getCategoryNames();
        }

        return array_values(
            array: array_unique(
                    array: array_filter(
                array: $requested,
                callback: static function ($value): bool {
                    return is_string(value: $value) === true && $value !== '';
                }
            )
                    )
        );
    }//end resolveCategoryNames()

    /**
     * Lazily resolve the distributed cache.
     *
     * @return ICache The cache instance.
     */
    private function cache(): ICache
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheFactory->createDistributed(
                prefix: 'mydash_cleanup'
            );
        }

        return $this->cache;
    }//end cache()

    /**
     * Emit one Activity event summarising a real purge run.
     *
     * Best-effort — a misconfigured Activity backend MUST NOT cause
     * the purge call to fail; the audit trail is still written to
     * the PSR-3 logger by the caller.
     *
     * @param CleanupResult $result The purge result.
     * @param string|null   $userId The actor (null = system).
     * @param string        $source The source label.
     *
     * @return void
     */
    private function emitActivityEvent(
        CleanupResult $result,
        ?string $userId,
        string $source
    ): void {
        try {
            $event = $this->activityManager->generateEvent();
            $event->setApp(app: Application::APP_ID)
                ->setType(type: self::ACTIVITY_TYPE)
                ->setAffectedUser(affectedUser: ($userId ?? ''))
                ->setAuthor(author: ($userId ?? ''))
                ->setSubject(
                    subject: 'mydash_cleanup_purge',
                    parameters: [
                        'totalRows'  => $result->getTotalRows(),
                        'byCategory' => $result->getByCategory(),
                        'durationMs' => $result->getDurationMs(),
                        'source'     => $source,
                    ]
                )
                ->setObject(
                    objectType: 'mydash_cleanup',
                    objectId: 0,
                    objectName: $source
                );

            $this->activityManager->publish(event: $event);
        } catch (IncompleteActivityException $e) {
            $this->logger->warning(
                message: sprintf(
                    'mydash.cleanup.activity_emit_failed: %s',
                    $e->getMessage()
                )
            );
        } catch (Throwable $t) {
            $this->logger->warning(
                message: sprintf(
                    'mydash.cleanup.activity_emit_threw: %s',
                    $t->getMessage()
                )
            );
        }//end try
    }//end emitActivityEvent()
}//end class
