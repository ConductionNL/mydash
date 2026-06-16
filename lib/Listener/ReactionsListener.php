<?php

/**
 * ReactionsListener
 *
 * Cleans up `oc_mydash_dashboard_reactions` rows when a dashboard is
 * soft-deleted. Subscribes to {@see DashboardDeletedEvent} via the
 * cascade-events listener registry (REQ-CSC-002, REQ-CSC-003) and
 * delegates the actual delete to {@see ReactionService} so the same
 * code path is reused by every cascade source.
 *
 * @category  Listener
 * @package   OCA\MyDash\Listener
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

namespace OCA\MyDash\Listener;

use OCA\MyDash\Event\DashboardDeletedEvent;
use OCA\MyDash\Service\ReactionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes reactions for the deleted dashboard. REQ-RXN-009.
 *
 * @implements IEventListener<DashboardDeletedEvent>
 */
class ReactionsListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param ReactionService $reactionService Service that owns the
     *                                         `deleteReactionsByDashboard`
     *                                         cascade path.
     * @param LoggerInterface $logger          PSR-3 logger for
     *                                         log-and-continue failure
     *                                         handling per REQ-CSC-006.
     */
    public function __construct(
        private readonly ReactionService $reactionService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the DashboardDeletedEvent.
     *
     * @param Event $event The event.
     *
     * @return void
     *
     * @spec openspec/specs/dashboard-cascade-events/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof DashboardDeletedEvent) === false) {
            return;
        }

        $uuid = $event->getDashboardUuid();

        try {
            $deleted = $this->reactionService->deleteReactionsByDashboard(
                dashboardUuid: $uuid
            );

            $this->logger->debug(
                message: sprintf(
                    'mydash ReactionsListener: deleted %d reactions for dashboard %s',
                    $deleted,
                    $uuid
                ),
                context: ['app' => 'mydash']
            );
        } catch (Throwable $t) {
            $this->logger->warning(
                message: sprintf(
                    'mydash ReactionsListener: failed for dashboard %s: %s',
                    $uuid,
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
        }//end try
    }//end handle()
}//end class
