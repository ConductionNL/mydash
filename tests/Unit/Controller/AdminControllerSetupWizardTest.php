<?php

/**
 * AdminController Setup-Wizard Test
 *
 * Covers the `getWizardState`, `completeWizard`, and `setWizardStorage`
 * endpoints added by the `setup-wizard` change (REQ-WIZ-008, REQ-WIZ-009,
 * REQ-WIZ-003).
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
use OCA\MyDash\Service\ExportService;
use OCA\MyDash\Service\ImportService;
use OCA\MyDash\Service\SetupWizardService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminControllerSetupWizardTest extends TestCase
{
    private AdminController $controller;

    /** @var IRequest&MockObject */
    private $request;
    /** @var IGroupManager&MockObject */
    private $groupManager;
    /** @var IUserSession&MockObject */
    private $userSession;
    /** @var SetupWizardService&MockObject */
    private $wizardService;

    protected function setUp(): void
    {
        $this->request       = $this->createMock(IRequest::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->wizardService = $this->createMock(SetupWizardService::class);

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
            footerService: $this->createMock(\OCA\MyDash\Service\FooterService::class),
            setupWizardService: $this->wizardService,
            actionAuth: $this->createMock(\OCA\MyDash\Service\ActionAuthService::class),
        );
    }

    private function loginAsAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
    }

    private function loginAsNonAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);
    }

    public function testGetWizardStateReturnsServicePayload(): void
    {
        $this->loginAsAdmin();
        $payload = [
            'complete'               => false,
            'currentRecommendedStep' => 2,
            'stepStatuses'           => ['1' => 'done'],
        ];
        $this->wizardService->expects($this->once())
            ->method('getWizardState')
            ->willReturn($payload);

        $response = $this->controller->getWizardState();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());
    }

    public function testGetWizardStateRejectsNonAdminWith403(): void
    {
        $this->loginAsNonAdmin();
        $this->wizardService->expects($this->never())->method('getWizardState');

        $response = $this->controller->getWizardState();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testCompleteWizardCallsServiceAndReturnsState(): void
    {
        $this->loginAsAdmin();
        $payload = [
            'complete'               => true,
            'currentRecommendedStep' => 1,
            'stepStatuses'           => ['7' => 'done'],
        ];
        $this->wizardService->expects($this->once())
            ->method('markWizardComplete')
            ->willReturn($payload);

        $response = $this->controller->completeWizard();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());
    }

    public function testCompleteWizardRejectsNonAdminWith403(): void
    {
        $this->loginAsNonAdmin();
        $this->wizardService->expects($this->never())->method('markWizardComplete');

        $response = $this->controller->completeWizard();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testSetWizardStorageRejectsEmptyValue(): void
    {
        $this->loginAsAdmin();
        $this->wizardService->expects($this->never())->method('setContentStorage');

        $response = $this->controller->setWizardStorage(storage: '');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testSetWizardStorageRejectsGroupfolderWhenAppMissing(): void
    {
        $this->loginAsAdmin();
        $this->wizardService->method('hasGroupfolderApp')->willReturn(false);
        $this->wizardService->expects($this->never())->method('setContentStorage');

        $response = $this->controller->setWizardStorage(storage: 'groupfolder');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testSetWizardStorageWritesAndReturnsState(): void
    {
        $this->loginAsAdmin();
        $this->wizardService->method('hasGroupfolderApp')->willReturn(true);

        $this->wizardService->expects($this->once())
            ->method('setContentStorage')
            ->with('groupfolder');

        $payload = [
            'complete'               => false,
            'currentRecommendedStep' => 3,
            'stepStatuses'           => ['2' => 'done'],
        ];
        $this->wizardService->method('getWizardState')->willReturn($payload);

        $response = $this->controller->setWizardStorage(storage: 'groupfolder');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());
    }

    public function testSetWizardStorageReturns401WhenLoggedOut(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->wizardService->expects($this->never())->method('setContentStorage');

        $response = $this->controller->setWizardStorage(storage: 'database');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }
}
