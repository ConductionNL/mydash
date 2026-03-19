<?php

/**
 * DashboardApiController
 *
 * Controller for dashboard API endpoints.
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
use OCA\MyDash\Service\DashboardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for dashboard API endpoints.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) - ResponseHelper uses static methods by design
 */
class DashboardApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest                  $request          The request.
     * @param DashboardService          $dashboardService The dashboard service.
     * @param DashboardRequestValidator $validator        The request validator.
     * @param IL10N                     $l10n             The localization service.
     * @param string|null               $userId           The user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly DashboardService $dashboardService,
        private readonly DashboardRequestValidator $validator,
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
     * List all dashboards for the current user.
     *
     * @return JSONResponse The list of dashboards.
     */
    #[NoAdminRequired]
    public function list(): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $dashboards = $this->dashboardService->getUserDashboards(
            userId: $this->userId
        );

        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $dashboards)
        );
    }//end list()

    /**
     * Get the user's active dashboard with placements.
     *
     * @return JSONResponse The active dashboard data.
     */
    #[NoAdminRequired]
    public function getActive(): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $result = $this->dashboardService->getEffectiveDashboard(
            userId: $this->userId
        );

        if ($result === null) {
            return ResponseHelper::success(
                data: ['error' => $this->l10n->t('No dashboard available')],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return ResponseHelper::success(
            data: [
                'dashboard'       => $result['dashboard']->jsonSerialize(),
                'placements'      => ResponseHelper::serializeList(
                    entities: $result['placements']
                ),
                'permissionLevel' => $result['permissionLevel'],
            ]
        );
    }//end getActive()

    /**
     * Create a new dashboard.
     *
     * @param mixed       $name        The dashboard name.
     * @param string|null $description The description.
     *
     * @return JSONResponse The created dashboard.
     */
    #[NoAdminRequired]
    public function create(
        $name=null,
        ?string $description=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $resolved = $this->validator->resolveCreateParams(
            name: $name,
            description: $description
        );

        $permError = $this->validator->checkCreatePermissions(
            userId: $this->userId
        );
        if ($permError !== null) {
            return $permError;
        }

        try {
            $dashboard = $this->dashboardService->createDashboard(
                userId: $this->userId,
                name: $resolved['name'],
                description: $resolved['description']
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()],
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end create()

    /**
     * Update a dashboard.
     *
     * @param int         $id          The dashboard ID.
     * @param string|null $name        The name.
     * @param string|null $description The description.
     * @param array|null  $placements  The placements.
     *
     * @return JSONResponse The updated dashboard.
     */
    #[NoAdminRequired]
    public function update(
        int $id,
        ?string $name=null,
        ?string $description=null,
        ?array $placements=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $permError = $this->validator->checkUpdatePermissions(
            userId: $this->userId,
            dashboardId: $id,
            placements: $placements
        );
        if ($permError !== null) {
            return $permError;
        }

        try {
            $data = $this->validator->buildUpdateData(
                name: $name,
                description: $description,
                placements: $placements
            );

            $dashboard = $this->dashboardService->updateDashboard(
                dashboardId: $id,
                userId: $this->userId,
                data: $data
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()]
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end update()

    /**
     * Delete a dashboard.
     *
     * @param int $id The dashboard ID.
     *
     * @return JSONResponse The deletion confirmation.
     */
    #[NoAdminRequired]
    public function delete(int $id): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $this->dashboardService->deleteDashboard(
                dashboardId: $id,
                userId: $this->userId
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end delete()

    /**
     * Activate a dashboard.
     *
     * @param int $id The dashboard ID.
     *
     * @return JSONResponse The activated dashboard.
     */
    #[NoAdminRequired]
    public function activate(int $id): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardService->activateDashboard(
                dashboardId: $id,
                userId: $this->userId
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()]
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end activate()
}//end class
