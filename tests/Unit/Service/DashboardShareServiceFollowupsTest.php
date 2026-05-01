<?php

/**
 * DashboardShareServiceFollowupsTest
 *
 * Unit tests for DashboardShareService follow-up methods:
 * replaceShares (REQ-SHARE-009) and revokeAllForRecipient (REQ-SHARE-010).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use InvalidArgumentException;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardShare;
use OCA\MyDash\Db\DashboardShareMapper;
use OCA\MyDash\Service\DashboardShareService;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardShareService follow-up methods.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DashboardShareServiceFollowupsTest extends TestCase
{

    /** @var DashboardShareMapper&MockObject */
    private $shareMapper;
    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;
    /** @var IGroupManager&MockObject */
    private $groupManager;
    /** @var INotificationManager&MockObject */
    private $notificationManager;
    /** @var IDBConnection&MockObject */
    private $db;

    private DashboardShareService $service;

    /**
     * Set up fresh mocks for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->shareMapper         = $this->createMock(DashboardShareMapper::class);
        $this->dashboardMapper     = $this->createMock(DashboardMapper::class);
        $this->groupManager        = $this->createMock(IGroupManager::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->db                  = $this->createMock(IDBConnection::class);

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->service = new DashboardShareService(
            shareMapper: $this->shareMapper,
            dashboardMapper: $this->dashboardMapper,
            groupManager: $this->groupManager,
            notificationManager: $this->notificationManager,
            db: $this->db,
        );
    }//end setUp()

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a Dashboard mock owned by the given user.
     *
     * @param int    $id     Dashboard ID.
     * @param string $userId Owner.
     * @param string $name   Dashboard name.
     *
     * @return Dashboard&MockObject
     */
    private function makeDashboard(
        int $id,
        string $userId,
        string $name='Test'
    ): Dashboard {
        $d = $this->getMockBuilder(Dashboard::class)
            ->addMethods(['getId', 'getUserId', 'getName'])
            ->getMock();
        $d->method('getId')->willReturn($id);
        $d->method('getUserId')->willReturn($userId);
        $d->method('getName')->willReturn($name);
        return $d;
    }//end makeDashboard()

    /**
     * Create a DashboardShare mock.
     *
     * @param int    $id              Share ID.
     * @param int    $dashboardId     Dashboard ID.
     * @param string $shareType       Share type.
     * @param string $shareWith       Recipient.
     * @param string $permissionLevel Permission level.
     *
     * @return DashboardShare&MockObject
     */
    private function makeShare(
        int $id,
        int $dashboardId,
        string $shareType,
        string $shareWith,
        string $permissionLevel='view_only'
    ): DashboardShare {
        $s = $this->getMockBuilder(DashboardShare::class)
            ->addMethods([
                'getId',
                'getDashboardId',
                'getShareType',
                'getShareWith',
                'getPermissionLevel',
            ])
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $s->method('getId')->willReturn($id);
        $s->method('getDashboardId')->willReturn($dashboardId);
        $s->method('getShareType')->willReturn($shareType);
        $s->method('getShareWith')->willReturn($shareWith);
        $s->method('getPermissionLevel')->willReturn($permissionLevel);
        $s->method('jsonSerialize')->willReturn([
            'id'              => $id,
            'dashboardId'     => $dashboardId,
            'shareType'       => $shareType,
            'shareWith'       => $shareWith,
            'permissionLevel' => $permissionLevel,
        ]);
        return $s;
    }//end makeShare()

    /**
     * Create an INotification mock.
     *
     * @return INotification&MockObject
     */
    private function makeNotification(): INotification
    {
        $n = $this->createMock(INotification::class);
        $n->method('setApp')->willReturnSelf();
        $n->method('setUser')->willReturnSelf();
        $n->method('setDateTime')->willReturnSelf();
        $n->method('setObject')->willReturnSelf();
        $n->method('setSubject')->willReturnSelf();
        return $n;
    }//end makeNotification()

    // =========================================================================
    // replaceShares tests
    // =========================================================================

    /**
     * replaceShares returns 403 when caller is not owner.
     *
     * @return void
     */
    public function testReplaceSharesForbiddenWhenNotOwner(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Access denied');

        $dashboard = $this->makeDashboard(5, 'alice');
        $this->dashboardMapper->method('find')->willReturn($dashboard);

        $this->service->replaceShares(
            dashboardId: 5,
            shares: [],
            userId: 'bob'
        );
    }//end testReplaceSharesForbiddenWhenNotOwner()

    /**
     * replaceShares runs in a single transaction and deletes shares not in payload.
     *
     * @return void
     */
    public function testReplaceSharesIsAtomic(): void
    {
        $dashboard = $this->makeDashboard(5, 'alice');
        $this->dashboardMapper->method('find')->willReturn($dashboard);

        // Existing shares: bob (view_only), carol (view_only), group:sales.
        $this->shareMapper->method('findShare')->willReturn(null);
        $this->shareMapper->expects($this->once())
            ->method('deleteNotIn')
            ->with(5, ['user:bob', 'user:dave']);

        // Verify transaction usage.
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        // Insert returns new shares.
        $bobShare  = $this->makeShare(1, 5, 'user', 'bob', 'full');
        $daveShare = $this->makeShare(2, 5, 'user', 'dave', 'view_only');
        $this->shareMapper->method('insert')
            ->willReturnOnConsecutiveCalls($bobShare, $daveShare);

        // findByDashboardId returns new list.
        $this->shareMapper->method('findByDashboardId')
            ->willReturn([$bobShare, $daveShare]);

        $result = $this->service->replaceShares(
            dashboardId: 5,
            shares: [
                ['shareType' => 'user', 'shareWith' => 'bob', 'permissionLevel' => 'full'],
                ['shareType' => 'user', 'shareWith' => 'dave', 'permissionLevel' => 'view_only'],
            ],
            userId: 'alice'
        );

        $this->assertCount(2, $result);
    }//end testReplaceSharesIsAtomic()

    /**
     * Idempotent replaceShares publishes no notifications.
     *
     * @return void
     */
    public function testIdempotentReplacePublishesNoNotifications(): void
    {
        $dashboard = $this->makeDashboard(5, 'alice');
        $this->dashboardMapper->method('find')->willReturn($dashboard);

        $existingBob = $this->makeShare(1, 5, 'user', 'bob', 'full');

        // findShare returns existing share (no-op path).
        $this->shareMapper->method('findShare')
            ->willReturn($existingBob);

        $this->shareMapper->method('deleteNotIn');
        $this->shareMapper->method('findByDashboardId')
            ->willReturn([$existingBob]);

        // No notifications should be published.
        $this->notificationManager->expects($this->never())
            ->method('notify');

        $this->service->replaceShares(
            dashboardId: 5,
            shares: [
                ['shareType' => 'user', 'shareWith' => 'bob', 'permissionLevel' => 'full'],
            ],
            userId: 'alice'
        );
    }//end testIdempotentReplacePublishesNoNotifications()

    /**
     * replaceShares notifies newly added and upgraded recipients only.
     *
     * @return void
     */
    public function testReplaceSharesNotifiesNewAndUpgraded(): void
    {
        $dashboard = $this->makeDashboard(5, 'alice', 'Board');
        $this->dashboardMapper->method('find')->willReturn($dashboard);

        // bob: upgrade from view_only to full.
        $existingBob = $this->makeShare(1, 5, 'user', 'bob', 'view_only');
        // dave: new share.
        // carol: same level (no-op) — not in payload anymore (removed).

        $this->shareMapper->method('findShare')
            ->willReturnCallback(function ($dashId, $type, $with) use ($existingBob) {
                if ($type === 'user' && $with === 'bob') {
                    return $existingBob;
                }

                return null;
            });

        $this->shareMapper->method('deleteNotIn');

        // Update bob (upgrade), insert dave.
        $upgradedBob = $this->makeShare(1, 5, 'user', 'bob', 'full');
        $newDave     = $this->makeShare(2, 5, 'user', 'dave', 'view_only');
        $this->shareMapper->method('update')->willReturn($upgradedBob);
        $this->shareMapper->method('insert')->willReturn($newDave);
        $this->shareMapper->method('findByDashboardId')
            ->willReturn([$upgradedBob, $newDave]);

        // Expect exactly 2 notifications (bob upgrade + dave new).
        $notification = $this->makeNotification();
        $this->notificationManager->method('createNotification')
            ->willReturn($notification);
        $this->notificationManager->expects($this->exactly(2))
            ->method('notify');

        $this->service->replaceShares(
            dashboardId: 5,
            shares: [
                ['shareType' => 'user', 'shareWith' => 'bob', 'permissionLevel' => 'full'],
                ['shareType' => 'user', 'shareWith' => 'dave', 'permissionLevel' => 'view_only'],
            ],
            userId: 'alice'
        );
    }//end testReplaceSharesNotifiesNewAndUpgraded()

    /**
     * replaceShares rejects invalid shareType.
     *
     * @return void
     */
    public function testReplaceSharesRejectsInvalidShareType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $dashboard = $this->makeDashboard(5, 'alice');
        $this->dashboardMapper->method('find')->willReturn($dashboard);

        $this->service->replaceShares(
            dashboardId: 5,
            shares: [
                ['shareType' => 'invalid', 'shareWith' => 'bob', 'permissionLevel' => 'full'],
            ],
            userId: 'alice'
        );
    }//end testReplaceSharesRejectsInvalidShareType()

    // =========================================================================
    // revokeAllForRecipient tests
    // =========================================================================

    /**
     * revokeAllForRecipient removes only the caller's owned shares.
     *
     * @return void
     */
    public function testRevokeAllForRecipientRemovesOnlyCallerOwnedShares(): void
    {
        $this->shareMapper->expects($this->once())
            ->method('deleteByOwnerAndRecipient')
            ->with('user', 'bob', 'alice')
            ->willReturn(2);

        $count = $this->service->revokeAllForRecipient(
            shareType: 'user',
            shareWith: 'bob',
            callerId: 'alice'
        );

        $this->assertSame(2, $count);
    }//end testRevokeAllForRecipientRemovesOnlyCallerOwnedShares()

    /**
     * revokeAllForRecipient throws on invalid shareType.
     *
     * @return void
     */
    public function testRevokeAllForRecipientRejectsInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->revokeAllForRecipient(
            shareType: 'invalid',
            shareWith: 'bob',
            callerId: 'alice'
        );
    }//end testRevokeAllForRecipientRejectsInvalidType()

    /**
     * revokeAllForRecipient works with group type.
     *
     * @return void
     */
    public function testRevokeAllForRecipientWorksForGroupType(): void
    {
        $this->shareMapper->expects($this->once())
            ->method('deleteByOwnerAndRecipient')
            ->with('group', 'marketing', 'alice')
            ->willReturn(3);

        $count = $this->service->revokeAllForRecipient(
            shareType: 'group',
            shareWith: 'marketing',
            callerId: 'alice'
        );

        $this->assertSame(3, $count);
    }//end testRevokeAllForRecipientWorksForGroupType()
}//end class
