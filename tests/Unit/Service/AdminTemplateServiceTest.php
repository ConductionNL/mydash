<?php

/**
 * AdminTemplateService Test
 *
 * Covers the primary-group resolver introduced by REQ-TMPL-012 / REQ-TMPL-013.
 * The resolver is the single routing authority for picking which Nextcloud
 * group a user's workspace renders for; these tests pin the algorithm and
 * its tolerance for stale / empty / missing inputs.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\AdminTemplateService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class AdminTemplateServiceTest extends TestCase
{

    private AdminTemplateService $service;

    private DashboardMapper $dashboardMapper;

    private WidgetPlacementMapper $placementMapper;

    private AdminSettingsService $adminSettings;

    private IGroupManager $groupManager;

    private IUserManager $userManager;

    protected function setUp(): void
    {
        $this->dashboardMapper = $this->createMock(DashboardMapper::class);
        $this->placementMapper = $this->createMock(WidgetPlacementMapper::class);
        $this->adminSettings   = $this->createMock(AdminSettingsService::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userManager     = $this->createMock(IUserManager::class);

        $this->service = new AdminTemplateService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            adminSettings: $this->adminSettings,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
        );
    }//end setUp()

    /**
     * Build a mock IUser whose getUID() returns the given ID.
     */
    private function mockUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }//end mockUser()

    /**
     * Table-driven cover of every REQ-TMPL-012 scenario.
     *
     * @dataProvider provideResolvePrimaryGroupCases
     *
     * @param array<int, string> $groupOrder admin-configured priority list
     * @param array<int, string> $userGroups Nextcloud memberships
     * @param string             $expected   resolver result
     * @param string             $caseLabel  human label for failure trace
     */
    public function testResolvePrimaryGroup(
        array $groupOrder,
        array $userGroups,
        string $expected,
        string $caseLabel
    ): void {
        $this->adminSettings
            ->method('getGroupOrder')
            ->willReturn($groupOrder);

        $user = $this->mockUser('alice');
        $this->userManager
            ->method('get')
            ->with('alice')
            ->willReturn($user);

        $this->groupManager
            ->method('getUserGroupIds')
            ->with($user)
            ->willReturn($userGroups);

        $this->assertSame(
            $expected,
            $this->service->resolvePrimaryGroup('alice'),
            $caseLabel
        );
    }//end testResolvePrimaryGroup()

    public function testResolvePrimaryGroupReturnsDefaultForUnknownUser(): void
    {
        $this->adminSettings
            ->method('getGroupOrder')
            ->willReturn(['engineering']);

        $this->userManager
            ->method('get')
            ->with('ghost')
            ->willReturn(null);

        $this->groupManager
            ->expects($this->never())
            ->method('getUserGroupIds');

        $this->assertSame(
            Dashboard::DEFAULT_GROUP_ID,
            $this->service->resolvePrimaryGroup('ghost'),
            'unknown user ID must short-circuit to default sentinel without throwing'
        );
    }//end testResolvePrimaryGroupReturnsDefaultForUnknownUser()

    /**
     * @return array<string, array{0: array<int, string>, 1: array<int, string>, 2: string, 3: string}>
     */
    public static function provideResolvePrimaryGroupCases(): array
    {
        return [
            'priority order wins over alphabetical'               => [
                ['engineering', 'all-staff'],
                ['all-staff', 'engineering', 'marketing'],
                'engineering',
                'engineering appears first in group_order, even though all-staff is alphabetically earlier in user groups',
            ],
            'configured group not in user memberships is skipped' => [
                ['executives', 'engineering'],
                ['engineering', 'support'],
                'engineering',
                'executives is configured but bob is not a member, so we fall through to engineering',
            ],
            'no overlap returns default sentinel'                 => [
                ['engineering', 'executives'],
                ['support'],
                Dashboard::DEFAULT_GROUP_ID,
                'carol is in support only — no overlap with group_order, must return default',
            ],
            'empty group_order always returns default'            => [
                [],
                ['engineering', 'all-staff', 'marketing'],
                Dashboard::DEFAULT_GROUP_ID,
                'unconfigured group_order means default regardless of user memberships',
            ],
            'user in no groups returns default'                   => [
                ['engineering', 'executives'],
                [],
                Dashboard::DEFAULT_GROUP_ID,
                'user with zero memberships always falls through to default',
            ],
            'stale (deleted) group ID is harmless'                => [
                ['deleted-group', 'engineering'],
                ['engineering'],
                'engineering',
                'deleted-group remains in group_order but is not in user groups; resolver must skip without error',
            ],
            'stale group ID at every position falls to default'   => [
                ['deleted-a', 'deleted-b'],
                ['engineering'],
                Dashboard::DEFAULT_GROUP_ID,
                'all configured groups are stale — must return default sentinel, not throw',
            ],
            'single configured + matching user group'             => [
                ['engineering'],
                ['engineering'],
                'engineering',
                'simplest happy path',
            ],
        ];
    }//end provideResolvePrimaryGroupCases()

    public function testResolvePrimaryGroupReturnsLiteralDefaultString(): void
    {
        // Pin the sentinel string itself so accidental constant rename
        // (e.g. to 'DEFAULT' or '__default__') is caught — REQ-DASH-012.
        $this->adminSettings->method('getGroupOrder')->willReturn([]);

        $this->assertSame(
            'default',
            $this->service->resolvePrimaryGroup('anyone'),
            'sentinel must be the literal string "default", not a renamed constant value'
        );
        $this->assertSame(
            'default',
            Dashboard::DEFAULT_GROUP_ID,
            'Dashboard::DEFAULT_GROUP_ID must be the literal "default" sentinel'
        );
    }//end testResolvePrimaryGroupReturnsLiteralDefaultString()

    public function testResolvePrimaryGroupIsReadOnly(): void
    {
        // Resolver MUST NOT write — guard via mock expectations on the only
        // injected mappers / settings (no setters / setSetting / insert /
        // update should ever be called).
        $this->dashboardMapper->expects($this->never())->method($this->anything());
        $this->placementMapper->expects($this->never())->method($this->anything());
        $this->adminSettings
            ->expects($this->never())
            ->method('updateSettings');
        $this->adminSettings
            ->expects($this->never())
            ->method('setGroupOrder');

        $this->adminSettings->method('getGroupOrder')->willReturn(['engineering']);
        $user = $this->mockUser('alice');
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['engineering']);

        $this->service->resolvePrimaryGroup('alice');
    }//end testResolvePrimaryGroupIsReadOnly()

    public function testPickFirstMatchEmptyOrderedReturnsNull(): void
    {
        $this->assertNull(
            AdminTemplateService::pickFirstMatch(
                orderedGroups: [],
                userGroups: ['engineering', 'all-staff']
            )
        );
    }//end testPickFirstMatchEmptyOrderedReturnsNull()

    public function testPickFirstMatchEmptyUserGroupsReturnsNull(): void
    {
        $this->assertNull(
            AdminTemplateService::pickFirstMatch(
                orderedGroups: ['engineering'],
                userGroups: []
            )
        );
    }//end testPickFirstMatchEmptyUserGroupsReturnsNull()

    public function testPickFirstMatchBothEmptyReturnsNull(): void
    {
        $this->assertNull(
            AdminTemplateService::pickFirstMatch(
                orderedGroups: [],
                userGroups: []
            )
        );
    }//end testPickFirstMatchBothEmptyReturnsNull()

    public function testPickFirstMatchNoOverlapReturnsNull(): void
    {
        $this->assertNull(
            AdminTemplateService::pickFirstMatch(
                orderedGroups: ['engineering', 'executives'],
                userGroups: ['support', 'marketing']
            )
        );
    }//end testPickFirstMatchNoOverlapReturnsNull()

    public function testPickFirstMatchMultipleOverlapsReturnsFirstByPriority(): void
    {
        // user is in BOTH engineering and all-staff; engineering comes first
        // in group_order, so it wins regardless of the user-side ordering.
        $this->assertSame(
            'engineering',
            AdminTemplateService::pickFirstMatch(
                orderedGroups: ['engineering', 'all-staff'],
                userGroups: ['all-staff', 'engineering']
            )
        );
    }//end testPickFirstMatchMultipleOverlapsReturnsFirstByPriority()

    public function testPickFirstMatchSkipsLeadingNonMembers(): void
    {
        $this->assertSame(
            'engineering',
            AdminTemplateService::pickFirstMatch(
                orderedGroups: ['executives', 'leadership', 'engineering', 'all-staff'],
                userGroups: ['engineering', 'all-staff']
            )
        );
    }//end testPickFirstMatchSkipsLeadingNonMembers()
}//end class
