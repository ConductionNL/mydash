<?php

/**
 * AdminControllerFooterSettingsTest
 *
 * Controller-level tests for the global footer settings endpoints
 * (`GET /api/admin/footer-settings`, `PUT /api/admin/footer-settings`).
 * Covers REQ-FTR-001, REQ-FTR-002, REQ-FTR-009, REQ-FTR-010 — admin
 * guard, sanitiser path-through, and 413 mapping for oversize HTML.
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
use OCA\MyDash\Controller\AdminController;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\ExportService;
use OCA\MyDash\Service\FooterService;
use OCA\MyDash\Service\ImportService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminControllerFooterSettingsTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;

    /** @var FooterService&MockObject */
    private $footerService;

    /** @var IGroupManager&MockObject */
    private $groupManager;

    /** @var IUserSession&MockObject */
    private $userSession;

    private AdminController $controller;

    protected function setUp(): void
    {
        $this->request       = $this->createMock(IRequest::class);
        $this->footerService = $this->createMock(FooterService::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->userSession   = $this->createMock(IUserSession::class);

        $this->controller = new AdminController(
            request: $this->request,
            templateService: $this->createMock(AdminTemplateService::class),
            settingsService: $this->createMock(AdminSettingsService::class),
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            exportService: $this->createMock(ExportService::class),
            importService: $this->createMock(ImportService::class),
            roleService: $this->createMock(\OCA\MyDash\Service\RoleService::class),
            feedRefresh: $this->createMock(\OCA\MyDash\Service\FeedRefreshService::class),
            footerService: $this->footerService,
            setupWizardService: $this->createMock(\OCA\MyDash\Service\SetupWizardService::class),
        );
    }

    private function loginAsAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
    }

    private function loginAsNonAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
    }

    public function testGetFooterSettingsReturnsServicePayload(): void
    {
        $this->loginAsAdmin();

        $payload = [
            'footerEnabled'         => true,
            'footerHtml'            => '<p>Hi</p>',
            'footerConfig'          => [],
            'footerBackgroundColor' => null,
            'footerTextColor'       => null,
        ];
        $this->footerService->method('getGlobalSettings')->willReturn($payload);

        $response = $this->controller->getFooterSettings();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());
    }

    public function testUpdateFooterSettingsForwardsPatch(): void
    {
        $this->loginAsAdmin();
        // Controller pulls keys from request->getParams to preserve null
        // markers (admin clearing a value). Stub the params accordingly.
        $this->request->method('getParams')->willReturn([
            'footerEnabled' => true,
            'footerHtml'    => '<p>Hi</p>',
        ]);

        $this->footerService
            ->expects($this->once())
            ->method('updateGlobalSettings')
            ->with([
                'footerEnabled' => true,
                'footerHtml'    => '<p>Hi</p>',
            ]);

        $response = $this->controller->updateFooterSettings(
            footerEnabled: true,
            footerHtml: '<p>Hi</p>'
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testUpdateFooterSettingsMapsOversizeTo413(): void
    {
        $this->loginAsAdmin();
        $this->request->method('getParams')->willReturn([
            'footerHtml' => str_repeat('a', 9000),
        ]);

        $this->footerService
            ->method('updateGlobalSettings')
            ->willThrowException(new InvalidArgumentException('footerHtml exceeds 8 KB limit'));

        $response = $this->controller->updateFooterSettings(
            footerHtml: str_repeat('a', 9000)
        );

        $this->assertSame(
            Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
            $response->getStatus()
        );
    }

    public function testUpdateFooterSettingsMapsValidationTo400(): void
    {
        $this->loginAsAdmin();
        $this->request->method('getParams')->willReturn([
            'footerConfig' => ['weird' => 'x'],
        ]);

        $this->footerService
            ->method('updateGlobalSettings')
            ->willThrowException(new InvalidArgumentException('unknown key'));

        $response = $this->controller->updateFooterSettings(
            footerConfig: ['weird' => 'x']
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }
}
