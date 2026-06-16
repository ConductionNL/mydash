<?php

/**
 * DashboardService Create-Defaults Test.
 *
 * Covers the default-widget seed bundle that fires on every
 * user-initiated dashboard creation: three preconfigured `tile`
 * widgets (Conduction / Sendent / Nextcloud) on the top row plus a
 * `files` widget below.
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

use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\DashboardTreeService;
use OCA\MyDash\Service\FooterService;
use OCA\MyDash\Service\TemplateService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the user-initiated default-widget seeding path.
 */
class DashboardServiceCreateDefaultsTest extends TestCase
{

    /**
     * @var DashboardMapper&MockObject
     */
    private $dashboardMapper;

    /**
     * @var WidgetPlacementMapper&MockObject
     */
    private $placementMapper;

    /**
     * @var DashboardFactory&MockObject
     */
    private $dashboardFactory;

    /**
     * @var DashboardTreeService&MockObject
     */
    private $treeService;

    private DashboardService $service;

    protected function setUp(): void
    {
        $this->dashboardMapper  = $this->createMock(DashboardMapper::class);
        $this->placementMapper  = $this->createMock(WidgetPlacementMapper::class);
        $this->dashboardFactory = $this->createMock(DashboardFactory::class);
        $this->treeService      = $this->createMock(DashboardTreeService::class);

        $this->service = new DashboardService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            settingMapper: $this->createMock(AdminSettingMapper::class),
            templateService: $this->createMock(TemplateService::class),
            dashboardFactory: $this->dashboardFactory,
            dashResolver: $this->createMock(DashboardResolver::class),
            treeService: $this->treeService,
            groupManager: $this->createMock(IGroupManager::class),
            adminTemplateService: $this->createMock(AdminTemplateService::class),
            db: $this->createMock(IDBConnection::class),
            config: $this->createMock(IConfig::class),
            l10nFactory: $this->createMock(IFactory::class),
            logger: $this->createMock(LoggerInterface::class),
            footerService: $this->createMock(FooterService::class),
        );
    }//end setUp()

    /**
     * Build a fresh Dashboard stub the factory/mapper can hand back.
     *
     * @return Dashboard
     */
    private function makeDashboard(): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->setId(42);
        $dashboard->setUserId('alice');
        $dashboard->setName('My Dashboard');
        return $dashboard;
    }//end makeDashboard()

    /**
     * `createDashboard(seedDefaults: true)` MUST insert four placements:
     * three preconfigured `tile` widgets on the top row plus a `files`
     * widget spanning the second row.
     *
     * @return void
     */
    public function testSeedDefaultsInsertsThreeTilesAndFilesWidget(): void
    {
        $dashboard = $this->makeDashboard();

        $this->dashboardFactory->expects($this->once())
            ->method('create')
            ->willReturn($dashboard);
        $this->dashboardMapper->expects($this->once())
            ->method('insert')
            ->willReturn($dashboard);

        $captured = [];
        $this->placementMapper->expects($this->exactly(4))
            ->method('insert')
            ->willReturnCallback(
                    static function (WidgetPlacement $placement) use (&$captured) {
                        $captured[] = $placement;
                        return $placement;
                    }
                    );

        $this->service->createDashboard(
            userId: 'alice',
            name: 'My Dashboard',
            seedDefaults: true
        );

        $this->assertCount(4, $captured);

        // Three tiles on the top row, then files spanning row 2.
        // `tileType='preset'` is required so `WidgetPlacement::jsonSerialize`
        // emits the flat tile* fields the frontend renderer reads.
        $this->assertSame('tile', $captured[0]->getWidgetId());
        $this->assertSame('preset', $captured[0]->getTileType());
        $this->assertSame('Conduction', $captured[0]->getTileTitle());
        $this->assertSame('https://conduction.nl', $captured[0]->getTileLinkValue());
        $this->assertSame(0, $captured[0]->getGridX());
        $this->assertSame(0, $captured[0]->getGridY());
        $this->assertSame(4, $captured[0]->getGridWidth());

        $this->assertSame('tile', $captured[1]->getWidgetId());
        $this->assertSame('preset', $captured[1]->getTileType());
        $this->assertSame('Sendent', $captured[1]->getTileTitle());
        $this->assertSame('https://sendent.com', $captured[1]->getTileLinkValue());
        $this->assertSame(4, $captured[1]->getGridX());

        $this->assertSame('tile', $captured[2]->getWidgetId());
        $this->assertSame('preset', $captured[2]->getTileType());
        $this->assertSame('Nextcloud', $captured[2]->getTileTitle());
        $this->assertSame('icon-nextcloud', $captured[2]->getTileIcon());
        $this->assertSame('class', $captured[2]->getTileIconType());
        $this->assertSame(8, $captured[2]->getGridX());

        $this->assertSame('files', $captured[3]->getWidgetId());
        $this->assertNull($captured[3]->getTileType());
        $this->assertSame(0, $captured[3]->getGridX());
        $this->assertSame(3, $captured[3]->getGridY());
        $this->assertSame(12, $captured[3]->getGridWidth());
        $this->assertSame(5, $captured[3]->getGridHeight());
    }//end testSeedDefaultsInsertsThreeTilesAndFilesWidget()

    /**
     * Default behaviour (`seedDefaults` omitted / false) MUST NOT insert
     * any widget placements — only the bootstrap path and the controller
     * opt into the seed.
     *
     * @return void
     */
    public function testCreateWithoutSeedFlagInsertsNoPlacements(): void
    {
        $dashboard = $this->makeDashboard();

        $this->dashboardFactory->expects($this->once())
            ->method('create')
            ->willReturn($dashboard);
        $this->dashboardMapper->expects($this->once())
            ->method('insert')
            ->willReturn($dashboard);

        $this->placementMapper->expects($this->never())
            ->method('insert');

        $this->service->createDashboard(
            userId: 'alice',
            name: 'My Dashboard'
        );
    }//end testCreateWithoutSeedFlagInsertsNoPlacements()
}//end class
