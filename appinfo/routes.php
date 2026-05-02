<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// Metrics and health
		['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
		['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

		// Main page
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// User dashboard endpoints (REQ-DASH-002..010).
		// NOTE: specific routes (`/visible`, `/group/...`, `/active`, `/{uuid}/fork`) MUST precede the
		// wildcard `{id}` routes — Symfony matches the first that fits and
		// would otherwise route them to the personal `getById` handler.
		['name' => 'dashboard_api#list', 'url' => '/api/dashboards', 'verb' => 'GET'],
		// Visible-to-user resolution endpoint (REQ-DASH-013).
		['name' => 'dashboard_api#visible', 'url' => '/api/dashboards/visible', 'verb' => 'GET'],
		// REQ-DASH-019: persist active-dashboard preference. Registered BEFORE
		// the group-scoped routes that share the /api/dashboards/ prefix so the
		// router matches the literal 'active' segment before any {groupId} wildcard.
		['name' => 'dashboard_api#setActiveDashboard', 'url' => '/api/dashboards/active', 'verb' => 'POST'],
		// REQ-DASH-020..022: fork a visible dashboard as a personal copy.
		// Registered BEFORE the group-scoped {groupId} wildcard routes to
		// prevent the literal 'fork' suffix being consumed by any wildcard.
		['name' => 'dashboard_api#fork', 'url' => '/api/dashboards/{uuid}/fork', 'verb' => 'POST',
		 'requirements' => ['uuid' => '[A-Za-z0-9\-]+']],

		// Group-shared dashboard CRUD (REQ-DASH-014). All five routes are
		// scoped to a single `groupId` (real Nextcloud group id or the
		// reserved literal `default`).
		['name' => 'dashboard_api#listGroup', 'url' => '/api/dashboards/group/{groupId}', 'verb' => 'GET',
		 'requirements' => ['groupId' => '[^/]+']],
		['name' => 'dashboard_api#createGroup', 'url' => '/api/dashboards/group/{groupId}', 'verb' => 'POST',
		 'requirements' => ['groupId' => '[^/]+']],
		// Default-flip endpoint (REQ-DASH-015). Body: {"uuid": "..."}.
		['name' => 'dashboard_api#setGroupDefault', 'url' => '/api/dashboards/group/{groupId}/default', 'verb' => 'POST',
		 'requirements' => ['groupId' => '[^/]+']],
		['name' => 'dashboard_api#getGroup', 'url' => '/api/dashboards/group/{groupId}/{uuid}', 'verb' => 'GET',
		 'requirements' => ['groupId' => '[^/]+', 'uuid' => '[A-Za-z0-9\-]+']],
		['name' => 'dashboard_api#updateGroup', 'url' => '/api/dashboards/group/{groupId}/{uuid}', 'verb' => 'PUT',
		 'requirements' => ['groupId' => '[^/]+', 'uuid' => '[A-Za-z0-9\-]+']],
		['name' => 'dashboard_api#deleteGroup', 'url' => '/api/dashboards/group/{groupId}/{uuid}', 'verb' => 'DELETE',
		 'requirements' => ['groupId' => '[^/]+', 'uuid' => '[A-Za-z0-9\-]+']],

		// Personal-scope endpoints (must come AFTER `/api/dashboards/...`
		// specific routes above to avoid wildcard hijack).
		['name' => 'dashboard_api#getActive', 'url' => '/api/dashboard', 'verb' => 'GET'],
		['name' => 'dashboard_api#create', 'url' => '/api/dashboard', 'verb' => 'POST'],
		['name' => 'dashboard_api#update', 'url' => '/api/dashboard/{id}', 'verb' => 'PUT'],
		['name' => 'dashboard_api#delete', 'url' => '/api/dashboard/{id}', 'verb' => 'DELETE'],
		['name' => 'dashboard_api#activate', 'url' => '/api/dashboard/{id}/activate', 'verb' => 'POST'],

		// Dashboard sharing endpoints (REQ-SHARE-001..010).
		['name' => 'dashboard_share_api#index', 'url' => '/api/dashboard/{id}/shares', 'verb' => 'GET'],
		['name' => 'dashboard_share_api#create', 'url' => '/api/dashboard/{id}/shares', 'verb' => 'POST'],
		// Bulk replace — REQ-SHARE-009.
		['name' => 'dashboard_share_api#replace', 'url' => '/api/dashboard/{id}/shares', 'verb' => 'PUT'],
		['name' => 'dashboard_share_api#destroy', 'url' => '/api/dashboard/share/{shareId}', 'verb' => 'DELETE'],
		['name' => 'dashboard_share_api#searchSharees', 'url' => '/api/sharees', 'verb' => 'GET'],
		// Revoke all for recipient — REQ-SHARE-010.
		['name' => 'dashboard_share_api#revokeForRecipient',
		 'url' => '/api/sharees/{shareType}/{shareWith}', 'verb' => 'DELETE',
		 'requirements' => ['shareType' => '[^/]+', 'shareWith' => '[^/]+']],

		// Widget endpoints
		['name' => 'widget_api#listAvailable', 'url' => '/api/widgets', 'verb' => 'GET'],
		['name' => 'widget_api#getItems', 'url' => '/api/widgets/items', 'verb' => 'GET'],
		['name' => 'widget_api#addWidget', 'url' => '/api/dashboard/{dashboardId}/widgets', 'verb' => 'POST'],
		['name' => 'widget_api#addTile', 'url' => '/api/dashboard/{dashboardId}/tile', 'verb' => 'POST'],
		['name' => 'widget_api#updatePlacement', 'url' => '/api/widgets/{placementId}', 'verb' => 'PUT'],
		['name' => 'widget_api#removePlacement', 'url' => '/api/widgets/{placementId}', 'verb' => 'DELETE'],

		// Tile endpoints
		['name' => 'tile_api#index', 'url' => '/api/tiles', 'verb' => 'GET'],
		['name' => 'tile_api#create', 'url' => '/api/tiles', 'verb' => 'POST'],
		['name' => 'tile_api#update', 'url' => '/api/tiles/{id}', 'verb' => 'PUT'],
		['name' => 'tile_api#destroy', 'url' => '/api/tiles/{id}', 'verb' => 'DELETE'],

		// Conditional rules endpoints
		['name' => 'rule_api#getRules', 'url' => '/api/widgets/{placementId}/rules', 'verb' => 'GET'],
		['name' => 'rule_api#addRule', 'url' => '/api/widgets/{placementId}/rules', 'verb' => 'POST'],
		['name' => 'rule_api#updateRule', 'url' => '/api/rules/{ruleId}', 'verb' => 'PUT'],
		['name' => 'rule_api#deleteRule', 'url' => '/api/rules/{ruleId}', 'verb' => 'DELETE'],

		// File creation endpoint (REQ-LBN-004) — link-button-widget
		// createFile flow. POST-only; validates filename, dir, and the
		// admin-configured extension allow-list before touching storage.
		['name' => 'file#createFile', 'url' => '/api/files/create', 'verb' => 'POST'],

		// Resource endpoints (REQ-RES-001..008).
		// Specific routes precede the wildcard `/resource/{filename}`
		// route so that any future addition of `/resource/...` paths
		// stays unambiguous. The non-OCS `/resource/{filename}`
		// streamer is intentionally NOT under `/api/...` because it
		// returns binary bytes, not a JSON envelope.
		['name' => 'resource#upload', 'url' => '/api/resources', 'verb' => 'POST'],
		// Resource listing — REQ-RES-007. Logged-in user only (no admin
		// gate); the listed names are already referenced from rendered
		// dashboards so admin gating would lock dashboards out of their
		// own assets.
		['name' => 'resource_serve#listResources', 'url' => '/api/resources', 'verb' => 'GET'],
		// Public resource serving — REQ-RES-006. NON-OCS plain web
		// route returning a StreamResponse with extension-derived
		// Content-Type and a one-year immutable cache header. The
		// `[^/]+` requirement on {filename} blocks path traversal at
		// the routing layer (the controller also re-checks for
		// defence in depth).
		['name' => 'resource_serve#getResource', 'url' => '/resource/{filename}', 'verb' => 'GET',
		 'requirements' => ['filename' => '[^/]+']],

		// Admin endpoints
		['name' => 'admin#listTemplates', 'url' => '/api/admin/templates', 'verb' => 'GET'],
		['name' => 'admin#createTemplate', 'url' => '/api/admin/templates', 'verb' => 'POST'],
		['name' => 'admin#getTemplate', 'url' => '/api/admin/templates/{id}', 'verb' => 'GET'],
		['name' => 'admin#updateTemplate', 'url' => '/api/admin/templates/{id}', 'verb' => 'PUT'],
		['name' => 'admin#deleteTemplate', 'url' => '/api/admin/templates/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#getSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#updateSettings', 'url' => '/api/admin/settings', 'verb' => 'PUT'],

		// Admin group-priority order endpoints (REQ-ASET-012,
		// REQ-ASET-013, REQ-ASET-014). Both admin-only via runtime
		// `IGroupManager::isAdmin` check inside the controller.
		['name' => 'admin_settings#listGroups', 'url' => '/api/admin/groups', 'verb' => 'GET'],
		['name' => 'admin_settings#updateGroupOrder', 'url' => '/api/admin/groups', 'verb' => 'POST'],
	],
];
