<?php

/**
 * AnalyticsController
 *
 * Admin-only HTTP entry points for the dashboard view-analytics
 * capability (REQ-ANLT-006..010). Exposes four endpoints:
 *   - `GET /api/admin/analytics/dashboards/top` — top-N dashboards
 *     by view count for the supplied period.
 *   - `GET /api/admin/analytics/dashboards/{uuid}` — daily
 *     breakdown for one dashboard.
 *   - `GET /api/admin/analytics/summary` — instance-wide totals +
 *     top-5.
 *   - `GET /api/admin/analytics/export` — CSV export download.
 *
 * Every endpoint applies a runtime admin guard via
 * {@see \OCP\IGroupManager::isAdmin()} — the framework attribute is
 * `#[NoAdminRequired]` because the in-body check is the actual
 * authorization point (gate-semantic-auth).
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
use OCA\MyDash\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Admin-only controller for dashboard view-analytics endpoints.
 */
class AnalyticsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The HTTP request.
     * @param AnalyticsService $analyticsService The analytics
     *                                           reporting service.
     *                                           manager (admin guard).
     *                                           accessor.
     */
    public function __construct(
        IRequest $request,
        private readonly AnalyticsService $analyticsService,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Handle `GET /api/admin/analytics/dashboards/top` (REQ-ANLT-006).
     *
     * @param string $period The period string (`7d`, `30d`, `90d`).
     * @param int    $limit  Maximum rows.
     *
     * @return JSONResponse The response.
         *
     * @spec openspec/specs/dashboard-view-analytics/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function topDashboards(
        string $period='30d',
        int $limit=10
    ): JSONResponse {

        try {
            $rows = $this->analyticsService->getTopDashboards(
                period: $period,
                limit: $limit
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_period',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return ResponseHelper::success(data: $rows);
    }//end topDashboards()

    /**
     * Handle `GET /api/admin/analytics/dashboards/{uuid}`
     * (REQ-ANLT-007).
     *
     * @param string $uuid   The dashboard UUID from the URL.
     * @param string $period The period string.
     *
     * @return JSONResponse The response.
         *
     * @spec openspec/specs/dashboard-view-analytics/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function dashboardDetail(
        string $uuid,
        string $period='30d'
    ): JSONResponse {
        try {
            $rows = $this->analyticsService->getDashboardDetail(
                dashboardUuid: $uuid,
                period: $period
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_period',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try

        return ResponseHelper::success(data: $rows);
    }//end dashboardDetail()

    /**
     * Handle `GET /api/admin/analytics/summary` (REQ-ANLT-008).
     *
     * @param string $period The period string.
     *
     * @return JSONResponse The response.
         *
     * @spec openspec/specs/dashboard-view-analytics/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function instanceSummary(string $period='30d'): JSONResponse
    {

        try {
            $summary = $this->analyticsService->getInstanceSummary(
                period: $period
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_period',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return ResponseHelper::success(data: $summary);
    }//end instanceSummary()

    /**
     * Handle `GET /api/admin/analytics/export` (REQ-ANLT-010).
     *
     * Returns a `text/csv` attachment with the filename
     * `dashboard-analytics-YYYY-MM-DD.csv` (today's UTC date).
     *
     * @param string $period The period string.
     *
     * @return Response The CSV download response or a JSON error
     *                  envelope.
         *
     * @spec openspec/specs/dashboard-view-analytics/spec.md
 */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function exportCsv(string $period='30d'): Response
    {

        try {
            $csv = $this->analyticsService->generateCsvExport(
                period: $period
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_period',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new DataDownloadResponse(
            data: $csv,
            filename: $this->analyticsService->csvExportFilename(),
            contentType: 'text/csv'
        );
    }//end exportCsv()
}//end class
