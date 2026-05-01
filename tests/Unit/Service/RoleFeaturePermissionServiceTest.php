<?php

/**
 * RoleFeaturePermissionService Test
 *
 * Pins the multi-group resolution algorithm (REQ-RFP-005), the deny-wins
 * rule, the no-restriction backwards-compat fallback (REQ-RFP-009), and
 * the seedLayoutFromRoleDefaults zero-existing-placements guard
 * (REQ-RFP-002 scenario 3).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\RoleFeaturePermission;
use OCA\MyDash\Db\RoleFeaturePermissionMapper;
use OCA\MyDash\Db\RoleLayoutDefault;
use OCA\MyDash\Db\RoleLayoutDefaultMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\RoleFeaturePermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class RoleFeaturePermissionServiceTest extends TestCase
{
    private RoleFeaturePermissionService $service;

    private RoleFeaturePermissionMapper $permMapper;

    private RoleLayoutDefaultMapper $defaultMapper;

    private WidgetPlacementMapper $placementMapper;

    private AdminSettingsService $adminSettings;

    private IGroupManager $groupManager;

    private IUserManager $userManager;

    protected function setUp(): void
    {
        $this->permMapper      = $this->createMock(originalClassName: RoleFeaturePermissionMapper::class);
        $this->defaultMapper   = $this->createMock(originalClassName: RoleLayoutDefaultMapper::class);
        $this->placementMapper = $this->createMock(originalClassName: WidgetPlacementMapper::class);
        $this->adminSettings   = $this->createMock(originalClassName: AdminSettingsService::class);
        $this->groupManager    = $this->createMock(originalClassName: IGroupManager::class);
        $this->userManager     = $this->createMock(originalClassName: IUserManager::class);

        $this->service = new RoleFeaturePermissionService(
            permissionMapper: $this->permMapper,
            defaultMapper: $this->defaultMapper,
            placementMapper: $this->placementMapper,
            adminSettings: $this->adminSettings,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
        );
    }//end setUp()

    /**
     * Build a RoleFeaturePermission entity from arrays.
     */
    private function makePerm(
        string $groupId,
        array $allowed,
        array $denied = []
    ): RoleFeaturePermission {
        $entity = new RoleFeaturePermission();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $entity->setName('perm-' . $groupId);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $entity->setGroupId($groupId);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $entity->setAllowedWidgets(json_encode(value: $allowed));
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $entity->setDeniedWidgets(json_encode(value: $denied));
        return $entity;
    }//end makePerm()

    /**
     * Mock IUserManager + IGroupManager so the user is in a list of groups.
     */
    private function withUserGroups(string $userId, array $groupIds): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $this->userManager->method(constraint: 'get')
            ->willReturn(value: $user);
        $this->groupManager->method(constraint: 'getUserGroupIds')
            ->willReturn(value: $groupIds);
    }//end withUserGroups()

    public function testNoRestrictionConfiguredReturnsNull(): void
    {
        $this->withUserGroups(userId: 'alice', groupIds: ['employees']);
        $this->adminSettings->method(constraint: 'getGroupOrder')
            ->willReturn(value: ['employees']);
        $this->permMapper->method(constraint: 'findByGroupIds')
            ->willReturn(value: []);
        $this->permMapper->method(constraint: 'findByGroupId')
            ->will($this->throwException(exception: new DoesNotExistException(msg: 'no row')));

        $result = $this->service->getAllowedWidgetIds(userId: 'alice');
        $this->assertNull(actual: $result);
    }//end testNoRestrictionConfiguredReturnsNull()

    public function testSingleGroupReturnsAllowedSet(): void
    {
        $this->withUserGroups(userId: 'alice', groupIds: ['employees']);
        $this->adminSettings->method(constraint: 'getGroupOrder')
            ->willReturn(value: ['employees', 'managers']);
        $this->permMapper->method(constraint: 'findByGroupIds')
            ->willReturn(value: [
                $this->makePerm(groupId: 'employees', allowed: ['activity', 'recommendations']),
            ]);

        $result = $this->service->getAllowedWidgetIds(userId: 'alice');
        $this->assertSame(expected: ['activity', 'recommendations'], actual: $result);
    }//end testSingleGroupReturnsAllowedSet()

    public function testMultiGroupUnionWidens(): void
    {
        $this->withUserGroups(userId: 'alice', groupIds: ['employees', 'managers']);
        $this->adminSettings->method(constraint: 'getGroupOrder')
            ->willReturn(value: ['employees', 'managers']);
        $this->permMapper->method(constraint: 'findByGroupIds')
            ->willReturn(value: [
                $this->makePerm(groupId: 'employees', allowed: ['activity']),
                $this->makePerm(groupId: 'managers', allowed: ['analytics']),
            ]);

        $result = $this->service->getAllowedWidgetIds(userId: 'alice');
        $this->assertSame(expected: ['activity', 'analytics'], actual: $result);
    }//end testMultiGroupUnionWidens()

    public function testDenyWinsOverAllow(): void
    {
        $this->withUserGroups(userId: 'alice', groupIds: ['employees', 'security']);
        $this->adminSettings->method(constraint: 'getGroupOrder')
            ->willReturn(value: ['employees', 'security']);
        $this->permMapper->method(constraint: 'findByGroupIds')
            ->willReturn(value: [
                $this->makePerm(groupId: 'employees', allowed: ['activity', 'analytics']),
                $this->makePerm(groupId: 'security', allowed: [], denied: ['analytics']),
            ]);

        $result = $this->service->getAllowedWidgetIds(userId: 'alice');
        $this->assertSame(expected: ['activity'], actual: $result);
    }//end testDenyWinsOverAllow()

    public function testFallbackToDefaultGroupWhenNoGroupOrderMatch(): void
    {
        $this->withUserGroups(userId: 'alice', groupIds: ['unmapped']);
        $this->adminSettings->method(constraint: 'getGroupOrder')
            ->willReturn(value: []);
        $this->permMapper->method(constraint: 'findByGroupIds')
            ->willReturn(value: []);
        $this->permMapper->method(constraint: 'findByGroupId')
            ->with($this->equalTo(value: RoleFeaturePermission::GROUP_DEFAULT))
            ->willReturn(value: $this->makePerm(groupId: 'default', allowed: ['recommendations']));

        $result = $this->service->getAllowedWidgetIds(userId: 'alice');
        $this->assertSame(expected: ['recommendations'], actual: $result);
    }//end testFallbackToDefaultGroupWhenNoGroupOrderMatch()

    public function testIsWidgetAllowedTrueWhenUnconfigured(): void
    {
        $this->withUserGroups(userId: 'alice', groupIds: []);
        $this->permMapper->method(constraint: 'findByGroupId')
            ->will($this->throwException(exception: new DoesNotExistException(msg: 'no row')));

        $this->assertTrue(condition: $this->service->isWidgetAllowed(
            userId: 'alice',
            widgetId: 'whatever'
        ));
    }//end testIsWidgetAllowedTrueWhenUnconfigured()

    public function testSeedLayoutNoOpWhenDashboardHasPlacements(): void
    {
        $dashboard = new Dashboard();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setId(42);
        $this->placementMapper->method(constraint: 'findByDashboardId')
            ->willReturn(value: ['existing-placement']);

        // No mapper / group manager calls expected because the guard fires first.
        $this->defaultMapper->expects(invocationOrder: $this->never())
            ->method(constraint: 'findByGroupId');

        $created = $this->service->seedLayoutFromRoleDefaults(
            userId: 'alice',
            dashboard: $dashboard
        );
        $this->assertSame(expected: 0, actual: $created);
    }//end testSeedLayoutNoOpWhenDashboardHasPlacements()

    public function testSeedLayoutCreatesPlacementsWhenEmpty(): void
    {
        $dashboard = new Dashboard();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setId(99);

        $this->placementMapper->method(constraint: 'findByDashboardId')
            ->willReturn(value: []);
        $this->withUserGroups(userId: 'alice', groupIds: ['managers']);
        $this->adminSettings->method(constraint: 'getGroupOrder')
            ->willReturn(value: ['managers']);

        $rld = new RoleLayoutDefault();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $rld->setName('manager-activity');
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $rld->setGroupId('managers');
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $rld->setWidgetId('activity');
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $rld->setGridX(0);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $rld->setGridY(0);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $rld->setGridWidth(6);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $rld->setGridHeight(5);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $rld->setSortOrder(0);

        $this->defaultMapper->method(constraint: 'findByGroupId')
            ->willReturn(value: [$rld]);

        $this->placementMapper->expects(invocationOrder: $this->once())
            ->method(constraint: 'insert');

        $created = $this->service->seedLayoutFromRoleDefaults(
            userId: 'alice',
            dashboard: $dashboard
        );
        $this->assertSame(expected: 1, actual: $created);
    }//end testSeedLayoutCreatesPlacementsWhenEmpty()
}//end class
