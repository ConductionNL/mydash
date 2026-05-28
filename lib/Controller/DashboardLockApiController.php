<?php

/**
 * DashboardLockApiController
 *
 * HTTP entry point for dashboard editing-lock management. Implements
 * the four REST verbs on a single lock resource URL plus one admin
 * action, per REQ-LOCK-001..008 and design D3 / D4:
 *
 *  - `POST   /api/dashboards/{uuid}/lock`               acquire (re-entrant for same user)
 *  - `PUT    /api/dashboards/{uuid}/lock`               heartbeat
 *  - `DELETE /api/dashboards/{uuid}/lock`               release
 *  - `GET    /api/dashboards/{uuid}/lock`               query current state
 *  - `POST   /api/dashboards/{uuid}/lock/force-release` admin override
 *
 * All endpoints carry `#[NoAdminRequired]` — the admin guard for
 * `force-release` is enforced inline via `IGroupManager::isAdmin`
 * inside the controller (and again inside the service, defence in
 * depth).
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
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Exception\LockConflictException;
use OCA\MyDash\Exception\LockForbiddenException;
use OCA\MyDash\Exception\LockNotFoundException;
use OCA\MyDash\Service\DashboardLockService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for dashboard editing-lock endpoints (REQ-LOCK-001..008).
 */
class DashboardLockApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest             $request           The HTTP request.
     * @param DashboardLockService $lockService       The lock service.
     * @param PermissionService    $permissionService Dashboard permission resolver.
     * @param DashboardMapper      $dashboardMapper   UUID → id lookup.
     * @param string|null          $userId            The calling user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly DashboardLockService $lockService,
        private readonly PermissionService $permissionService,
        private readonly DashboardMapper $dashboardMapper,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Acquire (or refresh) the lock for the given dashboard.
     *
     * Re-entrant for the same user — a second tab MUST receive HTTP
     * 200 with the refreshed lock instead of HTTP 409 (REQ-LOCK-001).
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse 200 with the lock object on success,
     *                      404 when the dashboard UUID is unknown,
     *                      409 with the existing lock on conflict.
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    #[NoAdminRequired]
    public function acquire(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $lock = $this->lockService->acquireLock(
                dashboardUuid: $uuid,
                userId: $this->userId
            );
            return new JSONResponse(
                data: $lock->jsonSerialize(),
                statusCode: Http::STATUS_OK
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (LockForbiddenException $e) {
            // C3 fix: caller lacks view access to this dashboard.
            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                    'code'  => LockForbiddenException::ERROR_CODE,
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (LockConflictException $e) {
            // M2: strip userId from conflict response — callers need the
            // displayName to show "X is editing" but should not receive
            // the internal user identifier of a third party.
            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                    'code'  => LockConflictException::ERROR_CODE,
                    'lock'  => $e->getExistingLock()->jsonSerializeConflict(),
                ],
                statusCode: Http::STATUS_CONFLICT
            );
        }//end try
    }//end acquire()

    /**
     * Refresh the lock (heartbeat). Owner-only.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse 200 with the refreshed lock; 404 when no
     *                      active lock exists; 403 on owner mismatch.
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    #[NoAdminRequired]
    public function heartbeat(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $lock = $this->lockService->heartbeat(
                dashboardUuid: $uuid,
                userId: $this->userId
            );
            return new JSONResponse(
                data: $lock->jsonSerialize(),
                statusCode: Http::STATUS_OK
            );
        } catch (LockNotFoundException $e) {
            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                    'code'  => LockNotFoundException::ERROR_CODE,
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (LockForbiddenException $e) {
            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                    'code'  => LockForbiddenException::ERROR_CODE,
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }//end try
    }//end heartbeat()

    /**
     * Release the lock. Owner-or-admin.
     *
     * Idempotent — releasing a non-existent lock returns 204 (the
     * caller's intent "no longer holding the lock" is satisfied).
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse 204 on success; 403 on permission mismatch.
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    #[NoAdminRequired]
    public function release(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->lockService->releaseLock(
                dashboardUuid: $uuid,
                userId: $this->userId,
                allowAdminOverride: true
            );
            return new JSONResponse(
                data: [],
                statusCode: Http::STATUS_NO_CONTENT
            );
        } catch (LockForbiddenException $e) {
            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                    'code'  => LockForbiddenException::ERROR_CODE,
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }
    }//end release()

    /**
     * Query the current lock state.
     *
     * Returns the lock object when active, or HTTP 404 when none
     * exists. Stale rows are scrubbed inline by the service before
     * the response.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse 200 with the lock or 404 when none.
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    #[NoAdminRequired]
    public function get(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // H1: guard against identity leak — any authed user could enumerate
        // lock holders for arbitrary UUIDs; return 404 on no-view-access
        // (same shape as "no lock") to avoid leaking dashboard existence.
        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Lock not found', 'code' => LockNotFoundException::ERROR_CODE],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->permissionService->canViewDashboard(
            userId: $this->userId,
            dashboardId: (int) $dashboard->getId()
        ) === false
        ) {
            // Return 404 not 403 to avoid leaking dashboard existence.
            return new JSONResponse(
                data: ['error' => 'Lock not found', 'code' => LockNotFoundException::ERROR_CODE],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $lock = $this->lockService->getLockState(dashboardUuid: $uuid);
        if ($lock === null) {
            return new JSONResponse(
                data: [
                    'error' => 'Lock not found',
                    'code'  => LockNotFoundException::ERROR_CODE,
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(
            data: $lock->jsonSerialize(),
            statusCode: Http::STATUS_OK
        );
    }//end get()

    /**
     * Admin-only: force-release any user's lock (REQ-LOCK-006, design
     * D4). The admin may then `acquire` normally if they want to take
     * the lock themselves.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse 200 on success; 403 when caller is not admin.
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    #[NoAdminRequired]
    public function forceRelease(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->lockService->forceRelease(
                dashboardUuid: $uuid,
                adminUserId: $this->userId
            );
            return new JSONResponse(
                data: ['status' => 'ok'],
                statusCode: Http::STATUS_OK
            );
        } catch (LockForbiddenException $e) {
            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                    'code'  => LockForbiddenException::ERROR_CODE,
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }
    }//end forceRelease()
}//end class
