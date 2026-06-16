<?php

/**
 * FilesWidgetController
 *
 * HTTP entry point for the files-widget capability. Exposes:
 *
 * - `GET    /api/widgets/files/{placementId}/contents` — paginated
 *   directory listing with view-time ACL filtering (REQ-FLS-003).
 * - `POST   /api/widgets/files/{placementId}/upload`   — multi-file
 *   upload, dual-gated by placement + viewer permission
 *   (REQ-FLS-007).
 * - `DELETE /api/widgets/files/{placementId}/files/{fileId}` — file
 *   deletion via the trash, dual-gated (REQ-FLS-008).
 *
 * Every typed exception from {@see \OCA\MyDash\Service\FilesWidgetService}
 * is mapped to the standardised `{status, error, message}` envelope —
 * raw underlying messages are NEVER returned to the caller.
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
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\FolderNotFoundException;
use OCA\MyDash\Exception\NoAccessException;
use OCA\MyDash\Service\FilesWidgetService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the files-widget capability.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Permission-aware
 *                                                 file browsing
 *                                                 inherently couples
 *                                                 the request,
 *                                                 placement, session,
 *                                                 logger, and the
 *                                                 underlying service.
 */
class FilesWidgetController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request           HTTP request.
     * @param FilesWidgetService    $service           Files widget service.
     * @param WidgetPlacementMapper $placementMapper   Placement entity mapper.
     * @param PermissionService     $permissionService Dashboard permission gate.
     * @param IUserSession          $userSession       Session accessor.
     * @param LoggerInterface       $logger            PSR logger.
     */
    public function __construct(
        IRequest $request,
        private readonly FilesWidgetService $service,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly PermissionService $permissionService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * `GET /api/widgets/files/{placementId}/contents`
     *
     * Returns the configured folder's contents as
     * `{items: [...], nextCursor: ?string}`. Empty folder is HTTP 200
     * with `items: []`. Missing folder is HTTP 404. Read-denied folder
     * is HTTP 403.
     *
     * @param integer $placementId The widget placement id.
     * @param string  $currentPath Sub-path inside the configured folder.
     * @param integer $limit       Page size (capped server-side).
     * @param string  $cursor      Opaque pagination cursor.
     *
     * @return JSONResponse
         *
     * @spec openspec/specs/files-widget/spec.md
 */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function contents(
        int $placementId,
        string $currentPath='/',
        int $limit=FilesWidgetService::DEFAULT_LIMIT,
        string $cursor=''
    ): JSONResponse {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->unauthorised();
        }

        $config = $this->loadConfig(placementId: $placementId, userId: $userId);
        if ($config === null) {
            return new JSONResponse(
                data: ['status' => 'error', 'error' => 'forbidden'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        try {
            $page = $this->service->getContentsForPlacement(
                userId: $userId,
                config: $config,
                currentSubPath: $currentPath,
                limit: $limit,
                cursor: $cursor
            );

            return new JSONResponse(
                data: $page,
                statusCode: Http::STATUS_OK
            );
        } catch (FolderNotFoundException $e) {
            return $this->errorResponse(
                error: 'folder_not_found',
                status: Http::STATUS_NOT_FOUND,
                message: $e->getDisplayMessage()
            );
        } catch (NoAccessException $e) {
            return $this->errorResponse(
                error: 'no_access',
                status: Http::STATUS_FORBIDDEN,
                message: $e->getDisplayMessage()
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Unexpected files widget contents failure',
                context: ['exception' => $e->getMessage()]
            );
            return $this->errorResponse(
                error: 'unknown_error',
                status: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end contents()

    /**
     * `POST /api/widgets/files/{placementId}/upload`
     *
     * Accepts `multipart/form-data` with one or more `files[]` entries
     * and writes them into the placement-configured folder (or a
     * sub-path of it, if `currentPath` is supplied).
     *
     * @param integer $placementId The widget placement id.
     * @param string  $currentPath Sub-path inside the configured folder.
     *
     * @return JSONResponse
     *
      * @spec openspec/specs/files-widget/spec.md
      */
    #[NoAdminRequired]
    public function upload(int $placementId, string $currentPath='/'): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->unauthorised();
        }

        $config = $this->loadConfig(placementId: $placementId, userId: $userId);
        if ($config === null) {
            return new JSONResponse(
                data: ['status' => 'error', 'error' => 'forbidden'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $files = $this->normaliseUploadedFiles();

        try {
            $result = $this->service->uploadFiles(
                userId: $userId,
                config: $config,
                currentSubPath: $currentPath,
                uploadedFiles: $files
            );

            return new JSONResponse(
                data: $result,
                statusCode: Http::STATUS_OK
            );
        } catch (FolderNotFoundException $e) {
            return $this->errorResponse(
                error: 'folder_not_found',
                status: Http::STATUS_NOT_FOUND,
                message: $e->getDisplayMessage()
            );
        } catch (NoAccessException $e) {
            return $this->errorResponse(
                error: 'no_access',
                status: Http::STATUS_FORBIDDEN,
                message: $e->getDisplayMessage()
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Unexpected files widget upload failure',
                context: ['exception' => $e->getMessage()]
            );
            return $this->errorResponse(
                error: 'unknown_error',
                status: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end upload()

    /**
     * `DELETE /api/widgets/files/{placementId}/files/{fileId}`
     *
     * Moves the supplied file into the user's trash bin.
     *
     * @param integer $placementId The widget placement id.
     * @param integer $fileId      File id (must live inside the
     *                             configured folder).
     *
     * @return JSONResponse
     *
      * @spec openspec/specs/files-widget/spec.md
      */
    #[NoAdminRequired]
    public function destroy(int $placementId, int $fileId): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->unauthorised();
        }

        $config = $this->loadConfig(placementId: $placementId, userId: $userId);
        if ($config === null) {
            return new JSONResponse(
                data: ['status' => 'error', 'error' => 'forbidden'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        try {
            $result = $this->service->deleteFile(
                userId: $userId,
                config: $config,
                fileId: $fileId
            );

            return new JSONResponse(
                data: $result,
                statusCode: Http::STATUS_OK
            );
        } catch (FolderNotFoundException $e) {
            return $this->errorResponse(
                error: 'folder_not_found',
                status: Http::STATUS_NOT_FOUND,
                message: $e->getDisplayMessage()
            );
        } catch (NoAccessException $e) {
            return $this->errorResponse(
                error: 'no_access',
                status: Http::STATUS_FORBIDDEN,
                message: $e->getDisplayMessage()
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Unexpected files widget delete failure',
                context: ['exception' => $e->getMessage()]
            );
            return $this->errorResponse(
                error: 'unknown_error',
                status: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end destroy()

    /**
     * Resolve the active user's UID, or `null` for anonymous.
     *
     * @return string|null
     */
    private function resolveUserId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end resolveUserId()

    /**
     * Load the placement, gate it through {@see PermissionService}, and
     * return the parsed `widgetContent` config blob.
     *
     * Returns `null` when the placement is missing OR the user cannot
     * view the underlying dashboard. The caller maps `null` to a
     * forbidden response so missing-vs-no-access is indistinguishable
     * to the client.
     *
     * @param integer $placementId Widget placement id.
     * @param string  $userId      Viewing user's UID.
     *
     * @return array<string,mixed>|null
     */
    private function loadConfig(int $placementId, string $userId): ?array
    {
        try {
            $placement = $this->placementMapper->find(id: $placementId);
        } catch (Throwable $e) {
            return null;
        }

        // L2: upload is a write operation — require write-level permission
        // (canAddWidget), not read-level (canViewDashboard).
        if ($this->permissionService->canAddWidget(
            userId: $userId,
            dashboardId: $placement->getDashboardId()
        ) === false
        ) {
            return null;
        }

        // Registry-driven custom widgets persist their per-type config
        // in the `content` column (added in Version001025). Older rows
        // that pre-date the column may still carry the blob inside the
        // legacy `style_config.content` slot, so we fall back to that
        // shape when the dedicated column is empty.
        $content = $placement->getContentArray();
        if ($content !== []) {
            return $content;
        }

        $legacy = $placement->getStyleConfigArray();
        if (isset($legacy['content']) === true && is_array($legacy['content']) === true) {
            return $legacy['content'];
        }

        return $legacy;
    }//end loadConfig()

    /**
     * Convert PHP's `$_FILES` super-global into a flat list of
     * upload entries. Supports both single (`files=...`) and
     * multi-part (`files[]=...`) submissions.
     *
     * @return list<array{name:string, tmp_name:string, size:int, error:int}>
     */
    private function normaliseUploadedFiles(): array
    {
        // @phpstan-ignore-next-line — superglobal access is mixed.
        $raw = $_FILES['files'] ?? null;
        if (is_array($raw) === false) {
            return [];
        }

        $names  = ($raw['name'] ?? null);
        $tmps   = ($raw['tmp_name'] ?? null);
        $sizes  = ($raw['size'] ?? null);
        $errors = ($raw['error'] ?? null);

        $entries = [];
        if (is_array($names) === true) {
            $count = count($names);
            if (is_array($tmps) === false) {
                $tmps = [];
            }

            if (is_array($sizes) === false) {
                $sizes = [];
            }

            if (is_array($errors) === false) {
                $errors = [];
            }

            for ($i = 0; $i < $count; $i++) {
                $entries[] = [
                    'name'     => (string) ($names[$i] ?? ''),
                    'tmp_name' => (string) ($tmps[$i] ?? ''),
                    'size'     => (int) ($sizes[$i] ?? 0),
                    'error'    => (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE),
                ];
            }
        } else if ($names !== null) {
            $entries[] = [
                'name'     => (string) $names,
                'tmp_name' => (string) ($tmps ?? ''),
                'size'     => (int) ($sizes ?? 0),
                'error'    => (int) ($errors ?? UPLOAD_ERR_NO_FILE),
            ];
        }//end if

        return $entries;
    }//end normaliseUploadedFiles()

    /**
     * Build a 401 envelope for the anonymous case.
     *
     * @return JSONResponse
     */
    private function unauthorised(): JSONResponse
    {
        return new JSONResponse(
            data: [
                'status'  => 'error',
                'error'   => 'unauthorized',
                'message' => 'Authentication required',
            ],
            statusCode: Http::STATUS_UNAUTHORIZED
        );
    }//end unauthorised()

    /**
     * Build a typed error envelope.
     *
     * The status code is restricted to the union of HTTP status codes
     * accepted by {@see JSONResponse::__construct()} so that PHPStan
     * can verify the literal at every call-site.
     *
     * @param string       $error   Machine-readable error code.
     * @param int<100,511> $status  HTTP status code.
     * @param string|null  $message Optional human-readable message.
     *
     * @return JSONResponse
     */
    private function errorResponse(string $error, int $status=Http::STATUS_BAD_REQUEST, ?string $message=null): JSONResponse
    {
        $payload = [
            'status' => 'error',
            'error'  => $error,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return new JSONResponse(
            data: $payload,
            statusCode: $status
        );
    }//end errorResponse()
}//end class
