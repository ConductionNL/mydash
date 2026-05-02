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

		// User dashboard endpoints
		['name' => 'dashboard_api#list', 'url' => '/api/dashboards', 'verb' => 'GET'],
		['name' => 'dashboard_api#getActive', 'url' => '/api/dashboard', 'verb' => 'GET'],
		['name' => 'dashboard_api#getById', 'url' => '/api/dashboard/{id}', 'verb' => 'GET'],
		['name' => 'dashboard_api#create', 'url' => '/api/dashboard', 'verb' => 'POST'],
		['name' => 'dashboard_api#update', 'url' => '/api/dashboard/{id}', 'verb' => 'PUT'],
		['name' => 'dashboard_api#delete', 'url' => '/api/dashboard/{id}', 'verb' => 'DELETE'],
		['name' => 'dashboard_api#activate', 'url' => '/api/dashboard/{id}/activate', 'verb' => 'POST'],

		// Dashboard sharing endpoints
		['name' => 'dashboard_share_api#index', 'url' => '/api/dashboard/{id}/shares', 'verb' => 'GET'],
		['name' => 'dashboard_share_api#create', 'url' => '/api/dashboard/{id}/shares', 'verb' => 'POST'],
		['name' => 'dashboard_share_api#replace', 'url' => '/api/dashboard/{id}/shares', 'verb' => 'PUT'],
		['name' => 'dashboard_share_api#destroy', 'url' => '/api/dashboard/share/{shareId}', 'verb' => 'DELETE'],
		['name' => 'dashboard_share_api#searchSharees', 'url' => '/api/sharees', 'verb' => 'GET'],
		['name' => 'dashboard_share_api#revokeForRecipient', 'url' => '/api/sharees/{shareType}/{shareWith}', 'verb' => 'DELETE'],

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

		// Resource upload endpoint (admin-only — see ResourceController)
		['name' => 'resource#upload', 'url' => '/api/resources', 'verb' => 'POST'],

		// Admin endpoints
		['name' => 'admin#listTemplates', 'url' => '/api/admin/templates', 'verb' => 'GET'],
		['name' => 'admin#createTemplate', 'url' => '/api/admin/templates', 'verb' => 'POST'],
		['name' => 'admin#getTemplate', 'url' => '/api/admin/templates/{id}', 'verb' => 'GET'],
		['name' => 'admin#updateTemplate', 'url' => '/api/admin/templates/{id}', 'verb' => 'PUT'],
		['name' => 'admin#deleteTemplate', 'url' => '/api/admin/templates/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#getSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#updateSettings', 'url' => '/api/admin/settings', 'verb' => 'PUT'],
	],
];
