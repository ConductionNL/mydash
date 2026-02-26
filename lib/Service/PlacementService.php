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
        $now       = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $placement->setDashboardId(dashboardId: $dashboardId);
        $placement->setWidgetId(widgetId: $widgetId);
        $placement->setGridX(gridX: $gridX);
        $placement->setGridY(gridY: $gridY);
        $placement->setGridWidth(gridWidth: $gridWidth);
        $placement->setGridHeight(gridHeight: $gridHeight);
        $placement->setIsCompulsory(isCompulsory: 0);
        $placement->setIsVisible(isVisible: 1);
        $placement->setShowTitle(showTitle: 1);
        $placement->setCreatedAt(createdAt: $now);
        $placement->setUpdatedAt(updatedAt: $now);

        return $this->placementMapper->insert(entity: $placement);
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
        $now       = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $placement->setDashboardId(dashboardId: $dashboardId);
        $placement->setWidgetId(widgetId: 'tile-'.uniqid());
        $placement->setGridX(gridX: $tileData['gridX'] ?? 0);
        $placement->setGridY(gridY: $tileData['gridY'] ?? 0);
        $placement->setGridWidth(gridWidth: $tileData['gridWidth'] ?? 2);
        $placement->setGridHeight(
            gridHeight: $tileData['gridHeight'] ?? 2
        );
        $placement->setIsCompulsory(isCompulsory: 0);
        $placement->setIsVisible(isVisible: 1);
        $placement->setShowTitle(showTitle: 1);

        $this->tileUpdater->applyTileConfig(
            placement: $placement,
            tileData: $tileData
        );

        $placement->setCreatedAt(createdAt: $now);
        $placement->setUpdatedAt(updatedAt: $now);

        return $this->placementMapper->insert(entity: $placement);
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
        $placement = $this->placementMapper->find(id: $placementId);

        $this->placementUpdater->applyGridUpdates(
            placement: $placement,
            data: $data
        );
        $this->placementUpdater->applyDisplayUpdates(
            placement: $placement,
            data: $data
        );
        $this->tileUpdater->applyTileUpdates(
            placement: $placement,
            data: $data
        );

        $placement->setUpdatedAt(
            updatedAt: (new DateTime())->format(format: 'Y-m-d H:i:s')
        );

        return $this->placementMapper->update(entity: $placement);
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
        $placement = $this->placementMapper->find(id: $placementId);
        $this->placementMapper->delete(entity: $placement);
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
        return $this->placementMapper->find(id: $placementId);
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
            dashboardId: $dashboardId
        );
    }//end getDashboardPlacements()
}//end class
