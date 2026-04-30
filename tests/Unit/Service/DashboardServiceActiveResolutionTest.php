<?php

/**
 * DashboardService Active-Resolution Test
 *
 * Table-driven unit tests for REQ-DASH-018 (resolveActiveDashboard) and
 * REQ-DASH-019 (setActivePreference). Covers all 7 precedence steps,
 * stale-pref auto-clear, cross-group preference invalidation, and the
 * empty-state scenario.
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
 * Unit tests for the active-dashboard resolution chain.
 *
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
    /** @var DashboardFactory&MockObject */
    private $dashboardFactory;
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

    protected function setUp(): void
    {
        $this->dashboardMapper  = $this->createMock(DashboardMapper::class);
        $this->placementMapper  = $this->createMock(WidgetPlacementMapper::class);
        $this->settingMapper    = $this->createMock(AdminSettingMapper::class);
        $this->templateService  = $this->createMock(TemplateService::class);
        $this->dashboardFactory = $this->createMock(DashboardFactory::class);
        $this->dashResolver     = $this->createMock(DashboardResolver::class);
        $this->groupManager     = $this->createMock(IGroupManager::class);
        $this->userManager      = $this->createMock(IUserManager::class);
        $this->db               = $this->createMock(IDBConnection::class);
        $this->config           = $this->createMock(IConfig::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->service = new DashboardService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            settingMapper: $this->settingMapper,
            templateService: $this->templateService,
            dashboardFactory: $this->dashboardFactory,
            dashResolver: $this->dashResolver,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            db: $this->db,
            config: $this->config,
            logger: $this->logger,
        );
    }//end setUp()

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a Dashboard stub with the given UUID, type, groupId and isDefault.
     */
    private function makeDashboard(
        string $uuid,
        string $type=Dashboard::TYPE_USER,
        ?string $groupId=null,
        int $isDefault=0,
        ?string $userId=null
    ): Dashboard {
        $d = new Dashboard();
        $d->setUuid($uuid);
        $d->setType($type);
        $d->setGroupId($groupId);
        $d->setIsDefault($isDefault);
        $d->setUserId($userId);
        $d->setName('Dashboard ' . $uuid);
        return $d;
    }//end makeDashboard()

    /**
     * Wire `getVisibleToUser` to return the given array of
     * `{dashboard, source}` entries. Sets up the IUser / IGroupManager mocks
     * needed by `getVisibleToUser`.
     *
     * @param array<int, array{dashboard: Dashboard, source: string}> $entries
     */
    private function stubVisibleToUser(array $entries): void
    {
        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn([]);
        // DashboardService::getVisibleToUser delegates to findVisibleToUser on the mapper.
        $this->dashboardMapper->method('findVisibleToUser')->willReturn($entries);
    }//end stubVisibleToUser()

    /**
     * Wire IConfig::getUserValue to return the given saved UUID (or '').
     */
    private function stubSavedPref(string $uuid): void
    {
        $this->config->method('getUserValue')
            ->with('alice', Application::APP_ID, DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY, '')
            ->willReturn($uuid);
    }//end stubSavedPref()

    // -----------------------------------------------------------------------
    // Step 1: Saved preference (honoured)
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018 step 1: Saved pref UUID is in the visible list — return it.
     *
     * @return void
     */
    public function testStep1HonouredSavedPref(): void
    {
        $personal = $this->makeDashboard('uuid-personal', Dashboard::TYPE_USER, null, 0, 'alice');
        $entries  = [
            ['dashboard' => $personal, 'source' => Dashboard::SOURCE_USER],
        ];

        $this->stubVisibleToUser($entries);
        $this->stubSavedPref('uuid-personal');

        $result = $this->service->resolveActiveDashboard('alice', null);

        $this->assertNotNull($result);
        $this->assertSame('uuid-personal', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_USER, $result['source']);
    }//end testStep1HonouredSavedPref()

    // -----------------------------------------------------------------------
    // Step 1: Stale pref (auto-clear)
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018 stale-pref: UUID not in visible list — clear pref exactly
     * once, log one WARNING, then fall through the chain.
     *
     * @return void
     */
    public function testStep1StalePrefClearedOnce(): void
    {
        // Visible list has only a default-group dashboard — stale pref
        // points to a UUID not in the list.
        $defaultDash = $this->makeDashboard('uuid-default', Dashboard::TYPE_GROUP_SHARED, 'default', 1);
        $entries     = [
            ['dashboard' => $defaultDash, 'source' => Dashboard::SOURCE_DEFAULT],
        ];

        $this->stubVisibleToUser($entries);
        $this->stubSavedPref('uuid-stale');

        // deleteUserValue MUST be called exactly once.
        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('alice', Application::APP_ID, DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY);

        // One WARNING log.
        $this->logger->expects($this->once())->method('warning');

        $result = $this->service->resolveActiveDashboard('alice', null);

        // Falls through to step 3 (default-group default).
        $this->assertNotNull($result);
        $this->assertSame('uuid-default', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_DEFAULT, $result['source']);
    }//end testStep1StalePrefClearedOnce()

    /**
     * REQ-DASH-018: Stale pref is cleared exactly once — not on every
     * visibility check. (Idempotency guard: no second call to deleteUserValue.)
     *
     * @return void
     */
    public function testStep1StalePrefClearedAtMostOnce(): void
    {
        $this->stubVisibleToUser([]);
        $this->stubSavedPref('uuid-gone');

        $this->config->expects($this->exactly(1))
            ->method('deleteUserValue');

        $this->logger->expects($this->once())->method('warning');

        $result = $this->service->resolveActiveDashboard('alice', null);
        $this->assertNull($result);
    }//end testStep1StalePrefClearedAtMostOnce()

    // -----------------------------------------------------------------------
    // Step 2: Primary group default
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018 step 2: Primary group has a default group-shared dashboard;
     * no user preference is saved. Must win over step 3.
     *
     * @return void
     */
    public function testStep2PrimaryGroupDefault(): void
    {
        $groupDefault   = $this->makeDashboard('uuid-grp-default', Dashboard::TYPE_GROUP_SHARED, 'engineering', 1);
        $defaultDefault = $this->makeDashboard('uuid-def-default', Dashboard::TYPE_GROUP_SHARED, 'default', 1);
        $entries        = [
            ['dashboard' => $groupDefault,   'source' => Dashboard::SOURCE_GROUP],
            ['dashboard' => $defaultDefault, 'source' => Dashboard::SOURCE_DEFAULT],
        ];

        $this->stubVisibleToUser($entries);
        $this->stubSavedPref('');

        $result = $this->service->resolveActiveDashboard('alice', 'engineering');

        $this->assertNotNull($result);
        $this->assertSame('uuid-grp-default', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_GROUP, $result['source']);
    }//end testStep2PrimaryGroupDefault()

    // -----------------------------------------------------------------------
    // Step 3: Default-group default
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018 step 3: Primary group has no default; default group has one.
     *
     * @return void
     */
    public function testStep3DefaultGroupDefault(): void
    {
        $groupFirst     = $this->makeDashboard('uuid-grp-first', Dashboard::TYPE_GROUP_SHARED, 'support', 0);
        $defaultDefault = $this->makeDashboard('uuid-def-default', Dashboard::TYPE_GROUP_SHARED, 'default', 1);
        $entries        = [
            ['dashboard' => $groupFirst,     'source' => Dashboard::SOURCE_GROUP],
            ['dashboard' => $defaultDefault, 'source' => Dashboard::SOURCE_DEFAULT],
        ];

        $this->stubVisibleToUser($entries);
        $this->stubSavedPref('');

        $result = $this->service->resolveActiveDashboard('alice', 'support');

        $this->assertNotNull($result);
        $this->assertSame('uuid-def-default', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_DEFAULT, $result['source']);
    }//end testStep3DefaultGroupDefault()

    // -----------------------------------------------------------------------
    // Step 4: First in primary group
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018 step 4: No defaults anywhere; primary group has dashboards.
     *
     * @return void
     */
    public function testStep4FirstInPrimaryGroup(): void
    {
        $groupA  = $this->makeDashboard('uuid-grp-a', Dashboard::TYPE_GROUP_SHARED, 'engineering', 0);
        $groupB  = $this->makeDashboard('uuid-grp-b', Dashboard::TYPE_GROUP_SHARED, 'engineering', 0);
        $entries = [
            ['dashboard' => $groupA, 'source' => Dashboard::SOURCE_GROUP],
            ['dashboard' => $groupB, 'source' => Dashboard::SOURCE_GROUP],
        ];

        $this->stubVisibleToUser($entries);
        $this->stubSavedPref('');

        $result = $this->service->resolveActiveDashboard('alice', 'engineering');

        $this->assertNotNull($result);
        $this->assertSame('uuid-grp-a', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_GROUP, $result['source']);
    }//end testStep4FirstInPrimaryGroup()

    // -----------------------------------------------------------------------
    // Step 5: First in default group
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018 step 5: Primary group has no dashboards; default group has
     * non-default dashboards.
     *
     * @return void
     */
    public function testStep5FirstInDefaultGroup(): void
    {
        $defA    = $this->makeDashboard('uuid-def-a', Dashboard::TYPE_GROUP_SHARED, 'default', 0);
        $entries = [
            ['dashboard' => $defA, 'source' => Dashboard::SOURCE_DEFAULT],
        ];

        $this->stubVisibleToUser($entries);
        $this->stubSavedPref('');

        // primaryGroupId = 'support' but no dashboards for that group.
        $result = $this->service->resolveActiveDashboard('alice', 'support');

        $this->assertNotNull($result);
        $this->assertSame('uuid-def-a', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_DEFAULT, $result['source']);
    }//end testStep5FirstInDefaultGroup()

    // -----------------------------------------------------------------------
    // Step 6: First personal dashboard
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018 step 6: No group dashboards at all; user has a personal one.
     *
     * @return void
     */
    public function testStep6FirstPersonalDashboard(): void
    {
        $personal = $this->makeDashboard('uuid-personal', Dashboard::TYPE_USER, null, 0, 'alice');
        $entries  = [
            ['dashboard' => $personal, 'source' => Dashboard::SOURCE_USER],
        ];

        $this->stubVisibleToUser($entries);
        $this->stubSavedPref('');

        $result = $this->service->resolveActiveDashboard('alice', null);

        $this->assertNotNull($result);
        $this->assertSame('uuid-personal', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_USER, $result['source']);
    }//end testStep6FirstPersonalDashboard()

    // -----------------------------------------------------------------------
    // Step 7: Empty state
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018 step 7: No dashboards of any kind — resolver returns null.
     *
     * @return void
     */
    public function testStep7EmptyStateReturnsNull(): void
    {
        $this->stubVisibleToUser([]);
        $this->stubSavedPref('');

        $result = $this->service->resolveActiveDashboard('alice', null);

        $this->assertNull($result);
    }//end testStep7EmptyStateReturnsNull()

    // -----------------------------------------------------------------------
    // Cross-group preference invalidation
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018: Alice's preference points to a dashboard in a group she no
     * longer belongs to — that dashboard is absent from `getVisibleToUser`,
     * so the pref must be cleared and the chain continues.
     *
     * @return void
     */
    public function testCrossGroupPrefInvalidated(): void
    {
        // alice's pref points to 'uuid-old-group' which is NOT in the visible list.
        $fallback = $this->makeDashboard('uuid-def-default', Dashboard::TYPE_GROUP_SHARED, 'default', 1);
        $entries  = [
            ['dashboard' => $fallback, 'source' => Dashboard::SOURCE_DEFAULT],
        ];

        $this->stubVisibleToUser($entries);
        $this->stubSavedPref('uuid-old-group');

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('alice', Application::APP_ID, DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY);

        $this->logger->expects($this->once())->method('warning');

        $result = $this->service->resolveActiveDashboard('alice', null);

        // Should fall through to the default-group default.
        $this->assertNotNull($result);
        $this->assertSame('uuid-def-default', $result['dashboard']->getUuid());
    }//end testCrossGroupPrefInvalidated()

    // -----------------------------------------------------------------------
    // No primary group (null / 'default' sentinel)
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-018: When primaryGroupId is null the resolver treats it as
     * 'default' and only steps 3, 5, 6, 7 are eligible (steps 2 and 4 skip).
     *
     * @return void
     */
    public function testNullPrimaryGroupSkipsGroupSteps(): void
    {
        $defaultDefault = $this->makeDashboard('uuid-def-default', Dashboard::TYPE_GROUP_SHARED, 'default', 1);
        $entries        = [
            ['dashboard' => $defaultDefault, 'source' => Dashboard::SOURCE_DEFAULT],
        ];

        $this->stubVisibleToUser($entries);
        $this->stubSavedPref('');

        // primaryGroupId = null → treated as 'default', so only step 3 applies.
        $result = $this->service->resolveActiveDashboard('alice', null);

        $this->assertNotNull($result);
        $this->assertSame('uuid-def-default', $result['dashboard']->getUuid());
        $this->assertSame(Dashboard::SOURCE_DEFAULT, $result['source']);
    }//end testNullPrimaryGroupSkipsGroupSteps()

    // -----------------------------------------------------------------------
    // REQ-DASH-019: setActivePreference
    // -----------------------------------------------------------------------

    /**
     * REQ-DASH-019: setActivePreference writes the UUID to IConfig.
     *
     * @return void
     */
    public function testSetActivePreferenceWritesUuid(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', Application::APP_ID, DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY, 'abc-123');

        $this->service->setActivePreference('alice', 'abc-123');
    }//end testSetActivePreferenceWritesUuid()

    /**
     * REQ-DASH-019 scenario "empty uuid clears the preference": empty string
     * MUST call deleteUserValue, not setUserValue.
     *
     * @return void
     */
    public function testSetActivePreferenceEmptyStringClears(): void
    {
        $this->config->expects($this->never())->method('setUserValue');
        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('alice', Application::APP_ID, DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY);

        $this->service->setActivePreference('alice', '');
    }//end testSetActivePreferenceEmptyStringClears()

    /**
     * REQ-DASH-019 scenario "no existence check on write": non-existent UUID is
     * accepted without error — setUserValue called with whatever was passed.
     *
     * @return void
     */
    public function testSetActivePreferenceAcceptsNonExistentUuid(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', Application::APP_ID, DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY, 'does-not-exist');

        // No exception thrown.
        $this->service->setActivePreference('alice', 'does-not-exist');
    }//end testSetActivePreferenceAcceptsNonExistentUuid()
}//end class
