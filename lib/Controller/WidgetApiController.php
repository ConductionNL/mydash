<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\WidgetService;
use OCA\MyDash\Service\PermissionService;
use OCA\MyDash\Service\ConditionalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class WidgetApiController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly WidgetService $widgetService,
		private readonly PermissionService $permissionService,
		private readonly ConditionalService $conditionalService,
		private readonly ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List all available Nextcloud widgets
	 */
	#[NoAdminRequired]
	public function listAvailable(): JSONResponse {
		$widgets = $this->widgetService->getAvailableWidgets();

		return new JSONResponse($widgets);
	}

	/**
	 * Get widget items for specified widgets
	 *
	 * @param array $widgets Array of widget IDs.
	 * @param int   $limit   Maximum number of items per widget.
	 *
	 * @return JSONResponse The widget items.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getItems(array $widgets = [], int $limit = 7): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$items = $this->widgetService->getWidgetItems($this->userId, $widgets, $limit);

		return new JSONResponse($items);
	}

	/**
	 * Add a widget to a dashboard
	 */
	#[NoAdminRequired]
	public function addWidget(
		int $dashboardId,
		string $widgetId,
		int $gridX = 0,
		int $gridY = 0,
		int $gridWidth = 4,
		int $gridHeight = 4
	): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->permissionService->canAddWidget($this->userId, $dashboardId)) {
			return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
		}

		try {
			$placement = $this->widgetService->addWidget(
				$dashboardId,
				$widgetId,
				$gridX,
				$gridY,
				$gridWidth,
				$gridHeight
			);

			return new JSONResponse($placement->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update a widget placement
	 */
	#[NoAdminRequired]
	public function updatePlacement(
		int $placementId,
		?int $gridX = null,
		?int $gridY = null,
		?int $gridWidth = null,
		?int $gridHeight = null,
		?bool $isVisible = null,
		?bool $showTitle = null,
		?string $customTitle = null,
		?array $styleConfig = null
	): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->permissionService->canStyleWidget($this->userId, $placementId)) {
			return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
		}

		try {
			$data = [];
			if ($gridX !== null) {
				$data['gridX'] = $gridX;
			}
			if ($gridY !== null) {
				$data['gridY'] = $gridY;
			}
			if ($gridWidth !== null) {
				$data['gridWidth'] = $gridWidth;
			}
			if ($gridHeight !== null) {
				$data['gridHeight'] = $gridHeight;
			}
			if ($isVisible !== null) {
				$data['isVisible'] = $isVisible;
			}
			if ($showTitle !== null) {
				$data['showTitle'] = $showTitle;
			}
			if ($customTitle !== null) {
				$data['customTitle'] = $customTitle;
			}
			if ($styleConfig !== null) {
				$data['styleConfig'] = $styleConfig;
			}

			$placement = $this->widgetService->updatePlacement($placementId, $data);

			return new JSONResponse($placement->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Remove a widget placement
	 */
	#[NoAdminRequired]
	public function removePlacement(int $placementId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->permissionService->canRemoveWidget($this->userId, $placementId)) {
			return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->widgetService->removePlacement($placementId);

			return new JSONResponse(['status' => 'ok']);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Get conditional rules for a widget placement
	 */
	#[NoAdminRequired]
	public function getRules(int $placementId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->permissionService->verifyPlacementOwnership($this->userId, $placementId);
			$rules = $this->conditionalService->getRules($placementId);

			return new JSONResponse(array_map(fn($r) => $r->jsonSerialize(), $rules));
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Add a conditional rule to a widget placement
	 */
	#[NoAdminRequired]
	public function addRule(
		int $placementId,
		string $ruleType,
		array $ruleConfig,
		bool $isInclude = true
	): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->permissionService->verifyPlacementOwnership($this->userId, $placementId);
			$rule = $this->conditionalService->addRule($placementId, $ruleType, $ruleConfig, $isInclude);

			return new JSONResponse($rule->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update a conditional rule
	 */
	#[NoAdminRequired]
	public function updateRule(int $ruleId, ?string $ruleType = null, ?array $ruleConfig = null, ?bool $isInclude = null): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = [];
			if ($ruleType !== null) {
				$data['ruleType'] = $ruleType;
			}
			if ($ruleConfig !== null) {
				$data['ruleConfig'] = $ruleConfig;
			}
			if ($isInclude !== null) {
				$data['isInclude'] = $isInclude;
			}

			$rule = $this->conditionalService->updateRule($ruleId, $data);

			return new JSONResponse($rule->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete a conditional rule
	 */
	#[NoAdminRequired]
	public function deleteRule(int $ruleId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->conditionalService->deleteRule($ruleId);

			return new JSONResponse(['status' => 'ok']);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
