<?php

/**
 * GroupDeletedListener
 *
 * Listens to OCP\Group\Events\GroupDeletedEvent and cascades MyDash
 * cleanup for the deleted group.
 *
 * Live coverage:
 *   1. Removes any MyDash role assignments scoped to the deleted group
 *      via RoleService::deleteByGroupId. REQ-ROLE-011.
 *
 * Pending follow-ups (TODO, owned by dashboard-sharing /
 * navigation-editor-org):
 *   - Enumerate group-shared dashboards owned by the deleted group and
 *     call DashboardService::delete() for each (which dispatches
 *     DashboardDeletedEvent and fires the full cascade).
 *   - Drop the group from `mydash.org_navigation_tree` JSON
 *     groupVisibility arrays.
 *   - Drop the group from the `mydash.group_order` IConfig array.
 *   REQ-CSC-005.
 *
 * Failures are best-effort: the listener logs and continues so the
 * Nextcloud group-deletion event chain is never interrupted (REQ-CSC-006).
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

use OCA\MyDash\Service\RoleService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cascade role-assignment removal on group deletion (REQ-ROLE-011 +
 * REQ-CSC-005 scaffolding for the wider group-scoped cleanup roadmap).
 *
 * @implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener
{
    /**
     * Constructor
     *
     * @param RoleService     $roleService The role service.
     * @param LoggerInterface $logger      PSR-3 logger for cascade failures.
     */
    public function __construct(
        private readonly RoleService $roleService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the GroupDeletedEvent — remove every role assignment
     * scoped to the deleted group.
     *
     * @param Event $event The event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof GroupDeletedEvent) === false) {
            return;
        }

        $groupId = (string) $event->getGroup()->getGID();

        try {
            $this->roleService->deleteByGroupId(groupId: $groupId);
        } catch (Throwable $t) {
            // Cascade is best-effort — log and continue so the group
            // deletion event chain is not interrupted.
            $this->logger->error(
                message: sprintf(
                    'mydash GroupDeletedListener: failed to cascade role-assignment cleanup for group %s: %s',
                    $groupId,
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
        }//end try
    }//end handle()
}//end class
