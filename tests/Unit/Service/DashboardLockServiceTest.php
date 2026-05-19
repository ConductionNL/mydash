<?php

/**
 * DashboardLockService Test
 *
 * Covers the acquire / heartbeat / release / query / force-release
 * lifecycle defined by REQ-LOCK-001..008. Mocks the mapper, user
 * manager and group manager so the assertions stay focused on
 * behaviour rather than persistence.
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

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardLock;
use OCA\MyDash\Db\DashboardLockMapper;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Exception\LockConflictException;
use OCA\MyDash\Exception\LockForbiddenException;
use OCA\MyDash\Exception\LockNotFoundException;
use OCA\MyDash\Service\DashboardLockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DashboardLockService.
 */
class DashboardLockServiceTest extends TestCase
{
    /**
     * Lock mapper mock.
     *
     * @var DashboardLockMapper&MockObject
     */
    private $lockMapper;

    /**
     * Dashboard mapper mock — used by `acquireLock` to verify the
     * dashboard UUID exists before persisting a new lock row.
     *
     * @var DashboardMapper&MockObject
     */
    private $dashboardMapper;

    /**
     * User manager mock.
     *
     * @var IUserManager&MockObject
     */
    private $userManager;

    /**
     * Group manager mock.
     *
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * Service under test.
     *
     * @var DashboardLockService
     */
    private DashboardLockService $service;

    /**
     * Set up the mocks and the service under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->lockMapper      = $this->createMock(originalClassName: DashboardLockMapper::class);
        $this->dashboardMapper = $this->createMock(originalClassName: DashboardMapper::class);
        $this->userManager     = $this->createMock(originalClassName: IUserManager::class);
        $this->groupManager    = $this->createMock(originalClassName: IGroupManager::class);
        $logger                = $this->createMock(originalClassName: LoggerInterface::class);

        // Default: dashboards exist. Tests that need to assert the
        // missing-dashboard branch override this via willThrow.
        $this->dashboardMapper
            ->method('findByUuid')
            ->willReturn(new Dashboard());

        $this->service = new DashboardLockService(
            lockMapper: $this->lockMapper,
            dashboardMapper: $this->dashboardMapper,
            userManager: $this->userManager,
            groupManager: $this->groupManager,
            logger: $logger,
        );
    }

    /**
     * Make a DashboardLock fixture owned by the named user.
     *
     * @param string $userId The owner user ID.
     *
     * @return DashboardLock The lock fixture.
     */
    private function makeLock(string $userId='alice'): DashboardLock
    {
        $lock = new DashboardLock();
        $lock->setDashboardUuid('d1');
        $lock->setUserId($userId);
        $lock->setDisplayName(ucfirst($userId));
        $lock->setCreatedAt('2026-05-02 12:00:00');
        $lock->setUpdatedAt('2026-05-02 12:00:00');

        return $lock;
    }

