<?php

/**
 * FileController
 *
 * HTTP entry point for the file-creation capability (REQ-LBN-004). Exposes a
 * single non-admin `POST /api/files/create` endpoint that accepts
 * `{filename, dir, content}` and returns a `{status, fileId, url}` success
 * envelope or a `{status, error, message}` error envelope on validation
 * failures.
 *
 * All errors are mapped to typed envelopes — raw exception messages are NEVER
 * returned to the client.
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

use OCA\MyDash\Exception\ForbiddenExtensionException;
use OCA\MyDash\Exception\InvalidDirectoryException;
use OCA\MyDash\Exception\InvalidFilenameException;
use OCA\MyDash\Service\FileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the link-button file-creation endpoint.
 */
class FileController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request     The HTTP request.
     * @param FileService     $fileService The file creation service.
     * @param LoggerInterface $logger      PSR logger.
     * @param string|null     $userId      The authenticated user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly FileService $fileService,
        private readonly LoggerInterface $logger,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: 'mydash',
            request: $request
        );
    }//end __construct()

    /**
     * Handle `POST /api/files/create`.
     *
     * Accepts `filename`, `dir` (default `/`), and `content` (default `''`)
     * from the request, delegates strict validation and file I/O to
     * FileService, and returns a typed envelope on success or error.
     *
     * @param string $filename The desired filename (basename only).
     * @param string $dir      Target directory inside the user folder.
     * @param string $content  Initial file content (may be empty).
     *
     * @return JSONResponse Either `{status:'success', fileId, url}` (HTTP 200)
     *                      or `{status:'error', error:<code>, message:<text>}`
     *                      (HTTP 400 or 500).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createFile(
        string $filename='',
        string $dir='/',
        string $content=''
    ): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'not_logged_in',
                    'message' => 'Not logged in',
                ],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $result = $this->fileService->createFile(
                userId: $this->userId,
                filename: $filename,
                dir: $dir,
                content: $content
            );

            return new JSONResponse(
                data: [
                    'status' => 'success',
                    'fileId' => $result['fileId'],
                    'url'    => $result['url'],
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (InvalidFilenameException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => $e->getErrorCode(),
                    'message' => $e->getDisplayMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (InvalidDirectoryException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => $e->getErrorCode(),
                    'message' => $e->getDisplayMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (ForbiddenExtensionException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => $e->getErrorCode(),
                    'message' => $e->getDisplayMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (Throwable $e) {
            // Defence in depth — never leak raw messages.
            $this->logger->error(
                message: 'Unexpected file creation failure',
                context: ['exception' => $e->getMessage()]
            );

            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'file_creation_failed',
                    'message' => 'Failed to create file',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end createFile()
}//end class
