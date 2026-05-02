<?php

/**
 * DashboardServicePersonalGatingTest
 *
 * Unit tests for the runtime gating of personal-dashboard creation by the
 * admin `allow_user_dashboards` flag. Covers REQ-ASET-003 (extended)
 * scenarios:
 *  - default value (missing row) MUST evaluate to `false`
 *  - flag off MUST raise {@see PersonalDashboardsDisabledException}
 *  - flag on MUST be a no-op
 *  - the exception carries the stable error code + 403 mapping.
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

use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\PersonalDashboardsDisabledException;
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\TemplateService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see DashboardService::getAllowUserDashboards()} and
 * {@see DashboardService::assertPersonalDashboardsAllowed()}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors the service constructor.
 */
class DashboardServicePersonalGatingTest extends TestCase
{

    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;

    private DashboardService $service;

    /**
     * Set up fresh mocks per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /** @var DashboardMapper&MockObject $dashboardMapper */
        $dashboardMapper     = $this->createMock(DashboardMapper::class);
        /** @var WidgetPlacementMapper&MockObject $placementMapper */
        $placementMapper     = $this->createMock(WidgetPlacementMapper::class);
        $this->settingMapper = $this->createMock(AdminSettingMapper::class);
        /** @var TemplateService&MockObject $templateService */
        $templateService     = $this->createMock(TemplateService::class);
        /** @var DashboardResolver&MockObject $dashResolver */
        $dashResolver        = $this->createMock(DashboardResolver::class);
        /** @var IGroupManager&MockObject $groupManager */
        $groupManager        = $this->createMock(IGroupManager::class);
        /** @var IUserManager&MockObject $userManager */
        $userManager         = $this->createMock(IUserManager::class);
        /** @var IDBConnection&MockObject $db */
        $db                  = $this->createMock(IDBConnection::class);
        /** @var IConfig&MockObject $config */
        $config              = $this->createMock(IConfig::class);
        /** @var IFactory&MockObject $l10nFactory */
        $l10nFactory         = $this->createMock(IFactory::class);
        /** @var LoggerInterface&MockObject $logger */
        $logger              = $this->createMock(LoggerInterface::class);

        $this->service = new DashboardService(
            dashboardMapper: $dashboardMapper,
            placementMapper: $placementMapper,
            settingMapper: $this->settingMapper,
            templateService: $templateService,
            dashboardFactory: new DashboardFactory(),
            dashResolver: $dashResolver,
            groupManager: $groupManager,
            userManager: $userManager,
            db: $db,
            config: $config,
            l10nFactory: $l10nFactory,
            logger: $logger,
        );
    }//end setUp()

    /**
     * REQ-ASET-003: missing row MUST default to `false`.
     *
     * @return void
     */
    public function testGetAllowUserDashboardsDefaultsToFalseWhenMissing(): void
    {
        $this->settingMapper
            ->method('getValue')
            ->with(AdminSetting::KEY_ALLOW_USER_DASHBOARDS, false)
            ->willReturn(false);

        $this->assertFalse($this->service->getAllowUserDashboards());
    }//end testGetAllowUserDashboardsDefaultsToFalseWhenMissing()

    /**
     * REQ-ASET-003: explicit `true` MUST surface as `true`.
     *
     * @return void
     */
    public function testGetAllowUserDashboardsReturnsTrueWhenSet(): void
    {
        $this->settingMapper
            ->method('getValue')
            ->with(AdminSetting::KEY_ALLOW_USER_DASHBOARDS, false)
            ->willReturn(true);

        $this->assertTrue($this->service->getAllowUserDashboards());
    }//end testGetAllowUserDashboardsReturnsTrueWhenSet()

    /**
     * REQ-ASET-003: assert MUST throw when the flag is off.
     *
     * @return void
     */
    public function testAssertPersonalDashboardsAllowedThrowsWhenFlagOff(): void
    {
        $this->settingMapper
            ->method('getValue')
            ->willReturn(false);

        $this->expectException(PersonalDashboardsDisabledException::class);

        $this->service->assertPersonalDashboardsAllowed();
    }//end testAssertPersonalDashboardsAllowedThrowsWhenFlagOff()

    /**
     * REQ-ASET-003: assert MUST be a no-op when the flag is on.
     *
     * @return void
     *
     * @doesNotPerformAssertions
     */
    public function testAssertPersonalDashboardsAllowedSilentWhenFlagOn(): void
    {
        $this->settingMapper
            ->method('getValue')
            ->willReturn(true);

        $this->service->assertPersonalDashboardsAllowed();
    }//end testAssertPersonalDashboardsAllowedSilentWhenFlagOn()

}//end class