    /**
     * Configure the user manager mock to return a user that yields the
     * given display name.
     *
     * @param string $userId      The user ID.
     * @param string $displayName The display name.
     *
     * @return void
     */
    private function stubUserDisplayName(string $userId, string $displayName): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getDisplayName')->willReturn($displayName);
        $this->userManager->method('get')->with($userId)->willReturn($user);
    }

    /**
     * REQ-LOCK-001: acquire on an unlocked dashboard creates a new
     * lock owned by the caller.
     */
    public function testAcquireFreshLockSucceeds(): void
    {
        $this->lockMapper->expects($this->once())
            ->method('deleteExpiredForDashboard')
            ->with(dashboardUuid: 'd1');

        $this->lockMapper->method('findByDashboardUuid')
            ->willThrowException(new DoesNotExistException(msg: 'no row'));

        $this->stubUserDisplayName(userId: 'alice', displayName: 'Alice Smith');

        $this->lockMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (DashboardLock $l): DashboardLock => $l);

        $lock = $this->service->acquireLock(
            dashboardUuid: 'd1',
            userId: 'alice'
        );

        $this->assertSame('alice', $lock->getUserId());
        $this->assertSame('Alice Smith', $lock->getDisplayName());
    }

    /**
     * REQ-LOCK-001: re-entrant acquire by the same user refreshes the
     * existing lock instead of throwing a conflict.
     */
    public function testReentrantAcquireRefreshesExistingLock(): void
    {
        $existing = $this->makeLock(userId: 'alice');

        $this->lockMapper->method('findByDashboardUuid')->willReturn($existing);

        $this->lockMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $this->lockMapper->expects($this->never())->method('insert');

        $lock = $this->service->acquireLock(
            dashboardUuid: 'd1',
            userId: 'alice'
        );

        $this->assertSame('alice', $lock->getUserId());
    }

    /**
     * REQ-LOCK-001: acquire by a different user MUST surface the
     * conflict via LockConflictException carrying the existing lock.
     */
    public function testAcquireConflictWhenHeldByOtherUser(): void
    {
        $existing = $this->makeLock(userId: 'alice');
        $this->lockMapper->method('findByDashboardUuid')->willReturn($existing);

        try {
            $this->service->acquireLock(
                dashboardUuid: 'd1',
                userId: 'bob'
            );
            $this->fail(message: 'Expected LockConflictException');
        } catch (LockConflictException $e) {
            $this->assertSame('alice', $e->getExistingLock()->getUserId());
        }
    }

    /**
     * REQ-LOCK-002: heartbeat by the owner bumps `updatedAt`.
     */
    public function testHeartbeatByOwnerSucceeds(): void
    {
        $existing = $this->makeLock(userId: 'alice');
        $this->lockMapper->method('findActive')->willReturn($existing);

        $this->lockMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $lock = $this->service->heartbeat(
            dashboardUuid: 'd1',
            userId: 'alice'
        );

        $this->assertSame('alice', $lock->getUserId());
    }

    /**
     * REQ-LOCK-002: heartbeat by a non-owner MUST be rejected with 403.
     */
    public function testHeartbeatByNonOwnerThrowsForbidden(): void
    {
        $existing = $this->makeLock(userId: 'alice');
        $this->lockMapper->method('findActive')->willReturn($existing);

        $this->expectException(LockForbiddenException::class);
        $this->service->heartbeat(
            dashboardUuid: 'd1',
            userId: 'bob'
        );
    }

    /**
     * REQ-LOCK-002: heartbeat against a dashboard with no active lock
     * MUST raise LockNotFoundException.
     */
    public function testHeartbeatOnMissingLockThrowsNotFound(): void
    {
        $this->lockMapper->method('findActive')->willReturn(null);

        $this->expectException(LockNotFoundException::class);
        $this->service->heartbeat(
            dashboardUuid: 'd1',
            userId: 'alice'
        );
    }

    /**
     * REQ-LOCK-003: owner can release the lock.
     */
    public function testReleaseByOwnerDeletes(): void
    {
        $existing = $this->makeLock(userId: 'alice');
        $this->lockMapper->method('findActive')->willReturn($existing);

        $this->lockMapper->expects($this->once())
            ->method('delete')
            ->with($existing);

        $this->service->releaseLock(
            dashboardUuid: 'd1',
            userId: 'alice'
        );
    }

    /**
     * REQ-LOCK-003: non-owner non-admin cannot release another user's
     * lock.
     */
    public function testReleaseByNonOwnerNonAdminThrowsForbidden(): void
    {
        $existing = $this->makeLock(userId: 'alice');
        $this->lockMapper->method('findActive')->willReturn($existing);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->expectException(LockForbiddenException::class);
        $this->service->releaseLock(
            dashboardUuid: 'd1',
            userId: 'bob'
        );
    }

    /**
     * REQ-LOCK-003: admin can release any user's lock through the
     * normal DELETE verb (allowAdminOverride defaults to true).
     */
    public function testReleaseByAdminSucceedsViaOverride(): void
    {
        $existing = $this->makeLock(userId: 'alice');
        $this->lockMapper->method('findActive')->willReturn($existing);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->lockMapper->expects($this->once())
            ->method('delete')
            ->with($existing);

        $this->service->releaseLock(
            dashboardUuid: 'd1',
            userId: 'charlie'
        );
    }

    /**
     * REQ-LOCK-003: releasing a non-existent lock is a no-op
     * (idempotent).
     */
    public function testReleaseOnMissingLockIsIdempotent(): void
    {
        $this->lockMapper->method('findActive')->willReturn(null);
        $this->lockMapper->expects($this->never())->method('delete');

        $this->service->releaseLock(
            dashboardUuid: 'd1',
            userId: 'alice'
        );
    }

    /**
     * REQ-LOCK-004: getLockState MUST trigger inline cleanup before
     * returning the active lock.
     */
    public function testGetLockStateRunsInlineCleanup(): void
    {
        $this->lockMapper->expects($this->once())
            ->method('deleteExpiredForDashboard')
            ->with(dashboardUuid: 'd1');

        $existing = $this->makeLock(userId: 'alice');
        $this->lockMapper->method('findActive')->willReturn($existing);

        $lock = $this->service->getLockState(dashboardUuid: 'd1');
        $this->assertNotNull($lock);
        $this->assertSame('alice', $lock->getUserId());
    }

    /**
     * REQ-LOCK-006: non-admin force-release MUST be rejected.
     */
    public function testForceReleaseRejectsNonAdmin(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->expectException(LockForbiddenException::class);
        $this->service->forceRelease(
            dashboardUuid: 'd1',
            adminUserId: 'bob'
        );
    }

    /**
     * REQ-LOCK-006: admin force-release deletes the existing lock and
     * leaves the dashboard unlocked.
     */
    public function testForceReleaseByAdminDeletesLock(): void
    {
        $existing = $this->makeLock(userId: 'alice');
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->lockMapper->method('findActive')->willReturn($existing);

        $this->lockMapper->expects($this->once())
            ->method('delete')
            ->with($existing);

        $this->service->forceRelease(
            dashboardUuid: 'd1',
            adminUserId: 'charlie'
        );
    }

    /**
     * REQ-LOCK-006: admin force-release on a dashboard with no lock
     * is idempotent (still succeeds).
     */
    public function testForceReleaseOnMissingLockIsIdempotent(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->lockMapper->method('findActive')->willReturn(null);
        $this->lockMapper->expects($this->never())->method('delete');

        $this->service->forceRelease(
            dashboardUuid: 'd1',
            adminUserId: 'charlie'
        );
    }

    /**
     * REQ-LOCK-008: cascadeDelete removes the lock row regardless of
     * who owns it (no permission check at this layer — caller has
     * already authorised the parent dashboard removal).
     */
    public function testCascadeDeleteRemovesRowUnconditionally(): void
    {
        $this->lockMapper->expects($this->once())
            ->method('deleteByDashboardUuid')
            ->with(dashboardUuid: 'd1')
            ->willReturn(1);

        $count = $this->service->cascadeDelete(dashboardUuid: 'd1');
        $this->assertSame(1, $count);
    }
}
