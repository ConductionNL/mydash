<?php

/**
 * DashboardService Allow-Flag Test
 *
 * Covers REQ-ASET-003 runtime gating on personal-dashboard creation
 * and REQ-ASET-015 (initial-state mirror — verified via PageController
 * side; this suite focuses on the service/controller gate behaviour).
 *
 * Scenarios:
 *   - 403 envelope has exactly {status, error, message} when flag off.
 *   - Existing personal dashboards remain readable/editable when flag off.
 *   - Toggling does not mutate data (no DB writes on toggle).
 *   - Direct API call still returns 403 (defence-in-depth REQ-ASET-015).
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

use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\PersonalDashboardsDisabledException;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\TemplateService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the allow-user-dashboards runtime gate (REQ-ASET-003).
 */
class DashboardServiceAllowFlagTest extends TestCase
{

    /**
     * Dashboard mapper mock.
     *
     * @var DashboardMapper&MockObject
     */
    private $dashboardMapper;

    /**
     * Widget placement mapper mock.
     *
     * @var WidgetPlacementMapper&MockObject
     */
    private $placementMapper;

    /**
     * Admin setting mapper mock.
     *
     * @var AdminSettingMapper&MockObject
     */
    private $settingMapper;

    /**
     * Template service mock.
     *
     * @var TemplateService&MockObject
     */
    private $templateService;

    /**
     * Dashboard factory mock.
     *
     * @var DashboardFactory&MockObject
     */
    private $dashboardFactory;

    /**
     * Dashboard resolver mock.
     *
     * @var DashboardResolver&MockObject
     */
    private $dashResolver;

    /**
     * Group manager mock.
     *
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * AdminTemplateService mock — single source of truth for group lookups
     * (REQ-TMPL-013).
     *
     * @var AdminTemplateService&MockObject
     */
    private $adminTemplateService;

    /**
     * DB connection mock.
     *
     * @var IDBConnection&MockObject
     */
    private $db;

    /**
     * IConfig mock.
     *
     * @var IConfig&MockObject
     */
    private $config;

    /**
     * IL10N factory mock.
     *
     * @var IFactory&MockObject
     */
    private $l10nFactory;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * Service under test.
     *
     * @var DashboardService
     */
    private DashboardService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dashboardMapper  = $this->createMock(DashboardMapper::class);
        $this->placementMapper  = $this->createMock(WidgetPlacementMapper::class);
        $this->settingMapper    = $this->createMock(AdminSettingMapper::class);
        $this->templateService  = $this->createMock(TemplateService::class);
        $this->dashboardFactory = $this->createMock(DashboardFactory::class);
        $this->dashResolver     = $this->createMock(DashboardResolver::class);
        $this->groupManager         = $this->createMock(IGroupManager::class);
        $this->adminTemplateService = $this->createMock(AdminTemplateService::class);
        $this->db                   = $this->createMock(IDBConnection::class);
        $this->config               = $this->createMock(IConfig::class);
        $this->l10nFactory          = $this->createMock(IFactory::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $this->service = new DashboardService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            settingMapper: $this->settingMapper,
            templateService: $this->templateService,
            dashboardFactory: $this->dashboardFactory,
            dashResolver: $this->dashResolver,
            groupManager: $this->groupManager,
            adminTemplateService: $this->adminTemplateService,
            db: $this->db,
            config: $this->config,
            l10nFactory: $this->l10nFactory,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * REQ-ASET-003: When flag is false, assertPersonalDashboardsAllowed()
     * MUST throw PersonalDashboardsDisabledException with stable error code.
     *
     * @return void
     */
    public function testAssertThrowsWhenFlagIsOff(): void
    {
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_ALLOW_USER_DASHBOARDS, false)
            ->willReturn(false);

        $this->expectException(PersonalDashboardsDisabledException::class);

        $this->service->assertPersonalDashboardsAllowed();
    }//end testAssertThrowsWhenFlagIsOff()

    /**
     * REQ-ASET-003 envelope shape: the thrown exception MUST carry error
     * code 'personal_dashboards_disabled', HTTP status 403, and the
     * English message per spec.
     *
     * @return void
     */
    public function testExceptionEnvelopeShape(): void
    {
        $this->settingMapper->method('getValue')
            ->willReturn(false);

        try {
            $this->service->assertPersonalDashboardsAllowed();
            $this->fail(
                message: 'Expected PersonalDashboardsDisabledException was not thrown'
            );
        } catch (PersonalDashboardsDisabledException $e) {
            $this->assertSame(
                expected: 'personal_dashboards_disabled',
                actual: $e->getErrorCode()
            );
            $this->assertSame(expected: 403, actual: $e->getHttpStatus());
            $this->assertSame(
                expected: 'Personal dashboards are not enabled by your administrator',
                actual: $e->getMessage()
            );
        }
    }//end testExceptionEnvelopeShape()

    /**
     * REQ-ASET-003: When flag is true, assertPersonalDashboardsAllowed()
     * MUST pass without throwing.
     *
     * @return void
     */
    public function testAssertPassesWhenFlagIsOn(): void
    {
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_ALLOW_USER_DASHBOARDS, false)
            ->willReturn(true);

