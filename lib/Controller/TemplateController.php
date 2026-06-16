<?php

/**
 * TemplateController
 *
 * HTTP entry point for the dashboard-templates discovery + save-as-template
 * capability (REQ-TMPL-014..017). Exposes the read-only gallery endpoint
 * available to every logged-in user, and the owner-only save-as-template
 * action that converts a personal dashboard into a reusable admin template.
 *
 * The admin-only preview-image upload endpoint lives on
 * {@see AdminController::uploadTemplatePreviewImage()} so the admin gating
 * stays consistent with the rest of the `/api/admin/...` namespace.
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
use OCA\MyDash\Exception\ForbiddenException;
use OCA\MyDash\Service\ActionAuthService;
use OCA\MyDash\Service\AdminTemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the template gallery + save-as-template flow
 * (REQ-TMPL-014, REQ-TMPL-015).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TemplateController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request         The request.
     * @param AdminTemplateService $templateService The template service.
     * @param IUserSession         $userSession     The current user
     *                                              session (required for
     *                                              the owner check).
     * @param ActionAuthService    $actionAuth      ADR-023 action
     *                                              authorization.
     */
    public function __construct(
        IRequest $request,
        private readonly AdminTemplateService $templateService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * `GET /api/templates/gallery` — list all admin templates with the
     * gallery metadata (REQ-TMPL-014).
     *
     * Query parameters:
     *   - `category` (string, optional): exact-match category filter.
     *   - `sort` (string, optional): `name` (default) or `updatedAt`.
     *
     * Available to every logged-in user. Returns a `{status: 'success',
     * templates: [...]}` envelope with no widget bodies — gallery is a
     * list view, not a render.
     *
     * @param string|null $category Optional category filter.
     * @param string      $sort     Sort key (`name` or `updatedAt`).
     *
     * @return JSONResponse The gallery list envelope.
         *
     * @spec openspec/specs/admin-templates/spec.md
 */
    #[NoAdminRequired]
    public function gallery(
        ?string $category=null,
        string $sort='name'
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction($user, 'template.gallery');
        } catch (OCSForbiddenException) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        $templates = $this->templateService->getGallery(
            category: $category,
            sortBy: $sort
        );

        return new JSONResponse(
            data: [
                'status'    => 'success',
                'templates' => $templates,
            ],
            statusCode: Http::STATUS_OK
        );
    }//end gallery()

    /**
     * `POST /api/dashboards/{uuid}/save-as-template` — convert a
     * personal dashboard into a new admin template (REQ-TMPL-015).
     *
     * Body fields (JSON):
     *   - `name` (string, required): template display name.
     *   - `description` (string, optional): long-form gallery description.
     *   - `category` (string, optional): free-form category label.
     *   - `previewImage` (string, optional): pre-uploaded preview URL.
     *
     * Owner check enforced inside the service: source row MUST have
     * `userId === <calling user>` AND `type === 'user'`. Mismatches map
     * to HTTP 403 — never 404 — to keep the contract consistent with
     * the spec.
     *
     * @param string      $uuid         The source dashboard UUID.
     * @param string|null $name         Template name (required).
     * @param string|null $description  Long-form description.
     * @param string|null $category     Free-form category label.
     * @param string|null $previewImage Pre-uploaded preview URL.
     *
     * @return JSONResponse The new template envelope.
         *
     * @spec openspec/specs/admin-templates/spec.md
 */
    #[NoAdminRequired]
    public function saveAsTemplate(
        string $uuid,
        ?string $name=null,
        ?string $description=null,
        ?string $category=null,
        ?string $previewImage=null
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'unauthenticated',
                    'message' => 'Login required',
                ],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $metadata = [
            'name'         => (string) $name,
            'description'  => $description,
            'category'     => $category,
            'previewImage' => $previewImage,
        ];

        try {
            $template = $this->templateService->saveAsTemplate(
                userId: $user->getUID(),
                dashboardUuid: $uuid,
                metadata: $metadata
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_payload',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (ForbiddenException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'forbidden',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'forbidden',
                    'message' => 'Dashboard not found or not owned by you',
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }//end try

        return new JSONResponse(
            data: [
                'status'   => 'success',
                'template' => $template->jsonSerialize(),
            ],
            statusCode: Http::STATUS_CREATED
        );
    }//end saveAsTemplate()
}//end class
