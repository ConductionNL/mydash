<?php

/**
 * DashboardVersionApiController
 *
 * Controller surface for the `dashboard-versioning` capability
 * (REQ-VERS-001..009). Exposes:
 *   - `GET    /api/dashboards/{uuid}/versions`             — list snapshots
 *   - `GET    /api/dashboards/{uuid}/versions/{number}`    — fetch body
 *   - `POST   /api/dashboards/{uuid}/versions`             — explicit snapshot
 *   - `POST   /api/dashboards/{uuid}/versions/{number}/restore` — restore
 *
 * Permission gating runs inside {@see DashboardVersionService} via the
 * owner-or-admin guard; this controller deliberately never inspects
 * permissions itself so the rule lives in one place.
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

use Exception;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Service\DashboardVersionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for dashboard version endpoints (REQ-VERS-001..009).
 */
class DashboardVersionApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest                $request         NC request.
     * @param DashboardMapper         $dashboardMapper Dashboard row lookup.
     * @param DashboardVersionService $versionService  Version service.
     * @param LoggerInterface         $logger          PSR logger.
     * @param string|null             $userId          Current user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly DashboardMapper $dashboardMapper,
        private readonly DashboardVersionService $versionService,
        private readonly LoggerInterface $logger,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * List the versions for a dashboard, newest-first (REQ-VERS-003).
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse The version list envelope.
     */
    #[NoAdminRequired]
    public function listVersions(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $envelope = $this->versionService->listVersions(
                dashboard: $dashboard,
                requestingUser: $this->userId
            );
        } catch (Exception $e) {
            return $this->mapServiceException(exception: $e);
        }

        return new JSONResponse(data: $envelope, statusCode: Http::STATUS_OK);
    }//end listVersions()

    /**
     * Fetch a single snapshot body (REQ-VERS-004).
     *
     * @param string  $uuid          The dashboard UUID.
     * @param integer $versionNumber The version number.
     *
     * @return JSONResponse The full snapshot body.
     */
    #[NoAdminRequired]
    public function fetchVersion(
        string $uuid,
        int $versionNumber
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $version = $this->versionService->fetchSnapshot(
                dashboard: $dashboard,
                versionNumber: $versionNumber,
                requestingUser: $this->userId
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Version not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Exception $e) {
            return $this->mapServiceException(exception: $e);
        }

        return new JSONResponse(
            data: [
                'version'  => $version->jsonSerialize(),
                'snapshot' => $version->getSnapshotJson(),
            ],
            statusCode: Http::STATUS_OK
        );
    }//end fetchVersion()

    /**
     * Create an explicit snapshot (REQ-VERS-002). Bypasses the
     * 60-second debounce window. The optional `note` field is read
     * from the request body.
     *
     * @param string      $uuid The dashboard UUID.
     * @param string|null $note Optional snapshot note (request body).
     *
     * @return JSONResponse The persisted version row.
     */
    #[NoAdminRequired]
    public function createVersion(
        string $uuid,
        ?string $note=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $version = $this->versionService->createExplicitSnapshot(
                dashboard: $dashboard,
                requestingUser: $this->userId,
                note: $note
            );
        } catch (Exception $e) {
            return $this->mapServiceException(exception: $e);
        }

        return new JSONResponse(
            data: ['version' => $version->jsonSerialize()],
            statusCode: Http::STATUS_CREATED
        );
    }//end createVersion()

    /**
     * Restore a snapshot (REQ-VERS-005). Captures the pre-restore
     * state as a new snapshot before applying the historical body.
     *
     * @param string  $uuid          The dashboard UUID.
     * @param integer $versionNumber The version number to restore.
     *
     * @return JSONResponse The restored snapshot envelope.
     */
    #[NoAdminRequired]
    public function restoreVersion(
        string $uuid,
        int $versionNumber
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $result = $this->versionService->restoreVersion(
                dashboard: $dashboard,
                versionNumber: $versionNumber,
                restoringUser: $this->userId
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Version not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Exception $e) {
            return $this->mapServiceException(exception: $e);
        }

        return new JSONResponse(
            data: [
                'version'  => $result['version']->jsonSerialize(),
                'snapshot' => $result['snapshot'],
            ],
            statusCode: Http::STATUS_OK
        );
    }//end restoreVersion()

    /**
     * Map a service-layer Exception to the appropriate JSON envelope.
     *
     * @param Exception $exception The exception.
     *
     * @return JSONResponse The mapped HTTP response.
     */
    private function mapServiceException(Exception $exception): JSONResponse
    {
        $message = $exception->getMessage();

        if ($message === DashboardVersionService::ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN) {
            return new JSONResponse(
                data: ['error' => 'forbidden'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $this->logger->error(
            message: 'mydash: version operation failed',
            context: ['exception' => $exception]
        );

        return new JSONResponse(
            data: ['error' => 'Operation failed'],
            statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }//end mapServiceException()
}//end class
