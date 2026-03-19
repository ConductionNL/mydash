<?php

/**
 * MetricsController
 *
 * Controller for exposing Prometheus metrics in text exposition format.
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
use OCA\MyDash\Service\MetricsCollector;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

/**
 * Controller for exposing Prometheus metrics.
 */
class MetricsController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest         $request          The request.
     * @param MetricsCollector $metricsCollector The metrics collector service.
     */
    public function __construct(
        IRequest $request,
        private readonly MetricsCollector $metricsCollector,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Expose Prometheus metrics.
     *
     * @return TextPlainResponse Plain text response with Prometheus metrics.
     *
     * @NoCSRFRequired
     */
    public function index(): TextPlainResponse
    {
        $lines = $this->metricsCollector->collectAll();

        $body     = implode("\n", $lines)."\n";
        $response = new TextPlainResponse($body);
        $response->addHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        return $response;
    }//end index()
}//end class
