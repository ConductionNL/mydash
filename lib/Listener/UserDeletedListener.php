<?php

/**
 * UserDeletedListener
 *
 * Listens to OCP\User\Events\UserDeletedEvent and applies the admin-retention
 * cascade: cleans up shares granted to the deleted user, then for every
 * dashboard the user owned either transfers ownership to the first admin-pool
 * member or deletes the dashboard when the pool is empty. REQ-SHARE-012.
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

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardShare;
use OCA\MyDash\Db\DashboardShareMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\DashboardShareService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles user deletion: recipient cleanup + ownership transfer / cascade.
 *
 * @implements IEventListener<UserDeletedEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The retention cascade
 *                                                  legitimately spans share,
 *                                                  dashboard, placement,
 *                                                  group and user services
 *                                                  in one orchestrating
 *                                                  listener.
 */
class UserDeletedListener implements IEventListener
{
    /**
     * Constructor
     *
     * @param DashboardShareMapper  $shareMapper     The share mapper.
     * @param DashboardMapper       $dashboardMapper The dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper The placement mapper.
     * @param DashboardShareService $shareService    The share service.
     * @param IGroupManager         $groupManager    The group manager.
     * @param IUserManager          $userManager     The user manager.
     * @param IDBConnection         $db              The DB connection.
     * @param LoggerInterface       $logger          PSR-3 logger (PHP_SAPI-safe;
     *                                               replaces deprecated
     *                                               `\OC::$server->getLogger()`).
     */
    public function __construct(
        private readonly DashboardShareMapper $shareMapper,
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly DashboardShareService $shareService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the UserDeletedEvent.
     *
     * Step A: delete all user-type shares where share_with = deleted user.
     * Step B: for each owned dashboard, compute the admin pool and either
     *         transfer ownership or delete the dashboard.
     *
     * @param Event $event The event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof UserDeletedEvent) === false) {
            return;
        }

        $userId = $event->getUser()->getUID();

        // Step A: remove shares granted TO the deleted user.
        $this->shareMapper->deleteByRecipientUser(userId: $userId);

        // Step B: handle owned dashboards.
        $ownedDashboards = $this->dashboardMapper->findByUserId(
            userId: $userId
        );

        foreach ($ownedDashboards as $dashboard) {
            $this->handleOwnedDashboard(
                dashboard: $dashboard,
                deletedUserId: $userId
            );
        }
    }//end handle()

    /**
     * Handle a single dashboard owned by the deleted user.
     *
     * @param Dashboard $dashboard     The dashboard.
     * @param string    $deletedUserId The deleted user's ID.
     *
     * @return void
     */
    private function handleOwnedDashboard(
        Dashboard $dashboard,
        string $deletedUserId
    ): void {
        $dashboardId = (int) $dashboard->getId();

        $this->db->beginTransaction();
        try {
            $newOwner = $this->pickNewOwner(
                dashboardId: $dashboardId,
                deletedUserId: $deletedUserId
            );

            if ($newOwner !== null) {
                // Transfer ownership.
                $this->shareService->transferOwnership(
                    dashboardId: $dashboardId,
                    newUserId: $newOwner
                );

                $this->db->commit();

                // Notify outside the transaction.
                $this->shareService->notifyOwnershipTransferred(
                    newOwnerId: $newOwner,
                    dashboardId: $dashboardId,
                    dashboardName: (string) $dashboard->getName()
                );
            } else {
                // Admin pool empty — delete dashboard, placements, and shares.
                $this->placementMapper->deleteByDashboardId(
                    dashboardId: $dashboardId
                );
                $this->shareMapper->deleteByDashboardId(
                    dashboardId: $dashboardId
                );
                $this->dashboardMapper->delete(entity: $dashboard);

                $this->db->commit();
            }//end if
        } catch (Throwable $t) {
            $this->db->rollBack();
            // Log but do not rethrow — we want to continue processing
            // the other dashboards.
            $this->logger->error(
                message: sprintf(
                    'mydash UserDeletedListener: failed to handle dashboard %d: %s',
                    $dashboardId,
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
        }//end try
    }//end handleOwnedDashboard()

    /**
     * Compute the admin pool and pick the new owner per REQ-SHARE-013.
     *
     * Selection rule:
     * 1. User-type shares with `permission_level='full'`, ordered by
     *    created_at ASC — pick the first still-existing user.
     * 2. If none, take the alphabetically-first group-type share with
     *    `permission_level='full'` and from its members pick the
     *    alphabetically-first uid that still exists.
     * 3. If both fail, return null (delete path).
     *
     * @param int    $dashboardId   The dashboard ID.
     * @param string $deletedUserId The deleted user (excluded from pool).
     *
     * @return string|null The new owner uid or null.
     */
    private function pickNewOwner(
        int $dashboardId,
        string $deletedUserId
    ): ?string {
        $fullShares = $this->shareMapper->findByDashboardAndLevel(
            dashboardId: $dashboardId,
            permissionLevel: Dashboard::PERMISSION_FULL
        );

        // Step 1: user-type shares sorted by created_at ASC (mapper already
        // returns rows in that order).
        foreach ($fullShares as $share) {
            if ($share->getShareType() !== DashboardShare::SHARE_TYPE_USER) {
                continue;
            }

            $uid = (string) $share->getShareWith();
            if ($uid === $deletedUserId) {
                continue;
            }

            if ($this->userManager->get(uid: $uid) !== null) {
                return $uid;
            }
        }

        // Step 2: group-type shares — pick alphabetically-first group name.
        $groupShares = [];
        foreach ($fullShares as $share) {
            if ($share->getShareType() === DashboardShare::SHARE_TYPE_GROUP) {
                $groupShares[] = $share;
            }
        }

        // Sort groups alphabetically.
        usort(
            array: $groupShares,
            callback: static fn($a, $b) => strcmp(
                string1: (string) $a->getShareWith(),
                string2: (string) $b->getShareWith()
            )
        );

        foreach ($groupShares as $groupShare) {
            $groupId = (string) $groupShare->getShareWith();
            $group   = $this->groupManager->get(gid: $groupId);
            if ($group === null) {
                continue;
            }

            // Get members and sort alphabetically.
            $members = [];
            foreach ($group->getUsers() as $user) {
                $members[] = $user->getUID();
            }

            sort(array: $members);

            foreach ($members as $uid) {
                if ($uid === $deletedUserId) {
                    continue;
                }

                if ($this->userManager->get(uid: $uid) !== null) {
                    return $uid;
                }
            }//end foreach
        }//end foreach

        return null;
    }//end pickNewOwner()
}//end class
