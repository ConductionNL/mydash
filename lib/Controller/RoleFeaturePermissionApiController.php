<?php

/**
 * RoleFeaturePermissionApiController
 *
 * Admin-only HTTP API for managing RoleFeaturePermission and
 * RoleLayoutDefault rows. All routes guard with the same `requireAdmin()`
 * pattern as `AdminController` (REQ-RFP-007 / ADR-005).
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\RoleFeaturePermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only API for role-feature permissions and role-layout defaults.
 *
 * @spec openspec/changes/role-based-content/tasks.md#task-3
 */
class RoleFeaturePermissionApiController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                     $request The HTTP request.
     * @param RoleFeaturePermissionService $service Permission service.
     */
    public function __construct(
        IRequest $request,
        private readonly RoleFeaturePermissionService $service,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * GET /api/role-feature-permissions
     *
     * @return JSONResponse The list of permission rows.
         *
     * @spec openspec/specs/admin-roles/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function listPermissions(): JSONResponse
    {
        $rows = $this->service->listPermissions();
        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $rows)
        );
    }//end listPermissions()

    /**
     * POST /api/role-feature-permissions
     *
     * Body: `{name, description?, groupId, allowedWidgets, deniedWidgets?, priorityWeights?}`.
     * Upsert keyed by `groupId`.
     *
     * @return JSONResponse The persisted row.
         *
     * @spec openspec/specs/admin-roles/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function savePermission(): JSONResponse
    {
        try {
            $payload = $this->readJsonBody();
            $entity  = $this->service->savePermission(data: $payload);
            return ResponseHelper::success(
                data: $entity->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::error(
                exception: $e,
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end savePermission()

    /**
     * DELETE /api/role-feature-permissions/{id}
     *
     * @param int $id The row id.
     *
     * @return JSONResponse Empty 204 on success.
         *
     * @spec openspec/specs/admin-roles/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function deletePermission(int $id): JSONResponse
    {
        try {
            $this->service->deletePermission(id: $id);
            return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (DoesNotExistException $e) {
            return ResponseHelper::error(
                exception: $e,
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end deletePermission()

    /**
     * GET /api/role-layout-defaults
     *
     * @return JSONResponse The list of layout default rows.
         *
     * @spec openspec/specs/admin-roles/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function listLayoutDefaults(): JSONResponse
    {
        $rows = $this->service->listLayoutDefaults();
        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $rows)
        );
    }//end listLayoutDefaults()

    /**
     * POST /api/role-layout-defaults
     *
     * @return JSONResponse The persisted row.
         *
     * @spec openspec/specs/admin-roles/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function saveLayoutDefault(): JSONResponse
    {
        try {
            $payload = $this->readJsonBody();
            $entity  = $this->service->saveLayoutDefault(data: $payload);
            return ResponseHelper::success(
                data: $entity->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::error(
                exception: $e,
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end saveLayoutDefault()

    /**
     * DELETE /api/role-layout-defaults/{id}
     *
     * @param int $id The row id.
     *
     * @return JSONResponse Empty 204 on success.
         *
     * @spec openspec/specs/admin-roles/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function deleteLayoutDefault(int $id): JSONResponse
    {
        try {
            $this->service->deleteLayoutDefault(id: $id);
            return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (DoesNotExistException $e) {
            return ResponseHelper::error(
                exception: $e,
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end deleteLayoutDefault()

    /**
     * Read the request body as a JSON object (best-effort).
     *
     * @return array The decoded body, or `[]` when not JSON.
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents(filename: 'php://input');
        if ($raw === false || $raw === '') {
            return $this->request->getParams();
        }

        $decoded = json_decode(json: $raw, associative: true);
        if (is_array(value: $decoded) === false) {
            return $this->request->getParams();
        }

        return $decoded;
    }//end readJsonBody()
}//end class
