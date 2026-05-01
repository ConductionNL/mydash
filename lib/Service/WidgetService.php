<?php

/**
 * WidgetService
 *
 * Service for discovering and querying Nextcloud dashboard widgets.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCA\MyDash\Db\WidgetPlacement;
use OCP\Dashboard\IManager;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\IUserSession;

/**
 * Service for discovering and querying Nextcloud dashboard widgets.
 */
class WidgetService
{
    /**
     * Constructor
     *
     * @param IManager         $dashboardManager Dashboard manager interface.
     * @param PlacementService $placementService Placement service for CRUD.
     * @param WidgetFormatter  $widgetFormatter  Widget formatter service.
     * @param WidgetItemLoader $widgetItemLoader Widget item loader service.
     * @param IUserSession     $userSession      User session interface.
     */
    public function __construct(
        private readonly IManager $dashboardManager,
        private readonly PlacementService $placementService,
        private readonly WidgetFormatter $widgetFormatter,
        private readonly WidgetItemLoader $widgetItemLoader,
        private readonly IUserSession $userSession,
    ) {
    }//end __construct()

    /**
     * Get all available widgets from Nextcloud.
     *
     * @return array The list of available widgets.
     */
    public function getAvailableWidgets(): array
    {
        $widgets = $this->dashboardManager->getWidgets();
        $result  = [];

        foreach ($widgets as $widget) {
            $user   = $this->userSession->getUser();
            $userId = '';
            if ($user !== null) {
                $userId = $user->getUID();
            }

            $result[] = $this->widgetFormatter->format(
                widget: $widget,
                userId: $userId
            );
        }

        usort(
            array: $result,
            callback: function ($a, $b) {
                return $a['order'] - $b['order'];
            }
        );

        return $result;
    }//end getAvailableWidgets()

    /**
     * Get widget items for multiple widgets.
     *
     * @param string $userId    The user ID.
     * @param array  $widgetIds The widget IDs.
     * @param int    $limit     Maximum number of items per widget.
     *
     * @return array The widget items.
     */
    public function getWidgetItems(
        string $userId,
        array $widgetIds,
        int $limit=7
    ): array {
        $widgets = $this->dashboardManager->getWidgets();

        return $this->widgetItemLoader->loadItems(
            widgets: $widgets,
            userId: $userId,
            widgetIds: $widgetIds,
            limit: $limit
        );
    }//end getWidgetItems()

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
        return $this->placementService->addWidget(
            dashboardId: $dashboardId,
            widgetId: $widgetId,
            gridX: $gridX,
            gridY: $gridY,
            gridWidth: $gridWidth,
            gridHeight: $gridHeight
        );
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
        return $this->placementService->addTileFromArray(
            dashboardId: $dashboardId,
            tileData: $tileData
        );
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
        return $this->placementService->updatePlacement(
            placementId: $placementId,
            data: $data
        );
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
        $this->placementService->removePlacement(
            placementId: $placementId
        );
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
        return $this->placementService->getPlacement(
            placementId: $placementId
        );
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
        return $this->placementService->getDashboardPlacements(
            dashboardId: $dashboardId
        );
    }//end getDashboardPlacements()
}//end class
