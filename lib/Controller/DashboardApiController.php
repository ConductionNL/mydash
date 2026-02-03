<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class DashboardApiController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly DashboardService $dashboardService,
		private readonly PermissionService $permissionService,
		private readonly ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List all dashboards for the current user
	 */
	#[NoAdminRequired]
	public function list(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$dashboards = $this->dashboardService->getUserDashboards($this->userId);

		return new JSONResponse(array_map(fn($d) => $d->jsonSerialize(), $dashboards));
	}

	/**
	 * Get the user's active dashboard with placements
	 */
	#[NoAdminRequired]
	public function getActive(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->dashboardService->getEffectiveDashboard($this->userId);

		if ($result === null) {
			return new JSONResponse(['error' => 'No dashboard available'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([
			'dashboard' => $result['dashboard']->jsonSerialize(),
			'placements' => array_map(fn($p) => $p->jsonSerialize(), $result['placements']),
			'permissionLevel' => $result['permissionLevel'],
		]);
	}

	/**
	 * Create a new dashboard
	 *
	 * @param mixed       $name        The dashboard name (string or array from JSON body).
	 * @param string|null $description The dashboard description.
	 *
	 * @return JSONResponse The JSON response with the created dashboard.
	 */
	#[NoAdminRequired]
	public function create($name = null, ?string $description = null): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		// Handle JSON body - Nextcloud sends JSON POST data as array.
		if (is_array($name)) {
			$data = $name;
			$name = $data['name'] ?? 'My Dashboard';
			$description = $data['description'] ?? null;
		} elseif ($name === null) {
			$name = 'My Dashboard';
		}

		if (!$this->permissionService->canCreateDashboard($this->userId)) {
			return new JSONResponse(['error' => 'Dashboard creation not allowed'], Http::STATUS_FORBIDDEN);
		}

		// Check if user already has dashboards and multiple aren't allowed.
		$existing = $this->dashboardService->getUserDashboards($this->userId);
		if (!empty($existing) && !$this->permissionService->canHaveMultipleDashboards($this->userId)) {
			return new JSONResponse(['error' => 'Multiple dashboards not allowed'], Http::STATUS_FORBIDDEN);
		}

		try {
			$dashboard = $this->dashboardService->createDashboard($this->userId, $name, $description);

			return new JSONResponse([
				'dashboard' => $dashboard->jsonSerialize(),
			], Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update a dashboard
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $name = null, ?string $description = null, ?array $placements = null): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->permissionService->canEditDashboard($this->userId, $id)) {
			return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
		}

		try {
			$data = [];
			if ($name !== null) {
				$data['name'] = $name;
			}
			if ($description !== null) {
				$data['description'] = $description;
			}
			if ($placements !== null) {
				$data['placements'] = $placements;
			}

			$dashboard = $this->dashboardService->updateDashboard($id, $this->userId, $data);

			return new JSONResponse([
				'dashboard' => $dashboard->jsonSerialize(),
			]);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete a dashboard
	 */
	#[NoAdminRequired]
	public function delete(int $id): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->dashboardService->deleteDashboard($id, $this->userId);

			return new JSONResponse(['status' => 'ok']);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Activate a dashboard
	 */
	#[NoAdminRequired]
	public function activate(int $id): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$dashboard = $this->dashboardService->activateDashboard($id, $this->userId);

			return new JSONResponse([
				'dashboard' => $dashboard->jsonSerialize(),
			]);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
