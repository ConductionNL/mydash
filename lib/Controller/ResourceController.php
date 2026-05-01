<?php

/**
 * ResourceController
 *
 * HTTP entry point for the resource-uploads capability. Exposes a
 * single admin-only `POST /api/resources` endpoint that accepts a
 * raw JSON body of the shape `{base64: 'data:image/<type>;base64,...'}`
 * and returns a standardised `{status, url, name, size}` envelope.
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

use OCA\MyDash\Exception\ForbiddenException;
use OCA\MyDash\Exception\ResourceException;
use OCA\MyDash\Exception\StorageFailureException;
use OCA\MyDash\Service\ResourceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin-only resource upload controller.
 */
class ResourceController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                    $request         The HTTP request.
     * @param ResourceService             $resourceService The upload pipeline.
     * @param ResourceUploadRequestParser $parser          Parses request body.
     * @param IUserSession                $userSession     Session accessor.
     * @param IGroupManager               $groupManager    Admin checker.
     * @param LoggerInterface             $logger          PSR logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ResourceService $resourceService,
        private readonly ResourceUploadRequestParser $parser,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
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
