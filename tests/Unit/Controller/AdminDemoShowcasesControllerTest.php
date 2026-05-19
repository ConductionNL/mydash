<?php

/**
 * AdminDemoShowcasesControllerTest
 *
 * Controller-level tests for the demo-showcases admin endpoints.
 * Verifies the admin gate, 404 mapping, install/uninstall semantics,
 * and idempotent reinstall response status (REQ-DEMO-002..006).
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

use OCA\MyDash\Controller\AdminDemoShowcasesController;
use OCA\MyDash\Exception\ShowcaseNotFoundException;
use OCA\MyDash\Service\DemoShowcasesService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AdminDemoShowcasesControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;

    /** @var DemoShowcasesService&MockObject */
    private $service;

    /** @var IGroupManager&MockObject */
    private $groupManager;

    /** @var IUserSession&MockObject */
    private $userSession;

    private AdminDemoShowcasesController $controller;

    protected function setUp(): void
    {
        $this->request      = $this->createMock(originalClassName: IRequest::class);
        $this->service      = $this->createMock(originalClassName: DemoShowcasesService::class);
        $this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
        $this->userSession  = $this->createMock(originalClassName: IUserSession::class);

        $this->controller = new AdminDemoShowcasesController(
            request: $this->request,
            showcasesSvc: $this->service,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            logger: new NullLogger(),
        );
    }

    private function loginAsAdmin(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
    }

    private function loginAsNonAdmin(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);
    }

    public function testIndexUnauthorisedWhenNotLoggedIn(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $response = $this->controller->index();
        $this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
    }

    public function testIndexForbiddenForNonAdmin(): void
    {
        $this->loginAsNonAdmin();
        $response = $this->controller->index();
        $this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
    }

    public function testIndexReturnsShowcaseList(): void
    {
        $this->loginAsAdmin();
        $this->service->method('getAvailableShowcases')->willReturn([
            ['id' => 'de-bron', 'name' => 'De Bron', 'language' => 'nl', 'isInstalled' => false],
        ]);
        $response = $this->controller->index();
        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertCount(expectedCount: 1, haystack: $response->getData());
    }

    public function testInstallReturns201OnFreshInstall(): void
    {
        $this->loginAsAdmin();
        $this->service->method('installShowcase')->willReturn([
            'installedDashboardUuid' => 'fresh-uuid',
            'skippedWidgets'         => [],
            'alreadyInstalled'       => false,
        ]);
        $response = $this->controller->install(id: 'de-bron');
        $this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
        $data = $response->getData();
        $this->assertSame(expected: 'fresh-uuid', actual: $data['installedDashboardUuid']);
    }

    public function testInstallReturns200WhenAlreadyInstalled(): void
    {
        $this->loginAsAdmin();
        $this->service->method('installShowcase')->willReturn([
            'installedDashboardUuid' => 'existing-uuid',
            'skippedWidgets'         => [],
            'alreadyInstalled'       => true,
        ]);
        $response = $this->controller->install(id: 'de-bron');
        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $data = $response->getData();
        $this->assertTrue(condition: $data['alreadyInstalled']);
    }

    public function testInstallReturns404ForUnknownShowcase(): void
    {
        $this->loginAsAdmin();
        $this->service->method('installShowcase')->willThrowException(
            exception: new ShowcaseNotFoundException(message: 'gone')
        );
        $response = $this->controller->install(id: 'unknown-id');
        $this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
    }

    public function testInstallForbiddenForNonAdmin(): void
    {
        $this->loginAsNonAdmin();
        $response = $this->controller->install(id: 'de-bron');
        $this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
    }

    public function testInstallSurfacesSkippedWidgets(): void
    {
        $this->loginAsAdmin();
        $this->service->method('installShowcase')->willReturn([
            'installedDashboardUuid' => 'uuid',
            'skippedWidgets'         => ['unknown-1', 'unknown-2'],
            'alreadyInstalled'       => false,
        ]);
        $response = $this->controller->install(id: 'de-bron');
        $this->assertSame(expected: ['unknown-1', 'unknown-2'], actual: $response->getData()['skippedWidgets']);
    }

    public function testDestroyReturns204(): void
    {
        $this->loginAsAdmin();
        $this->service->expects($this->once())->method('uninstallShowcase')->with(showcaseId: 'de-bron');
        $response = $this->controller->destroy(id: 'de-bron');
        $this->assertSame(expected: Http::STATUS_NO_CONTENT, actual: $response->getStatus());
    }

    public function testDestroyIdempotent(): void
    {
        $this->loginAsAdmin();
        $this->service->expects($this->exactly(2))->method('uninstallShowcase');
        $first = $this->controller->destroy(id: 'de-bron');
        $second = $this->controller->destroy(id: 'de-bron');
        $this->assertSame(expected: Http::STATUS_NO_CONTENT, actual: $first->getStatus());
        $this->assertSame(expected: Http::STATUS_NO_CONTENT, actual: $second->getStatus());
    }

    public function testDestroyForbiddenForNonAdmin(): void
    {
        $this->loginAsNonAdmin();
        $response = $this->controller->destroy(id: 'de-bron');
        $this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
    }
}
