<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Controller;

use DateTime;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Ramsey\Uuid\Uuid;

class AdminController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly DashboardMapper $dashboardMapper,
		private readonly AdminSettingMapper $settingMapper,
		private readonly WidgetPlacementMapper $placementMapper,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List all admin dashboard templates
	 */
	public function listTemplates(): JSONResponse {
		$templates = $this->dashboardMapper->findAdminTemplates();

		return new JSONResponse(array_map(fn($t) => $t->jsonSerialize(), $templates));
	}

	/**
	 * Get a specific admin template
	 */
	public function getTemplate(int $id): JSONResponse {
		try {
			$template = $this->dashboardMapper->find($id);

			if ($template->getType() !== Dashboard::TYPE_ADMIN_TEMPLATE) {
				return new JSONResponse(['error' => 'Not an admin template'], Http::STATUS_BAD_REQUEST);
			}

			$placements = $this->placementMapper->findByDashboardId($id);

			return new JSONResponse([
				'template' => $template->jsonSerialize(),
				'placements' => array_map(fn($p) => $p->jsonSerialize(), $placements),
			]);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Create a new admin template
	 */
	public function createTemplate(
		string $name,
		?string $description = null,
		?array $targetGroups = null,
		string $permissionLevel = Dashboard::PERMISSION_ADD_ONLY,
		bool $isDefault = false
	): JSONResponse {
		try {
			// If setting as default, clear other defaults
			if ($isDefault) {
				$this->dashboardMapper->clearDefaultTemplates();
			}

			$template = new Dashboard();
			$template->setUuid(Uuid::uuid4()->toString());
			$template->setName($name);
			$template->setDescription($description);
			$template->setType(Dashboard::TYPE_ADMIN_TEMPLATE);
			$template->setUserId(null);
			$template->setGridColumns(12);
			$template->setPermissionLevel($permissionLevel);
			$template->setTargetGroupsArray($targetGroups ?? []);
			$template->setIsDefault($isDefault);
			$template->setCreatedAt(new DateTime());
			$template->setUpdatedAt(new DateTime());

			$template = $this->dashboardMapper->insert($template);

			return new JSONResponse($template->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update an admin template
	 */
	public function updateTemplate(
		int $id,
		?string $name = null,
		?string $description = null,
		?array $targetGroups = null,
		?string $permissionLevel = null,
		?bool $isDefault = null,
		?int $gridColumns = null
	): JSONResponse {
		try {
			$template = $this->dashboardMapper->find($id);

			if ($template->getType() !== Dashboard::TYPE_ADMIN_TEMPLATE) {
				return new JSONResponse(['error' => 'Not an admin template'], Http::STATUS_BAD_REQUEST);
			}

			if ($name !== null) {
				$template->setName($name);
			}
			if ($description !== null) {
				$template->setDescription($description);
			}
			if ($targetGroups !== null) {
				$template->setTargetGroupsArray($targetGroups);
			}
			if ($permissionLevel !== null) {
				$template->setPermissionLevel($permissionLevel);
			}
			if ($isDefault !== null) {
				if ($isDefault) {
					$this->dashboardMapper->clearDefaultTemplates();
				}
				$template->setIsDefault($isDefault);
			}
			if ($gridColumns !== null) {
				$template->setGridColumns($gridColumns);
			}

			$template->setUpdatedAt(new DateTime());

			$template = $this->dashboardMapper->update($template);

			return new JSONResponse($template->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete an admin template
	 */
	public function deleteTemplate(int $id): JSONResponse {
		try {
			$template = $this->dashboardMapper->find($id);

			if ($template->getType() !== Dashboard::TYPE_ADMIN_TEMPLATE) {
				return new JSONResponse(['error' => 'Not an admin template'], Http::STATUS_BAD_REQUEST);
			}

			// Delete placements first
			$this->placementMapper->deleteByDashboardId($id);

			// Delete template
			$this->dashboardMapper->delete($template);

			return new JSONResponse(['status' => 'ok']);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Get admin settings
	 */
	public function getSettings(): JSONResponse {
		$settings = $this->settingMapper->getAllAsArray();

		// Return with defaults
		return new JSONResponse([
			'defaultPermissionLevel' => $settings[AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL] ?? Dashboard::PERMISSION_ADD_ONLY,
			'allowUserDashboards' => $settings[AdminSetting::KEY_ALLOW_USER_DASHBOARDS] ?? true,
			'allowMultipleDashboards' => $settings[AdminSetting::KEY_ALLOW_MULTIPLE_DASHBOARDS] ?? true,
			'defaultGridColumns' => $settings[AdminSetting::KEY_DEFAULT_GRID_COLUMNS] ?? 12,
		]);
	}

	/**
	 * Update admin settings
	 */
	public function updateSettings(
		?string $defaultPermissionLevel = null,
		?bool $allowUserDashboards = null,
		?bool $allowMultipleDashboards = null,
		?int $defaultGridColumns = null
	): JSONResponse {
		try {
			if ($defaultPermissionLevel !== null) {
				$this->settingMapper->setSetting(AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL, $defaultPermissionLevel);
			}
			if ($allowUserDashboards !== null) {
				$this->settingMapper->setSetting(AdminSetting::KEY_ALLOW_USER_DASHBOARDS, $allowUserDashboards);
			}
			if ($allowMultipleDashboards !== null) {
				$this->settingMapper->setSetting(AdminSetting::KEY_ALLOW_MULTIPLE_DASHBOARDS, $allowMultipleDashboards);
			}
			if ($defaultGridColumns !== null) {
				$this->settingMapper->setSetting(AdminSetting::KEY_DEFAULT_GRID_COLUMNS, $defaultGridColumns);
			}

			return new JSONResponse(['status' => 'ok']);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
