<?php

/**
 * DashboardService Seed Tiles Test
 *
 * Verifies that createDashboard() invokes the DashboardSeeder for the
 * newly persisted dashboard so every fresh dashboard ships with the
 * standard tile set.
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

use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardSeeder;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\TemplateService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DashboardServiceSeedTilesTest extends TestCase
{
    private DashboardMapper&MockObject $dashboardMapper;
    private DashboardSeeder&MockObject $dashboardSeeder;

    private DashboardService $service;

    protected function setUp(): void
    {
        $this->dashboardMapper = $this->createMock(DashboardMapper::class);
        $this->dashboardSeeder = $this->createMock(DashboardSeeder::class);

        $settingMapper = $this->createMock(AdminSettingMapper::class);
        $settingMapper->method('getValue')->willReturnCallback(
            static fn ($key, $default) => $default
        );

        $this->dashboardMapper->method('insert')->willReturnCallback(
            static function (Dashboard $dashboard): Dashboard {
                $dashboard->setId(42);
                return $dashboard;
            }
        );

        $this->service = new DashboardService(
            $this->dashboardMapper,
            $this->createMock(WidgetPlacementMapper::class),
            $settingMapper,
            $this->createMock(TemplateService::class),
            new DashboardFactory(),
            $this->createMock(DashboardResolver::class),
            $this->dashboardSeeder,
        );
    }

    public function testCreateDashboardInvokesSeederWithPersistedId(): void
    {
        $this->dashboardSeeder
            ->expects($this->once())
            ->method('seed')
            ->with(dashboardId: 42);

        $this->service->createDashboard(userId: 'alice', name: 'Test');
    }

    public function testCreateDashboardSeedsAfterPersistence(): void
    {
        $this->dashboardSeeder
            ->expects($this->once())
            ->method('seed');

        $dashboard = $this->service->createDashboard(userId: 'alice', name: 'Test');

        $this->assertSame(42, $dashboard->getId());
    }
}
