<?php

/**
 * DashboardApiController Default-Flag Test
 *
 * Covers the new `setGroupDefault` action and the contract that the
 * existing `createGroup`/`updateGroup` actions never expose the
 * `isDefault` field as a writable parameter (REQ-DASH-015..017).
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

use OCA\MyDash\Controller\DashboardApiController;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for the default-dashboard-flag controller surface.
 */
class DashboardApiControllerDefaultFlagTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;
    /** @var DashboardService&MockObject */
    private $dashboardService;
    /** @var PermissionService&MockObject */
    private $permissionService;
    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->request           = $this->createMock(IRequest::class);
        $this->dashboardService  = $this->createMock(DashboardService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the controller with the supplied logged-in user ID (or
     * `null` for an anonymous session).
     */
    private function makeController(?string $userId): DashboardApiController
    {
        return new DashboardApiController(
            request: $this->request,
            dashboardService: $this->dashboardService,
            permissionService: $this->permissionService,
            logger: $this->logger,
            userId: $userId,
        );
    }//end makeController()

    /**
     * REQ-DASH-015: non-admin caller MUST get 403 with no service call.
     *
     * @return void
     */
    public function testSetGroupDefaultRejectsNonAdmin(): void
    {
        $this->dashboardService->method('isAdmin')
            ->with('alice')
            ->willReturn(false);
        $this->dashboardService->expects($this->never())
            ->method('setGroupDefault');

        $controller = $this->makeController('alice');
        $response   = $controller->setGroupDefault(
            groupId: 'marketing',
            uuid: 'uuid-a'
        );

        $this->assertSame(
            Http::STATUS_FORBIDDEN,
            $response->getStatus()
        );
    }//end testSetGroupDefaultRejectsNonAdmin()

    /**
     * Anonymous caller MUST get 401 — the route attribute alone is not
     * enough; the in-body guard runs first.
     *
     * @return void
     */
    public function testSetGroupDefaultRejectsAnonymousWith401(): void
    {
        $this->dashboardService->expects($this->never())
            ->method('setGroupDefault');

        $controller = $this->makeController(null);
        $response   = $controller->setGroupDefault(
            groupId: 'marketing',
            uuid: 'uuid-a'
        );

        $this->assertSame(
            Http::STATUS_UNAUTHORIZED,
            $response->getStatus()
        );
    }//end testSetGroupDefaultRejectsAnonymousWith401()

    /**
     * REQ-DASH-015: missing uuid in body → HTTP 400.
     *
     * @return void
     */
    public function testSetGroupDefaultRejectsMissingUuid(): void
    {
        $this->dashboardService->method('isAdmin')->willReturn(true);
        $this->dashboardService->expects($this->never())
            ->method('setGroupDefault');

        $controller = $this->makeController('admin');
        $response   = $controller->setGroupDefault(
            groupId: 'marketing',
            uuid: null
        );

        $this->assertSame(
            Http::STATUS_BAD_REQUEST,
            $response->getStatus()
        );
    }//end testSetGroupDefaultRejectsMissingUuid()

    /**
     * REQ-DASH-015 happy path — service is invoked, response is 200.
     *
     * @return void
     */
    public function testSetGroupDefaultHappyPath(): void
    {
        $this->dashboardService->method('isAdmin')->willReturn(true);
        $this->dashboardService->expects($this->once())
            ->method('setGroupDefault')
            ->with(
                actorUserId: 'admin',
                groupId: 'marketing',
                uuid: 'uuid-c'
            );

        $controller = $this->makeController('admin');
        $response   = $controller->setGroupDefault(
            groupId: 'marketing',
            uuid: 'uuid-c'
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('ok', $body['status']);
        $this->assertSame('marketing', $body['groupId']);
        $this->assertSame('uuid-c', $body['uuid']);
    }//end testSetGroupDefaultHappyPath()

    /**
     * REQ-DASH-015 scenario: cross-group uuid → service throws
     * DoesNotExistException → controller returns HTTP 404.
     *
     * @return void
     */
    public function testSetGroupDefaultMapsDoesNotExistTo404(): void
    {
        $this->dashboardService->method('isAdmin')->willReturn(true);
        $this->dashboardService->method('setGroupDefault')
            ->willThrowException(
                new DoesNotExistException(msg: 'not in group')
            );

        $controller = $this->makeController('admin');
        $response   = $controller->setGroupDefault(
            groupId: 'sales',
            uuid: 'uuid-from-marketing'
        );

        $this->assertSame(
            Http::STATUS_NOT_FOUND,
            $response->getStatus()
        );
    }//end testSetGroupDefaultMapsDoesNotExistTo404()

    /**
     * REQ-DASH-016: the controller `createGroup` signature MUST NOT
     * expose `isDefault` as a parameter — Nextcloud's parameter binder
     * would otherwise pull it from the JSON body and the service would
     * see it. Reflection-level check for defense-in-depth.
     *
     * @return void
     */
    public function testCreateGroupSignatureDoesNotExposeIsDefault(): void
    {
        $reflection = new ReflectionMethod(
            DashboardApiController::class,
            'createGroup'
        );
        $paramNames = array_map(
            static fn ($p) => $p->getName(),
            $reflection->getParameters()
        );

        $this->assertNotContains('isDefault', $paramNames);
    }//end testCreateGroupSignatureDoesNotExposeIsDefault()

    /**
     * REQ-DASH-017: the controller `updateGroup` signature MUST NOT
     * expose `isDefault` as a parameter — same reasoning.
     *
     * @return void
     */
    public function testUpdateGroupSignatureDoesNotExposeIsDefault(): void
    {
        $reflection = new ReflectionMethod(
            DashboardApiController::class,
            'updateGroup'
        );
        $paramNames = array_map(
            static fn ($p) => $p->getName(),
            $reflection->getParameters()
        );

        $this->assertNotContains('isDefault', $paramNames);
    }//end testUpdateGroupSignatureDoesNotExposeIsDefault()
}//end class
