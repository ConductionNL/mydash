<?php

/**
 * DashboardSeeder
 *
 * Seeds newly created dashboards with the standard
 * Nextcloud / Conduction / Sendent tiles.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCA\MyDash\AppInfo\Application;
use OCP\IURLGenerator;

/**
 * Seeds a fresh dashboard with the standard set of company tiles.
 *
 * Layout: stacked at the right edge, 3 columns wide × 1 row tall, placed
 * below the default top-row widgets (rows 5..7). Tiles are not compulsory
 * so users can delete them.
 */
class DashboardSeeder
{

    /**
     * Tile definitions seeded onto every newly created dashboard.
     *
     * Icon paths are leaf filenames inside img/. The absolute URL is
     * resolved at seed time via IURLGenerator so we work across dev
     * (`/custom_apps/...`) and prod (`/apps/...`) layouts.
     */
    private const SEED_TILES = [
        [
            'title'      => 'Nextcloud',
            'iconFile'   => 'seed-tiles/nextcloud.svg',
            'bgColor'    => '#0082c9',
            'txtColor'   => '#ffffff',
            'linkVal'    => 'https://nextcloud.com',
            'gridX'      => 9,
            'gridY'      => 5,
            'gridWidth'  => 3,
            'gridHeight' => 1,
        ],
        [
            'title'      => 'Conduction',
            'iconFile'   => 'seed-tiles/conduction.png',
            'bgColor'    => '#ffffff',
            'txtColor'   => '#1a1a1a',
            'linkVal'    => 'https://conduction.nl',
            'gridX'      => 9,
            'gridY'      => 6,
            'gridWidth'  => 3,
            'gridHeight' => 1,
        ],
        [
            'title'      => 'Sendent',
            'iconFile'   => 'seed-tiles/sendent.png',
            'bgColor'    => '#ffffff',
            'txtColor'   => '#1a1a1a',
            'linkVal'    => 'https://sendent.com',
            'gridX'      => 9,
            'gridY'      => 7,
            'gridWidth'  => 3,
            'gridHeight' => 1,
        ],
    ];

    /**
     * Constructor
     *
     * @param PlacementService $placementService Placement service for adding tiles.
     * @param IURLGenerator    $urlGenerator     URL generator for icon paths.
     */
    public function __construct(
        private readonly PlacementService $placementService,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Seed the standard tile set onto a dashboard.
     *
     * @param int $dashboardId The dashboard ID.
     *
     * @return void
     */
    public function seed(int $dashboardId): void
    {
        foreach (self::SEED_TILES as $tile) {
            $tile['icon']     = $this->urlGenerator->imagePath(
                appName: Application::APP_ID,
                file: $tile['iconFile']
            );
            $tile['iconType'] = 'url';
            $tile['linkType'] = 'url';
            unset($tile['iconFile']);

            $this->placementService->addTileFromArray(
                dashboardId: $dashboardId,
                tileData: $tile
            );
        }
    }//end seed()
}//end class
