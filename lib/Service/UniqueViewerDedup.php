<?php

/**
 * UniqueViewerDedup
 *
 * Privacy-preserving unique-viewer deduplication for the dashboard
 * view-analytics capability (REQ-ANLT-003).
 *
 * Computes a daily-rotating salted hash of the user identifier and
 * stores it in the Nextcloud distributed cache so duplicate views
 * within the same UTC day are detected without persisting any
 * user-attributable rows. The salt rotates each UTC midnight and
 * the previous value is overwritten without any history kept —
 * cross-day re-identification is therefore computationally
 * infeasible from the analytics table alone, even if the current
 * salt is later compromised.
 *
 * The cache TTL is set to the number of seconds remaining until the
 * next UTC midnight (NOT a fixed 86400) so the entry expires exactly
 * when the salt rotates.
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

use DateTimeImmutable;
use DateTimeZone;
use OCP\IConfig;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Daily-rotating salted-hash dedup for the dashboard view-analytics
 * capability (REQ-ANLT-003).
 */
class UniqueViewerDedup
{
    /**
     * App-config key under which the daily salt is persisted
     * (REQ-ANLT-003 design D2).
     *
     * @var string
     */
    public const CONFIG_KEY_SALT = 'analytics_dailysalt';

    /**
     * App-config key under which the salt's UTC date marker is
     * persisted. Used to detect that the cached salt belongs to a
     * previous day so the lazy-rotation path can refresh it without
     * waiting for the cron.
     *
     * @var string
     */
    public const CONFIG_KEY_SALT_DATE = 'analytics_dailysalt_date';

    /**
     * Cache namespace for dedup hashes.
     *
     * @var string
     */
    public const CACHE_NAMESPACE = 'mydash_anlt';

    /**
     * Concrete cache instance, lazily resolved from the cache
     * factory the first time it is needed.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Constructor.
     *
     * @param ICacheFactory $cacheFactory The Nextcloud cache factory.
     * @param IConfig       $config       The Nextcloud config service.
     */
    public function __construct(
        private readonly ICacheFactory $cacheFactory,
        private readonly IConfig $config,
    ) {
    }//end __construct()

    /**
     * Compute the UTC date string `YYYY-MM-DD` for the supplied
     * timestamp (defaults to "now").
     *
     * @param DateTimeImmutable|null $when Optional reference timestamp.
     *
     * @return string The UTC date in `YYYY-MM-DD` format.
     */
    public static function utcDateFor(?DateTimeImmutable $when=null): string
    {
        $reference = ($when ?? new DateTimeImmutable('now'))
            ->setTimezone(timezone: new DateTimeZone(timezone: 'UTC'));

        return $reference->format(format: 'Y-m-d');
    }//end utcDateFor()

    /**
     * Compute the number of seconds remaining until the next UTC
     * midnight from `$when`. The result is always strictly positive
     * (a minimum of 1 is enforced so cache entries do not expire
     * instantly).
     *
     * @param DateTimeImmutable|null $when Optional reference timestamp.
     *
     * @return int The TTL in seconds.
     */
    public static function secondsUntilNextUtcMidnight(
        ?DateTimeImmutable $when=null
    ): int {
        $reference = ($when ?? new DateTimeImmutable('now'))
            ->setTimezone(timezone: new DateTimeZone(timezone: 'UTC'));

        $nextMidnight = $reference
            ->modify(modifier: 'tomorrow')
            ->setTime(hour: 0, minute: 0, second: 0);

        $delta = ($nextMidnight->getTimestamp() - $reference->getTimestamp());

        if ($delta < 1) {
            return 1;
        }

        return $delta;
    }//end secondsUntilNextUtcMidnight()

    /**
     * Retrieve (or lazily generate) the daily salt for the supplied
     * UTC date. When the persisted salt's date marker does not match
     * `$viewBucketDate`, a fresh 32-byte random value is generated,
     * the previous salt is overwritten without history, and the new
     * value is returned (REQ-ANLT-003 design D2).
     *
     * @param string $viewBucketDate The UTC date `YYYY-MM-DD`.
     *
     * @return string The salt as a hex string.
     */
    public function getSaltForDate(string $viewBucketDate): string
    {
        $existingSalt = $this->config->getAppValue(
            'mydash',
            self::CONFIG_KEY_SALT,
            ''
        );
        $existingDate = $this->config->getAppValue(
            'mydash',
            self::CONFIG_KEY_SALT_DATE,
            ''
        );

        if ($existingSalt !== '' && $existingDate === $viewBucketDate) {
            return $existingSalt;
        }

        return $this->rotateSalt(viewBucketDate: $viewBucketDate);
    }//end getSaltForDate()

