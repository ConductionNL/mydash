<?php

/**
 * AdminBulkController
 *
 * Admin-only controller exposing the four batch endpoints from the
 * `dashboard-bulk-operations` capability:
 *
 * - `POST /api/admin/dashboards/bulk-delete`
 * - `POST /api/admin/dashboards/bulk-move`
 * - `POST /api/admin/dashboards/bulk-status`
 * - `POST /api/admin/dashboards/bulk-reindex`
 *
 * Each endpoint enforces the all-or-nothing permission pre-check
 * (REQ-BULK-011), the per-request size cap (REQ-BULK-006), and
 * dispatches into {@see BulkOperationService} which owns the per-uuid
 * idempotency, dry-run, and audit-event logic.
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

use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\BulkOperationService;
use OCA\MyDash\Service\PermissionDeniedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Bulk admin endpoints for dashboards (REQ-BULK-001..011).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Admin-guard +
 *  request decoding + service dispatch is the smallest viable surface
 *  area here.
 */
class AdminBulkController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request      The current request.
     * @param BulkOperationService $bulkService  The bulk service.
     * @param IUserSession         $userSession  The user session.
     */
    public function __construct(
        IRequest $request,
        private readonly BulkOperationService $bulkService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * `POST /api/admin/dashboards/bulk-delete` — REQ-BULK-001.
     *
     * @param mixed     $dashboardUuids The UUID array.
     * @param bool|null $dryRun         When true, preview only.
     * @param bool|null $cascade        When true, cascade into children.
     *
     * @return JSONResponse The bulk-delete envelope.
     *
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    /** @spec openspec/specs/dashboard-bulk-operations/spec.md */
    public function bulkDelete(
        mixed $dashboardUuids=null,
        ?bool $dryRun=null,
        ?bool $cascade=null
    ): JSONResponse {
        $uuids = $this->extractUuids(value: $dashboardUuids);
        if ($uuids === null) {
            return new JSONResponse(
                data: ['error' => 'dashboardUuids must be an array of strings'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $userId    = (string) $this->userSession->getUser()?->getUID();
        $isDryRun  = $this->resolveBool(value: $dryRun, queryKey: 'dryRun');
        $doCascade = $this->resolveBool(value: $cascade, queryKey: 'cascade');

        try {
            $result = $this->bulkService->bulkDelete(
                dashboardUuids: $uuids,
                userId: $userId,
                dryRun: $isDryRun,
                cascade: $doCascade
            );
        } catch (PermissionDeniedException $e) {
            return new JSONResponse(
                data: [
                    'error'       => $e->getMessage(),
                    'deniedUuids' => $e->getDeniedUuids(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try

        return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
    }//end bulkDelete()

    /**
     * `POST /api/admin/dashboards/bulk-move` — REQ-BULK-002.
     *
     * @param mixed       $dashboardUuids The UUID array.
     * @param string|null $parentUuid     The new parent UUID
     *                                    (NULL ⇒ root).
     * @param bool|null   $dryRun         When true, preview only.
     *
     * @return JSONResponse The bulk-move envelope.
     *
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    /** @spec openspec/specs/dashboard-bulk-operations/spec.md */
    public function bulkMove(
        mixed $dashboardUuids=null,
        ?string $parentUuid=null,
        ?bool $dryRun=null
    ): JSONResponse {
        $uuids = $this->extractUuids(value: $dashboardUuids);
        if ($uuids === null) {
            return new JSONResponse(
                data: ['error' => 'dashboardUuids must be an array of strings'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $userId   = (string) $this->userSession->getUser()?->getUID();
        $isDryRun = $this->resolveBool(value: $dryRun, queryKey: 'dryRun');

        try {
            $result = $this->bulkService->bulkMove(
                dashboardUuids: $uuids,
                parentUuid: $parentUuid,
                userId: $userId,
                dryRun: $isDryRun
            );
        } catch (PermissionDeniedException $e) {
            return new JSONResponse(
                data: [
                    'error'       => $e->getMessage(),
                    'deniedUuids' => $e->getDeniedUuids(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try

        return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
    }//end bulkMove()

    /**
     * `POST /api/admin/dashboards/bulk-status` — REQ-BULK-003.
     *
     * @param mixed       $dashboardUuids    The UUID array.
     * @param string|null $publicationStatus The target status enum value.
     * @param string|null $publishAt         Future ISO-8601 timestamp.
     * @param bool|null   $dryRun            When true, preview only.
     *
     * @return JSONResponse The bulk-status envelope.
     *
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    /** @spec openspec/specs/dashboard-bulk-operations/spec.md */
    public function bulkStatus(
        mixed $dashboardUuids=null,
        ?string $publicationStatus=null,
        ?string $publishAt=null,
        ?bool $dryRun=null
    ): JSONResponse {
        $uuids = $this->extractUuids(value: $dashboardUuids);
        if ($uuids === null) {
            return new JSONResponse(
                data: ['error' => 'dashboardUuids must be an array of strings'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($publicationStatus === null || trim($publicationStatus) === '') {
            return new JSONResponse(
                data: ['error' => 'publicationStatus is required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $userId   = (string) $this->userSession->getUser()?->getUID();
        $isDryRun = $this->resolveBool(value: $dryRun, queryKey: 'dryRun');

        try {
            $result = $this->bulkService->bulkStatus(
                dashboardUuids: $uuids,
                publicationStatus: $publicationStatus,
                publishAt: $publishAt,
                userId: $userId,
                dryRun: $isDryRun
            );
        } catch (PermissionDeniedException $e) {
            return new JSONResponse(
                data: [
                    'error'       => $e->getMessage(),
                    'deniedUuids' => $e->getDeniedUuids(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try

        return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
    }//end bulkStatus()

    /**
     * `POST /api/admin/dashboards/bulk-reindex` — REQ-BULK-004.
     *
     * @param mixed     $dashboardUuids The UUID array.
     * @param bool|null $dryRun         When true, preview only.
     *
     * @return JSONResponse The bulk-reindex envelope.
     *
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    /** @spec openspec/specs/dashboard-bulk-operations/spec.md */
    public function bulkReindex(
        mixed $dashboardUuids=null,
        ?bool $dryRun=null
    ): JSONResponse {
        $uuids = $this->extractUuids(value: $dashboardUuids);
        if ($uuids === null) {
            return new JSONResponse(
                data: ['error' => 'dashboardUuids must be an array of strings'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $userId   = (string) $this->userSession->getUser()?->getUID();
        $isDryRun = $this->resolveBool(value: $dryRun, queryKey: 'dryRun');

        try {
            $result = $this->bulkService->bulkReindex(
                dashboardUuids: $uuids,
                userId: $userId,
                dryRun: $isDryRun
            );
        } catch (PermissionDeniedException $e) {
            return new JSONResponse(
                data: [
                    'error'       => $e->getMessage(),
                    'deniedUuids' => $e->getDeniedUuids(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
    }//end bulkReindex()

    /**
     * Validate and unwrap a `dashboardUuids` body field into a string
     * list. Returns null when the value is not a list of strings.
     *
     * @param mixed $value The raw decoded value.
     *
     * @return string[]|null The extracted UUID list, or null on
     *                       validation failure.
     */
    private function extractUuids(mixed $value): ?array
    {
        if (is_array($value) === false) {
            return null;
        }

        $uuids = [];
        foreach ($value as $item) {
            if (is_string($item) === false) {
                return null;
            }

            $trimmed = trim($item);
            if ($trimmed === '') {
                return null;
            }

            $uuids[] = $trimmed;
        }

        return $uuids;
    }//end extractUuids()

    /**
     * Resolve a boolean parameter that may arrive either in the body
     * (`bool`) or via the query string (`?dryRun=true`). The query
     * string takes precedence when the body parameter is null.
     *
     * @param bool|null $value    The body-parsed value.
     * @param string    $queryKey The query string key.
     *
     * @return bool The resolved boolean.
     */
    private function resolveBool(?bool $value, string $queryKey): bool
    {
        if ($value !== null) {
            return $value;
        }

        $raw = $this->request->getParam(key: $queryKey);
        if ($raw === null || $raw === '') {
            return false;
        }

        $lower = strtolower((string) $raw);
        return in_array(needle: $lower, haystack: ['1', 'true', 'yes', 'on'], strict: true);
    }//end resolveBool()

}//end class
