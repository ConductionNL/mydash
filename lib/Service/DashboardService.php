<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Service;

use DateTime;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\AdminSetting;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * DashboardService
 *
 * Service for managing dashboards
 *
 * @category Service
 * @package  OCA\MyDash\Service
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/mydash
 */
class DashboardService {

	/**
	 * Constructor
	 *
	 * @param DashboardMapper        $dashboardMapper  Dashboard mapper.
	 * @param WidgetPlacementMapper  $placementMapper  Widget placement mapper.
	 * @param AdminSettingMapper     $settingMapper    Admin setting mapper.
	 * @param IGroupManager          $groupManager     Group manager interface.
	 * @param IUserManager           $userManager      User manager interface.
	 */
	public function __construct(
		private readonly DashboardMapper $dashboardMapper,
		private readonly WidgetPlacementMapper $placementMapper,
		private readonly AdminSettingMapper $settingMapper,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * Generate a UUID v4
	 *
	 * @return string The generated UUID.
	 */
	private function generateUuid(): string {
		// Generate UUID v4 using PHP random_bytes.
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4.
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant.
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Get all dashboards for a user
	 *
	 * @return Dashboard[]
	 */
	public function getUserDashboards(string $userId): array {
		return $this->dashboardMapper->findByUserId($userId);
	}

	/**
	 * Get the effective dashboard for a user
	 * Returns user's active dashboard or applicable admin template
	 */
	public function getEffectiveDashboard(string $userId): ?array {
		// Try to get user's active dashboard first
		try {
			$dashboard = $this->dashboardMapper->findActiveByUserId($userId);
			$placements = $this->placementMapper->findByDashboardId($dashboard->getId());
			$permissionLevel = $this->getEffectivePermissionLevel($userId, $dashboard);

			return [
				'dashboard' => $dashboard,
				'placements' => $placements,
				'permissionLevel' => $permissionLevel,
			];
		} catch (DoesNotExistException) {
			// No active dashboard, check if user has any dashboards
		}

		// Check if user has any dashboards
		$userDashboards = $this->dashboardMapper->findByUserId($userId);
		if (!empty($userDashboards)) {
			// Activate the first one
			$dashboard = $userDashboards[0];
			$this->dashboardMapper->setActive($dashboard->getId(), $userId);
			$dashboard->setIsActive(true);

			$placements = $this->placementMapper->findByDashboardId($dashboard->getId());
			$permissionLevel = $this->getEffectivePermissionLevel($userId, $dashboard);

			return [
				'dashboard' => $dashboard,
				'placements' => $placements,
				'permissionLevel' => $permissionLevel,
			];
		}

		// Check if user creation is allowed
		$allowUserDashboards = $this->settingMapper->getValue(
			AdminSetting::KEY_ALLOW_USER_DASHBOARDS,
			true
		);

		// Try to get applicable admin template
		$template = $this->getApplicableTemplate($userId);

		if ($template !== null) {
			if ($allowUserDashboards) {
				// Create a user dashboard based on template
				$dashboard = $this->createDashboardFromTemplate($userId, $template);
				$placements = $this->placementMapper->findByDashboardId($dashboard->getId());
			} else {
				// Return template directly (view only)
				$placements = $this->placementMapper->findByDashboardId($template->getId());
				return [
					'dashboard' => $template,
					'placements' => $placements,
					'permissionLevel' => Dashboard::PERMISSION_VIEW_ONLY,
				];
			}

			$permissionLevel = $this->getEffectivePermissionLevel($userId, $dashboard);

			return [
				'dashboard' => $dashboard,
				'placements' => $placements,
				'permissionLevel' => $permissionLevel,
			];
		}

		// No template, create empty dashboard if allowed
		if ($allowUserDashboards) {
			$dashboard = $this->createDashboard($userId, 'My Dashboard');
			return [
				'dashboard' => $dashboard,
				'placements' => [],
				'permissionLevel' => Dashboard::PERMISSION_FULL,
			];
		}

		return null;
	}

	/**
	 * Get the applicable admin template for a user
	 *
	 * @param string $userId The user ID.
	 *
	 * @return Dashboard|null The applicable template or null.
	 */
	public function getApplicableTemplate(string $userId): ?Dashboard {
		$templates = $this->dashboardMapper->findAdminTemplates();

		// Get user object and their groups.
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return null;
		}
		
		$userGroups = $this->groupManager->getUserGroupIds($user);

		// Find template that matches user's groups.
		foreach ($templates as $template) {
			$targetGroups = $template->getTargetGroupsArray();

			// Empty target groups means applies to all users.
			if (empty($targetGroups)) {
				continue; // Check for more specific templates first.
			}

			// Check if user is in any target group.
			if (!empty(array_intersect($userGroups, $targetGroups))) {
				return $template;
			}
		}

		// Return default template if exists.
		try {
			return $this->dashboardMapper->findDefaultTemplate();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Create a new dashboard for a user
	 *
	 * @param string      $userId      The user ID.
	 * @param string      $name        The dashboard name.
	 * @param string|null $description The dashboard description.
	 *
	 * @return Dashboard The created dashboard.
	 */
	public function createDashboard(string $userId, string $name, ?string $description = null): Dashboard {
		$now = (new DateTime())->format('Y-m-d H:i:s');
		$dashboard = new Dashboard();
		$dashboard->setUuid($this->generateUuid());
		$dashboard->setName($name);
		$dashboard->setDescription($description);
		$dashboard->setType(Dashboard::TYPE_USER);
		$dashboard->setUserId($userId);
		$dashboard->setGridColumns(12); // Default grid columns.
		$dashboard->setPermissionLevel(Dashboard::PERMISSION_FULL);
		$dashboard->setIsActive(1); // Use 1 for true in SMALLINT column.
		$dashboard->setCreatedAt($now);
		$dashboard->setUpdatedAt($now);

		// Deactivate other dashboards.
		$this->dashboardMapper->deactivateAllForUser($userId);

		return $this->dashboardMapper->insert($dashboard);
	}

	/**
	 * Create a user dashboard based on an admin template
	 */
	public function createDashboardFromTemplate(string $userId, Dashboard $template): Dashboard {
		// Create user dashboard
		$dashboard = new Dashboard();
		$dashboard->setUuid(Uuid::uuid4()->toString());
		$dashboard->setName($template->getName());
		$dashboard->setDescription($template->getDescription());
		$dashboard->setType(Dashboard::TYPE_USER);
		$dashboard->setUserId($userId);
		$dashboard->setBasedOnTemplate($template->getId());
		$dashboard->setGridColumns($template->getGridColumns());
		$dashboard->setPermissionLevel($template->getPermissionLevel());
		$dashboard->setIsActive(true);
		$dashboard->setCreatedAt(new DateTime());
		$dashboard->setUpdatedAt(new DateTime());

		// Deactivate other dashboards
		$this->dashboardMapper->deactivateAllForUser($userId);

		$dashboard = $this->dashboardMapper->insert($dashboard);

		// Copy widget placements from template
		$templatePlacements = $this->placementMapper->findByDashboardId($template->getId());
		foreach ($templatePlacements as $templatePlacement) {
			$placement = new WidgetPlacement();
			$placement->setDashboardId($dashboard->getId());
			$placement->setWidgetId($templatePlacement->getWidgetId());
			$placement->setGridX($templatePlacement->getGridX());
			$placement->setGridY($templatePlacement->getGridY());
			$placement->setGridWidth($templatePlacement->getGridWidth());
			$placement->setGridHeight($templatePlacement->getGridHeight());
			$placement->setIsCompulsory($templatePlacement->getIsCompulsory());
			$placement->setIsVisible($templatePlacement->getIsVisible());
			$placement->setStyleConfig($templatePlacement->getStyleConfig());
			$placement->setCustomTitle($templatePlacement->getCustomTitle());
			$placement->setShowTitle($templatePlacement->getShowTitle());
			$placement->setSortOrder($templatePlacement->getSortOrder());
			$placement->setCreatedAt(new DateTime());
			$placement->setUpdatedAt(new DateTime());

			$this->placementMapper->insert($placement);
		}

		return $dashboard;
	}

	/**
	 * Update a dashboard
	 */
	public function updateDashboard(int $dashboardId, string $userId, array $data): Dashboard {
		$dashboard = $this->dashboardMapper->find($dashboardId);

		// Verify ownership
		if ($dashboard->getUserId() !== $userId) {
			throw new \Exception('Access denied');
		}

		if (isset($data['name'])) {
			$dashboard->setName($data['name']);
		}
		if (isset($data['description'])) {
			$dashboard->setDescription($data['description']);
		}
		if (isset($data['gridColumns'])) {
			$dashboard->setGridColumns($data['gridColumns']);
		}

		$dashboard->setUpdatedAt(new DateTime());

		// Update widget placements if provided
		if (isset($data['placements']) && is_array($data['placements'])) {
			$this->placementMapper->updatePositions($data['placements']);
		}

		return $this->dashboardMapper->update($dashboard);
	}

	/**
	 * Delete a dashboard
	 */
	public function deleteDashboard(int $dashboardId, string $userId): void {
		$dashboard = $this->dashboardMapper->find($dashboardId);

		// Verify ownership
		if ($dashboard->getUserId() !== $userId) {
			throw new \Exception('Access denied');
		}

		// Delete placements first
		$this->placementMapper->deleteByDashboardId($dashboardId);

		// Delete dashboard
		$this->dashboardMapper->delete($dashboard);
	}

	/**
	 * Activate a dashboard for a user
	 */
	public function activateDashboard(int $dashboardId, string $userId): Dashboard {
		$dashboard = $this->dashboardMapper->find($dashboardId);

		// Verify ownership
		if ($dashboard->getUserId() !== $userId) {
			throw new \Exception('Access denied');
		}

		$this->dashboardMapper->setActive($dashboardId, $userId);
		$dashboard->setIsActive(true);

		return $dashboard;
	}

	/**
	 * Get the effective permission level for a user on a dashboard
	 */
	private function getEffectivePermissionLevel(string $userId, Dashboard $dashboard): string {
		// If it's a user dashboard, check the template it's based on
		if ($dashboard->getBasedOnTemplate() !== null) {
			try {
				$template = $this->dashboardMapper->find($dashboard->getBasedOnTemplate());
				return $template->getPermissionLevel();
			} catch (DoesNotExistException) {
				// Template was deleted, use full permissions
			}
		}

		// Return dashboard's own permission level or default
		$defaultLevel = $this->settingMapper->getValue(
			AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL,
			Dashboard::PERMISSION_FULL
		);

		return $dashboard->getPermissionLevel() ?: $defaultLevel;
	}
}
