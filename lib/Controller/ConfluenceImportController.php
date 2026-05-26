<?php

/**
 * ConfluenceImportController
 *
 * Admin-only HTTP surface for the `confluence-html-import` capability.
 * Wraps {@see ConfluenceImportService} with multipart upload handling,
 * admin-guard enforcement, and JSON response shaping.
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
use OCA\MyDash\Service\ConfluenceImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Admin-only Confluence import endpoints.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 *      `$_FILES` is the only multipart entry point under Nextcloud.
 */
class ConfluenceImportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request       Request handle.
     * @param ConfluenceImportService $importService The import orchestrator.
     * @param IUserSession            $userSession   Current session.
     */
    public function __construct(
        IRequest $request,
        private readonly ConfluenceImportService $importService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * `POST /api/admin/import/confluence/dry-run` — REQ-CFLI-007.
     *
     * @return JSONResponse The dry-run preview, or an error response.
     *
     * @NoCSRFRequired
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    /** @spec openspec/specs/confluence-html-import/spec.md */
    public function dryRun(): JSONResponse
    {
        $tmpName = $this->resolveUpload();
        if ($tmpName instanceof JSONResponse) {
            return $tmpName;
        }

        try {
            $result = $this->importService->dryRun(zipPath: $tmpName);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (Throwable $e) {
            return new JSONResponse(
                data: ['error' => 'Confluence dry-run failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(data: $result);
    }//end dryRun()

    /**
     * `POST /api/admin/import/confluence` — REQ-CFLI-001..006, 009, 012.
     *
     * @param string|null $parentUuid Optional parent dashboard UUID
     *                                under which root pages will be slotted.
     *
     * @return JSONResponse The import summary, or an error response.
     *
     * @NoCSRFRequired
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    /** @spec openspec/specs/confluence-html-import/spec.md */
    public function import(?string $parentUuid=null): JSONResponse
    {
        $tmpName = $this->resolveUpload();
        if ($tmpName instanceof JSONResponse) {
            return $tmpName;
        }

        $userId = (string) $this->userSession->getUser()?->getUID();

        $resolvedParent = null;
        if ($parentUuid !== null && $parentUuid !== '') {
            $resolvedParent = $parentUuid;
        }

        try {
            $result = $this->importService->import(
                zipPath: $tmpName,
                currentUserId: $userId,
                parentUuid: $resolvedParent
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (Throwable $e) {
            return new JSONResponse(
                data: ['error' => 'Confluence import failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(data: $result);
    }//end import()

    /**
     * Locate and validate the uploaded ZIP, returning its tmp path.
     *
     * @return string|JSONResponse Either the tmp path or a 400 response.
     */
    private function resolveUpload(): string|JSONResponse
    {
        $upload = $_FILES['file'] ?? null;
        if (is_array($upload) === false
            || isset($upload['tmp_name']) === false
            || (string) $upload['tmp_name'] === ''
        ) {
            return new JSONResponse(
                data: ['error' => 'No file uploaded under field "file".'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return (string) $upload['tmp_name'];
    }//end resolveUpload()

    /**
     * Verify the current session belongs to a Nextcloud admin.
     *
     * @return JSONResponse|null Null when admin; an error response otherwise.
     */
}//end class
