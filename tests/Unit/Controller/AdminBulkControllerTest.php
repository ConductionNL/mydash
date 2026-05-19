<?php

/**
 * AdminBulkControllerTest
 *
 * Controller-level tests for `POST /api/admin/dashboards/bulk-*`
 * (REQ-BULK-001..011). Verifies admin-only guard, request shape
 * validation, and propagation of `PermissionDeniedException` and
 * `InvalidArgumentException` from the bulk service.
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

use InvalidArgumentException;
use OCA\MyDash\Controller\AdminBulkController;
use OCA\MyDash\Service\BulkOperationService;
use OCA\MyDash\Service\PermissionDeniedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminBulkControllerTest extends TestCase
{

    /**
     * @var IRequest&MockObject
     */
    private $request;

    /**
     * @var BulkOperationService&MockObject
     */
    private $bulkService;

    /**
     * @var IUserSession&MockObject
     */
    private $userSession;

    /**
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    private AdminBulkController $controller;

    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->bulkService  = $this->createMock(BulkOperationService::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->controller = new AdminBulkController(
            request: $this->request,
            bulkService: $this->bulkService,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
        );
    }//end setUp()

    private function loginAsAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
    }//end loginAsAdmin()

    private function loginAsNonAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);
    }//end loginAsNonAdmin()

    public function testBulkDeleteNonAdminForbidden(): void
    {
        $this->loginAsNonAdmin();

        $response = $this->controller->bulkDelete(dashboardUuids: ['uuid-1']);

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testBulkDeleteNonAdminForbidden()

    public function testBulkDeleteRejectsNonArrayBody(): void
    {
        $this->loginAsAdmin();

        $response = $this->controller->bulkDelete(dashboardUuids: 'not-an-array');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testBulkDeleteRejectsNonArrayBody()

    public function testBulkDeleteValidRequestReturns200(): void
    {
        $this->loginAsAdmin();

        $this->bulkService->method('bulkDelete')->willReturn(
                [
                    'deletedCount' => 2,
                    'skippedCount' => 0,
                    'errors'       => [],
                    'dryRun'       => false,
                ]
                );

        $response = $this->controller->bulkDelete(
            dashboardUuids: ['uuid-1', 'uuid-2']
        );

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        $payload = $response->getData();
        self::assertSame(2, $payload['deletedCount']);
    }//end testBulkDeleteValidRequestReturns200()

    public function testBulkDeletePermissionDeniedMappedTo403(): void
    {
        $this->loginAsAdmin();

        $this->bulkService->method('bulkDelete')->willThrowException(
            new PermissionDeniedException(
                message: 'denied',
                deniedUuids: ['uuid-3']
            )
        );

        $response = $this->controller->bulkDelete(dashboardUuids: ['uuid-3']);

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        self::assertSame(['uuid-3'], $response->getData()['deniedUuids']);
    }//end testBulkDeletePermissionDeniedMappedTo403()

    public function testBulkDeleteSizeCapMappedTo400(): void
    {
        $this->loginAsAdmin();

        $this->bulkService->method('bulkDelete')->willThrowException(
            new InvalidArgumentException(message: 'maximum is 500')
        );

        $response = $this->controller->bulkDelete(dashboardUuids: ['x']);

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testBulkDeleteSizeCapMappedTo400()

    public function testBulkMoveValidRequest(): void
    {
        $this->loginAsAdmin();

        $this->bulkService->method('bulkMove')->willReturn(
                [
                    'movedCount'   => 1,
                    'skippedCount' => 0,
                    'errors'       => [],
                    'dryRun'       => false,
                ]
                );

        $response = $this->controller->bulkMove(
            dashboardUuids: ['child'],
            parentUuid: 'parent'
        );

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testBulkMoveValidRequest()

    public function testBulkStatusRequiresPublicationStatus(): void
    {
        $this->loginAsAdmin();

        $response = $this->controller->bulkStatus(
            dashboardUuids: ['d1'],
            publicationStatus: null
        );

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testBulkStatusRequiresPublicationStatus()

    public function testBulkStatusValidRequest(): void
    {
        $this->loginAsAdmin();

        $this->bulkService->method('bulkStatus')->willReturn(
                [
                    'updatedCount' => 1,
                    'skippedCount' => 0,
                    'errors'       => [],
                    'dryRun'       => false,
                ]
                );

        $response = $this->controller->bulkStatus(
            dashboardUuids: ['d1'],
            publicationStatus: 'published'
        );

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testBulkStatusValidRequest()

    public function testBulkReindexValidRequest(): void
    {
        $this->loginAsAdmin();

        $this->bulkService->method('bulkReindex')->willReturn(
                [
                    'reindexedCount' => 1,
                    'errors'         => [],
                    'dryRun'         => false,
                ]
                );

        $response = $this->controller->bulkReindex(dashboardUuids: ['d1']);

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testBulkReindexValidRequest()

    public function testBulkReindexNonAdminForbidden(): void
    {
        $this->loginAsNonAdmin();

        $response = $this->controller->bulkReindex(dashboardUuids: ['d1']);

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testBulkReindexNonAdminForbidden()
}//end class
