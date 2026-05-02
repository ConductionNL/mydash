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
        // NOTE: specific routes (`/visible`, `/group/...`) MUST precede the
        // wildcard `{id}` routes — Symfony matches the first that fits and
        // would otherwise route `visible` to the personal `getById` handler.
        ['name' => 'dashboard_api#list', 'url' => '/api/dashboards', 'verb' => 'GET'],
        // Visible-to-user resolution endpoint (REQ-DASH-013).
        ['name' => 'dashboard_api#visible', 'url' => '/api/dashboards/visible', 'verb' => 'GET'],
        // Group-shared dashboard CRUD (REQ-DASH-014). All five routes are
        // scoped to a single `groupId` (real Nextcloud group id or the
        // reserved literal `default`).
        ['name' => 'dashboard_api#listGroup', 'url' => '/api/dashboards/group/{groupId}', 'verb' => 'GET'],
        ['name' => 'dashboard_api#createGroup', 'url' => '/api/dashboards/group/{groupId}', 'verb' => 'POST'],
        ['name' => 'dashboard_api#setGroupDefault', 'url' => '/api/dashboards/group/{groupId}/default', 'verb' => 'POST'],
        ['name' => 'dashboard_api#getGroup', 'url' => '/api/dashboards/group/{groupId}/{uuid}', 'verb' => 'GET'],
        ['name' => 'dashboard_api#updateGroup', 'url' => '/api/dashboards/group/{groupId}/{uuid}', 'verb' => 'PUT'],
        ['name' => 'dashboard_api#deleteGroup', 'url' => '/api/dashboards/group/{groupId}/{uuid}', 'verb' => 'DELETE'],
        // Persist the user's active-dashboard preference (REQ-DASH-019).
        // Lives under `/api/dashboards/...` (plural) so it is matched before
        // the singular `/api/dashboard/{id}` wildcard below.
        ['name' => 'dashboard_api#setActiveDashboard', 'url' => '/api/dashboards/active', 'verb' => 'POST'],
        // Fork-current-layout to a personal copy (REQ-DASH-020). The
        // route lives under `/api/dashboards/{uuid}/fork` so it is
        // scoped to a source UUID rather than a numeric id — the
        // resolver uses the visible-to-user chain (REQ-DASH-013) which
        // is keyed on UUID, not on the dashboards table primary key.
        ['name' => 'dashboard_api#fork', 'url' => '/api/dashboards/{uuid}/fork', 'verb' => 'POST'],
        // Personal-scope endpoints (must come AFTER `/api/dashboards/...`
        // specific routes above to avoid wildcard hijack).
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
        ['name' => 'resource#listResources', 'url' => '/api/resources', 'verb' => 'GET'],
        ['name' => 'resource#getResource', 'url' => '/resource/{filename}', 'verb' => 'GET'],

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
