<?php

/**
 * DashboardApiController Active-Preference Test
 *
 * Covers the new `setActiveDashboard` action mapping to
 * `POST /api/dashboards/active` per REQ-DASH-019.
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
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the setActiveDashboard controller action (REQ-DASH-019).
 */
class DashboardApiControllerActiveTest extends TestCase
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
     * Build the controller with the given user ID (or null for anonymous).
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

    // -----------------------------------------------------------------------
    // REQ-DASH-019: POST /api/dashboards/active — valid uuid
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-019 scenario "Save preference": valid UUID → 200 + service called.
     *
     * @return void
     */
    public function testSetActiveDashboardWritesPref(): void
    {
        $this->dashboardService->expects($this->once())
            ->method('setActivePreference')
            ->with('alice', 'abc-123');

        $controller = $this->makeController('alice');
        $response   = $controller->setActiveDashboard('abc-123');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('success', $data['status']);
    }//end testSetActiveDashboardWritesPref()

    // -----------------------------------------------------------------------
    // REQ-DASH-019: empty uuid clears the preference
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-019 scenario "Empty uuid clears the preference": empty string
     * passed to service and 200 returned.
     *
     * @return void
     */
    public function testSetActiveDashboardEmptyUuidClearsPref(): void
    {
        $this->dashboardService->expects($this->once())
            ->method('setActivePreference')
            ->with('alice', '');

        $controller = $this->makeController('alice');
        $response   = $controller->setActiveDashboard('');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testSetActiveDashboardEmptyUuidClearsPref()

    // -----------------------------------------------------------------------
    // REQ-DASH-019: null uuid (omitted body field)
    // -----------------------------------------------------------------------

    /**
     * Null uuid (body field omitted) should be normalised to empty string and
     * call the service with ''.
     *
     * @return void
     */
    public function testSetActiveDashboardNullUuidTreatedAsEmpty(): void
    {
        $this->dashboardService->expects($this->once())
            ->method('setActivePreference')
            ->with('alice', '');

        $controller = $this->makeController('alice');
        $response   = $controller->setActiveDashboard(null);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testSetActiveDashboardNullUuidTreatedAsEmpty()

    // -----------------------------------------------------------------------
    // Unauthenticated session → 401
    // -----------------------------------------------------------------------

    /**
     * Anonymous session: setActiveDashboard MUST return 401 and MUST NOT call
     * the service.
     *
     * @return void
     */
    public function testSetActiveDashboardUnauthenticatedReturns401(): void
    {
        $this->dashboardService->expects($this->never())
            ->method('setActivePreference');

        $controller = $this->makeController(null);
        $response   = $controller->setActiveDashboard('abc-123');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testSetActiveDashboardUnauthenticatedReturns401()

    // -----------------------------------------------------------------------
    // No existence check on write (REQ-DASH-019)
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-019 scenario "No existence check on write": non-existent UUID is
     * forwarded to the service without validation — 200 returned.
     *
     * @return void
     */
    public function testSetActiveDashboardNonExistentUuidAccepted(): void
    {
        $this->dashboardService->expects($this->once())
            ->method('setActivePreference')
            ->with('alice', 'does-not-exist');

        $controller = $this->makeController('alice');
        $response   = $controller->setActiveDashboard('does-not-exist');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testSetActiveDashboardNonExistentUuidAccepted()
}//end class
