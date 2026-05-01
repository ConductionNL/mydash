<?php

/**
 * AdminController Group-Order Test
 *
 * Covers the `listGroups` and `updateGroupOrder` actions added by the
 * `group-priority-order` change (REQ-ASET-013, REQ-ASET-014).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\MyDash\Controller\AdminController;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\AdminTemplateService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminControllerGroupOrderTest extends TestCase
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

    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->templateService = $this->createMock(AdminTemplateService::class);
        $this->settingsService = $this->createMock(AdminSettingsService::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userSession     = $this->createMock(IUserSession::class);

        $this->controller = new AdminController(
            request: $this->request,
            templateService: $this->templateService,
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
        );
    }

    /**
     * Build a Nextcloud `IGroup` mock with a given GID and display name.
     */
    private function group(string $gid, string $displayName): \OCP\IGroup
    {
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('getDisplayName')->willReturn($displayName);
        return $group;
    }

    /**
     * Convenience: stub the user session as an admin "alice".
     */
    private function loginAsAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
    }

    /**
     * Convenience: stub the user session as a non-admin "bob".
     */
    private function loginAsNonAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);
    }

    public function testListGroupsRejectsNonAdminWith403(): void
    {
        $this->loginAsNonAdmin();

        // No service calls expected — the guard must short-circuit.
        $this->settingsService->expects($this->never())->method('getGroupOrder');

        $response = $this->controller->listGroups();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testUpdateGroupOrderRejectsNonAdminWith403(): void
    {
        $this->loginAsNonAdmin();

        $this->settingsService->expects($this->never())->method('setGroupOrder');

        $response = $this->controller->updateGroupOrder(['engineering']);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testListGroupsReturnsDisjointAndExhaustiveLists(): void
    {
        $this->loginAsAdmin();

        $this->groupManager->method('search')->with('')->willReturn([
            $this->group('a', 'Alpha'),
            $this->group('b', 'Beta'),
            $this->group('c', 'Gamma'),
            $this->group('d', 'Delta'),
        ]);
        $this->settingsService->method('getGroupOrder')->willReturn(['b', 'd']);

        $response = $this->controller->listGroups();
        $body     = $response->getData();

        $this->assertSame(['b', 'd'], $body['active']);
        // `inactive` must be sorted by displayName: "Alpha"=a, "Gamma"=c.
        $this->assertSame(['a', 'c'], $body['inactive']);

        // disjoint
        $this->assertSame(
            [],
            array_intersect($body['active'], $body['inactive'])
        );

        // exhaustive: active ∪ inactive == set of allKnown ids
        $allIds = array_column($body['allKnown'], 'id');
        sort($allIds);
        $union = array_merge($body['active'], $body['inactive']);
        sort($union);
        $this->assertSame($allIds, $union);
    }

    public function testListGroupsPreservesActiveOrderAndSurfacesStaleIds(): void
    {
        $this->loginAsAdmin();

        // Nextcloud no longer has "deleted-group".
        $this->groupManager->method('search')->with('')->willReturn([
            $this->group('engineering', 'Engineering'),
            $this->group('marketing', 'Marketing'),
        ]);
        $this->settingsService->method('getGroupOrder')->willReturn(
            ['deleted-group', 'engineering']
        );

        $body = $this->controller->listGroups()->getData();

        // Stale ID retained in `active` (order preserved exactly).
        $this->assertSame(['deleted-group', 'engineering'], $body['active']);

        // Stale ID NOT in `allKnown` (no display name).
        $this->assertSame(
            ['engineering', 'marketing'],
            array_column($body['allKnown'], 'id')
        );

        // Stale ID NOT in `inactive`.
        $this->assertNotContains('deleted-group', $body['inactive']);
        $this->assertSame(['marketing'], $body['inactive']);
    }

    public function testUpdateGroupOrderRejectsMissingGroupsKeyWith400(): void
    {
        $this->loginAsAdmin();

        $this->settingsService->expects($this->never())->method('setGroupOrder');

        $response = $this->controller->updateGroupOrder(null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testUpdateGroupOrderRejectsNonStringElementWith400(): void
    {
        $this->loginAsAdmin();

        // Service throws InvalidArgumentException; controller must translate to 400.
        $this->settingsService
            ->method('setGroupOrder')
            ->willThrowException(new \InvalidArgumentException('bad'));

        /** @phpstan-ignore-next-line — intentionally invalid for the test */
        $response = $this->controller->updateGroupOrder(['engineering', 42, 'marketing']);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testUpdateGroupOrderReplacesWholesale(): void
    {
        $this->loginAsAdmin();

        // Captured arg from the service call.
        $captured = null;

        $this->settingsService
            ->expects($this->once())
            ->method('setGroupOrder')
            ->with($this->callback(static function ($v) use (&$captured): bool {
                $captured = $v;
                return true;
            }));

        // After write, controller re-reads the persisted order.
        $this->settingsService
            ->method('getGroupOrder')
            ->willReturn(['c', 'b']);

        $response = $this->controller->updateGroupOrder(['c', 'b']);
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['c', 'b'], $captured);
        $this->assertSame(['c', 'b'], $body['groupOrder']);
        $this->assertSame('ok', $body['status']);
    }

    public function testUpdateGroupOrderReturns401WhenNoUserLoggedIn(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->settingsService->expects($this->never())->method('setGroupOrder');

        $response = $this->controller->updateGroupOrder(['engineering']);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }
}
