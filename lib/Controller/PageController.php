<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'mydash-main');
		Util::addStyle(Application::APP_ID, 'mydash');

		// Load all widget scripts so legacy widgets can register their callbacks.
		$this->loadWidgetScripts();

		return new TemplateResponse(Application::APP_ID, 'index');
	}

	/**
	 * Load scripts for all available dashboard widgets.
	 * This ensures legacy widgets can register their callbacks via OCA.Dashboard.register.
	 */
	private function loadWidgetScripts(): void {
		$dashboardManager = \OC::$server->get(\OCP\Dashboard\IDashboardManager::class);
		$widgets = $dashboardManager->getWidgets();

		foreach ($widgets as $widget) {
			// Call the widget's load() method to inject its scripts.
			$widget->load();
		}
	}
}
