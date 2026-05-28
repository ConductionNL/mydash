<?php

/**
 * DashboardMetadataController
 *
 * Per-dashboard metadata read / write endpoints
 * (REQ-MDFL-004..006, REQ-MDFL-008). Lives in a dedicated controller
 * (rather than `DashboardApiController`) so the dashboard-metadata-fields
 * capability ships independently of the in-flight dashboard-tree and
 * dashboard-draft-published changes that also touch
 * `DashboardApiController`.
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
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Exception\InvalidMetadataFieldException;
use OCA\MyDash\Service\MetadataService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Dashboard metadata read/write controller.
 */
class DashboardMetadataController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         The HTTP request.
     * @param MetadataService $metadataService The metadata service facade.
     * @param DashboardMapper $dashboardMapper For ownership/visibility lookup.
     * @param IGroupManager   $groupManager    For group-share access checks.
     * @param string|null     $userId          The active user id.
     */
    public function __construct(
        IRequest $request,
        private readonly MetadataService $metadataService,
        private readonly DashboardMapper $dashboardMapper,
        private readonly IGroupManager $groupManager,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * `GET /api/dashboards/{uuid}/metadata` — REQ-MDFL-004 / REQ-MDFL-008.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse 200 + flat metadata, 404 when missing,
     *                      403 when the caller cannot see the dashboard.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function getMetadata(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $dashboard = $this->loadDashboard(uuid: $uuid);
        if ($dashboard === null) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->canRead(dashboard: $dashboard) === false) {
            return ResponseHelper::forbidden();
        }

        $metadata = $this->metadataService->getMetadataForDashboard(
            dashboardUuid: $uuid
        );

        return ResponseHelper::success(data: $metadata);
    }//end getMetadata()

    /**
     * `PUT /api/dashboards/{uuid}/metadata` — REQ-MDFL-005 / REQ-MDFL-008.
     *
     * Body: flat key-value object. Omitted keys are NOT removed; only
     * keys present in the payload are upserted.
     *
     * @param string               $uuid     The dashboard UUID.
     * @param array<string, mixed> $metadata The patch payload.
     *
     * @return JSONResponse 200 + updated metadata, 400 on validation
     *                      failure, 404 when missing, 403 otherwise.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function setMetadata(string $uuid, array $metadata=[]): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $dashboard = $this->loadDashboard(uuid: $uuid);
        if ($dashboard === null) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->canWrite(dashboard: $dashboard) === false) {
            return ResponseHelper::forbidden();
        }

        try {
            $updated = $this->metadataService->setMetadataForDashboard(
                dashboardUuid: $uuid,
                keyValues: $metadata
            );
        } catch (InvalidMetadataFieldException $exception) {
            return new JSONResponse(
                data: [
                    'error'   => InvalidMetadataFieldException::ERROR_CODE,
                    'message' => $exception->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return ResponseHelper::success(data: $updated);
    }//end setMetadata()

    /**
     * Resolve a UUID to a dashboard or null.
     *
     * @param string $uuid The UUID.
     *
     * @return Dashboard|null The dashboard or null.
     */
    private function loadDashboard(string $uuid): ?Dashboard
    {
        try {
            return $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end loadDashboard()

    /**
     * Read access check (REQ-MDFL-008).
     *
     * Owners always read. For group-shared dashboards, group members
     * read. Admin templates and the default group are world-readable
     * (consistent with the existing dashboard read paths).
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return bool True when the active user can read.
     */
    private function canRead(Dashboard $dashboard): bool
    {
        if ($dashboard->getUserId() !== null
            && $dashboard->getUserId() === $this->userId
        ) {
            return true;
        }

        $type = (string) $dashboard->getType();
        if ($type === Dashboard::TYPE_ADMIN_TEMPLATE) {
            return true;
        }

        $groupId = $dashboard->getGroupId();
        if ($groupId !== null && $this->userId !== null) {
            return $this->groupManager->isInGroup(
                userId: $this->userId,
                group: $groupId
            );
        }

        return false;
    }//end canRead()

    /**
     * Write access check (REQ-MDFL-008).
     *
     * Owners write. Admins can write to admin templates. Group members
     * can write to group-shared dashboards (same gating as the existing
     * group-shared update path).
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return bool True when the active user can write.
     */
    private function canWrite(Dashboard $dashboard): bool
    {
        if ($dashboard->getUserId() !== null
            && $dashboard->getUserId() === $this->userId
        ) {
            return true;
        }

        $type = (string) $dashboard->getType();
        if ($type === Dashboard::TYPE_ADMIN_TEMPLATE
            && $this->userId !== null
            && $this->groupManager->isAdmin(userId: $this->userId) === true
        ) {
            return true;
        }

        $groupId = $dashboard->getGroupId();
        if ($groupId !== null && $this->userId !== null) {
            return $this->groupManager->isInGroup(
                userId: $this->userId,
                group: $groupId
            );
        }

        return false;
    }//end canWrite()
}//end class
