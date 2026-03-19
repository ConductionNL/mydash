<?php

/**
 * HealthController
 *
 * Controller for exposing health check status.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for health check endpoint.
 */
class HealthController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest        $request The request.
     * @param IDBConnection   $db      The database connection.
     * @param LoggerInterface $logger  Logger for error reporting.
     */
    public function __construct(
        IRequest $request,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Return health check status.
     *
     * @return JSONResponse JSON response with health status and checks.
     *
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        $checks = [];
        $status = 'ok';

        // Database check.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'));
            $result = $qb->executeQuery();
            $result->closeCursor();
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['database'] = 'error';
            $status              = 'error';
            $this->logger->error('Health check: database failed', ['exception' => $e->getMessage()]);
        }

        return new JSONResponse(
            [
                'status' => $status,
                'checks' => $checks,
            ]
        );
    }//end index()
}//end class
