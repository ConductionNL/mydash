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

use DateTimeImmutable;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Event\DashboardDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recursively dispatches DashboardDeletedEvent for each child.
 * C4 fix (REQ-CSC-003, REQ-CSC-010).
 *
 * @implements IEventListener<DashboardDeletedEvent>
 */
class TreeListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param DashboardMapper  $dashboardMapper Dashboard row mapper (read
     *                                          children by parentUuid).
     * @param IEventDispatcher $dispatcher      NC event dispatcher for
     *                                          recursive child dispatch.
     * @param LoggerInterface  $logger          PSR-3 logger for
     *                                          log-and-continue failure
     *                                          handling per REQ-CSC-006.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly IEventDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the DashboardDeletedEvent.
     *
     * Dispatches a fresh DashboardDeletedEvent for each direct child so the
     * full listener stack (placements, locks, shares, …) runs for every node
     * in the tree. The event dispatcher will invoke this listener again for
     * each child, providing recursive cascade without a manual loop.
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

        $uuid      = $event->getDashboardUuid();
        $deletedAt = $event->getDeletedAt();

        try {
            $children = $this->dashboardMapper->findByParent(
                parentUuid: $uuid
            );

            foreach ($children as $child) {
                $childUuid = (string) $child->getUuid();
                if ($childUuid === '') {
                    continue;
                }

                $childOwnerId = (string) ($child->getUserId() ?? $event->getOwnerUserId());
                $childType    = (string) ($child->getType() ?? $event->getType());

                $this->dispatcher->dispatchTyped(
                    new DashboardDeletedEvent(
                        dashboardUuid: $childUuid,
                        ownerUserId:   $childOwnerId,
                        type:          $childType,
                        deletedAt:     $deletedAt
                    )
                );
            }//end foreach

            $this->logger->debug(
                message: sprintf(
                    'mydash TreeListener: dispatched delete for %d children of dashboard %s',
                    count($children),
                    $uuid
                ),
                context: ['app' => 'mydash']
            );
        } catch (Throwable $t) {
            $this->logger->warning(
                message: sprintf(
                    'mydash TreeListener: failed for dashboard %s: %s',
                    $uuid,
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
        }//end try
    }//end handle()
}//end class
