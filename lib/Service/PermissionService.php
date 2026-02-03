<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Service;

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\AdminSetting;
use OCP\AppFramework\Db\DoesNotExistException;

class PermissionService {

	public function __construct(
		private readonly DashboardMapper $dashboardMapper,
		private readonly WidgetPlacementMapper $placementMapper,
		private readonly AdminSettingMapper $settingMapper,
	) {
	}

	/**
	 * Check if user can edit a dashboard
	 */
	public function canEditDashboard(string $userId, int $dashboardId): bool {
		try {
			$dashboard = $this->dashboardMapper->find($dashboardId);
		} catch (DoesNotExistException) {
			return false;
		}

		// Admin templates can only be edited by admins
		if ($dashboard->getType() === Dashboard::TYPE_ADMIN_TEMPLATE) {
			return false;
		}

		// User must own the dashboard
		if ($dashboard->getUserId() !== $userId) {
			return false;
		}

		// Check permission level
		$permissionLevel = $this->getEffectivePermissionLevel($dashboard);

		return in_array($permissionLevel, [
			Dashboard::PERMISSION_ADD_ONLY,
			Dashboard::PERMISSION_FULL,
		]);
	}

	/**
	 * Check if user can add widgets to a dashboard
	 */
	public function canAddWidget(string $userId, int $dashboardId): bool {
		try {
			$dashboard = $this->dashboardMapper->find($dashboardId);
		} catch (DoesNotExistException) {
			return false;
		}

		// User must own the dashboard
		if ($dashboard->getUserId() !== $userId) {
			return false;
		}

		// Check permission level
		$permissionLevel = $this->getEffectivePermissionLevel($dashboard);

		return in_array($permissionLevel, [
			Dashboard::PERMISSION_ADD_ONLY,
			Dashboard::PERMISSION_FULL,
		]);
	}

	/**
	 * Check if user can remove a widget
	 */
	public function canRemoveWidget(string $userId, int $placementId): bool {
		try {
			$placement = $this->placementMapper->find($placementId);
			$dashboard = $this->dashboardMapper->find($placement->getDashboardId());
		} catch (DoesNotExistException) {
			return false;
		}

		// User must own the dashboard
		if ($dashboard->getUserId() !== $userId) {
			return false;
		}

		// Check permission level
		$permissionLevel = $this->getEffectivePermissionLevel($dashboard);

		// View only users can't remove anything
		if ($permissionLevel === Dashboard::PERMISSION_VIEW_ONLY) {
			return false;
		}

		// Full permission can remove anything
		if ($permissionLevel === Dashboard::PERMISSION_FULL) {
			return true;
		}

		// Add only users can't remove compulsory widgets
		if ($permissionLevel === Dashboard::PERMISSION_ADD_ONLY) {
			return !$placement->getIsCompulsory();
		}

		return false;
	}

	/**
	 * Check if user can style a widget
	 */
	public function canStyleWidget(string $userId, int $placementId): bool {
		try {
			$placement = $this->placementMapper->find($placementId);
			$dashboard = $this->dashboardMapper->find($placement->getDashboardId());
		} catch (DoesNotExistException) {
			return false;
		}

		// User must own the dashboard
		if ($dashboard->getUserId() !== $userId) {
			return false;
		}

		// Check permission level
		$permissionLevel = $this->getEffectivePermissionLevel($dashboard);

		return in_array($permissionLevel, [
			Dashboard::PERMISSION_ADD_ONLY,
			Dashboard::PERMISSION_FULL,
		]);
	}

	/**
	 * Check if user can create dashboards
	 */
	public function canCreateDashboard(string $userId): bool {
		return $this->settingMapper->getValue(
			AdminSetting::KEY_ALLOW_USER_DASHBOARDS,
			true
		);
	}

	/**
	 * Check if user can have multiple dashboards
	 */
	public function canHaveMultipleDashboards(string $userId): bool {
		return $this->settingMapper->getValue(
			AdminSetting::KEY_ALLOW_MULTIPLE_DASHBOARDS,
			true
		);
	}

	/**
	 * Get the effective permission level for a dashboard
	 */
	public function getEffectivePermissionLevel(Dashboard $dashboard): string {
		// If based on a template, use template's permission level
		if ($dashboard->getBasedOnTemplate() !== null) {
			try {
				$template = $this->dashboardMapper->find($dashboard->getBasedOnTemplate());
				return $template->getPermissionLevel();
			} catch (DoesNotExistException) {
				// Template deleted, use dashboard's level
			}
		}

		// Use dashboard's permission level or default
		$level = $dashboard->getPermissionLevel();
		if (!empty($level)) {
			return $level;
		}

		return $this->settingMapper->getValue(
			AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL,
			Dashboard::PERMISSION_FULL
		);
	}

	/**
	 * Verify user owns a dashboard
	 *
	 * @throws \Exception
	 */
	public function verifyDashboardOwnership(string $userId, int $dashboardId): Dashboard {
		$dashboard = $this->dashboardMapper->find($dashboardId);

		if ($dashboard->getUserId() !== $userId) {
			throw new \Exception('Access denied');
		}

		return $dashboard;
	}

	/**
	 * Verify user owns a placement's dashboard
	 *
	 * @throws \Exception
	 */
	public function verifyPlacementOwnership(string $userId, int $placementId): WidgetPlacement {
		$placement = $this->placementMapper->find($placementId);
		$this->verifyDashboardOwnership($userId, $placement->getDashboardId());

		return $placement;
	}
}
