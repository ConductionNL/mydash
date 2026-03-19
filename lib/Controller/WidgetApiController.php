<?php

/**
 * WidgetApiController
 *
 * Controller for managing dashboard widgets.
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
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

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\WidgetService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for managing dashboard widgets.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) - ResponseHelper and RequestDataExtractor use static methods by design
 */
class WidgetApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest          $request           The request.
     * @param WidgetService     $widgetService     The widget service.
     * @param PermissionService $permissionService The permission service.
     * @param IL10N             $l10n              The localization service.
     * @param string|null       $userId            The user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly WidgetService $widgetService,
        private readonly PermissionService $permissionService,
        private readonly IL10N $l10n,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );

        ResponseHelper::setL10N($this->l10n);
    }//end __construct()

    /**
     * List all available Nextcloud widgets.
     *
     * @return JSONResponse The list of available widgets.
     */
    #[NoAdminRequired]
    public function listAvailable(): JSONResponse
    {
        return ResponseHelper::success(
            data: $this->widgetService->getAvailableWidgets()
        );
    }//end listAvailable()

    /**
     * Get widget items for specified widgets.
     *
     * @param array $widgets Array of widget IDs.
     * @param int   $limit   Maximum items per widget.
     *
     * @return JSONResponse The widget items.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getItems(
        array $widgets=[],
        int $limit=7
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        return ResponseHelper::success(
            data: $this->widgetService->getWidgetItems(
                userId: $this->userId,
                widgetIds: $widgets,
                limit: $limit
            )
        );
    }//end getItems()

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
     * @return JSONResponse The created widget placement.
     */
    #[NoAdminRequired]
    public function addWidget(
        int $dashboardId,
        string $widgetId,
        int $gridX=0,
        int $gridY=0,
        int $gridWidth=4,
        int $gridHeight=4
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->permissionService->canAddWidget(
            userId: $this->userId,
            dashboardId: $dashboardId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $placement = $this->widgetService->addWidget(
                dashboardId: $dashboardId,
                widgetId: $widgetId,
                gridX: $gridX,
                gridY: $gridY,
                gridWidth: $gridWidth,
                gridHeight: $gridHeight
            );

            return ResponseHelper::success(
                data: $placement->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end addWidget()

    /**
     * Add a tile to a dashboard.
     *
     * @param int $dashboardId Dashboard ID.
     *
     * @return JSONResponse The created tile placement.
     */
    #[NoAdminRequired]
    public function addTile(int $dashboardId): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->permissionService->canAddWidget(
            userId: $this->userId,
            dashboardId: $dashboardId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $placement = $this->widgetService->addTileFromArray(
                dashboardId: $dashboardId,
                tileData: RequestDataExtractor::extractTileData(
                    request: $this->request,
                    l10n: $this->l10n
                )
            );

            return ResponseHelper::success(
                data: $placement->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end addTile()

    /**
     * Update a widget placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return JSONResponse The updated widget placement.
     */
    #[NoAdminRequired]
    public function updatePlacement(int $placementId): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->permissionService->canStyleWidget(
            userId: $this->userId,
            placementId: $placementId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $placement = $this->widgetService->updatePlacement(
                placementId: $placementId,
                data: RequestDataExtractor::extractPlacementData(
                    request: $this->request
                )
            );

            return ResponseHelper::success(
                data: $placement->jsonSerialize()
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end updatePlacement()

    /**
     * Remove a widget placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return JSONResponse The removal confirmation.
     */
    #[NoAdminRequired]
    public function removePlacement(int $placementId): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->permissionService->canRemoveWidget(
            userId: $this->userId,
            placementId: $placementId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $this->widgetService->removePlacement(
                placementId: $placementId
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end removePlacement()
}//end class
