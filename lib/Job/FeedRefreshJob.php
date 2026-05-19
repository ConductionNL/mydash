<?php

/**
 * FeedRefreshJob
 *
 * Nextcloud `\OCP\BackgroundJob\TimedJob` that periodically rebuilds the
 * feed cache for the news widget (REQ-FRJ-002, REQ-FRJ-007). Reads the
 * admin-tunable `mydash.feed_refresh_interval_seconds` config (clamped
 * to [300, 86400] seconds, default 3600), acquires a global cluster
 * lock to prevent concurrent ticks, and delegates the per-feed work to
 * {@see \OCA\MyDash\Service\FeedRefreshService::refreshAll()}. The lock
 * is released in a `finally` block so an unhandled exception cannot
 * leave the lock stuck.
 *
 * @category  Job
 * @package   OCA\MyDash\Job
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

namespace OCA\MyDash\Job;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\FeedRefreshService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Periodic feed-refresh job.
 */
class FeedRefreshJob extends TimedJob
{
    /**
     * App config key — admin-tunable refresh interval in seconds
     * (REQ-FRJ-002). Clamped to [300, 86400] (5 min .. 24 h).
     *
     * @var string
     */
    public const CONFIG_KEY_INTERVAL = 'feed_refresh_interval_seconds';

    /**
     * Default refresh interval in seconds (60 minutes).
     *
     * @var integer
     */
    public const DEFAULT_INTERVAL = 3600;

    /**
     * Minimum refresh interval clamp (5 minutes).
     *
     * @var integer
     */
    public const MIN_INTERVAL = 300;

    /**
     * Maximum refresh interval clamp (24 hours).
     *
     * @var integer
     */
    public const MAX_INTERVAL = 86400;

    /**
     * Global cluster lock id — only one job instance runs at a time
     * (REQ-FRJ-007).
     *
     * @var string
     */
    public const LOCK_ID = 'mydash_feed_refresh_running';

    /**
     * Constructor — clamps the configured interval and registers it
     * with the parent TimedJob.
     *
     * @param ITimeFactory       $time            The TimedJob clock.
     * @param IConfig            $config          The Nextcloud config reader.
     * @param FeedRefreshService $refreshService  The refresh worker.
     * @param ILockingProvider   $lockingProvider The cluster lock provider.
     * @param LoggerInterface    $logger          The diagnostic logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IConfig $config,
        private readonly FeedRefreshService $refreshService,
        private readonly ILockingProvider $lockingProvider,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        $configured = (int) $this->config->getAppValue(
            Application::APP_ID,
            self::CONFIG_KEY_INTERVAL,
            (string) self::DEFAULT_INTERVAL
        );
        if ($configured <= 0) {
            $configured = self::DEFAULT_INTERVAL;
        }

        $clamped = max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $configured));

        $this->setInterval(seconds: $clamped);
    }//end __construct()

    /**
     * Execute one tick of the refresh job.
     *
     * @param mixed $argument The TimedJob argument (unused).
     *
     * @return void
     */
    protected function run(mixed $argument): void
    {
        try {
            $this->lockingProvider->acquireLock(
                path: self::LOCK_ID,
                type: ILockingProvider::LOCK_EXCLUSIVE
            );
        } catch (LockedException) {
            $this->logger->warning(
                message: 'FeedRefreshJob already running; skipping this tick',
                context: ['app' => Application::APP_ID]
            );
            return;
        }

        try {
            $result = $this->refreshService->refreshAll();
            $this->logger->info(
                message: 'FeedRefreshJob tick completed',
                context: [
                    'app'            => Application::APP_ID,
                    'processedCount' => $result['processedCount'],
                    'successCount'   => $result['successCount'],
                    'failureCount'   => $result['failureCount'],
                    'durationMs'     => $result['durationMs'],
                ]
            );
        } catch (Throwable $exception) {
            $this->logger->error(
                message: 'FeedRefreshJob tick raised an unexpected exception',
                context: [
                    'app'       => Application::APP_ID,
                    'exception' => $exception->getMessage(),
                ]
            );
        } finally {
            $this->lockingProvider->releaseLock(
                path: self::LOCK_ID,
                type: ILockingProvider::LOCK_EXCLUSIVE
            );
        }//end try
    }//end run()
}//end class
