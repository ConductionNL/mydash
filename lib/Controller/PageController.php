<?php

/**
 * PageController
 *
 * Controller for rendering the main page.
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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest $request The request.
     */
    public function __construct(
        IRequest $request,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the main index page.
     *
     * @return TemplateResponse The template response.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) - Util::addScript/addStyle is the Nextcloud API
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        Util::addScript(application: Application::APP_ID, file: 'mydash-main');
        Util::addStyle(application: Application::APP_ID, file: 'mydash');

        // Load all widget scripts so legacy widgets can register their callbacks.
        $this->loadWidgetScripts();

        return new TemplateResponse(
            appName: Application::APP_ID,
            templateName: 'index'
        );
    }//end index()

    /**
     * Load scripts for all available dashboard widgets.
     * This ensures legacy widgets can register their callbacks via OCA.Dashboard.register.
     *
     * @return void
     */
    private function loadWidgetScripts(): void
    {
        $dashboardManager = \OC::$server->get(
            \OCP\Dashboard\IManager::class
        );
        $widgets          = $dashboardManager->getWidgets();

        foreach ($widgets as $widget) {
            // Call the widget's load() method to inject its scripts.
            $widget->load();
        }
    }//end loadWidgetScripts()
}//end class
