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
     * @param MenuService      $menuService      Validator for `menu` widgets (REQ-MENU-002).
     */
    public function __construct(
        private readonly IManager $dashboardManager,
        private readonly PlacementService $placementService,
        private readonly WidgetFormatter $widgetFormatter,
        private readonly WidgetItemLoader $widgetItemLoader,
        private readonly IUserSession $userSession,
        private readonly MenuService $menuService=new MenuService(),
    ) {
    }//end __construct()

    /**
     * Validate the `content` blob of a widget placement before save.
     *
     * REQ-MENU-002 server-side hook. Currently only the `menu` widget type
     * has a server-side validator; any future widget that needs save-time
     * validation should add its own branch here so the dispatcher stays
     * single-responsibility.
     *
     * @param string $widgetType Widget type identifier (e.g. `menu`).
     * @param array  $content    Widget content blob.
     *
     * @return void
     * @throws \InvalidArgumentException When the content blob is invalid.
     *
     * @spec openspec/specs/widgets/spec.md
     */
    public function validateWidgetContent(string $widgetType, array $content): void
    {
        if ($widgetType !== 'menu') {
            return;
        }

        $items = [];
        if (isset($content['items']) === true && is_array($content['items']) === true) {
            $items = $content['items'];
        }

        $this->menuService->validateMenuConfig(content: $content);
        $this->menuService->validateMenuItems(items: $items);
    }//end validateWidgetContent()

    /**
     * Get all available widgets from Nextcloud.
     *
     * @return array The list of available widgets.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-32
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-33
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
     * @param int        $dashboardId Dashboard ID.
     * @param string     $widgetId    Widget ID.
     * @param int        $gridX       Grid X position.
     * @param int        $gridY       Grid Y position.
     * @param int        $gridWidth   Grid width.
     * @param int        $gridHeight  Grid height.
     * @param array|null $content     Optional per-type content payload for
     *                                registry-driven custom widgets.
     *
     * @return WidgetPlacement The created widget placement.
     *
     * @spec openspec/specs/widgets/spec.md
     */
    public function addWidget(
        int $dashboardId,
        string $widgetId,
        int $gridX=0,
        int $gridY=0,
        int $gridWidth=4,
        int $gridHeight=4,
        ?array $content=null
    ): WidgetPlacement {
        if ($content !== null) {
            $this->validateWidgetContent(
                widgetType: $widgetId,
                content: $content
            );
        }

        return $this->placementService->addWidget(
            dashboardId: $dashboardId,
            widgetId: $widgetId,
            gridX: $gridX,
            gridY: $gridY,
            gridWidth: $gridWidth,
            gridHeight: $gridHeight,
            content: $content
        );
    }//end addWidget()

    /**
     * Add a tile to a dashboard using an array of tile data.
     *
     * @param int   $dashboardId Dashboard ID.
     * @param array $tileData    Tile configuration data array.
     *
     * @return WidgetPlacement The created tile placement.
     *
     * @spec openspec/specs/widgets/spec.md
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
     *
     * @spec openspec/specs/widgets/spec.md
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
     *
     * @spec openspec/specs/widgets/spec.md
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
     *
     * @spec openspec/specs/widgets/spec.md
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
     *
     * @spec openspec/specs/widgets/spec.md
     */
    public function getDashboardPlacements(int $dashboardId): array
    {
        return $this->placementService->getDashboardPlacements(
            dashboardId: $dashboardId
        );
    }//end getDashboardPlacements()
}//end class
