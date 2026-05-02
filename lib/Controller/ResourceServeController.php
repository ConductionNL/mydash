<?php

/**
 * ResourceServeController
 *
 * Read-side HTTP entry point for the resource-uploads capability.
 * Pairs with the existing admin-only `ResourceController::upload` to
 * deliver REQ-RES-006..008 — public serve + listing.
 *
 * Exposes:
 *
 *  - `GET /apps/mydash/resource/{filename}` — non-OCS plain web route
 *    streaming the resource bytes via a `php://memory` buffer with an
 *    extension-derived `Content-Type` and `Cache-Control: public,
 *    max-age=31536000` (REQ-RES-006).
 *  - `GET /apps/mydash/api/resources` — listing endpoint returning
 *    `{status, resources: [{name, url, size, modifiedAt}, …]}` ordered
 *    by `modifiedAt` descending (REQ-RES-007).
 *
 * Cache busting: filenames produced by REQ-RES-004 carry a `uniqid`
 * suffix, so the one-year immutable cache is safe — when an asset
 * changes, a new upload yields a brand-new filename and the previously
 * cached entry is naturally bypassed.
 *
 * Path traversal: the route requirement `[^/]+` blocks `/` at the
 * routing layer; this controller adds defence-in-depth checks for
 * `..`, leading dots, backslashes, and empty values, all returning a
 * uniform HTTP 404 (no detail leaked).
 *
 * Memory bound: `getResource` checks `$file->getSize()` BEFORE
 * `getContent()` and refuses anything above the 5 MB upload cap with
 * HTTP 413. Only reachable via manual filesystem tampering since
 * REQ-RES-003 caps uploads to 5 MB, but worth the guard.
 *
 * Auth: both methods are `#[NoAdminRequired]` — any logged-in user
 * may read resources (admin gating would lock dashboards out of their
 * own assets).
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
use OCA\MyDash\Service\ResourceServeService;
use OCA\MyDash\Service\ResourceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public read-side controller for uploaded resources.
 */
class ResourceServeController extends Controller
{
    /**
     * Cache directive sent on every served resource (REQ-RES-006).
     *
     * @var string
     */
    private const CACHE_CONTROL = 'public, max-age=31536000';

