<?php

/**
 * DashboardApiController Fork Test
 *
 * Unit tests for the `POST /api/dashboards/{uuid}/fork` endpoint covering
 * REQ-DASH-020..022:
 *   - Happy path: HTTP 201 + new dashboard payload.
 *   - Flag off: HTTP 403 with personal_dashboards_disabled error code.
 *   - Source not visible: HTTP 404.
 *   - Anonymous caller: HTTP 401.
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
use OCA\MyDash\Exception\PersonalDashboardsDisabledException;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the fork controller action (REQ-DASH-020..022).
 */
class DashboardApiControllerForkTest extends TestCase
{

    /** @var IRequest&MockObject */
    private $request;

    /** @var DashboardService&MockObject */
    private $dashboardService;

    /** @var PermissionService&MockObject */
    private $permissionService;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request           = $this->createMock(IRequest::class);
        $this->dashboardService  = $this->createMock(DashboardService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build a controller with the given user ID.
     *
     * @param string|null $userId The logged-in user ID, or null for anon.
     *
     * @return DashboardApiController
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
     * Build a minimal stub Dashboard for use in mock return values.
     *
     * @param string $uuid The dashboard UUID.
     * @param string $name The dashboard name.
     * @param string $type The dashboard type.
     *
     * @return Dashboard
     */
    private function makeDashboard(
        string $uuid='new-uuid',
        string $name='My copy of Source',
        string $type=Dashboard::TYPE_USER
    ): Dashboard {
        $dash = new Dashboard();
        $dash->setId(99);
        $dash->setUuid($uuid);
        $dash->setName($name);
        $dash->setType($type);
        $dash->setUserId('alice');
        $dash->setGridColumns(12);
        $dash->setIsActive(1);
        $dash->setIsDefault(0);
        $dash->setPermissionLevel(Dashboard::PERMISSION_FULL);

        return $dash;
    }//end makeDashboard()

    /**
     * REQ-DASH-020: Happy path — service succeeds, controller returns
     * HTTP 201 with the new dashboard payload.
     *
     * @return void
     */
    public function testForkHappyPath(): void
    {
        $newDash = $this->makeDashboard(
            uuid: 'fork-uuid',
            name: 'My copy of Source'
        );

        $this->dashboardService->expects($this->once())
            ->method('forkAsPersonal')
            ->with(
                userId: 'alice',
                sourceUuid: 'src-uuid',
                name: null
            )
            ->willReturn($newDash);

        $controller = $this->makeController('alice');
        $response   = $controller->fork(uuid: 'src-uuid');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('success', $body['status']);
        $this->assertArrayHasKey('dashboard', $body);
        $this->assertSame('fork-uuid', $body['dashboard']['uuid']);
        $this->assertSame('My copy of Source', $body['dashboard']['name']);
    }//end testForkHappyPath()

    /**
     * REQ-DASH-020: Custom name in request body is forwarded to the service.
     *
     * @return void
     */
    public function testForkForwardsCustomName(): void
    {
        $newDash = $this->makeDashboard(name: 'My Marketing');

        $this->dashboardService->expects($this->once())
            ->method('forkAsPersonal')
            ->with(
                userId: 'alice',
                sourceUuid: 'src-uuid',
                name: 'My Marketing'
            )
            ->willReturn($newDash);

        $controller = $this->makeController('alice');
        $response   = $controller->fork(
            uuid: 'src-uuid',
            name: 'My Marketing'
        );

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }//end testForkForwardsCustomName()

    /**
     * REQ-DASH-020: When allow_user_dashboards is off, the service throws
     * PersonalDashboardsDisabledException; the controller MUST return HTTP 403
     * with the stable error envelope.
     *
     * @return void
     */
    public function testForkReturnsForbiddenWhenFlagOff(): void
    {
        $this->dashboardService->method('forkAsPersonal')
            ->willThrowException(new PersonalDashboardsDisabledException());

        $controller = $this->makeController('alice');
        $response   = $controller->fork(uuid: 'src-uuid');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('error', $body['status']);
        $this->assertSame('personal_dashboards_disabled', $body['error']);
        $this->assertArrayHasKey('message', $body);
    }//end testForkReturnsForbiddenWhenFlagOff()

    /**
     * REQ-DASH-020: Source not visible to caller — service throws
     * DoesNotExistException; the controller MUST return HTTP 404 without
     * leaking existence.
     *
     * @return void
     */
    public function testForkReturnsNotFoundWhenSourceNotVisible(): void
    {
        $this->dashboardService->method('forkAsPersonal')
            ->willThrowException(
                new DoesNotExistException(
                    msg: 'Dashboard not found or not visible'
                )
            );

        $controller = $this->makeController('alice');
        $response   = $controller->fork(uuid: 'invisible-uuid');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('error', $body['status']);
        $this->assertSame('not_found', $body['error']);
        // The exception message MUST NOT be leaked.
        $this->assertArrayNotHasKey('message', $body);
    }//end testForkReturnsNotFoundWhenSourceNotVisible()

    /**
     * Anonymous session MUST get HTTP 401 without calling the service.
     *
     * @return void
     */
    public function testForkReturnsUnauthorizedForAnonymousCaller(): void
    {
        $this->dashboardService->expects($this->never())
            ->method('forkAsPersonal');

        $controller = $this->makeController(null);
        $response   = $controller->fork(uuid: 'src-uuid');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testForkReturnsUnauthorizedForAnonymousCaller()

    /**
     * Unexpected DB error MUST return HTTP 500 with a sanitised message
     * (the raw exception message is NOT exposed to the client).
     *
     * @return void
     */
    public function testForkReturnsInternalErrorOnUnexpectedThrowable(): void
    {
        $this->dashboardService->method('forkAsPersonal')
            ->willThrowException(
                new \RuntimeException('raw db error details')
            );

        // The logger MUST be called with the raw error for debugging.
        $this->logger->expects($this->once())
            ->method('error');

        $controller = $this->makeController('alice');
        $response   = $controller->fork(uuid: 'src-uuid');

        $this->assertSame(
            Http::STATUS_INTERNAL_SERVER_ERROR,
            $response->getStatus()
        );
        $body = $response->getData();
        $this->assertSame('error', $body['status']);
        $this->assertSame('internal_error', $body['error']);
        // Raw exception message MUST NOT appear in the response.
        $this->assertStringNotContainsString(
            'raw db error details',
            (string) json_encode($body)
        );
    }//end testForkReturnsInternalErrorOnUnexpectedThrowable()
}//end class
