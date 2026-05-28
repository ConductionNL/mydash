<?php

/**
 * DashboardMetadataControllerTest
 *
 * Covers REQ-MDFL-004 / REQ-MDFL-005 / REQ-MDFL-006 / REQ-MDFL-008 —
 * the per-dashboard metadata read/write endpoints, ownership gating,
 * and validation-error response shape.
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

use OCA\MyDash\Controller\DashboardMetadataController;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Exception\InvalidMetadataFieldException;
use OCA\MyDash\Service\MetadataService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DashboardMetadataControllerTest extends TestCase
{
    /** @var MetadataService&MockObject */
    private MetadataService $metadataService;
    /** @var DashboardMapper&MockObject */
    private DashboardMapper $dashboardMapper;
    /** @var PermissionService&MockObject */
    private PermissionService $permissionService;
    /** @var IRequest&MockObject */
    private IRequest $request;

    protected function setUp(): void
    {
        $this->metadataService   = $this->createMock(MetadataService::class);
        $this->dashboardMapper   = $this->createMock(DashboardMapper::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->request           = $this->createMock(IRequest::class);

        // Default: permission checks allow access (overridden per test where needed).
    }

    private function controller(?string $userId): DashboardMetadataController
    {
        return new DashboardMetadataController(
            request: $this->request,
            metadataService: $this->metadataService,
            dashboardMapper: $this->dashboardMapper,
            permissionService: $this->permissionService,
            userId: $userId,
        );
    }

    private function makeDashboard(
        string $uuid,
        ?string $userId=null,
        ?string $groupId=null,
        string $type=Dashboard::TYPE_USER
    ): Dashboard {
        $dashboard = new Dashboard();
        $dashboard->setId(1);
        $dashboard->setUuid($uuid);
        if ($userId !== null) {
            $dashboard->setUserId($userId);
        }
        if ($groupId !== null) {
            $dashboard->setGroupId($groupId);
        }
        $dashboard->setType($type);
        return $dashboard;
    }

    public function testGetMetadataUnauthorisedWithoutUser(): void
    {
        $controller = $this->controller(null);
        $response   = $controller->getMetadata('abc');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testGetMetadata404WhenDashboardMissing(): void
    {
        $this->dashboardMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException(''));

        $controller = $this->controller('alice');
        $response   = $controller->getMetadata('abc');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testGetMetadataForbiddenForNonOwner(): void
    {
        $dashboard = $this->makeDashboard('abc', userId: 'bob');
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->permissionService->method('canViewDashboard')->willReturn(false);

        $controller = $this->controller('alice');
        $response   = $controller->getMetadata('abc');
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testGetMetadataReturnsMapForOwner(): void
    {
        $dashboard = $this->makeDashboard('abc', userId: 'alice');
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->permissionService->method('canViewDashboard')->willReturn(true);
        $this->metadataService->method('getMetadataForDashboard')
            ->with('abc')
            ->willReturn(['department' => 'marketing']);

        $controller = $this->controller('alice');
        $response   = $controller->getMetadata('abc');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['department' => 'marketing'], $response->getData());
    }

    public function testGetMetadataAllowsGroupMember(): void
    {
        $dashboard = $this->makeDashboard('abc', groupId: 'team', type: Dashboard::TYPE_GROUP_SHARED);
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->permissionService->method('canViewDashboard')->willReturn(true);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $controller = $this->controller('alice');
        $response   = $controller->getMetadata('abc');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testSetMetadataValidationErrorReturns400(): void
    {
        $dashboard = $this->makeDashboard('abc', userId: 'alice');
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->permissionService->method('canEditDashboardMetadata')->willReturn(true);
        $this->metadataService->method('setMetadataForDashboard')
            ->willThrowException(new InvalidMetadataFieldException("Field 'Priority' must be a valid number"));

        $controller = $this->controller('alice');
        $response   = $controller->setMetadata('abc', ['priority' => 'banana']);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(InvalidMetadataFieldException::ERROR_CODE, $response->getData()['error']);
    }

    public function testSetMetadataSuccessReturnsUpdated(): void
    {
        $dashboard = $this->makeDashboard('abc', userId: 'alice');
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->permissionService->method('canEditDashboardMetadata')->willReturn(true);
        $this->metadataService
            ->expects($this->once())
            ->method('setMetadataForDashboard')
            ->with('abc', ['department' => 'marketing'])
            ->willReturn(['department' => 'marketing']);

        $controller = $this->controller('alice');
        $response   = $controller->setMetadata('abc', ['department' => 'marketing']);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['department' => 'marketing'], $response->getData());
    }
}
