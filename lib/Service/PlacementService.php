<?php

/**
 * PlacementService
 *
 * Service for managing widget placement CRUD operations.
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

use DateTime;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;

/**
 * Service for managing widget placement CRUD operations.
 */
class PlacementService
{
    /**
     * Constructor
     *
     * @param WidgetPlacementMapper $placementMapper  Widget placement mapper.
     * @param TileUpdater           $tileUpdater      Tile updater service.
     * @param PlacementUpdater      $placementUpdater Placement updater service.
     */
    public function __construct(
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly TileUpdater $tileUpdater,
        private readonly PlacementUpdater $placementUpdater,
    ) {
    }//end __construct()

    /**
     * Add a widget to a dashboard.
     *
     * @param int    $dashboardId Dashboard ID.
     * @param string $widgetId    Widget ID.
     * @param int    $gridX       Grid X position.
     * @param int    $gridY       Grid Y position.
     * @param int    $gridWidth   Grid width.
     * @param int    $gridHeight  Grid height.
     *
     * @return WidgetPlacement The created widget placement.
     */
    public function addWidget(
        int $dashboardId,
        string $widgetId,
        int $gridX=0,
        int $gridY=0,
        int $gridWidth=4,
        int $gridHeight=4
    ): WidgetPlacement {
        $placement = new WidgetPlacement();
        $now       = (new DateTime())->format('Y-m-d H:i:s');
        $placement->setDashboardId($dashboardId);
        $placement->setWidgetId($widgetId);
        $placement->setGridX($gridX);
        $placement->setGridY($gridY);
        $placement->setGridWidth($gridWidth);
        $placement->setGridHeight($gridHeight);
        $placement->setIsCompulsory(0);
        $placement->setIsVisible(1);
        $placement->setShowTitle(1);
        $placement->setCreatedAt($now);
        $placement->setUpdatedAt($now);

        return $this->placementMapper->insert($placement);
    }//end addWidget()

    /**
     * Add a tile to a dashboard using an array of tile data.
     *
     * @param int   $dashboardId Dashboard ID.
     * @param array $tileData    Tile configuration data array.
     *
     * @return WidgetPlacement The created tile placement.
     */
    public function addTileFromArray(
        int $dashboardId,
        array $tileData
    ): WidgetPlacement {
        $placement = new WidgetPlacement();
        $now       = (new DateTime())->format('Y-m-d H:i:s');
        $placement->setDashboardId($dashboardId);
        $placement->setWidgetId('tile-'.uniqid());
        $placement->setGridX($tileData['gridX'] ?? 0);
        $placement->setGridY($tileData['gridY'] ?? 0);
        $placement->setGridWidth($tileData['gridWidth'] ?? 2);
        $placement->setGridHeight(
            $tileData['gridHeight'] ?? 2
        );
        $placement->setIsCompulsory(0);
        $placement->setIsVisible(1);
        $placement->setShowTitle(1);

        $this->tileUpdater->applyTileConfig(
            $placement,
            $tileData
        );

        $placement->setCreatedAt($now);
        $placement->setUpdatedAt($now);

        return $this->placementMapper->insert($placement);
    }//end addTileFromArray()

    /**
     * Update a widget placement.
     *
     * @param int   $placementId The placement ID.
     * @param array $data        The data to update.
     *
     * @return WidgetPlacement The updated widget placement.
     */
    public function updatePlacement(
        int $placementId,
        array $data
    ): WidgetPlacement {
        $placement = $this->placementMapper->find($placementId);

        $this->placementUpdater->applyGridUpdates(
            $placement,
            $data
        );
        $this->placementUpdater->applyDisplayUpdates(
            $placement,
            $data
        );
        $this->tileUpdater->applyTileUpdates(
            $placement,
            $data
        );

        $placement->setUpdatedAt(
            (new DateTime())->format('Y-m-d H:i:s')
        );

        return $this->placementMapper->update($placement);
    }//end updatePlacement()

    /**
     * Remove a widget placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return void
     */
    public function removePlacement(int $placementId): void
    {
        $placement = $this->placementMapper->find($placementId);
        $this->placementMapper->delete($placement);
    }//end removePlacement()

    /**
     * Get placement by ID.
     *
     * @param int $placementId The placement ID.
     *
     * @return WidgetPlacement The widget placement.
     */
    public function getPlacement(int $placementId): WidgetPlacement
    {
        return $this->placementMapper->find($placementId);
    }//end getPlacement()

    /**
     * Get all placements for a dashboard.
     *
     * @param int $dashboardId The dashboard ID.
     *
     * @return WidgetPlacement[] The list of placements.
     */
    public function getDashboardPlacements(int $dashboardId): array
    {
        return $this->placementMapper->findByDashboardId(
            $dashboardId
        );
    }//end getDashboardPlacements()
}//end class