        // Must not throw.
        $this->service->assertPersonalDashboardsAllowed();
        $this->assertTrue(condition: true);
    }//end testAssertPassesWhenFlagIsOn()

    /**
     * REQ-ASET-003: Default value (no row in DB) MUST block creation.
     * The default argument passed to getValue() MUST be false.
     *
     * @return void
     */
    public function testDefaultValueBlocksCreation(): void
    {
        // Simulate missing row: getValue returns the default arg value.
        $this->settingMapper->method('getValue')
            ->willReturnCallback(
                static function (string $key, mixed $default): mixed {
                    // No persisted value — return whatever default was
                    // passed in. We assert the default is false.
                    return $default;
                }
            );

        $this->expectException(PersonalDashboardsDisabledException::class);

        $this->service->assertPersonalDashboardsAllowed();
    }//end testDefaultValueBlocksCreation()

    /**
     * REQ-ASET-003 scenario: existing personal dashboards remain readable
     * when flag is off. getUserDashboards() MUST NOT call the assert and
     * MUST reach the mapper.
     *
     * @return void
     */
    public function testExistingDashboardsRemainReadableWhenFlagOff(): void
    {
        // Flag is off — but getUserDashboards must NOT consult it.
        $this->settingMapper->expects($this->never())
            ->method('getValue');

        $dashboard = new Dashboard();
        $dashboard->setUserId('alice');
        $dashboard->setName('My Dashboard');

        $this->dashboardMapper->expects($this->once())
            ->method('findByUserId')
            ->with('alice')
            ->willReturn([$dashboard]);

        $result = $this->service->getUserDashboards(userId: 'alice');

        $this->assertCount(expectedCount: 1, haystack: $result);
        $this->assertSame(expected: 'alice', actual: $result[0]->getUserId());
    }//end testExistingDashboardsRemainReadableWhenFlagOff()

    /**
     * REQ-ASET-003: updateDashboard() MUST NOT call
     * assertPersonalDashboardsAllowed(). Existing personal dashboards
     * must remain editable when flag off.
     *
     * @return void
     */
    public function testExistingDashboardsRemainEditableWhenFlagOff(): void
    {
        // Flag is off — settingMapper should never be called for updates.
        $this->settingMapper->expects($this->never())
            ->method('getValue');

        $dashboard = new Dashboard();
        $dashboard->setId(1);
        $dashboard->setUserId('alice');
        $dashboard->setName('Original');

        $this->dashboardMapper->method('find')
            ->with(1)
            ->willReturn($dashboard);
        $this->dashboardMapper->method('update')
            ->willReturnArgument(0);

        $result = $this->service->updateDashboard(
            dashboardId: 1,
            userId: 'alice',
            data: ['name' => 'Renamed']
        );

        $this->assertSame(expected: 'Renamed', actual: $result->getName());
    }//end testExistingDashboardsRemainEditableWhenFlagOff()

    /**
     * REQ-ASET-003: deleteDashboard() MUST NOT call
     * assertPersonalDashboardsAllowed(). Users can still clean up
     * their personal dashboards when flag is off.
     *
     * @return void
     */
    public function testExistingDashboardsRemainsDeleteableWhenFlagOff(): void
    {
        // Flag is off — settingMapper should never be called for deletes.
        $this->settingMapper->expects($this->never())
            ->method('getValue');

        $dashboard = new Dashboard();
        $dashboard->setId(5);
        $dashboard->setUserId('alice');

        $this->dashboardMapper->method('find')
            ->with(5)
            ->willReturn($dashboard);
        $this->placementMapper->expects($this->once())
            ->method('deleteByDashboardId')
            ->with(5);
        $this->dashboardMapper->expects($this->once())
            ->method('delete');

        $this->service->deleteDashboard(dashboardId: 5, userId: 'alice');
    }//end testExistingDashboardsRemainsDeleteableWhenFlagOff()

    /**
     * REQ-ASET-003 scenario: toggling does not mutate data.
     * Verify that assertPersonalDashboardsAllowed() itself performs NO
     * DB write operations — it is read-only.
     *
     * @return void
     */
    public function testTogglingFlagDoesNotMutateData(): void
    {
        // The assert only reads — no inserts, updates, or deletes.
        $this->dashboardMapper->expects($this->never())->method('insert');
        $this->dashboardMapper->expects($this->never())->method('update');
        $this->dashboardMapper->expects($this->never())->method('delete');
        $this->placementMapper->expects($this->never())->method('insert');
        $this->placementMapper->expects($this->never())->method('update');
        $this->placementMapper->expects($this->never())->method('delete');

        // Consecutive calls: first returns false (expect throw), then true (no throw).
        $this->settingMapper->method('getValue')
            ->willReturnOnConsecutiveCalls(false, true);

        try {
            $this->service->assertPersonalDashboardsAllowed();
        } catch (PersonalDashboardsDisabledException) {
            // Expected — no writes should have occurred.
        }

        // Second call: flag=true (no throw — still no writes).
        $this->service->assertPersonalDashboardsAllowed();
    }//end testTogglingFlagDoesNotMutateData()
}//end class
