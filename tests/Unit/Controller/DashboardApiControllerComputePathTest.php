<?php

/**
 * DashboardApiController ComputePath Test.
 *
 * Covers the `GET /api/dashboards/{uuid}/path` endpoint that powers the
 * frontend's outbound URL sync: every sidebar switch fetches the active
 * dashboard's canonical slug-chain so the address bar mirrors the state.
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
use OCA\MyDash\Service\AnalyticsService;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\DashboardTreeService;
use OCA\MyDash\Service\DashboardVersionService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the canonical-path lookup endpoint.
 */
class DashboardApiControllerComputePathTest extends TestCase
{

    /**
     * @var IRequest&MockObject
     */
    private $request;

    /**
     * @var DashboardTreeService&MockObject
     */
    private $treeService;

    protected function setUp(): void
    {
        $this->request     = $this->createMock(originalClassName: IRequest::class);
        $this->treeService = $this->createMock(originalClassName: DashboardTreeService::class);
    }//end setUp()

    /**
     * Build the controller with the given user ID (or null for anonymous).
     */
    private function makeController(?string $userId): DashboardApiController
    {
        return new DashboardApiController(
            request: $this->request,
            dashboardService: $this->createMock(originalClassName: DashboardService::class),
            permissionService: $this->createMock(originalClassName: PermissionService::class),
            treeService: $this->treeService,
            versionService: $this->createMock(originalClassName: DashboardVersionService::class),
            analyticsService: $this->createMock(originalClassName: AnalyticsService::class),
            logger: $this->createMock(originalClassName: LoggerInterface::class),
            userId: $userId,
        );
    }//end makeController()

    /**
     * Anonymous calls MUST return 401 — the endpoint is `NoAdminRequired`
     * but still requires a session.
     *
     * @return void
     */
    public function testReturnsUnauthorizedForAnonymousCaller(): void
    {
        $controller = $this->makeController(userId: null);

        $this->treeService->expects(matcher: $this->never())
            ->method(constraint: 'computePath');

        $response = $controller->computePath(uuid: 'abc');

        $this->assertSame(
            expected: Http::STATUS_UNAUTHORIZED,
            actual: $response->getStatus()
        );
    }//end testReturnsUnauthorizedForAnonymousCaller()

    /**
     * Empty uuid path argument MUST return 400 with the
     * `missing_uuid` error code.
     *
     * @return void
     */
    public function testReturnsBadRequestWhenUuidIsEmpty(): void
    {
        $controller = $this->makeController(userId: 'alice');

        $this->treeService->expects(matcher: $this->never())
            ->method(constraint: 'computePath');

        $response = $controller->computePath(uuid: '');
        $data     = $response->getData();

        $this->assertSame(
            expected: Http::STATUS_BAD_REQUEST,
            actual: $response->getStatus()
        );
        $this->assertSame(expected: 'missing_uuid', actual: $data['error']);
    }//end testReturnsBadRequestWhenUuidIsEmpty()

    /**
     * Happy path — tree service returns a path, controller surfaces it
     * inside the standard `{path: ...}` envelope.
     *
     * @return void
     */
    public function testReturnsPathFromTreeService(): void
    {
        $controller = $this->makeController(userId: 'alice');

        $this->treeService->expects(matcher: $this->once())
            ->method(constraint: 'computePath')
            ->with('abc-123')
            ->willReturn(value: '/finance/q1');

        $response = $controller->computePath(uuid: 'abc-123');
        $data     = $response->getData();

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: '/finance/q1', actual: $data['path']);
    }//end testReturnsPathFromTreeService()

    /**
     * Empty path from the tree service is a valid response — dashboards
     * with NULL slugs are unaddressable but legal. The endpoint MUST
     * return 200 with an empty path so the frontend can distinguish
     * "no URL update needed" from "lookup failed".
     *
     * @return void
     */
    public function testReturnsEmptyPathAsValidResponse(): void
    {
        $controller = $this->makeController(userId: 'alice');

        $this->treeService->expects(matcher: $this->once())
            ->method(constraint: 'computePath')
            ->with('no-slug-uuid')
            ->willReturn(value: '');

        $response = $controller->computePath(uuid: 'no-slug-uuid');
        $data     = $response->getData();

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: '', actual: $data['path']);
    }//end testReturnsEmptyPathAsValidResponse()
}//end class
