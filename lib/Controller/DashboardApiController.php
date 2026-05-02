<?php

/**
 * DashboardApiController
 *
 * Controller for dashboard API endpoints — personal scope, group-shared
 * scope (REQ-DASH-014), and the visible-to-user resolution endpoint
 * (REQ-DASH-013).
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

use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Exception\PersonalDashboardsDisabledException;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for dashboard API endpoints.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class DashboardApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest          $request           The request.
     * @param DashboardService  $dashboardService  The dashboard service.
     * @param PermissionService $permissionService The permission service.
     * @param string|null       $userId            The user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly DashboardService $dashboardService,
        private readonly PermissionService $permissionService,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * List all personal dashboards for the current user.
     *
     * Backward compatible — this endpoint never returns group-shared
     * dashboards (REQ-DASH-014). Use {@see self::visible()} for the
     * unioned listing.
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
     * List the deduplicated union of dashboards visible to the user.
     *
     * Returns personal + group-matching + default-group dashboards, each
     * tagged with `source` (`'user'`, `'group'`, `'default'`).
     * REQ-DASH-013.
     *
     * @return JSONResponse The visible dashboards.
     */
    #[NoAdminRequired]
    public function visible(): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $items = $this->dashboardService->getVisibleToUser(
            userId: $this->userId
        );

        $serialized = [];
        foreach ($items as $entry) {
            $row           = $entry['dashboard']->jsonSerialize();
            $row['source'] = $entry['source'];
            $serialized[]  = $row;
        }

        return ResponseHelper::success(data: $serialized);
    }//end visible()

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
                data: ['error' => 'No dashboard available'],
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

        // REQ-ASET-003 (extended): admin gating runs FIRST so the response
        // envelope is the stable `personal_dashboards_disabled` shape no
        // matter what the request body looked like.
        try {
            $this->dashboardService->assertPersonalDashboardsAllowed();
        } catch (PersonalDashboardsDisabledException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $resolved = $this->resolveCreateParams(
            name: $name,
            description: $description
        );

        $permError = $this->checkCreatePermissions(
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

        // REQ-PERM-007: Metadata-only updates (name, description) are allowed
        // for all permission levels. Widget/tile/layout changes require
        // add_only or full permission.
        $isMetadataOnly = $placements === null;
        if ($isMetadataOnly === true) {
            if ($this->permissionService->canEditDashboardMetadata(
                userId: $this->userId,
                dashboardId: $id
            ) === false
            ) {
                return ResponseHelper::forbidden();
            }
        } else {
            if ($this->permissionService->canEditDashboard(
                userId: $this->userId,
                dashboardId: $id
            ) === false
            ) {
                return ResponseHelper::forbidden();
            }
        }

        try {
            $data = $this->buildUpdateData(
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

    /**
     * List the group-shared dashboards in a single group.
     *
     * Any logged-in user may list. REQ-DASH-014.
     *
     * @param string $groupId The group ID.
     *
     * @return JSONResponse The list of group-shared dashboards.
     */
    #[NoAdminRequired]
    public function listGroup(string $groupId): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $dashboards = $this->dashboardService->listGroupDashboards(
            groupId: $groupId
        );

        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $dashboards)
        );
    }//end listGroup()

    /**
     * Create a new group-shared dashboard.
     *
     * Admin-only — the route attribute is `#[NoAdminRequired]` so the
     * gate-route-auth check passes; the in-body admin check is the
     * actual authorization point (gate-semantic-auth). REQ-DASH-014.
     *
     * @param string      $groupId     The group ID.
     * @param mixed       $name        The dashboard name (or {name,...}
     *                                 dict as the body).
     * @param string|null $description The dashboard description.
     *
     * @return JSONResponse The created dashboard.
     */
    #[NoAdminRequired]
    public function createGroup(
        string $groupId,
        $name=null,
        ?string $description=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->dashboardService->isAdmin(
            userId: $this->userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: DashboardService::ERR_FORBIDDEN_NOT_ADMIN
            );
        }

        $resolved = $this->resolveCreateParams(
            name: $name,
            description: $description
        );

        try {
            $dashboard = $this->dashboardService->createGroupShared(
                actorUserId: $this->userId,
                groupId: $groupId,
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
    }//end createGroup()

    /**
     * Get a single group-shared dashboard with placements.
     *
     * @param string $groupId The group ID from the URL.
     * @param string $uuid    The dashboard UUID from the URL.
     *
     * @return JSONResponse The dashboard payload.
     */
    #[NoAdminRequired]
    public function getGroup(
        string $groupId,
        string $uuid
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardService->findGroupDashboard(
                groupId: $groupId,
                uuid: $uuid
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return ResponseHelper::success(
            data: ['dashboard' => $dashboard->jsonSerialize()]
        );
    }//end getGroup()

    /**
     * Update a group-shared dashboard. Admin-only.
     *
     * @param string      $groupId     The group ID from the URL.
     * @param string      $uuid        The dashboard UUID from the URL.
     * @param string|null $name        The new name.
     * @param string|null $description The new description.
     * @param int|null    $gridColumns The new grid column count.
     * @param array|null  $placements  Updated placements.
     *
     * @return JSONResponse The updated dashboard.
     */
    #[NoAdminRequired]
    public function updateGroup(
        string $groupId,
        string $uuid,
        ?string $name=null,
        ?string $description=null,
        ?int $gridColumns=null,
        ?array $placements=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->dashboardService->isAdmin(
            userId: $this->userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: DashboardService::ERR_FORBIDDEN_NOT_ADMIN
            );
        }

        $patch = $this->buildGroupUpdateData(
            name: $name,
            description: $description,
            gridColumns: $gridColumns,
            placements: $placements
        );

        try {
            $dashboard = $this->dashboardService->updateGroupShared(
                actorUserId: $this->userId,
                groupId: $groupId,
                uuid: $uuid,
                patch: $patch
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()]
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end updateGroup()

    /**
     * Delete a group-shared dashboard. Admin-only.
     *
     * Returns HTTP 400 when the last-in-group guard rejects the delete
     * (REQ-DASH-014).
     *
     * @param string $groupId The group ID from the URL.
     * @param string $uuid    The dashboard UUID from the URL.
     *
     * @return JSONResponse The status payload.
     */
    #[NoAdminRequired]
    public function deleteGroup(
        string $groupId,
        string $uuid
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->dashboardService->isAdmin(
            userId: $this->userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: DashboardService::ERR_FORBIDDEN_NOT_ADMIN
            );
        }

        try {
            $this->dashboardService->deleteGroupShared(
                actorUserId: $this->userId,
                groupId: $groupId,
                uuid: $uuid
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end deleteGroup()

    /**
     * Promote a single group-shared dashboard to the group's default.
     *
     * Admin-only — the route attribute is `#[NoAdminRequired]` so
     * gate-route-auth passes; the in-body admin check is the actual
     * authorization point (gate-semantic-auth). The body payload is
     * `{"uuid": "..."}`. Returns 404 when the uuid does not belong to
     * the given groupId. REQ-DASH-015.
     *
     * @param string      $groupId The group ID from the URL.
     * @param string|null $uuid    The dashboard UUID from the body.
     *
     * @return JSONResponse The status payload.
     */
    #[NoAdminRequired]
    public function setGroupDefault(
        string $groupId,
        ?string $uuid=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->dashboardService->isAdmin(
            userId: $this->userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: DashboardService::ERR_FORBIDDEN_NOT_ADMIN
            );
        }

        if ($uuid === null || $uuid === '') {
            return ResponseHelper::error(
                exception: new InvalidArgumentException(
                    'Missing required field: uuid'
                ),
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->dashboardService->setGroupDefault(
                actorUserId: $this->userId,
                groupId: $groupId,
                uuid: $uuid
            );

            return ResponseHelper::success(
                data: [
                    'status'  => 'ok',
                    'groupId' => $groupId,
                    'uuid'    => $uuid,
                ]
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end setGroupDefault()

    /**
     * Persist the user's active-dashboard preference.
     *
     * Accepts any UUID string (including non-existent UUIDs — the resolver's
     * stale-pref path handles invalid values on next render). Empty string
     * clears the preference. REQ-DASH-019.
     *
     * @param string|null $uuid The dashboard UUID from the request body, or
     *                          empty string to clear.
     *
     * @return JSONResponse HTTP 200 `{status: 'success'}` on success; 401
     *                      when the session has no user.
     */
    #[NoAdminRequired]
    public function setActiveDashboard(?string $uuid=null): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $this->dashboardService->setActivePreference(
            userId: $this->userId,
            uuid: ($uuid ?? '')
        );

        return ResponseHelper::success(data: ['status' => 'success']);
    }//end setActiveDashboard()

    /**
     * Resolve create parameters from JSON body or individual params.
     *
     * @param mixed       $name        The name parameter.
     * @param string|null $description The description parameter.
     *
     * @return array The resolved name and description.
     */
    private function resolveCreateParams(
        $name,
        ?string $description
    ): array {
        if (is_array($name) === true) {
            return [
                'name'        => $name['name'] ?? 'My Dashboard',
                'description' => $name['description'] ?? null,
            ];
        }

        return [
            'name'        => $name ?? 'My Dashboard',
            'description' => $description,
        ];
    }//end resolveCreateParams()

    /**
     * Check creation permissions and return error if denied.
     *
     * @param string $userId The user ID.
     *
     * @return JSONResponse|null Error response or null if allowed.
     */
    private function checkCreatePermissions(string $userId): ?JSONResponse
    {
        if ($this->permissionService->canCreateDashboard(
            userId: $userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: 'Dashboard creation not allowed'
            );
        }

        $existing = $this->dashboardService->getUserDashboards(
            userId: $userId
        );
        if (empty($existing) === false
            && $this->permissionService->canHaveMultipleDashboards(
                userId: $userId
            ) === false
        ) {
            return ResponseHelper::forbidden(
                message: 'Multiple dashboards not allowed'
            );
        }

        return null;
    }//end checkCreatePermissions()

    /**
     * Build update data from nullable parameters.
     *
     * @param string|null $name        The name.
     * @param string|null $description The description.
     * @param array|null  $placements  The placements.
     *
     * @return array The non-null update data.
     */
    private function buildUpdateData(
        ?string $name,
        ?string $description,
        ?array $placements
    ): array {
        $fields = [
            'name'        => $name,
            'description' => $description,
            'placements'  => $placements,
        ];

        return array_filter(
            array: $fields,
            callback: function ($value) {
                return $value !== null;
            }
        );
    }//end buildUpdateData()

    /**
     * Build the patch payload for the group-shared update endpoint.
     *
     * @param string|null $name        The new name.
     * @param string|null $description The new description.
     * @param int|null    $gridColumns The new grid columns.
     * @param array|null  $placements  Updated placements.
     *
     * @return array The non-null patch fields.
     */
    private function buildGroupUpdateData(
        ?string $name,
        ?string $description,
        ?int $gridColumns,
        ?array $placements
    ): array {
        $fields = [
            'name'        => $name,
            'description' => $description,
            'gridColumns' => $gridColumns,
            'placements'  => $placements,
        ];

        return array_filter(
            array: $fields,
            callback: function ($value) {
                return $value !== null;
            }
        );
    }//end buildGroupUpdateData()
}//end class
