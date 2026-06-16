<?php

/**
 * AdminController Refresh-Feeds Test
 *
 * Covers the `refreshFeedsNow` action added by the
 * `background-job-feed-refresh` change (REQ-FRJ-010).
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

use OCA\MyDash\Controller\AdminController;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\FeedRefreshService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AdminController::refreshFeedsNow.
 */
class AdminControllerRefreshFeedsTest extends TestCase
{

    private AdminController $controller;
    /** @var IRequest&MockObject */
    private $request;
    /** @var AdminTemplateService&MockObject */
    private $templateService;
    /** @var AdminSettingsService&MockObject */
    private $settingsService;
    /** @var IGroupManager&MockObject */
    private $groupManager;
    /** @var IUserSession&MockObject */
    private $userSession;
    /** @var FeedRefreshService&MockObject */
    private $feedRefresh;

    /**
     * Wire mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->templateService = $this->createMock(AdminTemplateService::class);
        $this->settingsService = $this->createMock(AdminSettingsService::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->feedRefresh     = $this->createMock(FeedRefreshService::class);

        $this->controller = new AdminController(
            request: $this->request,
            templateService: $this->templateService,
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            exportService: $this->createMock(\OCA\MyDash\Service\ExportService::class),
            importService: $this->createMock(\OCA\MyDash\Service\ImportService::class),
            roleService: $this->createMock(\OCA\MyDash\Service\RoleService::class),
            feedRefresh: $this->feedRefresh,
            footerService: $this->createMock(\OCA\MyDash\Service\FooterService::class),
            setupWizardService: $this->createMock(\OCA\MyDash\Service\SetupWizardService::class),
            actionAuth: $this->createMock(\OCA\MyDash\Service\ActionAuthService::class),
        );
    }//end setUp()

    /**
     * Admin triggers a full refresh — receives the aggregate summary
     * with HTTP 200.
     *
     * @return void
     */
    public function testAdminFullRefreshReturns200WithSummary(): void
    {
        $this->loginAsAdmin();

        $summary = [
            'processedCount' => 3,
            'successCount'   => 2,
            'failureCount'   => 1,
            'durationMs'     => 123,
        ];

        $this->feedRefresh->expects($this->once())
            ->method('refreshAll')
            ->with(null)
            ->willReturn($summary);

        $response = $this->controller->refreshFeedsNow();

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: $summary, actual: $response->getData());
    }//end testAdminFullRefreshReturns200WithSummary()

    /**
     * Admin triggers a single-URL refresh — service receives the URL.
     *
     * @return void
     */
    public function testAdminSingleUrlRefreshDelegates(): void
    {
        $this->loginAsAdmin();

        $this->feedRefresh->expects($this->once())
            ->method('refreshAll')
            ->with('https://example.com/rss')
            ->willReturn([
                'processedCount' => 1,
                'successCount'   => 1,
                'failureCount'   => 0,
                'durationMs'     => 7,
            ]);

        $response = $this->controller->refreshFeedsNow(
            feedUrl: 'https://example.com/rss'
        );

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 1, actual: $response->getData()['processedCount']);
    }//end testAdminSingleUrlRefreshDelegates()

    /**
     * Non-admin caller receives HTTP 403; no fetch is attempted.
     *
     * @return void
     */
    public function testNonAdminReceivesForbidden(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(false);

        $this->feedRefresh->expects($this->never())->method('refreshAll');

        $response = $this->controller->refreshFeedsNow();
        $this->assertSame(
            expected: Http::STATUS_FORBIDDEN,
            actual: $response->getStatus()
        );
    }//end testNonAdminReceivesForbidden()

    /**
     * Invalid (non-HTTP/HTTPS) scheme returns 400; no fetch is
     * attempted (REQ-FRJ-010 "Invalid feedUrl scheme rejected").
     *
     * @return void
     */
    public function testInvalidSchemeReturns400(): void
    {
        $this->loginAsAdmin();

        $this->feedRefresh->expects($this->never())->method('refreshAll');

        $response = $this->controller->refreshFeedsNow(
            feedUrl: 'ftp://bad.com/rss'
        );

        $this->assertSame(
            expected: Http::STATUS_BAD_REQUEST,
            actual: $response->getStatus()
        );
    }//end testInvalidSchemeReturns400()

    /**
     * Convenience: stub the user session as an admin "alice".
     *
     * @return void
     */
    private function loginAsAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
    }//end loginAsAdmin()
}//end class
