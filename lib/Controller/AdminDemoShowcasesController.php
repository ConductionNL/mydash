<?php

/**
 * AdminDemoShowcasesController
 *
 * HTTP surface for the `demo-data-showcases` capability. Exposes
 * three admin-only endpoints (REQ-DEMO-002, REQ-DEMO-003, REQ-DEMO-006):
 *
 *   - `GET    /api/admin/demo-showcases`
 *   - `POST   /api/admin/demo-showcases/{id}/install`
 *   - `DELETE /api/admin/demo-showcases/{id}`
 *
 * Admin enforcement uses the same {@see IGroupManager::isAdmin} runtime
 * gate as the sibling `dashboard-export-import` controller — the
 * request-attribute alone is not sufficient (per Hydra semantic-auth
 * gate).
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
use OCA\MyDash\Exception\ShowcaseNotFoundException;
use OCA\MyDash\Service\DemoShowcasesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin endpoints for managing bundled demo showcase dashboards.
 */
class AdminDemoShowcasesController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request      The HTTP request.
     * @param DemoShowcasesService $showcasesSvc Showcase service.
     * @param IUserSession         $userSession  Active user session.
     * @param IGroupManager        $groupManager Admin check.
     * @param LoggerInterface      $logger       PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly DemoShowcasesService $showcasesSvc,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Inline admin guard.
     *
     * @return JSONResponse|null Non-null = caller must be rejected.
     */
    private function assertAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Not authenticated'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        if ($this->groupManager->isAdmin(userId: $user->getUID()) === false) {
            return new JSONResponse(
                data: ['error' => 'Admin required'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end assertAdmin()

    /**
     * List bundled showcases with installation status (REQ-DEMO-002).
     *
     * @return JSONResponse Showcase descriptors, or 401/403.
         *
     * @spec openspec/specs/demo-data-showcases/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function index(): JSONResponse
    {
        $guard = $this->assertAdmin();
        if ($guard !== null) {
            return $guard;
        }

        return ResponseHelper::success(
            data: $this->showcasesSvc->getAvailableShowcases()
        );
    }//end index()

    /**
     * Install a bundled showcase (REQ-DEMO-003, REQ-DEMO-004).
     *
     * Always returns the dashboard UUID — when the showcase is
     * already installed and `force` is unset, the existing UUID is
     * returned with `alreadyInstalled: true` so callers can render an
     * informational banner.
     *
     * @param string $id    The showcase ID (path segment).
     * @param string $lang  Optional locale (always resolves to `nl`
     *                      in v1; REQ-DEMO-007).
     * @param bool   $force Force reinstallation, removing the existing
     *                      dashboard if any.
     *
     * @return JSONResponse The install result, or an error.
         *
     * @spec openspec/specs/demo-data-showcases/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function install(
        string $id,
        string $lang='nl',
        bool $force=false
    ): JSONResponse {
        $guard = $this->assertAdmin();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $result = $this->showcasesSvc->installShowcase(
                showcaseId: $id,
                lang: $lang,
                force: $force
            );
        } catch (ShowcaseNotFoundException $e) {
            return new JSONResponse(
                data: ['error' => 'Showcase not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Showcase install failed',
                context: [
                    'showcaseId' => $id,
                    'exception'  => $e,
                ]
            );
            return new JSONResponse(
                data: ['error' => 'Showcase installation failed'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        $statusCode = Http::STATUS_CREATED;
        if ($result['alreadyInstalled'] === true) {
            $statusCode = Http::STATUS_OK;
        }

        return new JSONResponse(
            data: [
                'installedDashboardUuid' => $result['installedDashboardUuid'],
                'skippedWidgets'         => $result['skippedWidgets'],
                'alreadyInstalled'       => $result['alreadyInstalled'],
            ],
            statusCode: $statusCode
        );
    }//end install()

    /**
     * Uninstall a previously-installed showcase (REQ-DEMO-006).
     *
     * Idempotent — returns 204 even when the showcase is not currently
     * installed.
     *
     * @param string $id The showcase ID (path segment).
     *
     * @return JSONResponse Empty 204, or 401/403.
         *
     * @spec openspec/specs/demo-data-showcases/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function destroy(string $id): JSONResponse
    {
        $guard = $this->assertAdmin();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $this->showcasesSvc->uninstallShowcase(showcaseId: $id);
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Showcase uninstall failed',
                context: [
                    'showcaseId' => $id,
                    'exception'  => $e,
                ]
            );
            return new JSONResponse(
                data: ['error' => 'Showcase uninstall failed'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
    }//end destroy()
}//end class
