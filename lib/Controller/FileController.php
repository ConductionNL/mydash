<?php

/**
 * FileController
 *
 * HTTP entry point for the link-button-widget capability's createFile
 * flow. Exposes:
 *
 * - `POST /api/files/create` (any logged-in user) — accepts JSON
 *   `{filename, dir, content}` and returns `{status, fileId, url}`
 *   where `url` deep-links into the Files app at `?openfile=<fileId>`.
 *
 * Every typed exception from {@see \OCA\MyDash\Service\FileService} is
 * mapped to the standardised `{status, error, message}` error envelope
 * — raw underlying exception messages are NEVER returned to the
 * caller (REQ-LBN-004).
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
use OCA\MyDash\Exception\ForbiddenException;
use OCA\MyDash\Exception\ResourceException;
use OCA\MyDash\Exception\StorageFailureException;
use OCA\MyDash\Service\FileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the link-button-widget createFile flow.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FileController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request     The HTTP request.
     * @param FileService     $fileService File-creation pipeline.
     * @param IUserSession    $userSession Session accessor.
     * @param LoggerInterface $logger      PSR logger.
     */
    public function __construct(
        IRequest $request,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Handle `POST /api/files/create` (REQ-LBN-004).
     *
     * @param string|null $filename Leaf filename.
     * @param string|null $dir      Target subdirectory (default `/`).
     * @param string|null $content  Bytes to write (default empty).
     *
     * @return JSONResponse Either `{status, fileId, url}` on HTTP 200
     *                      or `{status, error, message}` on failure.
     *
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    public function createFile(
        ?string $filename=null,
        ?string $dir='/',
        ?string $content=''
    ): JSONResponse {
        try {
            $userId = $this->resolveUserId();

            $result = $this->fileService->createFile(
                userId: $userId,
                filename: ($filename ?? ''),
                dir: ($dir ?? '/'),
                content: ($content ?? '')
            );

            return new JSONResponse(
                data: $result,
                statusCode: Http::STATUS_OK
            );
        } catch (ForbiddenException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'forbidden',
                    'message' => 'Authentication required',
                ],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        } catch (ResourceException $e) {
            if ($e instanceof StorageFailureException) {
                $this->logger->error(
                    message: 'File create storage failure',
                    context: ['exception' => $e->getMessage()]
                );
            }

            return $this->errorResponse(exception: $e);
        } catch (Throwable $e) {
            // Defence in depth — never leak raw messages on
            // truly unexpected paths.
            $this->logger->error(
                message: 'Unexpected file create failure',
                context: ['exception' => $e->getMessage()]
            );

            $fallback = new StorageFailureException(
                message: 'Failed to create file'
            );

            return $this->errorResponse(exception: $fallback);
        }//end try
    }//end createFile()

    /**
     * Resolve the logged-in user's ID.
     *
     * @return string The user's UID.
     *
     * @throws ForbiddenException When the request is not authenticated.
     */
    private function resolveUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new ForbiddenException();
        }

        return $user->getUID();
    }//end resolveUserId()

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
