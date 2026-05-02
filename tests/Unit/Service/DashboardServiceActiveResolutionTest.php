<?php

/**
 * DashboardServiceActiveResolutionTest
 *
 * Unit tests for the active-dashboard resolution chain — REQ-DASH-018 and
 * REQ-DASH-019. Covers the full 7-step precedence (saved pref → group
 * default → default-group default → first-in-group → first-in-default
 * → first personal → null), the silent stale-pref auto-clear, and the
 * persist/clear API on `DashboardService::setActivePreference`.
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

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\TemplateService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the REQ-DASH-018 / REQ-DASH-019 active-dashboard resolver and
 * preference write API on {@see \OCA\MyDash\Service\DashboardService}.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DashboardServiceActiveResolutionTest extends TestCase
{

    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;

    /** @var TemplateService&MockObject */
    private $templateService;

    /** @var DashboardResolver&MockObject */
    private $dashResolver;

    /** @var IGroupManager&MockObject */
    private $groupManager;

    /** @var IUserManager&MockObject */
    private $userManager;

    /** @var IDBConnection&MockObject */
    private $db;

    /** @var IConfig&MockObject */
    private $config;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private DashboardService $service;

    /**
     * Wire fresh mocks per test so expectation counts never leak.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardMapper = $this->createMock(DashboardMapper::class);
        $this->placementMapper = $this->createMock(WidgetPlacementMapper::class);
        $this->settingMapper   = $this->createMock(AdminSettingMapper::class);
        $this->templateService = $this->createMock(TemplateService::class);
        $this->dashResolver    = $this->createMock(DashboardResolver::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userManager     = $this->createMock(IUserManager::class);
        $this->db              = $this->createMock(IDBConnection::class);
        $this->config          = $this->createMock(IConfig::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new DashboardService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            settingMapper: $this->settingMapper,
            templateService: $this->templateService,
            dashboardFactory: new DashboardFactory(),
            dashResolver: $this->dashResolver,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            db: $this->db,
            config: $this->config,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Build a Dashboard entity with the fields the resolver inspects.
     *
     * @param string      $uuid      The dashboard UUID.
     * @param string      $type      The Dashboard::TYPE_* constant.
     * @param string|null $groupId   The group id (null for personal).
     * @param int         $isDefault The is_default flag (0 or 1).
     * @param string|null $userId    Owner user id for personal rows.
     *
     * @return Dashboard
     */
    private function makeDashboard(
        string $uuid,
        string $type,
        ?string $groupId,
        int $isDefault=0,
        ?string $userId=null
    ): Dashboard {
        $dashboard = new Dashboard();
        $dashboard->setUuid($uuid);
        $dashboard->setType($type);
        $dashboard->setGroupId($groupId);
        $dashboard->setIsDefault($isDefault);
        if ($userId !== null) {
            $dashboard->setUserId($userId);
        }

        return $dashboard;
    }//end makeDashboard()

    /**
     * Wire `getVisibleToUser` to return the supplied list. The resolver
     * routes through `getVisibleToUser` which itself fans out to
     * `IUserManager::get` + `IGroupManager::getUserGroupIds` +
     * `DashboardMapper::findVisibleToUser`. We mock the deepest mapper
     * call so the wiring stays exercised.
     *
     * @param string $userId  The user id.
     * @param array  $visible The visible-to-user payload to return.
     *
     * @return void
     */
    private function stubVisible(string $userId, array $visible): void
    {
        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->with($userId)->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->with($user)->willReturn([]);
        $this->dashboardMapper
            ->method('findVisibleToUser')
            ->willReturn($visible);
    }//end stubVisible()

    /**
     * REQ-DASH-018 step 1 (happy path): saved pref UUID resolves and is
     * returned with the source attached by the visible-list builder.
     *
     * @return void
     */
    public function testStep1HonoursSavedPreference(): void
    {
        $personal = $this->makeDashboard('uuid-pref', Dashboard::TYPE_USER, null, 0, 'alice');
        $this->stubVisible('alice', [
            ['dashboard' => $personal, 'source' => Dashboard::SOURCE_USER],
        ]);

        $this->config
            ->method('getUserValue')
            ->with('alice', Application::APP_ID, DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY, '')
            ->willReturn('uuid-pref');

        // Pure read: never delete on a hit.
        $this->config->expects($this->never())->method('deleteUserValue');

        $result = $this->service->resolveActiveDashboard(
            userId: 'alice',
            primaryGroupId: 'marketing'
        );

        $this->assertNotNull($result);
        $this->assertSame('uuid-pref', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_USER, $result['source']);
    }//end testStep1HonoursSavedPreference()

    /**
     * REQ-DASH-018 stale-pref: saved UUID is no longer visible — the
     * resolver MUST silently delete the pref, log a warning, and continue
     * down the chain (lands on step 2 in this fixture).
     *
     * @return void
     */
    public function testStalePreferenceIsClearedAndChainContinues(): void
    {
        $groupDefault = $this->makeDashboard(
            uuid: 'uuid-group-default',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: 'marketing',
            isDefault: 1
        );
        $this->stubVisible('alice', [
            ['dashboard' => $groupDefault, 'source' => Dashboard::SOURCE_GROUP],
        ]);

        $this->config
            ->method('getUserValue')
            ->willReturn('stale-uuid');

        $this->config
            ->expects($this->once())
            ->method('deleteUserValue')
            ->with('alice', Application::APP_ID, DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('stale active_dashboard_uuid'),
                $this->callback(function ($context) {
                    return ($context['uuid'] ?? null) === 'stale-uuid'
                        && ($context['user'] ?? null) === 'alice';
                })
            );

        $result = $this->service->resolveActiveDashboard(
            userId: 'alice',
            primaryGroupId: 'marketing'
        );

        $this->assertNotNull($result);
        $this->assertSame('uuid-group-default', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_GROUP, $result['source']);
    }//end testStalePreferenceIsClearedAndChainContinues()

    /**
     * REQ-DASH-018 step 2 wins step 3: a primary-group default beats the
     * default-group default.
     *
     * @return void
     */
    public function testStep2GroupDefaultBeatsDefaultGroupDefault(): void
    {
        $groupDefault = $this->makeDashboard(
            uuid: 'uuid-engineering',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: 'engineering',
            isDefault: 1
        );
        $defaultGroupDefault = $this->makeDashboard(
            uuid: 'uuid-default',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: Dashboard::DEFAULT_GROUP_ID,
            isDefault: 1
        );

        $this->stubVisible('bob', [
            ['dashboard' => $groupDefault,        'source' => Dashboard::SOURCE_GROUP],
            ['dashboard' => $defaultGroupDefault, 'source' => Dashboard::SOURCE_DEFAULT],
        ]);

        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->resolveActiveDashboard(
            userId: 'bob',
            primaryGroupId: 'engineering'
        );

        $this->assertNotNull($result);
        $this->assertSame('uuid-engineering', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_GROUP, $result['source']);
    }//end testStep2GroupDefaultBeatsDefaultGroupDefault()

    /**
     * REQ-DASH-018 step 3: when the primary group has no group-default
     * but the default group does, the default-group default is picked.
     *
     * @return void
     */
    public function testStep3FallsThroughToDefaultGroupDefault(): void
    {
        $defaultGroupDefault = $this->makeDashboard(
            uuid: 'uuid-default',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: Dashboard::DEFAULT_GROUP_ID,
            isDefault: 1
        );

        $this->stubVisible('carol', [
            ['dashboard' => $defaultGroupDefault, 'source' => Dashboard::SOURCE_DEFAULT],
        ]);

        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->resolveActiveDashboard(
            userId: 'carol',
            primaryGroupId: 'support'
        );

        $this->assertNotNull($result);
        $this->assertSame('uuid-default', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_DEFAULT, $result['source']);
    }//end testStep3FallsThroughToDefaultGroupDefault()

    /**
     * REQ-DASH-018 step 4: with no defaults set, the first group-shared
     * row in the user's primary group wins.
     *
     * @return void
     */
    public function testStep4FirstGroupSharedInPrimaryGroup(): void
    {
        $first = $this->makeDashboard(
            uuid: 'uuid-eng-first',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: 'engineering'
        );
        $second = $this->makeDashboard(
            uuid: 'uuid-eng-second',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: 'engineering'
        );
        $defaultRow = $this->makeDashboard(
            uuid: 'uuid-default-fallback',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: Dashboard::DEFAULT_GROUP_ID
        );

        $this->stubVisible('alice', [
            ['dashboard' => $first,      'source' => Dashboard::SOURCE_GROUP],
            ['dashboard' => $second,     'source' => Dashboard::SOURCE_GROUP],
            ['dashboard' => $defaultRow, 'source' => Dashboard::SOURCE_DEFAULT],
        ]);

        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->resolveActiveDashboard(
            userId: 'alice',
            primaryGroupId: 'engineering'
        );

        $this->assertNotNull($result);
        $this->assertSame('uuid-eng-first', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_GROUP, $result['source']);
    }//end testStep4FirstGroupSharedInPrimaryGroup()

    /**
     * REQ-DASH-018 step 5: nothing in the primary group, no defaults
     * anywhere — the first default-group dashboard wins.
     *
     * @return void
     */
    public function testStep5FirstDefaultGroupDashboard(): void
    {
        $defaultRow = $this->makeDashboard(
            uuid: 'uuid-default-first',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: Dashboard::DEFAULT_GROUP_ID
        );

        $this->stubVisible('alice', [
            ['dashboard' => $defaultRow, 'source' => Dashboard::SOURCE_DEFAULT],
        ]);

        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->resolveActiveDashboard(
            userId: 'alice',
            primaryGroupId: 'orphan'
        );

        $this->assertNotNull($result);
        $this->assertSame('uuid-default-first', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_DEFAULT, $result['source']);
    }//end testStep5FirstDefaultGroupDashboard()

    /**
     * REQ-DASH-018 step 6: only personal dashboards exist — pick the
     * first one.
     *
     * @return void
     */
    public function testStep6FirstPersonalDashboard(): void
    {
        $personal = $this->makeDashboard(
            uuid: 'uuid-mine',
            type: Dashboard::TYPE_USER,
            groupId: null,
            isDefault: 0,
            userId: 'alice'
        );

        $this->stubVisible('alice', [
            ['dashboard' => $personal, 'source' => Dashboard::SOURCE_USER],
        ]);

        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->resolveActiveDashboard(
            userId: 'alice',
            primaryGroupId: null
        );

        $this->assertNotNull($result);
        $this->assertSame('uuid-mine', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_USER, $result['source']);
    }//end testStep6FirstPersonalDashboard()

    /**
     * REQ-DASH-018 step 7: no dashboards anywhere — null triggers the
     * empty-state UI.
     *
     * @return void
     */
    public function testStep7EmptyStateReturnsNull(): void
    {
        $this->stubVisible('alice', []);
        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->resolveActiveDashboard(
            userId: 'alice',
            primaryGroupId: null
        );

        $this->assertNull($result);
    }//end testStep7EmptyStateReturnsNull()

    /**
     * REQ-DASH-018 — REQ-DASH-019 cross-cut: alice's saved pref points to
     * a dashboard whose group she no longer belongs to. The visible-list
     * therefore omits it; the resolver MUST silently clear the pref and
     * fall through.
     *
     * @return void
     */
    public function testCrossGroupPreferenceInvalidated(): void
    {
        // Visible list has only her current group's dashboards.
        $current = $this->makeDashboard(
            uuid: 'uuid-current',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: 'marketing'
        );
        $this->stubVisible('alice', [
            ['dashboard' => $current, 'source' => Dashboard::SOURCE_GROUP],
        ]);

        $this->config
            ->method('getUserValue')
            ->willReturn('uuid-old-engineering');

        $this->config
            ->expects($this->once())
            ->method('deleteUserValue');
        $this->logger
            ->expects($this->once())
            ->method('warning');

        $result = $this->service->resolveActiveDashboard(
            userId: 'alice',
            primaryGroupId: 'marketing'
        );

        $this->assertNotNull($result);
        $this->assertSame('uuid-current', $result['dashboard']->getUuid());
    }//end testCrossGroupPreferenceInvalidated()

    /**
     * REQ-DASH-018: stale-pref clear runs at most once per resolve call —
     * the resolver pre-fetches the visible list once and indexes it once;
     * the deleteUserValue call must never be issued more than once.
     *
     * @return void
     */
    public function testStalePrefDeletedExactlyOncePerResolve(): void
    {
        $this->stubVisible('alice', []);
        $this->config->method('getUserValue')->willReturn('stale');

        $this->config
            ->expects($this->once())
            ->method('deleteUserValue');

        $this->service->resolveActiveDashboard(
            userId: 'alice',
            primaryGroupId: null
        );
    }//end testStalePrefDeletedExactlyOncePerResolve()

    /**
     * REQ-DASH-019: setActivePreference accepts arbitrary strings without
     * validating against the dashboard table — the resolver's stale-pref
     * path handles invalid UUIDs on next render.
     *
     * @return void
     */
    public function testSetActivePreferenceAcceptsAnyUuid(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setUserValue')
            ->with(
                'alice',
                Application::APP_ID,
                DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY,
                'does-not-exist'
            );
        $this->config->expects($this->never())->method('deleteUserValue');
        $this->dashboardMapper->expects($this->never())->method('findByUuid');

        $this->service->setActivePreference(
            userId: 'alice',
            uuid: 'does-not-exist'
        );
    }//end testSetActivePreferenceAcceptsAnyUuid()

    /**
     * REQ-DASH-019: an empty-string UUID clears the preference (delete,
     * not write of empty string) so the resolver falls through the chain
     * from step 2 on the next read.
     *
     * @return void
     */
    public function testSetActivePreferenceEmptyStringClears(): void
    {
        $this->config
            ->expects($this->once())
            ->method('deleteUserValue')
            ->with(
                'alice',
                Application::APP_ID,
                DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY
            );
        $this->config->expects($this->never())->method('setUserValue');

        $this->service->setActivePreference(
            userId: 'alice',
            uuid: ''
        );
    }//end testSetActivePreferenceEmptyStringClears()

}//end class
