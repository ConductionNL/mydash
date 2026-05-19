<?php

/**
 * ResourceController
 *
 * HTTP entry point for the resource-uploads capability. Exposes:
 *
 * - `POST /api/resources` (admin-only) — accepts a raw JSON body of
 *   the shape `{base64: 'data:image/<type>;base64,...'}` and returns
 *   a standardised `{status, url, name, size}` envelope.
 * - `GET /apps/mydash/resource/{filename}` (any logged-in user) —
 *   streams the resource bytes with extension-derived Content-Type
 *   and `Cache-Control: public, max-age=31536000`. The `uniqid`
 *   suffix in REQ-RES-004 filenames doubles as the cache buster.
 * - `GET /api/resources` (any logged-in user) — lists the uploaded
 *   resources as `{name, url, size, modifiedAt}` tuples ordered by
 *   `modifiedAt` desc.
 *
 * All errors are mapped to a `{status: 'error', error: <stable_code>,
 * message: <display>}` envelope — raw exception messages are NEVER
 * returned to the client.
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
use OCA\MyDash\Exception\ForbiddenException;
use OCA\MyDash\Exception\ResourceException;
use OCA\MyDash\Exception\StorageFailureException;
use OCA\MyDash\Service\ResourceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IL10N;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resource upload + serving controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ResourceController extends Controller
{
    /**
     * Maximum bytes we are willing to read into memory when serving
     * a resource. Mirrors {@see ResourceService::MAX_BYTES}.
     */
    private const SERVE_MAX_BYTES = (5 * 1024 * 1024);

    /**
     * Map of lowercase file extensions to Content-Type strings used by
     * the {@see getResource()} streamer. Anything not in this map
     * falls back to `application/octet-stream`.
     *
     * @var array<string, string>
     */
    private const CONTENT_TYPE_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    /**
     * Constructor.
     *
     * @param IRequest                    $request         The HTTP request.
     * @param ResourceService             $resourceService The upload pipeline.
     * @param ResourceUploadRequestParser $parser          Parses request body.
     * @param IUserSession                $userSession     Session accessor.
     * @param IGroupManager               $groupManager    Admin checker.
     * @param IAppData                    $appData         App-data accessor.
     * @param IL10N                       $l10n            Translator.
     * @param LoggerInterface             $logger          PSR logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ResourceService $resourceService,
        private readonly ResourceUploadRequestParser $parser,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppData $appData,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(
            appName: 'mydash',
            request: $request
        );
    }//end __construct()

    /**
     * Handle `POST /api/resources`.
     *
     * Reads the raw JSON body from `php://input`, asserts an admin
     * caller, delegates to ResourceService, and maps the response
     * (success or any typed exception) to the standardised envelope.
     *
     * @return JSONResponse Either the success envelope with
     *                      `{status, url, name, size}` or the error
     *                      envelope with `{status, error, message}`.
     *
     * @NoCSRFRequired
     */
    public function upload(): JSONResponse
    {
        try {
            $this->assertAdmin();
            $base64 = $this->parser->extractBase64(
                request: $this->request,
                rawBody: $this->readRequestBody()
            );

            $result = $this->resourceService->upload(
                base64DataUrl: $base64
            );

            return new JSONResponse(
                data: [
                    'status' => 'success',
                    'url'    => $result['url'],
                    'name'   => $result['name'],
                    'size'   => $result['size'],
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (ResourceException $e) {
            // Log storage failures — these usually indicate an
            // operational problem (disk, permissions, etc.).
            if ($e instanceof StorageFailureException) {
                $this->logger->error(
                    message: 'Resource upload storage failure',
                    context: ['exception' => $e->getMessage()]
                );
            }

            return $this->errorResponse(exception: $e);
        } catch (Throwable $e) {
            // Defence in depth — never leak raw messages on truly
            // unexpected paths.
            $this->logger->error(
                message: 'Unexpected resource upload failure',
                context: ['exception' => $e->getMessage()]
            );

            $fallback = new StorageFailureException(
                message: 'Failed to store resource'
            );

            return $this->errorResponse(exception: $fallback);
        }//end try
    }//end upload()

    /**
     * Handle `GET /apps/mydash/resource/{filename}` (REQ-RES-006).
     *
     * Streams the bytes of an uploaded resource via `php://memory`
     * (REQ-RES-008) with a Content-Type derived from the file
     * extension and `Cache-Control: public, max-age=31536000`. The
     * `uniqid` suffix produced by REQ-RES-004 already acts as the
     * cache-busting key — re-uploading the same logical asset
     * produces a fresh filename so clients can cache aggressively.
     *
     * Path traversal protection: the `{filename}` parameter is
     * validated as a leaf name; any embedded `/` or `..` (decoded
     * from the route parameter) results in HTTP 404 — never a 200
     * with a system file.
     *
     * Files larger than the upload cap (5 MB per REQ-RES-003) are
     * refused with HTTP 413 BEFORE the bytes are loaded into memory
     * — only reachable via manual filesystem tampering, but bounds
     * worst-case memory usage.
     *
     * @param string $filename The leaf filename inside the resources
     *                         folder.
     *
     * @return StreamResponse|JSONResponse The streamed bytes, or a
     *                                     JSON error envelope on
     *                                     `not_found` / `file_too_large`.
     *
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    public function getResource(string $filename)
    {
        // Leaf-only guard — the Symfony route param matches `[^/]+`
        // by default, but defence in depth catches any encoded
        // `..%2F` that decoders may have already collapsed.
        if ($this->isUnsafeFilename(filename: $filename) === true) {
            return $this->notFoundResponse();
        }

        try {
            $folder = $this->appData->getFolder(name: ResourceService::FOLDER);
        } catch (NotFoundException $e) {
            return $this->notFoundResponse();
        }

        try {
            $file = $folder->getFile(name: $filename);
        } catch (NotFoundException $e) {
            return $this->notFoundResponse();
        }

        $size = (int) $file->getSize();
        if ($size > self::SERVE_MAX_BYTES) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'file_too_large',
                    'message' => $this->l10n->t('Resource exceeds the 5 MB serving cap'),
                ],
                statusCode: Http::STATUS_REQUEST_ENTITY_TOO_LARGE
            );
        }

        try {
            $bytes = $file->getContent();
        } catch (NotFoundException | NotPermittedException $e) {
            return $this->notFoundResponse();
        }

        // Stream via php://memory — bounded by the size guard above
        // so worst-case memory is the 5 MB upload cap.
        $stream = fopen(filename: 'php://memory', mode: 'r+');
        if ($stream === false) {
            $this->logger->error(message: 'Could not open php://memory for resource serving');
            return $this->notFoundResponse();
        }

        fwrite(stream: $stream, data: $bytes);
        rewind(stream: $stream);

        $contentType = $this->contentTypeFor(filename: $filename);

        $response = new StreamResponse(
            filePath: $stream,
            status: Http::STATUS_OK,
            headers: [
                'Content-Type'  => $contentType,
                'Cache-Control' => 'public, max-age=31536000',
            ]
        );

        return $response;
    }//end getResource()

    /**
     * Handle `GET /api/resources` (REQ-RES-007).
     *
     * Lists every file in `<appdata>/resources/` as `{name, url,
     * size, modifiedAt}` tuples ordered by `modifiedAt` descending.
     * If the folder does not yet exist (no upload has happened), the
     * response is `{status: 'success', resources: []}` with HTTP 200
     * — never 404.
     *
     * Authentication: any logged-in user (NOT admin-gated) — the
     * uploaded resources are referenced by every dashboard render so
     * gating the listing would break dashboards for non-admins.
     *
     * @return JSONResponse `{status: 'success', resources: [...]}`.
     *
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    public function listResources(): JSONResponse
    {
        try {
            $folder = $this->appData->getFolder(name: ResourceService::FOLDER);
        } catch (NotFoundException $e) {
            // Empty folder → empty array, HTTP 200 (NOT 404).
            return new JSONResponse(
                data: [
                    'status'    => 'success',
                    'resources' => [],
                ],
                statusCode: Http::STATUS_OK
            );
        }

        $resources = [];
        foreach ($folder->getDirectoryListing() as $file) {
            $resources[] = $this->describeResource(file: $file);
        }

        // Sort by modifiedAt descending (newest first).
        usort(
                $resources,
                static function (array $left, array $right): int {
                    return ($right['mtime'] <=> $left['mtime']);
                }
                );

        // Strip internal sort key.
        $resources = array_map(
            static function (array $row): array {
                unset($row['mtime']);
                return $row;
            },
            $resources
        );

        return new JSONResponse(
            data: [
                'status'    => 'success',
                'resources' => $resources,
            ],
            statusCode: Http::STATUS_OK
        );
    }//end listResources()

    /**
     * Describe a single resource file for the listing response.
     *
     * Includes an internal `mtime` field used purely for sorting —
     * stripped before the response is serialised.
     *
     * @param ISimpleFile $file The file to describe.
     *
     * @return array{name: string, url: string, size: int, modifiedAt: string, mtime: int}
     */
    private function describeResource(ISimpleFile $file): array
    {
        $name  = $file->getName();
        $mtime = $file->getMTime();

        return [
            'name'       => $name,
            'url'        => ('/apps/'.Application::APP_ID.'/resource/'.$name),
            'size'       => (int) $file->getSize(),
            'modifiedAt' => gmdate(format: 'Y-m-d\TH:i:s\Z', timestamp: $mtime),
            'mtime'      => $mtime,
        ];
    }//end describeResource()

    /**
     * Test whether a filename is unsafe to use as a leaf name.
     *
     * Rejects any name containing a path separator (`/`, `\`) or a
     * literal `..` segment, plus the empty string. Treated as 404 by
     * the caller to avoid leaking the existence of a system path.
     *
     * @param string $filename The decoded route parameter.
     *
     * @return bool True if the name is unsafe.
     */
    private function isUnsafeFilename(string $filename): bool
    {
        if ($filename === '') {
            return true;
        }

        if (strpos(haystack: $filename, needle: '/') !== false) {
            return true;
        }

        if (strpos(haystack: $filename, needle: '\\') !== false) {
            return true;
        }

        if (strpos(haystack: $filename, needle: '..') !== false) {
            return true;
        }

        return false;
    }//end isUnsafeFilename()

    /**
     * Map a filename's extension to a Content-Type string.
     *
     * Lowercased lookup against {@see CONTENT_TYPE_MAP}; unknown
     * extensions fall back to `application/octet-stream`.
     *
     * @param string $filename The leaf filename.
     *
     * @return string The Content-Type for the StreamResponse.
     */
    private function contentTypeFor(string $filename): string
    {
        $extension = strtolower(string: pathinfo(path: $filename, flags: PATHINFO_EXTENSION));

        return (self::CONTENT_TYPE_MAP[$extension] ?? 'application/octet-stream');
    }//end contentTypeFor()

    /**
     * Build a 404 response with an empty body.
     *
     * Per REQ-RES-006 the body MAY be empty — we deliberately do not
     * leak which path was missing or which traversal pattern was
     * blocked.
     *
     * @return JSONResponse The 404 response.
     */
    private function notFoundResponse(): JSONResponse
    {
        return new JSONResponse(
            data: [],
            statusCode: Http::STATUS_NOT_FOUND
        );
    }//end notFoundResponse()

    /**
     * Throw if the current session user is not an admin.
     *
     * Delegates to `IGroupManager::isAdmin` — both an unauthenticated
     * request and an authenticated non-admin produce HTTP 403.
     *
     * @return void
     *
     * @throws ForbiddenException When the caller is not an admin.
     */
    private function assertAdmin(): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new ForbiddenException();
        }

        if ($this->groupManager->isAdmin(userId: $user->getUID()) === false) {
            throw new ForbiddenException();
        }
    }//end assertAdmin()

    /**
     * Read the raw request body.
     *
     * Extracted so tests can override the source — `php://input` is
     * not realistically pluggable in PHPUnit otherwise. Production
     * code reads from the standard PHP input stream.
     *
     * @return string The raw request body.
     */
    protected function readRequestBody(): string
    {
        return (string) file_get_contents(filename: 'php://input');
    }//end readRequestBody()

    /**
     * Build the standardised error envelope from a typed exception.
     *
     * @param ResourceException $exception The typed exception.
     *
     * @return JSONResponse The error response.
     */
    private function errorResponse(ResourceException $exception): JSONResponse
    {
        return new JSONResponse(
            data: [
                'status'  => 'error',
                'error'   => $exception->getErrorCode(),
                'message' => $exception->getDisplayMessage(),
            ],
            statusCode: $exception->getHttpStatus()
        );
    }//end errorResponse()
}//end class
