<?php

/**
 * AdminTemplateServiceTest
 *
 * Unit tests for the primary-group routing resolver added by the
 * `group-routing` change. Covers:
 *   - REQ-TMPL-012: `resolvePrimaryGroup` algorithm scenarios
 *     (priority order, empty list, no match, configured-but-not-member,
 *     stale-group tolerance).
 *   - The static `pickFirstMatch` helper directly (empty inputs, no
 *     overlap, multiple overlaps).
 *   - `getUserGroupIdsFor` defensive behaviour (unknown user → []).
 *   - `resolvePrimaryGroupDisplayName` (sentinel → 'Default', real
 *     group → display name, deleted group → group ID fallback).
 *
 * The grep-guard test (REQ-TMPL-013) lives in
 * {@see AdminTemplateServiceGrepGuardTest} so it can fail loud without
 * the resolver scenarios masking it.
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
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\AdminTemplateService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AdminTemplateService routing resolver (REQ-TMPL-012).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors constructor.
 */
class AdminTemplateServiceTest extends TestCase
{

    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var AdminSettingsService&MockObject */
    private $settingsService;

    /** @var IGroupManager&MockObject */
    private $groupManager;

    /** @var IUserManager&MockObject */
    private $userManager;

    private AdminTemplateService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardMapper = $this->createMock(DashboardMapper::class);
        $this->placementMapper = $this->createMock(WidgetPlacementMapper::class);
        $this->settingsService = $this->createMock(AdminSettingsService::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userManager     = $this->createMock(IUserManager::class);

        $this->service = new AdminTemplateService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
        );
    }//end setUp()

    // ---------------------------------------------------------------
    // pickFirstMatch — pure helper (REQ-TMPL-012, task 1.2 + 4.3)
    // ---------------------------------------------------------------

    /**
     * @return void
     */
    public function testPickFirstMatchReturnsNullForEmptyOrderedGroups(): void
    {
        $this->assertNull(
            AdminTemplateService::pickFirstMatch(
                orderedGroups: [],
                userGroups: ['engineering']
            )
        );
    }//end testPickFirstMatchReturnsNullForEmptyOrderedGroups()

    /**
     * @return void
     */
    public function testPickFirstMatchReturnsNullForEmptyUserGroups(): void
    {
        $this->assertNull(
            AdminTemplateService::pickFirstMatch(
                orderedGroups: ['engineering'],
                userGroups: []
            )
        );
    }//end testPickFirstMatchReturnsNullForEmptyUserGroups()

    /**
     * @return void
     */
    public function testPickFirstMatchReturnsNullWhenNoOverlap(): void
    {
        $this->assertNull(
            AdminTemplateService::pickFirstMatch(
                orderedGroups: ['engineering', 'executives'],
                userGroups: ['support', 'marketing']
            )
        );
    }//end testPickFirstMatchReturnsNullWhenNoOverlap()

    /**
     * @return void
     */
    public function testPickFirstMatchReturnsFirstMatchWhenMultipleOverlap(): void
    {
        // engineering wins because it appears first in orderedGroups,
        // even though all-staff is alphabetically first.
        $this->assertSame(
            'engineering',
            AdminTemplateService::pickFirstMatch(
                orderedGroups: ['engineering', 'all-staff'],
                userGroups: ['all-staff', 'engineering', 'marketing']
            )
        );
    }//end testPickFirstMatchReturnsFirstMatchWhenMultipleOverlap()

    /**
     * @return void
     */
    public function testPickFirstMatchSkipsConfiguredGroupsUserIsNotIn(): void
    {
        // executives is configured first but the user is not a member —
        // the algorithm walks past it and returns engineering.
        $this->assertSame(
            'engineering',
            AdminTemplateService::pickFirstMatch(
                orderedGroups: ['executives', 'engineering'],
                userGroups: ['engineering', 'support']
            )
        );
    }//end testPickFirstMatchSkipsConfiguredGroupsUserIsNotIn()

    // ---------------------------------------------------------------
    // resolvePrimaryGroup — full integration (REQ-TMPL-012, task 4.1)
    // ---------------------------------------------------------------

    /**
     * REQ-TMPL-012 scenario: First match wins by admin-configured priority.
     *
     * @return void
     */
    public function testResolvePrimaryGroupReturnsFirstMatchByPriority(): void
    {
        $this->settingsService
            ->method('getGroupOrder')
            ->willReturn(['engineering', 'all-staff']);

        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->with('alice')->willReturn($user);
        $this->groupManager
            ->method('getUserGroupIds')
            ->with($user)
            ->willReturn(['all-staff', 'engineering', 'marketing']);

        $this->assertSame(
            'engineering',
            $this->service->resolvePrimaryGroup(userId: 'alice')
        );
    }//end testResolvePrimaryGroupReturnsFirstMatchByPriority()

    /**
     * REQ-TMPL-012 scenario: User in no active group falls through.
     *
     * @return void
     */
    public function testResolvePrimaryGroupReturnsDefaultWhenNoMatch(): void
    {
        $this->settingsService
            ->method('getGroupOrder')
            ->willReturn(['engineering', 'executives']);

        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->with('carol')->willReturn($user);
        $this->groupManager
            ->method('getUserGroupIds')
            ->with($user)
            ->willReturn(['support']);

        $this->assertSame(
            Dashboard::DEFAULT_GROUP_ID,
            $this->service->resolvePrimaryGroup(userId: 'carol')
        );
    }//end testResolvePrimaryGroupReturnsDefaultWhenNoMatch()

    /**
     * REQ-TMPL-012 scenario: Empty group_order always returns default.
     *
     * @return void
     */
    public function testResolvePrimaryGroupReturnsDefaultWhenGroupOrderEmpty(): void
    {
        $this->settingsService
            ->method('getGroupOrder')
            ->willReturn([]);

        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->with('anyone')->willReturn($user);
        // The user has groups, but group_order is empty — sentinel wins.
        $this->groupManager
            ->method('getUserGroupIds')
            ->willReturn(['engineering', 'support']);

        $this->assertSame(
            Dashboard::DEFAULT_GROUP_ID,
            $this->service->resolvePrimaryGroup(userId: 'anyone')
        );
    }//end testResolvePrimaryGroupReturnsDefaultWhenGroupOrderEmpty()

    /**
     * REQ-TMPL-012 scenario: Configured group user is NOT in is skipped.
     *
     * @return void
     */
    public function testResolvePrimaryGroupSkipsConfiguredGroupUserIsNotIn(): void
    {
        $this->settingsService
            ->method('getGroupOrder')
            ->willReturn(['executives', 'engineering']);

        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->with('bob')->willReturn($user);
        $this->groupManager
            ->method('getUserGroupIds')
            ->with($user)
            ->willReturn(['engineering', 'support']);

        $this->assertSame(
            'engineering',
            $this->service->resolvePrimaryGroup(userId: 'bob')
        );
    }//end testResolvePrimaryGroupSkipsConfiguredGroupUserIsNotIn()

    /**
     * REQ-TMPL-012 scenario: Stale (deleted) group ID in group_order is
     * harmless — the resolver simply never matches and walks on.
     *
     * @return void
     */
    public function testResolvePrimaryGroupToleratesStaleGroupIds(): void
    {
        $this->settingsService
            ->method('getGroupOrder')
            ->willReturn(['deleted-group', 'engineering']);

        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->with('alice')->willReturn($user);
        // The user is not a member of the deleted group — the resolver
        // simply never matches it. The fact the group no longer exists
        // in Nextcloud is irrelevant: the resolver MUST NOT throw.
        $this->groupManager
            ->method('getUserGroupIds')
            ->with($user)
            ->willReturn(['engineering']);

        $this->assertSame(
            'engineering',
            $this->service->resolvePrimaryGroup(userId: 'alice')
        );
    }//end testResolvePrimaryGroupToleratesStaleGroupIds()

    /**
     * REQ-TMPL-012: unknown user → resolver returns the default sentinel
     * (mirrors the upstream IUserManager `null` path through
     * `getUserGroupIdsFor`).
     *
     * @return void
     */
    public function testResolvePrimaryGroupReturnsDefaultForUnknownUser(): void
    {
        $this->settingsService
            ->method('getGroupOrder')
            ->willReturn(['engineering']);

        $this->userManager->method('get')->with('ghost')->willReturn(null);

        $this->assertSame(
            Dashboard::DEFAULT_GROUP_ID,
            $this->service->resolvePrimaryGroup(userId: 'ghost')
        );
    }//end testResolvePrimaryGroupReturnsDefaultForUnknownUser()

    // ---------------------------------------------------------------
    // getUserGroupIdsFor — single-source-of-truth wrapper
    // ---------------------------------------------------------------

    /**
     * @return void
     */
    public function testGetUserGroupIdsForReturnsEmptyForUnknownUser(): void
    {
        $this->userManager->method('get')->with('ghost')->willReturn(null);

        $this->assertSame(
            [],
            $this->service->getUserGroupIdsFor(userId: 'ghost')
        );
    }//end testGetUserGroupIdsForReturnsEmptyForUnknownUser()

    /**
     * @return void
     */
    public function testGetUserGroupIdsForDelegatesToGroupManager(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->with('alice')->willReturn($user);
        $this->groupManager
            ->method('getUserGroupIds')
            ->with($user)
            ->willReturn(['engineering', 'support']);

        $this->assertSame(
            ['engineering', 'support'],
            $this->service->getUserGroupIdsFor(userId: 'alice')
        );
    }//end testGetUserGroupIdsForDelegatesToGroupManager()

    // ---------------------------------------------------------------
    // resolvePrimaryGroupDisplayName
    // ---------------------------------------------------------------

    /**
     * @return void
     */
    public function testResolvePrimaryGroupDisplayNameReturnsLiteralForSentinel(): void
    {
        // Sentinel always renders 'Default' — translated client-side.
        $this->assertSame(
            'Default',
            $this->service->resolvePrimaryGroupDisplayName(
                groupId: Dashboard::DEFAULT_GROUP_ID
            )
        );
    }//end testResolvePrimaryGroupDisplayNameReturnsLiteralForSentinel()

    /**
     * @return void
     */
    public function testResolvePrimaryGroupDisplayNameLooksUpRealGroup(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getDisplayName')->willReturn('Engineering Team');

        $this->groupManager
            ->method('get')
            ->with('engineering')
            ->willReturn($group);

        $this->assertSame(
            'Engineering Team',
            $this->service->resolvePrimaryGroupDisplayName(groupId: 'engineering')
        );
    }//end testResolvePrimaryGroupDisplayNameLooksUpRealGroup()

    /**
     * @return void
     */
    public function testResolvePrimaryGroupDisplayNameFallsBackToIdForDeletedGroup(): void
    {
        $this->groupManager
            ->method('get')
            ->with('deleted-group')
            ->willReturn(null);

        $this->assertSame(
            'deleted-group',
            $this->service->resolvePrimaryGroupDisplayName(groupId: 'deleted-group')
        );
    }//end testResolvePrimaryGroupDisplayNameFallsBackToIdForDeletedGroup()

}//end class
