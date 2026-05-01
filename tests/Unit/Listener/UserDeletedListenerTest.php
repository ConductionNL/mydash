<?php

/**
 * UserDeletedListenerTest
 *
 * Unit tests for UserDeletedListener covering REQ-SHARE-012 and
 * REQ-SHARE-013: admin-pool computation, ownership transfer, deletion
 * cascade, selection rules, and recipient-cleanup.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Listener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardShare;
use OCA\MyDash\Db\DashboardShareMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Listener\UserDeletedListener;
use OCA\MyDash\Service\DashboardShareService;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UserDeletedListener.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class UserDeletedListenerTest extends TestCase
{

    /** @var DashboardShareMapper&MockObject */
    private $shareMapper;
    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;
    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;
    /** @var DashboardShareService&MockObject */
    private $shareService;
    /** @var IGroupManager&MockObject */
    private $groupManager;
    /** @var IUserManager&MockObject */
    private $userManager;
    /** @var IDBConnection&MockObject */
    private $db;

    private UserDeletedListener $listener;

    /**
     * Set up fresh mocks for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->shareMapper     = $this->createMock(DashboardShareMapper::class);
        $this->dashboardMapper = $this->createMock(DashboardMapper::class);
        $this->placementMapper = $this->createMock(WidgetPlacementMapper::class);
        $this->shareService    = $this->createMock(DashboardShareService::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userManager     = $this->createMock(IUserManager::class);
        $this->db              = $this->createMock(IDBConnection::class);

        // Default: transaction methods succeed (void return).
        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->listener = new UserDeletedListener(
            shareMapper: $this->shareMapper,
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            shareService: $this->shareService,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            db: $this->db,
        );
    }//end setUp()

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a minimal Dashboard mock.
     *
     * @param int    $id     The dashboard ID.
     * @param string $userId The owner user ID.
     * @param string $name   The dashboard name.
     *
     * @return Dashboard&MockObject
     */
    private function makeDashboard(
        int $id,
        string $userId,
        string $name='Test Dashboard'
    ): Dashboard {
        $dashboard = $this->getMockBuilder(Dashboard::class)
            ->addMethods(['getId', 'getUserId', 'getName'])
            ->getMock();
        $dashboard->method('getId')->willReturn($id);
        $dashboard->method('getUserId')->willReturn($userId);
        $dashboard->method('getName')->willReturn($name);
        return $dashboard;
    }//end makeDashboard()

    /**
     * Create a DashboardShare mock.
     *
     * @param int    $dashboardId     Dashboard ID.
     * @param string $shareType       Share type.
     * @param string $shareWith       Recipient.
     * @param string $permissionLevel Permission level.
     * @param string $createdAt       Creation timestamp.
     *
     * @return DashboardShare&MockObject
     */
    private function makeShare(
        int $dashboardId,
        string $shareType,
        string $shareWith,
        string $permissionLevel='full',
        string $createdAt='2026-01-01 00:00:00'
    ): DashboardShare {
        $share = $this->getMockBuilder(DashboardShare::class)
            ->addMethods([
                'getDashboardId',
                'getShareType',
                'getShareWith',
                'getPermissionLevel',
                'getCreatedAt',
            ])
            ->getMock();
        $share->method('getDashboardId')->willReturn($dashboardId);
        $share->method('getShareType')->willReturn($shareType);
        $share->method('getShareWith')->willReturn($shareWith);
        $share->method('getPermissionLevel')->willReturn($permissionLevel);
        $share->method('getCreatedAt')->willReturn($createdAt);
        return $share;
    }//end makeShare()

    /**
     * Create an IUser mock.
     *
     * @param string $uid The user ID.
     *
     * @return IUser&MockObject
     */
    private function makeUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }//end makeUser()

    /**
     * Create an IGroup mock with the given member UIDs.
     *
     * @param string   $gid     The group ID.
     * @param string[] $members The member UIDs.
     *
     * @return IGroup&MockObject
     */
    private function makeGroup(string $gid, array $members): IGroup
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $userMocks = array_map(fn($uid) => $this->makeUser($uid), $members);
        $group->method('getUsers')->willReturn($userMocks);
        return $group;
    }//end makeGroup()

    /**
     * Create a UserDeletedEvent mock for the given uid.
     *
     * @param string $uid The user ID.
     *
     * @return UserDeletedEvent&MockObject
     */
    private function makeEvent(string $uid): UserDeletedEvent
    {
        $user  = $this->makeUser($uid);
        $event = $this->createMock(UserDeletedEvent::class);
        $event->method('getUser')->willReturn($user);
        return $event;
    }//end makeEvent()

    // =========================================================================
    // Tests — recipient cleanup
    // =========================================================================

    /**
     * All shares granted TO the deleted user must be removed.
     *
     * @return void
     */
    public function testRecipientSharesAreDeleted(): void
    {
        $event = $this->makeEvent('bob');

        $this->shareMapper->expects($this->once())
            ->method('deleteByRecipientUser')
            ->with('bob');

        // Bob owns no dashboards.
        $this->dashboardMapper->method('findByUserId')
            ->with('bob')
            ->willReturn([]);

        $this->listener->handle($event);
    }//end testRecipientSharesAreDeleted()

    // =========================================================================
    // Tests — admin pool non-empty: ownership transferred
    // =========================================================================

    /**
     * Dashboard with a full-level user share is transferred, not deleted.
     *
     * @return void
     */
    public function testOwnershipTransferredWhenUserShareExists(): void
    {
        $event     = $this->makeEvent('alice');
        $dashboard = $this->makeDashboard(id: 5, userId: 'alice', name: 'Q3 Plan');

        $this->shareMapper->method('deleteByRecipientUser');
        $this->dashboardMapper->method('findByUserId')
            ->with('alice')
            ->willReturn([$dashboard]);

        $bobShare = $this->makeShare(
            dashboardId: 5,
            shareType: 'user',
            shareWith: 'bob',
            permissionLevel: 'full'
        );
        $carolShare = $this->makeShare(
            dashboardId: 5,
            shareType: 'user',
            shareWith: 'carol',
            permissionLevel: 'view_only'
        );

        $this->shareMapper->method('findByDashboardAndLevel')
            ->with(5, 'full')
            ->willReturn([$bobShare]);

        // Bob still exists.
        $this->userManager->method('get')
            ->with('bob')
            ->willReturn($this->makeUser('bob'));

        // Expect transferOwnership called with dashboard 5 and bob.
        $this->shareService->expects($this->once())
            ->method('transferOwnership')
            ->with(5, 'bob');

        // Expect notification sent to bob.
        $this->shareService->expects($this->once())
            ->method('notifyOwnershipTransferred')
            ->with('bob', 5, 'Q3 Plan');

        // Must NOT delete dashboard.
        $this->dashboardMapper->expects($this->never())
            ->method('delete');

        $this->listener->handle($event);
    }//end testOwnershipTransferredWhenUserShareExists()

    // =========================================================================
    // Tests — admin pool empty: dashboard deleted
    // =========================================================================

    /**
     * Dashboard with only a view_only share must be deleted.
     *
     * @return void
     */
    public function testDashboardDeletedWhenAdminPoolEmpty(): void
    {
        $event     = $this->makeEvent('alice');
        $dashboard = $this->makeDashboard(id: 5, userId: 'alice');

        $this->shareMapper->method('deleteByRecipientUser');
        $this->dashboardMapper->method('findByUserId')
            ->with('alice')
            ->willReturn([$dashboard]);

        // Only view_only shares — admin pool is empty.
        $this->shareMapper->method('findByDashboardAndLevel')
            ->with(5, 'full')
            ->willReturn([]);

        // Expect placements + shares + dashboard deleted.
        $this->placementMapper->expects($this->once())
            ->method('deleteByDashboardId')
            ->with(5);
        $this->shareMapper->expects($this->once())
            ->method('deleteByDashboardId')
            ->with(5);
        $this->dashboardMapper->expects($this->once())
            ->method('delete')
            ->with($dashboard);

        // Must NOT transfer ownership.
        $this->shareService->expects($this->never())
            ->method('transferOwnership');

        $this->listener->handle($event);
    }//end testDashboardDeletedWhenAdminPoolEmpty()

    // =========================================================================
    // Tests — selection rule: user-type preferred; earliest created_at
    // =========================================================================

    /**
     * Among user-type full shares, the one with earliest created_at is chosen.
     *
     * @return void
     */
    public function testUserShareEarliestCreatedAtWins(): void
    {
        $event     = $this->makeEvent('alice');
        $dashboard = $this->makeDashboard(id: 5, userId: 'alice', name: 'Board');

        $this->shareMapper->method('deleteByRecipientUser');
        $this->dashboardMapper->method('findByUserId')
            ->willReturn([$dashboard]);

        // Dave has earliest created_at; bob has later.
        $daveShare = $this->makeShare(5, 'user', 'dave', 'full', '2025-12-10 00:00:00');
        $bobShare  = $this->makeShare(5, 'user', 'bob', 'full', '2026-01-15 00:00:00');

        // Mapper returns in created_at ASC order (dave first).
        $this->shareMapper->method('findByDashboardAndLevel')
            ->willReturn([$daveShare, $bobShare]);

        $this->userManager->method('get')
            ->willReturnCallback(function ($uid) {
                return $this->makeUser($uid);
            });

        $this->shareService->expects($this->once())
            ->method('transferOwnership')
            ->with(5, 'dave');

        $this->listener->handle($event);
    }//end testUserShareEarliestCreatedAtWins()

    // =========================================================================
    // Tests — selection rule: group fallback
    // =========================================================================

    /**
     * When no user shares exist, falls back to alphabetically-first member of
     * alphabetically-first group.
     *
     * @return void
     */
    public function testGroupFallbackAlphabeticallyFirstMember(): void
    {
        $event     = $this->makeEvent('alice');
        $dashboard = $this->makeDashboard(id: 5, userId: 'alice', name: 'Board');

        $this->shareMapper->method('deleteByRecipientUser');
        $this->dashboardMapper->method('findByUserId')
            ->willReturn([$dashboard]);

        // Two group shares: zeta-team and alpha-team.
        $zetaShare  = $this->makeShare(5, 'group', 'zeta-team', 'full');
        $alphaShare = $this->makeShare(5, 'group', 'alpha-team', 'full');

        // Only group shares (no user shares).
        $this->shareMapper->method('findByDashboardAndLevel')
            ->willReturn([$zetaShare, $alphaShare]);

        $alphaGroup = $this->makeGroup('alpha-team', ['victor', 'bob', 'alex']);
        $zetaGroup  = $this->makeGroup('zeta-team', ['mark', 'lily']);

        $this->groupManager->method('get')
            ->willReturnCallback(function ($gid) use ($alphaGroup, $zetaGroup) {
                if ($gid === 'alpha-team') {
                    return $alphaGroup;
                }

                if ($gid === 'zeta-team') {
                    return $zetaGroup;
                }

                return null;
            });

        $this->userManager->method('get')
            ->willReturnCallback(function ($uid) {
                return $this->makeUser($uid);
            });

        // alex is alphabetically first in alpha-team (alphabetically first group).
        $this->shareService->expects($this->once())
            ->method('transferOwnership')
            ->with(5, 'alex');

        $this->listener->handle($event);
    }//end testGroupFallbackAlphabeticallyFirstMember()

    // =========================================================================
    // Tests — group share members all deleted → falls through to delete
    // =========================================================================

    /**
     * When every group member is also a deleted user, the pool is empty
     * and the dashboard must be deleted.
     *
     * @return void
     */
    public function testGroupShareAllMembersDeletedFallsToDelete(): void
    {
        $event     = $this->makeEvent('alice');
        $dashboard = $this->makeDashboard(id: 5, userId: 'alice');

        $this->shareMapper->method('deleteByRecipientUser');
        $this->dashboardMapper->method('findByUserId')
            ->willReturn([$dashboard]);

        $ghostShare = $this->makeShare(5, 'group', 'ghosts', 'full');
        $this->shareMapper->method('findByDashboardAndLevel')
            ->willReturn([$ghostShare]);

        $ghostGroup = $this->makeGroup('ghosts', ['ghost1', 'ghost2']);
        $this->groupManager->method('get')
            ->willReturn($ghostGroup);

        // All members return null — they are deleted.
        $this->userManager->method('get')
            ->willReturn(null);

        $this->placementMapper->expects($this->once())
            ->method('deleteByDashboardId')
            ->with(5);
        $this->shareMapper->expects($this->once())
            ->method('deleteByDashboardId')
            ->with(5);
        $this->dashboardMapper->expects($this->once())
            ->method('delete');

        $this->shareService->expects($this->never())
            ->method('transferOwnership');

        $this->listener->handle($event);
    }//end testGroupShareAllMembersDeletedFallsToDelete()

    // =========================================================================
    // Tests — non-UserDeletedEvent is ignored
    // =========================================================================

    /**
     * Non-UserDeletedEvent events are silently ignored.
     *
     * @return void
     */
    public function testNonUserDeletedEventIsIgnored(): void
    {
        $event = $this->createMock(\OCP\EventDispatcher\Event::class);

        $this->shareMapper->expects($this->never())
            ->method('deleteByRecipientUser');
        $this->dashboardMapper->expects($this->never())
            ->method('findByUserId');

        $this->listener->handle($event);
    }//end testNonUserDeletedEventIsIgnored()
}//end class