    /**
     * Constructor.
     *
     * @param IRequest             $request The HTTP request.
     * @param ResourceServeService $serve   Filesystem + formatting helper.
     * @param LoggerInterface      $logger  PSR logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ResourceServeService $serve,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Handle `GET /apps/mydash/resource/{filename}` — serve raw bytes.
     *
     * Returns a `StreamResponse` with extension-derived `Content-Type`
     * and a one-year immutable `Cache-Control` header. Path traversal
     * and missing files yield a flat HTTP 404 (no detail leaked).
     * Files larger than the 5 MB upload cap (REQ-RES-003) — only
     * reachable via manual filesystem tampering — are refused with
     * HTTP 413 BEFORE any bytes are loaded into memory.
     *
     * Cache busting: REQ-RES-004 mandates a `uniqid` suffix on every
     * uploaded filename, so the one-year cache is safe — a logical
     * asset change yields a brand-new filename, naturally bypassing
     * the previously cached entry.
     *
     * @param string $filename The leaf filename inside the resources folder.
     *
     * @return StreamResponse|JSONResponse Stream on success, JSON envelope on error.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getResource(string $filename): StreamResponse|JSONResponse
    {
        if ($this->isUnsafeFilename(filename: $filename) === true) {
            return $this->notFoundResponse();
        }

        $file = $this->serve->findFile(filename: $filename);
        if ($file === null) {
            return $this->notFoundResponse();
        }

        // 413 guard — refuse oversize files BEFORE pulling bytes.
        if ((int) $file->getSize() > ResourceService::MAX_BYTES) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'file_too_large',
                ],
                statusCode: Http::STATUS_REQUEST_ENTITY_TOO_LARGE
            );
        }

        try {
            $bytes = $file->getContent();
        } catch (Throwable $e) {
            $this->logger->warning(
                message: 'Resource serve failed to read bytes',
                context: ['exception' => $e->getMessage()]
            );

            return $this->notFoundResponse();
        }

        return $this->buildStreamResponse(filename: $filename, bytes: $bytes);
    }//end getResource()

    /**
     * Handle `GET /api/resources` — list uploaded resources.
     *
     * Returns `{status: 'success', resources: [{name, url, size,
     * modifiedAt}, …]}` ordered by `modifiedAt` descending. When the
     * `resources/` folder does not yet exist (or any other read error
     * occurs) the response is HTTP 200 with `{status: 'success',
     * resources: []}` — never a 404.
     *
     * The returned `url` values point at the serve route added by
     * `getResource`. Cache busting falls out of the `uniqid` suffix
     * REQ-RES-004 puts on every filename — a logical asset change
     * yields a new name and a fresh cache entry.
     *
     * @return JSONResponse The list envelope.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function listResources(): JSONResponse
    {
        $files = $this->serve->listFiles();

        $resources = [];
        foreach ($files as $file) {
            $name        = $file->getName();
            $resources[] = [
                'name'       => $name,
                'url'        => ('/apps/'.Application::APP_ID.'/resource/'.$name),
                'size'       => (int) $file->getSize(),
                'modifiedAt' => $this->serve->formatTimestamp(epoch: $file->getMTime()),
                '_mtime'     => $file->getMTime(),
            ];
        }

        // Sort newest first by mtime, then strip the helper key.
        usort(
            array: $resources,
            callback: static function (array $left, array $right): int {
                return ($right['_mtime'] <=> $left['_mtime']);
            }
        );

        $resources = array_map(
            callback: static function (array $entry): array {
                unset($entry['_mtime']);
                return $entry;
            },
            array: $resources
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
     * Decide whether a route filename argument is unsafe.
     *
     * Treats empty values, `.`, `..`, and any value containing `/`,
     * `\`, or `..` as unsafe. The route requirement `[^/]+` already
     * blocks `/`, but the others are caught here as defence in depth.
     *
     * @param string $filename The filename argument from the route.
     *
     * @return bool True if the value is unsafe and MUST yield HTTP 404.
     */
    private function isUnsafeFilename(string $filename): bool
    {
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return true;
        }

        if (str_contains(haystack: $filename, needle: '/') === true) {
            return true;
        }

        if (str_contains(haystack: $filename, needle: '\\') === true) {
            return true;
        }

        if (str_contains(haystack: $filename, needle: '..') === true) {
            return true;
        }

        return false;
    }//end isUnsafeFilename()

    /**
     * Build the StreamResponse for a successful serve.
     *
     * Allocates a `php://memory` stream, writes the bytes, rewinds,
     * and attaches the standard headers. Streaming via `php://memory`
     * is bounded by the 5 MB cap (REQ-RES-008).
     *
     * @param string $filename The filename being served (for Content-Type).
     * @param string $bytes    The raw bytes to stream.
     *
     * @return StreamResponse|JSONResponse The stream, or a 404 envelope
     *                                     if the memory stream cannot
     *                                     be allocated.
     */
    private function buildStreamResponse(string $filename, string $bytes): StreamResponse|JSONResponse
    {
        $stream = fopen(filename: 'php://memory', mode: 'r+b');
        if ($stream === false) {
            return $this->notFoundResponse();
        }

        fwrite(stream: $stream, data: $bytes);
        rewind(stream: $stream);

        $response = new StreamResponse(filePath: $stream);
        $response->addHeader(
            name: 'Content-Type',
            value: $this->serve->contentTypeForFilename(filename: $filename)
        );
        $response->addHeader(name: 'Cache-Control', value: self::CACHE_CONTROL);
        $response->addHeader(
            name: 'Content-Length',
            value: (string) strlen(string: $bytes)
        );

        return $response;
    }//end buildStreamResponse()

    /**
     * Build the empty 404 envelope.
     *
     * Body is intentionally empty — callers MUST NOT leak which
     * specific failure mode tripped (missing file vs. blocked
     * traversal vs. permissions); a uniform 404 is the contract.
     *
     * @return JSONResponse The empty 404 envelope.
     */
    private function notFoundResponse(): JSONResponse
    {
        return new JSONResponse(
            data: [],
            statusCode: Http::STATUS_NOT_FOUND
        );
    }//end notFoundResponse()
}//end class
