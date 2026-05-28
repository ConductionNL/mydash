<?php

/**
 * DashboardLockApiController Test
 *
 * Covers H1 (identity leak: GET /lock must respect canViewDashboard)
 * and M2 (conflict 409 must not expose userId).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\MyDash\Controller\DashboardLockApiController;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardLock;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Exception\LockConflictException;
use OCA\MyDash\Service\DashboardLockService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DashboardLockApiControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;
    /** @var DashboardLockService&MockObject */
    private $lockService;
    /** @var PermissionService&MockObject */
    private $permissionService;
    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    protected function setUp(): void
    {
        $this->request           = $this->createMock(IRequest::class);
        $this->lockService       = $this->createMock(DashboardLockService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->dashboardMapper   = $this->createMock(DashboardMapper::class);
    }

    private function makeController(?string $userId='user1'): DashboardLockApiController
    {
        return new DashboardLockApiController(
            request: $this->request,
            lockService: $this->lockService,
            permissionService: $this->permissionService,
            dashboardMapper: $this->dashboardMapper,
            userId: $userId,
        );
    }

    /**
     * H1: GET /lock MUST return 404 when caller has no view access,
     * rather than leaking the lock holder's identity.
     */
    public function testGetReturns404WhenCallerCannotViewDashboard(): void
    {
        $dashboard = new Dashboard();
        $dashboard->setId(42);
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->permissionService
            ->method('canViewDashboard')
            ->willReturn(false);

        $controller = $this->makeController(userId: 'outsider');
        $response   = $controller->get('some-uuid');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    /**
     * H1: GET /lock MUST return the lock when caller has view access.
     */
    public function testGetReturnsLockWhenCallerCanView(): void
    {
        $dashboard = new Dashboard();
        $dashboard->setId(42);
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->permissionService
            ->method('canViewDashboard')
            ->willReturn(true);

        $lock = new DashboardLock();
        $lock->setDashboardUuid('some-uuid');
        $lock->setUserId('owner');
        $lock->setDisplayName('Owner Name');
        $lock->setCreatedAt('2026-05-28 10:00:00');
        $lock->setUpdatedAt('2026-05-28 10:00:00');
        $this->lockService->method('getLockState')->willReturn($lock);

        $controller = $this->makeController(userId: 'viewer');
        $response   = $controller->get('some-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    /**
     * M2: acquire 409 response MUST NOT include userId in the lock payload.
     */
    public function testAcquireConflictResponseDoesNotLeakUserId(): void
    {
        $dashboard = new Dashboard();
        $dashboard->setId(42);
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);

        $existingLock = new DashboardLock();
        $existingLock->setDashboardUuid('some-uuid');
        $existingLock->setUserId('other-user');
        $existingLock->setDisplayName('Other User');
        $existingLock->setCreatedAt('2026-05-28 10:00:00');
        $existingLock->setUpdatedAt('2026-05-28 10:00:00');

        $this->lockService
            ->method('acquireLock')
            ->willThrowException(new LockConflictException('Lock held by another user', $existingLock));

        $controller = $this->makeController(userId: 'user1');
        $response   = $controller->acquire('some-uuid');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());

        $data = $response->getData();
        $lock = $data['lock'] ?? [];

        $this->assertArrayNotHasKey(
            'userId',
            $lock,
            'Conflict 409 response MUST NOT expose the lock holder userId'
        );
        $this->assertArrayHasKey('displayName', $lock);
    }
}
