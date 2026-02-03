<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Settings;

use OCA\MyDash\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class MyDashAdminSection implements IIconSection {

	public function __construct(
		private readonly IL10N $l,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'mydash';
	}

	public function getName(): string {
		return $this->l->t('MyDash');
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'mydash.svg');
	}
}
