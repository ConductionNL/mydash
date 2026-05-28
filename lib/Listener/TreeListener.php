<?php

/**
 * TreeListener
 *
 * Recursively dispatches `DashboardDeletedEvent` for every child
 * dashboard of the parent that was just deleted, so that the full
 * cascade-listener stack runs for every node in the tree. Stub
 * registered as part of the cascade-events scaffolding; the live
 * implementation is owned by the dashboard-tree follow-up.
 * REQ-CSC-003, REQ-CSC-010.
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
 * Recursively dispatches DashboardDeletedEvent for each child.
 *
 * @implements IEventListener<DashboardDeletedEvent>
 */
class TreeListener implements IEventListener
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
    /** @spec openspec/specs/dashboard-cascade-events/spec.md */
    public function handle(Event $event): void
    {
        if (($event instanceof DashboardDeletedEvent) === false) {
            return;
        }

        try {
            // TODO(dashboard-tree): query oc_mydash_dashboards for
            // children WHERE parentUuid = $event->getDashboardUuid()
            // and dispatch a fresh DashboardDeletedEvent for each
            // child via IEventDispatcher. Stub registered for cascade
            // scaffolding — see REQ-CSC-010 scenarios for the cascade
            // guard and tree-no-op behaviour.
            $this->logger->debug(
                message: sprintf(
                    'mydash TreeListener: stub invoked for dashboard %s',
                    $event->getDashboardUuid()
                ),
                context: ['app' => 'mydash']
            );
        } catch (Throwable $t) {
            $this->logger->warning(
                message: sprintf(
                    'mydash TreeListener: failed for dashboard %s: %s',
                    $event->getDashboardUuid(),
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
        }//end try
    }//end handle()
}//end class