    /**
     * Force a salt rotation for the supplied UTC date. Generates a
     * fresh 32-byte random value, overwrites the previous salt with
     * no history kept, and returns the new value. Called eagerly by
     * the {@see \OCA\MyDash\BackgroundJob\SaltRotationJob} and
     * lazily by {@see self::getSaltForDate()} when the persisted
     * date marker is stale.
     *
     * @param string $viewBucketDate The UTC date `YYYY-MM-DD`.
     *
     * @return string The new salt as a hex string.
     */
    public function rotateSalt(string $viewBucketDate): string
    {
        $newSalt = bin2hex(string: random_bytes(length: 32));
        $this->config->setAppValue(
            'mydash',
            self::CONFIG_KEY_SALT,
            $newSalt
        );
        $this->config->setAppValue(
            'mydash',
            self::CONFIG_KEY_SALT_DATE,
            $viewBucketDate
        );

        return $newSalt;
    }//end rotateSalt()

    /**
     * Compute the deterministic SHA-256 hex hash of `userId` salted
     * with the supplied date's daily salt.
     *
     * @param string $userId         The user identifier (hashed
     *                               before storage; raw string fine).
     * @param string $viewBucketDate The UTC date `YYYY-MM-DD`.
     *
     * @return string The 64-char hex SHA-256 digest.
     */
    public function hashUserForDate(
        string $userId,
        string $viewBucketDate
    ): string {
        $salt = $this->getSaltForDate(viewBucketDate: $viewBucketDate);

        return hash(algo: 'sha256', data: $userId.'|'.$salt);
    }//end hashUserForDate()

    /**
     * Determine whether the supplied `(userId, viewBucketDate,
     * dashboardUuid)` tuple represents a NEW unique viewer for that
     * day. If so, the marker is stored in cache (TTL = seconds until
     * the next UTC midnight) and `true` is returned; otherwise
     * `false` is returned and no DB increment should follow
     * (REQ-ANLT-003).
     *
     * @param string $userId         The user identifier.
     * @param string $viewBucketDate The UTC date `YYYY-MM-DD`.
     * @param string $dashboardUuid  The dashboard UUID being viewed.
     *
     * @return bool `true` when the user is a new unique viewer for
     *              this dashboard today, `false` when they have
     *              already been counted.
     */
    public function isNewUniqueViewer(
        string $userId,
        string $viewBucketDate,
        string $dashboardUuid
    ): bool {
        $hash     = $this->hashUserForDate(
            userId: $userId,
            viewBucketDate: $viewBucketDate
        );
        $cacheKey = $this->buildCacheKey(
            dashboardUuid: $dashboardUuid,
            viewerHash: $hash
        );

        $cache = $this->getCache();

        if ($cache->hasKey(key: $cacheKey) === true) {
            return false;
        }

        $cache->set(
            key: $cacheKey,
            value: '1',
            ttl: self::secondsUntilNextUtcMidnight()
        );

        return true;
    }//end isNewUniqueViewer()

    /**
     * Build the canonical dedup cache key for `(dashboardUuid,
     * viewerHash)`.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $viewerHash    The 64-char hex SHA-256 digest.
     *
     * @return string The cache key.
     */
    public function buildCacheKey(
        string $dashboardUuid,
        string $viewerHash
    ): string {
        return $dashboardUuid.'_'.$viewerHash;
    }//end buildCacheKey()

    /**
     * Lazily resolve the underlying ICache instance (memcache when
     * available, otherwise the local in-process fallback).
     *
     * @return ICache The cache instance.
     */
    private function getCache(): ICache
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheFactory
                ->createDistributed(prefix: self::CACHE_NAMESPACE.'_');
        }

        return $this->cache;
    }//end getCache()
}//end class
