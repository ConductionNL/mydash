<?php

/**
 * AdminCleanupController
 *
 * HTTP surface for the orphaned-data-cleanup pipeline. Two endpoints,
 * both admin-only:
 *
 *  - `GET  /api/admin/cleanup/scan`  — REQ-CLN-004
 *  - `POST /api/admin/cleanup/purge` — REQ-CLN-005
 *
 * Admin enforcement is done at request time via `IGroupManager` and
 * `IUserSession` (mirroring the pattern in
 * {@see \OCA\MyDash\Controller\AdminController::requireAdmin()}). Both
 * unauthenticated and non-admin callers receive HTTP 403 — we
 * deliberately do not differentiate to keep the admin surface from
 * leaking which users exist.
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
use OCA\MyDash\Service\Cleanup\CategoryRegistryService;
use OCA\MyDash\Service\OrphanedDataCleanupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin endpoints for scan + purge.
 */
class AdminCleanupController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request        The request.
     * @param OrphanedDataCleanupService $cleanupService The orchestrator.
     * @param CategoryRegistryService    $registry       Category registry
     *                                                   (for unknown-name
     *                                                   error messages).
     * @param IGroupManager              $groupManager   Admin check.
     * @param IUserSession               $userSession    Current user.
     */
    public function __construct(
        IRequest $request,
        private readonly OrphanedDataCleanupService $cleanupService,
        private readonly CategoryRegistryService $registry,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * `GET /api/admin/cleanup/scan` — REQ-CLN-004.
     *
     * Returns a JSON envelope describing the per-category orphan
     * counts. Reads from the distributed cache when available
     * (REQ-CLN-010) and surfaces `cached`/`cachedAt` hints so the UI
     * can display "last refreshed" badges.
     *
     * @return JSONResponse The scan result.
     *
     * @NoAdminRequired
     */
    public function scan(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $cached = $this->cleanupService->getCachedScanResult();
        if ($cached !== null) {
            return new JSONResponse(
                data: array_merge(
                    $cached->jsonSerialize(),
                    [
                        'cached'   => true,
                        'cachedAt' => $cached->getScannedAt(),
                    ]
                ),
                statusCode: Http::STATUS_OK
            );
        }

        $result = $this->cleanupService->scan();

        return new JSONResponse(
            data: array_merge(
                $result->jsonSerialize(),
                [
                    'cached'   => false,
                    'cachedAt' => null,
                ]
            ),
            statusCode: Http::STATUS_OK
        );
    }//end scan()

    /**
     * `POST /api/admin/cleanup/purge` — REQ-CLN-005.
     *
     * Body shape:
     *  {
     *      "categories": ["expired_locks", ...],   // optional, []=all
     *      "dryRun":     true|false                // optional, false default
     *  }
     *
     * Returns the per-category breakdown plus total, duration, and
     * dryRun flag. Unknown categories receive HTTP 400 with the list
     * of valid names so the caller can correct the request.
     *
     * @param array<int, mixed>|null $categories Per-category filter.
     * @param bool|null              $dryRun     Dry-run flag.
     *
     * @return JSONResponse The purge result.
     *
     * @NoAdminRequired
     */
    public function purge(?array $categories=null, ?bool $dryRun=null): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $names = $this->normaliseCategories(input: ($categories ?? []));
        if ($names === null) {
            return new JSONResponse(
                data: [
                    'error'           => 'Unknown cleanup category in request',
                    'validCategories' => $this->registry->getCategoryNames(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $userId = '';
        $user   = $this->userSession->getUser();
        if ($user !== null) {
            $userId = $user->getUID();
        }

        $result = $this->cleanupService->purge(
            categoryNames: $names,
            dryRun: ($dryRun ?? false),
            userId: $userId,
            source: 'api',
        );

        return new JSONResponse(
            data: [
                'purgedByCategory' => $result->getByCategory(),
                'totalRows'        => $result->getTotalRows(),
                'durationMs'       => $result->getDurationMs(),
                'dryRun'           => $result->isDryRun(),
                'skipped'          => $result->getSkipped(),
            ],
            statusCode: Http::STATUS_OK
        );
    }//end purge()

    /**
     * Normalise the API-supplied categories list.
     *
     * Filters non-string entries silently (defence in depth — the
     * controller is reached after framework JSON parsing) and
     * returns `null` if any of the remaining names are not registered.
     * An empty list is returned as `[]` (which the orchestrator
     * treats as "all categories").
     *
     * @param array<int, mixed> $input The raw input list.
     *
     * @return array<int, string>|null The validated names or null.
     */
    private function normaliseCategories(array $input): ?array
    {
        $known      = $this->registry->getCategoryNames();
        $normalised = [];

        foreach ($input as $value) {
            if (is_string(value: $value) === false || $value === '') {
                continue;
            }

            if (in_array(needle: $value, haystack: $known, strict: true) === false) {
                return null;
            }

            $normalised[] = $value;
        }

        return $normalised;
    }//end normaliseCategories()

    /**
     * Verify the caller is an authenticated Nextcloud admin.
     *
     * Returns `null` to signal "proceed". Returns a 403 JSON envelope
     * for unauthenticated and non-admin callers — see class docblock
     * for the rationale of not distinguishing the two.
     *
     * @return JSONResponse|null Null when admin, else 403.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Administrator privileges required.'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        if ($this->groupManager->isAdmin(userId: $user->getUID()) === false) {
            return new JSONResponse(
                data: ['error' => 'Administrator privileges required.'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end requireAdmin()
}//end class
