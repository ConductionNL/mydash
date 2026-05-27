<?php

/**
 * OrphanedDataCleanupJob
 *
 * Daily background job that auto-purges the admin-configured subset
 * of cleanup categories. REQ-CLN-007. The default subset is the
 * Tier-A list returned by
 * {@see CategoryRegistryService::getAutoSafeCategoryNames()}; admins
 * can override the list via the `mydash` app config under the
 * `cleanup_auto_purge_categories` key (JSON-encoded array).
 *
 * The job is registered in `OCA\MyDash\AppInfo\Application::register`
 * via `IJobList::add`. Interval is 24 hours.
 *
 * @category  BackgroundJob
 * @package   OCA\MyDash\BackgroundJob
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

namespace OCA\MyDash\BackgroundJob;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\Cleanup\CategoryRegistryService;
use OCA\MyDash\Service\OrphanedDataCleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily auto-purge job.
 */
class OrphanedDataCleanupJob extends TimedJob
{
    /**
     * IAppConfig key holding the JSON-encoded auto-purge category list.
     *
     * @var string
     */
    public const CONFIG_KEY_CATEGORIES = 'cleanup_auto_purge_categories';

    /**
     * Run interval in seconds (24 hours). REQ-CLN-007 "scheduled
     * daily". Time-insensitive — the job is allowed to slip into the
     * next low-traffic window.
     *
     * @var int
     */
    public const INTERVAL_SECONDS = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory               $time           Time factory
     *                                                   (parent
     *                                                   requirement).
     * @param OrphanedDataCleanupService $cleanupService The orchestrator.
     * @param CategoryRegistryService    $registry       Category registry
     *                                                   for the auto-safe
     *                                                   default list.
     * @param IAppConfig                 $appConfig      App config to
     *                                                   read the
     *                                                   admin-chosen
     *                                                   auto-purge
     *                                                   set.
     * @param LoggerInterface            $logger         PSR-3 logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly OrphanedDataCleanupService $cleanupService,
        private readonly CategoryRegistryService $registry,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
    }//end __construct()

    /**
     * Run the auto-purge.
     *
     * Reads the admin-configured category list, falls back to the
     * Tier-A default when none is configured. Skips quietly when the
     * list is empty (admin has explicitly disabled auto-purge).
     *
     * Errors thrown by individual category implementations are
     * caught by the parent {@see Job::start()} which logs them; we
     * additionally write a structured "skipped" log here when the
     * config is empty so cluster operators can grep for the reason.
     *
     * @param mixed $argument Ignored — the job carries no arguments.
     *
     * @return void
     *
     * @spec openspec/specs/orphaned-data-cleanup/spec.md
     */
    protected function run($argument): void
    {
        $categories = $this->resolveCategories();

        if (count(value: $categories) === 0) {
            $this->logger->info(
                message: 'mydash.cleanup.job_skipped reason=no_categories_enabled'
            );
            return;
        }

        $result = $this->cleanupService->purge(
            categoryNames: $categories,
            dryRun: false,
            userId: null,
            source: 'job',
        );

        $this->logger->info(
            message: sprintf(
                'mydash.cleanup.job_run rows=%d duration_ms=%d categories=%s',
                $result->getTotalRows(),
                $result->getDurationMs(),
                implode(separator: ',', array: $categories),
            )
        );
    }//end run()

    /**
     * Resolve the configured auto-purge categories.
     *
     * Reads the JSON-encoded list from `IAppConfig` under
     * {@see self::CONFIG_KEY_CATEGORIES}. Falls back to the registry's
     * Tier-A default list when the config is missing or unparseable.
     * An explicit empty array (admin-set) is preserved — that signals
     * "auto-purge disabled" and the run() method skips.
     *
     * @return array<int, string> The category names.
     */
    private function resolveCategories(): array
    {
        $raw = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: self::CONFIG_KEY_CATEGORIES,
            default: ''
        );

        if ($raw === '') {
            return $this->registry->getAutoSafeCategoryNames();
        }

        $decoded = json_decode(json: $raw, associative: true);
        if (is_array(value: $decoded) === false) {
            $this->logger->warning(
                message: sprintf(
                    'mydash.cleanup.job_config_invalid raw=%s',
                    $raw
                )
            );
            return $this->registry->getAutoSafeCategoryNames();
        }

        $known    = $this->registry->getCategoryNames();
        $filtered = [];
        foreach ($decoded as $entry) {
            if (is_string(value: $entry) === false || $entry === '') {
                continue;
            }

            if (in_array(needle: $entry, haystack: $known, strict: true) === true) {
                $filtered[] = $entry;
            }
        }

        return $filtered;
    }//end resolveCategories()
}//end class
