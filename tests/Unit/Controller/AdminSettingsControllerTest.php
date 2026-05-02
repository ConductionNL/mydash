<?php

/**
 * AdminSettingsController Test
 *
 * Covers REQ-ASET-012, REQ-ASET-013, REQ-ASET-014.
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

use OCA\MyDash\Controller\AdminSettingsController;
use OCA\MyDash\Service\AdminSettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IGroup;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminSettingsControllerTest extends TestCase
{
    private AdminSettingsController $controller;

    /** @var AdminSettingsService&MockObject */
    private $settingsService;
    /** @var IGroupManager&MockObject */
    private $groupManager;
    /** @var IUserSession&MockObject */
    private $userSession;
    /** @var IRequest&MockObject */
    private $request;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(AdminSettingsService::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->request         = $this->createMock(IRequest::class);

        $this->controller = new AdminSettingsController(
            request: $this->request,
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
        );
    }

    private function makeAdmin(string $userId='admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager
            ->method('isAdmin')
            ->with($userId)
            ->willReturn(true);
    }

    private function makeNonAdmin(string $userId='alice'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager
            ->method('isAdmin')
            ->with($userId)
            ->willReturn(false);
    }

    private function makeGroup(string $id, string $name): IGroup
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($id);
        $group->method('getDisplayName')->willReturn($name);
        return $group;
    }

    // ----- REQ-ASET-014: admin guard on both endpoints -----

    public function testListGroupsRejectsNonAdmin(): void
    {
        $this->makeNonAdmin();
        // Service MUST NOT be touched on a 403.
        $this->settingsService->expects($this->never())->method('getGroupOrder');
        $this->groupManager->expects($this->never())->method('search');

        $response = $this->controller->listGroups();
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testListGroupsRejectsUnauthenticatedSession(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->listGroups();
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testUpdateGroupOrderRejectsNonAdmin(): void
    {
        $this->makeNonAdmin();
        // Persistence MUST NOT happen.
        $this->settingsService->expects($this->never())->method('setGroupOrder');

        $response = $this->controller->updateGroupOrder(['anything']);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    // ----- REQ-ASET-013: list groups payload shape -----

    public function testListGroupsReturnsDisjointExhaustiveSplit(): void
    {
        $this->makeAdmin();
        $this->groupManager->method('search')->with('')->willReturn([
            $this->makeGroup('a', 'Alpha'),
            $this->makeGroup('b', 'Bravo'),
            $this->makeGroup('c', 'Charlie'),
            $this->makeGroup('d', 'Delta'),
        ]);
        $this->settingsService->method('getGroupOrder')->willReturn(['b', 'd']);

        $response = $this->controller->listGroups();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $data = $response->getData();
        $this->assertSame(['b', 'd'], $data['active']);
        // Inactive sorted by displayName (case-insensitive).
        $this->assertSame(['a', 'c'], $data['inactive']);
        $this->assertCount(4, $data['allKnown']);
        // active ∩ inactive == ∅
        $this->assertEmpty(array_intersect($data['active'], $data['inactive']));
    }

    public function testListGroupsActivePreservesAdminOrder(): void
    {
        $this->makeAdmin();
        $this->groupManager->method('search')->with('')->willReturn([
            $this->makeGroup('zebra', 'Zebra'),
            $this->makeGroup('alpha', 'Alpha'),
            $this->makeGroup('marigold', 'Marigold'),
        ]);
        $this->settingsService
            ->method('getGroupOrder')
            ->willReturn(['zebra', 'alpha', 'marigold']);

        $data = $this->controller->listGroups()->getData();
        $this->assertSame(['zebra', 'alpha', 'marigold'], $data['active']);
        $this->assertSame([], $data['inactive']);
    }

    public function testListGroupsSurfacesStaleIdsInActive(): void
    {
        // REQ-ASET-013 — stale IDs remain in `active`, not in `allKnown`,
        // not in `inactive`.
        $this->makeAdmin();
        $this->groupManager->method('search')->with('')->willReturn([
            $this->makeGroup('engineering', 'Engineering'),
        ]);
        $this->settingsService
            ->method('getGroupOrder')
            ->willReturn(['deleted-group', 'engineering']);

        $data = $this->controller->listGroups()->getData();
        $this->assertContains('deleted-group', $data['active']);
        $this->assertNotContains('deleted-group', $data['inactive']);
        $this->assertNotContains(
            'deleted-group',
            array_column($data['allKnown'], 'id')
        );
    }

    public function testListGroupsEmptyOrderAllInactive(): void
    {
        $this->makeAdmin();
        $this->groupManager->method('search')->with('')->willReturn([
            $this->makeGroup('a', 'Alpha'),
            $this->makeGroup('b', 'Bravo'),
        ]);
        $this->settingsService->method('getGroupOrder')->willReturn([]);

        $data = $this->controller->listGroups()->getData();
        $this->assertSame([], $data['active']);
        $this->assertSame(['a', 'b'], $data['inactive']);
    }

    // ----- REQ-ASET-012: replace-wholesale + persistence -----

    public function testUpdateGroupOrderReplacesWholesale(): void
    {
        $this->makeAdmin();
        $this->settingsService
            ->expects($this->once())
            ->method('setGroupOrder')
            ->with(['c', 'b']);

        $response = $this->controller->updateGroupOrder(['c', 'b']);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['status' => 'ok'], $response->getData());
    }

    public function testUpdateGroupOrderEmptyArrayPersisted(): void
    {
        $this->makeAdmin();
        $this->settingsService
            ->expects($this->once())
            ->method('setGroupOrder')
            ->with([]);

        $response = $this->controller->updateGroupOrder([]);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    // ----- REQ-ASET-014: payload validation -----

    public function testUpdateGroupOrderRejectsMissingGroupsKey(): void
    {
        $this->makeAdmin();
        $this->settingsService->expects($this->never())->method('setGroupOrder');

        // Default arg `null` simulates missing `groups` key in body.
        $response = $this->controller->updateGroupOrder();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testUpdateGroupOrderRejectsNonStringElement(): void
    {
        $this->makeAdmin();
        // Controller hands the array to the service; service throws
        // InvalidArgumentException — controller maps it to 400.
        $this->settingsService
            ->expects($this->once())
            ->method('setGroupOrder')
            ->willThrowException(new \InvalidArgumentException('bad'));

        $response = $this->controller->updateGroupOrder(['engineering', 42]);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testUpdateGroupOrderAcceptsUnknownIds(): void
    {
        // REQ-ASET-014 — unknown IDs MUST NOT cause validation failure.
        $this->makeAdmin();
        $this->settingsService
            ->expects($this->once())
            ->method('setGroupOrder')
            ->with(['does-not-exist', 'engineering']);

        $response = $this->controller->updateGroupOrder(
            ['does-not-exist', 'engineering']
        );
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }
}
