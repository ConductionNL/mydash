<?php

/**
 * DashboardSeeder Test
 *
 * Verifies that the seeder produces the expected three-tile layout
 * (Nextcloud / Conduction / Sendent) at the right column, rows 5..7.
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

use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Service\DashboardSeeder;
use OCA\MyDash\Service\PlacementService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class DashboardSeederTest extends TestCase
{
    private array $captured = [];
    private DashboardSeeder $seeder;

    protected function setUp(): void
    {
        $this->captured = [];

        $placementService = $this->createMock(PlacementService::class);
        $placementService->method('addTileFromArray')->willReturnCallback(
            function (int $dashboardId, array $tileData): WidgetPlacement {
                $this->captured[] = ['dashboardId' => $dashboardId, 'tileData' => $tileData];
                return new WidgetPlacement();
            }
        );

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->willReturnCallback(
            static fn (string $appName, string $file): string => "/apps/$appName/img/$file"
        );

        $this->seeder = new DashboardSeeder($placementService, $urlGenerator);
    }

    public function testSeedsThreeTilesInOrder(): void
    {
        $this->seeder->seed(dashboardId: 99);

        $titles = array_column(array_column($this->captured, 'tileData'), 'title');
        $this->assertSame(['Nextcloud', 'Conduction', 'Sendent'], $titles);
    }

    public function testTilesHaveCorrectGridPositions(): void
    {
        $this->seeder->seed(dashboardId: 99);

        $tiles = array_column($this->captured, 'tileData');
        $this->assertSame(['gridX' => 9, 'gridY' => 5], ['gridX' => $tiles[0]['gridX'], 'gridY' => $tiles[0]['gridY']]);
        $this->assertSame(['gridX' => 9, 'gridY' => 6], ['gridX' => $tiles[1]['gridX'], 'gridY' => $tiles[1]['gridY']]);
        $this->assertSame(['gridX' => 9, 'gridY' => 7], ['gridX' => $tiles[2]['gridX'], 'gridY' => $tiles[2]['gridY']]);
        foreach ($tiles as $tile) {
            $this->assertSame(3, $tile['gridWidth']);
            $this->assertSame(1, $tile['gridHeight']);
        }
    }

    public function testTilesUseUrlIconType(): void
    {
        $this->seeder->seed(dashboardId: 99);

        foreach ($this->captured as $entry) {
            $tile = $entry['tileData'];
            $this->assertSame('url', $tile['iconType']);
            $this->assertSame('url', $tile['linkType']);
            $this->assertStringStartsWith('/apps/mydash/img/seed-tiles/', $tile['icon']);
            $this->assertArrayNotHasKey('iconFile', $tile);
        }
    }

    public function testTileLinksTargetCompanyHomepages(): void
    {
        $this->seeder->seed(dashboardId: 99);

        $links = array_column(array_column($this->captured, 'tileData'), 'linkVal');
        $this->assertSame(
            ['https://nextcloud.com', 'https://conduction.nl', 'https://sendent.com'],
            $links
        );
    }

    public function testSeederPassesDashboardIdThrough(): void
    {
        $this->seeder->seed(dashboardId: 1234);

        foreach ($this->captured as $entry) {
            $this->assertSame(1234, $entry['dashboardId']);
        }
    }
}
