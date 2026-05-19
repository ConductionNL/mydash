<?php

/**
 * DashboardShareService
 *
 * Service for managing dashboard shares. Covers REQ-SHARE-001..013:
 * per-row add/remove, bulk replace, revoke-all-for-recipient, ownership
 * transfer, and notification publishing.
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

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardShare;
use OCA\MyDash\Db\DashboardShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Throwable;

/**
 * Service for creating and managing dashboard shares.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DashboardShareService
{

    /**
     * Permission level ordering for upgrade detection (higher index = more).
     *
     * @var array<string, int>
     */
    private const LEVEL_ORDER = [
        Dashboard::PERMISSION_VIEW_ONLY => 0,
        Dashboard::PERMISSION_ADD_ONLY  => 1,
        Dashboard::PERMISSION_FULL      => 2,
    ];

    /**
     * Constructor
     *
     * @param DashboardShareMapper $shareMapper         The share mapper.
     * @param DashboardMapper      $dashboardMapper     The dashboard mapper.
     * @param IGroupManager        $groupManager        The group manager.
     * @param INotificationManager $notificationManager The notification manager.
     * @param IDBConnection        $db                  The DB connection.
     */
    public function __construct(
        private readonly DashboardShareMapper $shareMapper,
        private readonly DashboardMapper $dashboardMapper,
        private readonly IGroupManager $groupManager,
        private readonly INotificationManager $notificationManager,
        private readonly IDBConnection $db,
    ) {
    }//end __construct()

    /**
     * List all shares for a dashboard.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $userId      The calling user ID (must own the dashboard).
     *
     * @return DashboardShare[] The shares.
     *
     * @throws Exception When the user is not the dashboard owner.
     */
    public function listShares(int $dashboardId, string $userId): array
    {
        $this->assertOwner(dashboardId: $dashboardId, userId: $userId);
        return $this->shareMapper->findByDashboardId(dashboardId: $dashboardId);
    }//end listShares()

    /**
     * Add or upsert a single share. Publishes a notification when the
     * entry is new or the permission level is upgraded. REQ-SHARE-008.
     *
     * @param int    $dashboardId     The dashboard ID.
     * @param string $shareType       The share type ('user' or 'group').
     * @param string $shareWith       The recipient user/group ID.
     * @param string $permissionLevel The permission level.
     * @param string $callerId        The calling user (must be dashboard owner).
     *
     * @return DashboardShare The persisted share.
     *
     * @throws Exception When the caller is not the owner or input is invalid.
     */
    public function addShare(
        int $dashboardId,
        string $shareType,
        string $shareWith,
        string $permissionLevel,
        string $callerId
    ): DashboardShare {
        $dashboard = $this->assertOwner(
            dashboardId: $dashboardId,
            userId: $callerId
        );
        $this->validateInput(
            shareType: $shareType,
            shareWith: $shareWith,
            permissionLevel: $permissionLevel
        );

        $result = $this->persistShare(
            dashboardId: $dashboardId,
            shareType: $shareType,
            shareWith: $shareWith,
            permissionLevel: $permissionLevel
        );

        if ($result['isNew'] === true || $result['isUpgrade'] === true) {
            $this->notifyShared(
                share: $result['share'],
                sharerUserId: $callerId,
                dashboardName: (string) $dashboard->getName()
            );
        }

        return $result['share'];
    }//end addShare()

    /**
     * Remove a share by ID. Silent — no notification is published.
     * REQ-SHARE-008 (revocations are silent).
     *
     * @param int    $shareId  The share ID.
     * @param string $callerId The calling user (must be dashboard owner).
     *
     * @return void
     *
     * @throws Exception When the share does not exist or caller is not owner.
     */
    public function removeShare(int $shareId, string $callerId): void
    {
        $share     = $this->shareMapper->find(id: $shareId);
        $dashboard = $this->dashboardMapper->find(
            id: (int) $share->getDashboardId()
        );

        if ($dashboard->getUserId() !== $callerId) {
            throw new Exception(message: 'Access denied');
        }

        $this->shareMapper->delete(entity: $share);
    }//end removeShare()

    /**
     * Atomically replace all shares for a dashboard. REQ-SHARE-009.
     *
     * Deletes every existing share not in the payload, upserts matching
     * ones. Publishes one notification per newly-added or upgraded
     * recipient only. All DB writes run in a single transaction.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param array  $shares      Array of {shareType, shareWith, permissionLevel}.
     * @param string $userId      The calling user (must be dashboard owner).
     *
     * @return DashboardShare[] The new full share list.
     *
     * @throws Exception    When the caller is not the owner or input is invalid.
     * @throws Throwable    On DB error (rolls back).
     */
    public function replaceShares(
        int $dashboardId,
        array $shares,
        string $userId
    ): array {
        $dashboard = $this->assertOwner(
            dashboardId: $dashboardId,
            userId: $userId
        );

        // Validate all entries up-front before touching the DB.
        foreach ($shares as $entry) {
            $this->validateInput(
                shareType: $entry['shareType'] ?? '',
                shareWith: $entry['shareWith'] ?? '',
                permissionLevel: $entry['permissionLevel'] ?? ''
            );
        }

        // Build the keep-key set for deletion.
        $keepKeys = [];
        foreach ($shares as $entry) {
            $keepKeys[] = $entry['shareType'].':'.$entry['shareWith'];
        }

        $notifyQueue = [];

        $this->db->beginTransaction();
        try {
            // Remove shares not in payload.
            $this->shareMapper->deleteNotIn(
                dashboardId: $dashboardId,
                keepKeys: $keepKeys
            );

            // Upsert each entry.
            foreach ($shares as $entry) {
                $result = $this->persistShare(
                    dashboardId: $dashboardId,
                    shareType: $entry['shareType'],
                    shareWith: $entry['shareWith'],
                    permissionLevel: $entry['permissionLevel']
                );

                if ($result['isNew'] === true || $result['isUpgrade'] === true) {
                    $notifyQueue[] = $result['share'];
                }
            }

            $this->db->commit();
        } catch (Throwable $t) {
            $this->db->rollBack();
            throw $t;
        }//end try

        // Publish notifications after the transaction commits.
        $dashboardName = (string) $dashboard->getName();
        foreach ($notifyQueue as $share) {
            $this->notifyShared(
                share: $share,
                sharerUserId: $userId,
                dashboardName: $dashboardName
            );
        }

        return $this->shareMapper->findByDashboardId(dashboardId: $dashboardId);
    }//end replaceShares()

    /**
     * Remove every share where the caller is the owner AND the share targets
     * the named recipient. REQ-SHARE-010.
     *
     * Only touches dashboards owned by $callerId — shares on dashboards
     * owned by others are not affected even if $callerId holds a `full`
     * share on them.
     *
     * @param string $shareType The share type.
     * @param string $shareWith The recipient user/group ID.
     * @param string $callerId  The calling user (owner restriction).
     *
     * @return int The number of share rows deleted.
     *
     * @throws InvalidArgumentException When shareType is invalid.
     */
    public function revokeAllForRecipient(
        string $shareType,
        string $shareWith,
        string $callerId
    ): int {
        $validType = in_array(
            needle: $shareType,
            haystack: DashboardShare::VALID_SHARE_TYPES,
            strict: true
        );
        if ($validType === false) {
            throw new InvalidArgumentException(
                message: 'Invalid shareType: '.$shareType
            );
        }

        return $this->shareMapper->deleteByOwnerAndRecipient(
            shareType: $shareType,
            shareWith: $shareWith,
            ownerId: $callerId
        );
    }//end revokeAllForRecipient()

    /**
     * Resolve every share that grants the given user access to a dashboard,
     * keyed by dashboard id. When the user is reached via multiple shares
     * (e.g. direct + group), the most permissive level wins.
     *
     * Used by PermissionService to compute effective access on dashboards
     * the user does not own. REQ-SHARE-002, REQ-SHARE-004.
     *
     * @param string   $userId   The recipient user id.
     * @param string[] $groupIds The recipient's group ids.
     *
     * @return array<int,string> Map of dashboardId => permission level.
     */
    public function resolveSharedDashboards(string $userId, array $groupIds): array
    {
        $shares = $this->shareMapper->findForRecipient(
            userId: $userId,
            groupIds: $groupIds
        );

        $result = [];
        foreach ($shares as $share) {
            $dashboardId  = (int) $share->getDashboardId();
            $currentLevel = (string) $share->getPermissionLevel();
            $currentRank  = (self::LEVEL_ORDER[$currentLevel] ?? 0);
            $existingRank = (self::LEVEL_ORDER[$result[$dashboardId] ?? ''] ?? -1);
            if ($currentRank > $existingRank) {
                $result[$dashboardId] = $currentLevel;
            }
        }

        return $result;
    }//end resolveSharedDashboards()

    /**
     * Transfer dashboard ownership to a new user.
     *
     * Updates the dashboard's user_id, removes the share row that
     * previously gave the new owner access, and stamps updated_at.
     * REQ-SHARE-013 (internal helper called by UserDeletedListener).
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $newUserId   The new owner's user ID.
     *
     * @return void
     */
    public function transferOwnership(int $dashboardId, string $newUserId): void
    {
        $dashboard = $this->dashboardMapper->find(id: $dashboardId);

        // Update ownership.
        $dashboard->setUserId($newUserId);
        $dashboard->setUpdatedAt(
            (new DateTime())->format(format: 'Y-m-d H:i:s')
        );
        $this->dashboardMapper->update(entity: $dashboard);

        // Remove the share row that gave newUserId access (they now own it).
        $existingShare = $this->shareMapper->findShare(
            dashboardId: $dashboardId,
            shareType: DashboardShare::SHARE_TYPE_USER,
            shareWith: $newUserId
        );
        if ($existingShare !== null) {
            $this->shareMapper->delete(entity: $existingShare);
        }
    }//end transferOwnership()

    /**
     * Publish a `dashboard_ownership_transferred` notification.
     *
     * @param string $newOwnerId    The new owner's user ID.
     * @param int    $dashboardId   The dashboard ID.
     * @param string $dashboardName The dashboard name.
     *
     * @return void
     */
    public function notifyOwnershipTransferred(
        string $newOwnerId,
        int $dashboardId,
        string $dashboardName
    ): void {
        $notification = $this->notificationManager->createNotification();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $notification->setApp('mydash')
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            ->setUser($newOwnerId)
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            ->setDateTime(new DateTime())
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            ->setObject('dashboard', (string) $dashboardId)
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            ->setSubject('dashboard_ownership_transferred', [$dashboardName]);

        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->notificationManager->notify($notification);
    }//end notifyOwnershipTransferred()

    /**
     * Persist a single share (insert or update). Returns metadata about
     * whether the row is new or the level was upgraded.
     *
     * @param int    $dashboardId     The dashboard ID.
     * @param string $shareType       The share type.
     * @param string $shareWith       The recipient.
     * @param string $permissionLevel The permission level.
     *
     * @return array{share: DashboardShare, isNew: bool, isUpgrade: bool}
     */
    private function persistShare(
        int $dashboardId,
        string $shareType,
        string $shareWith,
        string $permissionLevel
    ): array {
        $now      = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $existing = $this->shareMapper->findShare(
            dashboardId: $dashboardId,
            shareType: $shareType,
            shareWith: $shareWith
        );

        if ($existing === null) {
            // Insert new share.
            $share = new DashboardShare();
            $share->setDashboardId($dashboardId);
            $share->setShareType($shareType);
            $share->setShareWith($shareWith);
            $share->setPermissionLevel($permissionLevel);
            $share->setCreatedAt($now);
            $share->setUpdatedAt($now);

            return [
                'share'     => $this->shareMapper->insert(entity: $share),
                'isNew'     => true,
                'isUpgrade' => false,
            ];
        }

        // Detect upgrade.
        $oldLevel  = (string) $existing->getPermissionLevel();
        $newOrder  = (self::LEVEL_ORDER[$permissionLevel] ?? 0);
        $oldOrder  = (self::LEVEL_ORDER[$oldLevel] ?? 0);
        $isUpgrade = ($newOrder > $oldOrder);

        // No-op: same level.
        if ($oldLevel === $permissionLevel) {
            return [
                'share'     => $existing,
                'isNew'     => false,
                'isUpgrade' => false,
            ];
        }

        // Update level.
        $existing->setPermissionLevel($permissionLevel);
        $existing->setUpdatedAt($now);

        return [
            'share'     => $this->shareMapper->update(entity: $existing),
            'isNew'     => false,
            'isUpgrade' => $isUpgrade,
        ];
    }//end persistShare()

    /**
     * Publish `dashboard_shared` notifications for a share.
     *
     * For `user`-type shares: one notification.
     * For `group`-type shares: fan out one notification per current group
     * member, excluding the sharer. REQ-SHARE-008.
     *
     * @param DashboardShare $share         The share row.
     * @param string         $sharerUserId  The user who created the share.
     * @param string         $dashboardName The dashboard name.
     *
     * @return void
     */
    private function notifyShared(
        DashboardShare $share,
        string $sharerUserId,
        string $dashboardName
    ): void {
        $recipients = $this->resolveRecipients(
            share: $share,
            excludeUserId: $sharerUserId
        );

        foreach ($recipients as $recipientId) {
            $notification = $this->notificationManager->createNotification();
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $notification->setApp('mydash')
                // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
                ->setUser($recipientId)
                // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
                ->setDateTime(new DateTime())
                // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
                ->setObject('dashboard', (string) $share->getDashboardId())
                // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
                ->setSubject(
                    'dashboard_shared',
                    [
                        $sharerUserId,
                        $dashboardName,
                        (string) $share->getPermissionLevel(),
                    ]
                );

            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $this->notificationManager->notify($notification);
        }//end foreach
    }//end notifyShared()

    /**
     * Resolve the recipient user IDs for a share.
     *
     * @param DashboardShare $share         The share.
     * @param string         $excludeUserId A user ID to exclude (the sharer).
     *
     * @return string[] The recipient user IDs.
     */
    private function resolveRecipients(
        DashboardShare $share,
        string $excludeUserId
    ): array {
        if ($share->getShareType() === DashboardShare::SHARE_TYPE_USER) {
            $uid = (string) $share->getShareWith();
            if ($uid === $excludeUserId) {
                return [];
            }

            return [$uid];
        }

        // Group share — expand members.
        $groupId = (string) $share->getShareWith();
        $group   = $this->groupManager->get(gid: $groupId);
        if ($group === null) {
            return [];
        }

        $recipients = [];
        foreach ($group->getUsers() as $user) {
            $uid = $user->getUID();
            if ($uid !== $excludeUserId) {
                $recipients[] = $uid;
            }
        }

        return $recipients;
    }//end resolveRecipients()

    /**
     * Validate share type, shareWith, and permission level.
     *
     * @param string $shareType       The share type.
     * @param string $shareWith       The recipient.
     * @param string $permissionLevel The permission level.
     *
     * @return void
     *
     * @throws InvalidArgumentException On invalid input.
     */
    private function validateInput(
        string $shareType,
        string $shareWith,
        string $permissionLevel
    ): void {
        $validType = in_array(
            needle: $shareType,
            haystack: DashboardShare::VALID_SHARE_TYPES,
            strict: true
        );
        if ($validType === false) {
            throw new InvalidArgumentException(
                message: 'Invalid shareType: '.$shareType
            );
        }

        if ($shareWith === '') {
            throw new InvalidArgumentException(message: 'shareWith is required');
        }

        $validLevel = in_array(
            needle: $permissionLevel,
            haystack: DashboardShare::VALID_PERMISSION_LEVELS,
            strict: true
        );
        if ($validLevel === false) {
            throw new InvalidArgumentException(
                message: 'Invalid permissionLevel: '.$permissionLevel
            );
        }
    }//end validateInput()

    /**
     * Assert the caller is the dashboard owner and return the dashboard.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $userId      The expected owner.
     *
     * @return Dashboard The dashboard.
     *
     * @throws Exception When the user is not the owner.
     */
    private function assertOwner(int $dashboardId, string $userId): Dashboard
    {
        $dashboard = $this->dashboardMapper->find(id: $dashboardId);

        if ($dashboard->getUserId() !== $userId) {
            throw new Exception(message: 'Access denied');
        }

        return $dashboard;
    }//end assertOwner()
}//end class
