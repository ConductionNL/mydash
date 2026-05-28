<?php

/**
 * DashboardCommentsApiController Test
 *
 * Covers M4: ADR-023 action-auth wiring on all four comment endpoints.
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

use OCA\MyDash\Controller\DashboardCommentsApiController;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Service\ActionAuthService;
use OCA\MyDash\Service\CommentService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DashboardCommentsApiController (M4 ADR-023 wiring).
 */
class DashboardCommentsApiControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;
    /** @var CommentService&MockObject */
    private $commentService;
    /** @var PermissionService&MockObject */
    private $permissionService;
    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;
    /** @var ActionAuthService&MockObject */
    private $actionAuth;
    /** @var IUserSession&MockObject */
    private $userSession;
    /** @var IUser&MockObject */
    private $user;

    protected function setUp(): void
    {
        $this->request           = $this->createMock(IRequest::class);
        $this->commentService    = $this->createMock(CommentService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->dashboardMapper   = $this->createMock(DashboardMapper::class);
        $this->actionAuth        = $this->createMock(ActionAuthService::class);
        $this->userSession       = $this->createMock(IUserSession::class);

        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($this->user);

        // Default: permission checks pass.
        $this->permissionService->method('canViewDashboard')->willReturn(true);
        $this->permissionService->method('canEditDashboard')->willReturn(true);
    }//end setUp()

    private function makeController(?string $userId='user1'): DashboardCommentsApiController
    {
        return new DashboardCommentsApiController(
            request: $this->request,
            commentService: $this->commentService,
            permissionService: $this->permissionService,
            dashboardMapper: $this->dashboardMapper,
            actionAuth: $this->actionAuth,
            userSession: $this->userSession,
            userId: $userId,
        );
    }//end makeController()

    private function makeDashboard(int $id=1): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->setId($id);
        return $dashboard;
    }//end makeDashboard()

    /**
     * M4: index() must call requireAction with 'dashboard-comments.index'.
     */
    public function testIndexCallsRequireAction(): void
    {
        $this->dashboardMapper->method('findByUuid')->willReturn($this->makeDashboard());
        $this->commentService->method('isEnabledFor')->willReturn(false);

        $this->actionAuth
            ->expects($this->once())
            ->method('requireAction')
            ->with($this->user, 'dashboard-comments.index');

        $controller = $this->makeController();
        $response   = $controller->index('some-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testIndexCallsRequireAction()

    /**
     * M4: index() must propagate OCSForbiddenException when action is denied.
     */
    public function testIndexPropagatesForbiddenWhenActionDenied(): void
    {
        $this->actionAuth
            ->method('requireAction')
            ->willThrowException(new OCSForbiddenException('Forbidden'));

        $this->expectException(OCSForbiddenException::class);

        $controller = $this->makeController();
        $controller->index('some-uuid');
    }//end testIndexPropagatesForbiddenWhenActionDenied()

    /**
     * M4: create() must call requireAction with 'dashboard-comments.create'.
     */
    public function testCreateCallsRequireAction(): void
    {
        $this->dashboardMapper->method('findByUuid')->willReturn($this->makeDashboard());
        $this->commentService->method('isEnabledFor')->willReturn(false);

        $this->actionAuth
            ->expects($this->once())
            ->method('requireAction')
            ->with($this->user, 'dashboard-comments.create');

        $controller = $this->makeController();
        // Comments disabled → short-circuits before calling createComment.
        $response   = $controller->create('some-uuid', 'Hello');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testCreateCallsRequireAction()

    /**
     * M4: create() must propagate OCSForbiddenException when action is denied.
     */
    public function testCreatePropagatesForbiddenWhenActionDenied(): void
    {
        $this->actionAuth
            ->method('requireAction')
            ->willThrowException(new OCSForbiddenException('Forbidden'));

        $this->expectException(OCSForbiddenException::class);

        $controller = $this->makeController();
        $controller->create('some-uuid', 'Hello');
    }//end testCreatePropagatesForbiddenWhenActionDenied()

    /**
     * M4: update() must call requireAction with 'dashboard-comments.update'.
     */
    public function testUpdateCallsRequireAction(): void
    {
        $this->dashboardMapper->method('findByUuid')->willReturn($this->makeDashboard());
        $this->commentService->method('isEnabledFor')->willReturn(false);

        $this->actionAuth
            ->expects($this->once())
            ->method('requireAction')
            ->with($this->user, 'dashboard-comments.update');

        $controller = $this->makeController();
        $response   = $controller->update('some-uuid', 7, 'Updated text');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testUpdateCallsRequireAction()

    /**
     * M4: update() must propagate OCSForbiddenException when action is denied.
     */
    public function testUpdatePropagatesForbiddenWhenActionDenied(): void
    {
        $this->actionAuth
            ->method('requireAction')
            ->willThrowException(new OCSForbiddenException('Forbidden'));

        $this->expectException(OCSForbiddenException::class);

        $controller = $this->makeController();
        $controller->update('some-uuid', 7, 'Updated text');
    }//end testUpdatePropagatesForbiddenWhenActionDenied()

    /**
     * M4: destroy() must call requireAction with 'dashboard-comments.destroy'.
     */
    public function testDestroyCallsRequireAction(): void
    {
        $this->dashboardMapper->method('findByUuid')->willReturn($this->makeDashboard());
        $this->commentService->method('isEnabledFor')->willReturn(true);
        // deleteComment returns void — no stub return value needed.

        $this->actionAuth
            ->expects($this->once())
            ->method('requireAction')
            ->with($this->user, 'dashboard-comments.destroy');

        $controller = $this->makeController();
        $response   = $controller->destroy('some-uuid', 7);

        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }//end testDestroyCallsRequireAction()

    /**
     * M4: destroy() must propagate OCSForbiddenException when action is denied.
     */
    public function testDestroyPropagatesForbiddenWhenActionDenied(): void
    {
        $this->actionAuth
            ->method('requireAction')
            ->willThrowException(new OCSForbiddenException('Forbidden'));

        $this->expectException(OCSForbiddenException::class);

        $controller = $this->makeController();
        $controller->destroy('some-uuid', 7);
    }//end testDestroyPropagatesForbiddenWhenActionDenied()

    /**
     * index() must return 401 when no authenticated user exists.
     */
    public function testIndexReturns401WhenUnauthenticated(): void
    {
        // Build a fresh session mock that returns null (no logged-in user).
        $anonSession = $this->createMock(IUserSession::class);
        $anonSession->method('getUser')->willReturn(null);

        $controller = new DashboardCommentsApiController(
            request: $this->request,
            commentService: $this->commentService,
            permissionService: $this->permissionService,
            dashboardMapper: $this->dashboardMapper,
            actionAuth: $this->actionAuth,
            userSession: $anonSession,
            userId: null,
        );
        $response = $controller->index('some-uuid');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testIndexReturns401WhenUnauthenticated()
}//end class
