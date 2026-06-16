<?php

/**
 * PurgeViewsJob
 *
 * Daily background job that purges aggregate view-count rows older
 * than the configured retention window (REQ-ANLT-009). Default
 * window is 365 days; admin override via
 * `mydash.analytics_retention_days` is clamped to `[30, 3650]`.
 *
 * Logging is intentionally aggregate-only: row count + cutoff date
 * — never any user-attributable identifiers (REQ-ANLT-009 scenario
 * "Purge logs execution"). The job is registered via
 * {@see \OCA\MyDash\AppInfo\Application::register()}.
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

use OCA\MyDash\Db\DashboardViewMapper;
use OCA\MyDash\Service\AnalyticsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily retention purge for the analytics aggregate table.
 */
class PurgeViewsJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory        $time             Time factory used by
     *                                              the parent
     *                                              `TimedJob` to gate
     *                                              the next-run
     *                                              decision.
     * @param AnalyticsService    $analyticsService Analytics service
     *                                              (cutoff date + log
     *                                              context).
     * @param DashboardViewMapper $viewMapper       Aggregate-row
     *                                              mapper.
     * @param LoggerInterface     $logger           PSR logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly AnalyticsService $analyticsService,
        private readonly DashboardViewMapper $viewMapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Run the job — delete every aggregate row strictly older than
     * the cutoff date.
     *
     * @param mixed $argument Required by the base class; unused.
     *
     * @return void
     *
     * @spec openspec/specs/dashboard-view-analytics/spec.md
     */
    protected function run($argument): void
    {
        $cutoff  = $this->analyticsService->getPurgeCutoffDate();
        $deleted = $this->viewMapper->deleteOlderThan(beforeDate: $cutoff);

        $this->logger->info(
            message: 'mydash analytics purge: deleted '.$deleted.' rows older than '.$cutoff,
            context: [
                'rows'      => $deleted,
                'cutoff'    => $cutoff,
                'retention' => $this->analyticsService->getRetentionDays(),
            ]
        );
    }//end run()
}//end class
