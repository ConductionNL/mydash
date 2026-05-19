<?php

/**
 * WidgetPlacementsListener
 *
 * Cleans up `oc_mydash_widget_placements` rows when a dashboard is
 * soft-deleted. Stub registered as part of the cascade-events
 * scaffolding; the live implementation is owned by the placements
 * subsystem and will be filled in as part of widget-placement
 * follow-up work. REQ-CSC-003.
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
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes widget placements for the deleted dashboard.
 *
 * @implements IEventListener<DashboardDeletedEvent>
 */
class WidgetPlacementsListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger PSR-3 logger for log-and-continue
     *                                failure handling per REQ-CSC-006.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the DashboardDeletedEvent.
     *
     * @param Event $event The event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof DashboardDeletedEvent) === false) {
            return;
        }

        try {
            // TODO(widget-placements): DELETE FROM oc_mydash_widget_placements
            // WHERE dashboardUuid = $event->getDashboardUuid().
            // Stub registered for cascade scaffolding — live cleanup
            // owned by the placements subsystem (REQ-CSC-003,
            // REQ-CSC-008 idempotency).
            $this->logger->debug(
                message: sprintf(
                    'mydash WidgetPlacementsListener: stub invoked for dashboard %s',
                    $event->getDashboardUuid()
                ),
                context: ['app' => 'mydash']
            );
        } catch (Throwable $t) {
            // Failure isolation per REQ-CSC-006 — log at WARN, do not
            // rethrow so peer listeners still execute.
            $this->logger->warning(
                message: sprintf(
                    'mydash WidgetPlacementsListener: failed for dashboard %s: %s',
                    $event->getDashboardUuid(),
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
        }//end try
    }//end handle()
}//end class
