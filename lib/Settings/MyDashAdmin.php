<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Settings;

use OCA\MyDash\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class MyDashAdmin implements ISettings {

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'mydash-admin');

		return new TemplateResponse(Application::APP_ID, 'settings/admin');
	}

	public function getSection(): string {
		return 'mydash';
	}

	public function getPriority(): int {
		return 10;
	}
}
