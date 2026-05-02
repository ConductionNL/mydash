---
status: implemented
---

# Admin Settings Specification

## Purpose

Admin settings provide Nextcloud administrators with global configuration options for the MyDash app. These settings control system-wide behavior such as whether users can create their own dashboards, how many dashboards they can have, default permission levels for new dashboards, and default grid configuration. Settings are stored as key-value pairs in a dedicated database table and are applied as defaults or constraints across the entire MyDash installation.

## Data Model

### Admin Settings (oc_mydash_admin_settings)
Settings are stored as key-value pairs:
- **id**: Auto-increment integer primary key
- **settingKey**: Unique string identifier for the setting (STRING)
- **settingValue**: JSON-encoded string value (STRING, nullable). Values are stored via `json_encode()` and retrieved via `json_decode()`.
- **updatedAt**: Timestamp (DATETIME)

### Defined Settings

| Setting Key (DB) | API Response Key | Type | Default | Description |
|------------------|------------------|------|---------|-------------|
| `allow_user_dashboards` | `allowUserDashboards` | boolean | `false` | Whether non-admin users can create their own personal dashboards. Admins MUST opt in (REQ-ASET-003). |
| `allow_multiple_dashboards` | `allowMultipleDashboards` | boolean | `true` | Whether users can have more than one dashboard |
| `default_permission_level` | `defaultPermissionLevel` | string | `add_only` | Default permission level for user-created dashboards |
| `default_grid_columns` | `defaultGridColumns` | integer | `12` | Default number of grid columns for new dashboards |

NOTE: The DB stores settings with snake_case keys, but the API response returns camelCase keys. The factory default for `defaultPermissionLevel` is `add_only` (Dashboard::PERMISSION_ADD_ONLY), NOT `full`. The API update endpoint accepts abbreviated camelCase parameter names: `defaultPermLevel`, `allowUserDash`, `allowMultiDash`, `defaultGridCols`.

## Requirements

### REQ-ASET-001: Retrieve Admin Settings

Administrators MUST be able to retrieve all current admin settings via the API. The endpoint returns a flat JSON object with all four settings using camelCase keys.

#### Scenario: Get all settings with defaults
- GIVEN no admin settings have been explicitly configured (fresh installation)
- WHEN the admin sends GET /api/admin/settings
- THEN the system MUST return HTTP 200 with all settings at their default values:
  ```json
  {
    "defaultPermissionLevel": "add_only",
    "allowUserDashboards": false,
    "allowMultipleDashboards": true,
    "defaultGridColumns": 12
  }
  ```
- NOTE: `allowUserDashboards` defaults to `false` (REQ-ASET-003) — admins MUST opt in to personal dashboard creation.

#### Scenario: Get settings after modification
- GIVEN the admin has set `allowUserDashboards` to `false`
- WHEN the admin sends GET /api/admin/settings
- THEN the system MUST return the updated value:
  ```json
  {
    "defaultPermissionLevel": "add_only",
    "allowUserDashboards": false,
    "allowMultipleDashboards": true,
    "defaultGridColumns": 12
  }
  ```

#### Scenario: Non-admin user retrieves settings
- GIVEN a regular user "alice"
- WHEN she sends GET /api/admin/settings
- THEN the system MUST return HTTP 403
- AND admin settings MUST NOT be exposed to non-admin users

#### Scenario: Settings used by non-admin endpoints
- GIVEN the admin has set `allowUserDashboards` to `false`
- WHEN user "alice" sends POST /api/dashboard (to create a dashboard)
- THEN the system MUST internally check the `allowUserDashboards` setting via `PermissionService::canCreateDashboard()`
- AND the non-admin user MUST NOT need to call GET /api/admin/settings to experience the effect

#### Scenario: Settings response format consistency
- GIVEN the admin has configured various settings at different times
- WHEN GET /api/admin/settings is called
- THEN the response MUST always return exactly four keys: `defaultPermissionLevel`, `allowUserDashboards`, `allowMultipleDashboards`, `defaultGridColumns`
- AND no additional keys MUST be present in the response
- AND the response MUST be a flat JSON object (no nesting)

### REQ-ASET-002: Update Admin Settings

Administrators MUST be able to update individual or multiple admin settings in a single PUT request.

#### Scenario: Update a single boolean setting
- GIVEN the admin wants to disable user dashboard creation
- WHEN they send PUT /api/admin/settings with body `{"allowUserDash": false}`
- THEN the system MUST update the `allowUserDashboards` setting to `false`
- AND the response MUST return HTTP 200 with `{"status": "ok"}`
- NOTE: The API update endpoint accepts abbreviated camelCase parameter names (`defaultPermLevel`, `allowUserDash`, `allowMultiDash`, `defaultGridCols`), NOT the full response key names. The response returns `{"status": "ok"}`, NOT the full settings object.

#### Scenario: Update multiple settings at once
- GIVEN the admin wants to change several settings
- WHEN they send PUT /api/admin/settings with body:
  ```json
  {
    "allowUserDash": true,
    "allowMultiDash": false,
    "defaultGridCols": 8
  }
  ```
- THEN the system MUST update all three specified settings
- AND settings not included in the request MUST remain unchanged

#### Scenario: Update with invalid permission level value
- GIVEN the admin sends PUT /api/admin/settings with body `{"defaultPermLevel": "super_admin"}`
- THEN the system SHOULD return HTTP 400 with a validation error
- AND only `view_only`, `add_only`, and `full` SHOULD be accepted for this setting
- NOTE: Permission level validation is NOT currently implemented -- any string value is accepted

#### Scenario: Update with invalid grid columns value
- GIVEN the admin sends PUT /api/admin/settings with body `{"defaultGridCols": 0}`
- THEN the system SHOULD return HTTP 400 with a validation error
- AND `defaultGridCols` SHOULD be a positive integer between 1 and 24
- NOTE: Grid columns validation is NOT currently implemented -- any integer value is accepted

#### Scenario: Update with invalid boolean value
- GIVEN the admin sends PUT /api/admin/settings with body `{"allowUserDash": "maybe"}`
- THEN the system SHOULD return HTTP 400 with a validation error
- AND boolean settings SHOULD only accept `true` or `false`
- NOTE: Boolean validation is NOT currently implemented -- PHP type coercion handles the value

#### Scenario: Non-admin cannot update settings
- GIVEN a regular user "alice"
- WHEN she sends PUT /api/admin/settings with any body
- THEN the system MUST return HTTP 403
- AND no settings MUST be modified
- NOTE: Admin-only access is enforced because the AdminController does NOT have a `#[NoAdminRequired]` attribute

#### Scenario: Update with unknown setting key
- GIVEN the admin sends PUT /api/admin/settings with body `{"unknownSetting": "value"}`
- THEN the system MUST ignore the unknown key (unrecognized parameters are simply not matched to method arguments)
- AND known settings MUST NOT be affected

### REQ-ASET-003: Allow User Dashboards Setting (runtime gating + envelope)

The setting `allow_user_dashboards` (boolean stored as `'0'` / `'1'`, default `'0'`) MUST gate every endpoint that **creates** a personal (`type='user'`) dashboard. Read endpoints, update endpoints, and existing personal dashboards MUST remain accessible regardless of the flag's value. Toggling the flag MUST NOT mutate any dashboard records.

The endpoints listed below MUST evaluate the flag at request time and, when it equals `'0'`, MUST return HTTP 403 with response body `{status: 'error', error: 'personal_dashboards_disabled', message: <translated string>}`:

- `POST /api/dashboard` (when payload omits `type` or sets `type='user'`)
- `POST /api/dashboards/{uuid}/fork` (always — fork target is always `type='user'`; the route itself is owned by the `fork-current-as-personal` capability and inherits this gating)

Endpoints that MUST NOT check the flag (so existing personal dashboards remain functional):

- `GET /api/dashboards/visible`
- `GET /api/dashboard/{id}`
- `PUT /api/dashboard/{id}` (existing personal dashboard updates)
- `DELETE /api/dashboard/{id}` (users can still clean up their old personal dashboards)
- `POST /api/dashboard/active`
- `POST /api/dashboard/{id}/activate`
- All `group_shared` and `admin_template` endpoints

Admins MAY always create dashboards regardless of the flag (the `PermissionService::canCreateDashboard()` companion check is layered defense-in-depth; admins bypass it through their group membership).

#### Scenario: Flag off blocks personal dashboard creation
- GIVEN admin setting `allow_user_dashboards = '0'`
- WHEN user "alice" sends `POST /api/dashboard` with body `{"name": "My Test"}`
- THEN the system MUST return HTTP 403 with `{status: 'error', error: 'personal_dashboards_disabled', message: 'Personal dashboards are not enabled by your administrator'}`
- AND no row MUST be inserted into `oc_mydash_dashboards`

#### Scenario: Flag off blocks fork
- GIVEN admin setting `allow_user_dashboards = '0'`
- AND alice can read group-shared dashboard `S`
- WHEN she sends `POST /api/dashboards/{S.uuid}/fork`
- THEN the system MUST return HTTP 403 with the same `personal_dashboards_disabled` error envelope

#### Scenario: Flag off does not break existing personal dashboards
- GIVEN alice has 2 existing personal dashboards `P1`, `P2`
- AND admin toggles `allow_user_dashboards` from `'1'` to `'0'`
- WHEN alice opens the workspace page
- THEN `P1` and `P2` MUST still appear in `GET /api/dashboards/visible`
- AND alice MUST be able to `PUT /api/dashboard/{P1.id}` and `DELETE` them
- AND alice MUST be able to set either as her active dashboard via `POST /api/dashboard/active`
- AND only `POST /api/dashboard` and fork endpoints MUST return 403

#### Scenario: Toggling does not destructively mutate data
- GIVEN alice has 1 personal dashboard `P1` (active)
- AND admin toggles `allow_user_dashboards` to `'0'` and back to `'1'`
- THEN `P1` MUST still exist with all original fields and placements
- AND `P1.isActive` MUST still be `1` (unchanged)
- AND no rows in `oc_mydash_dashboards` or `oc_mydash_widget_placements` MUST have been touched

#### Scenario: Default value when setting is missing
- GIVEN a fresh MyDash install with no row for `allow_user_dashboards` in `oc_mydash_admin_settings`
- WHEN any code reads the setting (via `DashboardService::getAllowUserDashboards()`, `PermissionService::canCreateDashboard()`, or `AdminSettingsService::getSettings()`)
- THEN it MUST evaluate to `false` (creation blocked) — the secure default

#### Scenario: Admins can always create dashboards
- GIVEN `allow_user_dashboards` is set to `false`
- WHEN a Nextcloud admin sends POST /api/dashboard with body `{"name": "Admin Dashboard"}`
- THEN the system MUST allow the creation
- AND the admin setting MUST NOT restrict admin users

#### Scenario: Frontend hides create affordance when disabled
- GIVEN `allow_user_dashboards` is set to `false`
- WHEN user "alice" views the MyDash workspace
- THEN the "Create dashboard…" entry in `DashboardConfigMenu` MUST NOT be rendered
- AND the empty-state "Create dashboard" button in `Views.vue` MUST NOT be rendered
- AND the empty-state description MUST read "Personal dashboards are not enabled by your administrator"
- AND if the call is invoked anyway (stale UI, direct API call) the backend MUST still return the 403 `personal_dashboards_disabled` envelope (defense in depth)

### REQ-ASET-004: Allow Multiple Dashboards Setting

When `allow_multiple_dashboards` is false, users MUST be limited to one dashboard.

#### Scenario: Second dashboard creation blocked
- GIVEN `allowMultipleDashboards` is set to `false`
- AND user "alice" already has 1 dashboard
- WHEN she sends POST /api/dashboard with body `{"name": "Second Dashboard"}`
- THEN the system MUST return HTTP 403 with a message indicating multiple dashboards are not allowed
- AND no dashboard MUST be created

#### Scenario: First dashboard creation allowed
- GIVEN `allowMultipleDashboards` is set to `false`
- AND user "bob" has no dashboards
- WHEN he sends POST /api/dashboard with body `{"name": "My Dashboard"}`
- THEN the system MUST allow the creation (this is his first dashboard)
- AND the response MUST return HTTP 201

#### Scenario: Multiple dashboards allowed (default)
- GIVEN `allowMultipleDashboards` is set to `true` (default)
- AND user "alice" already has 3 dashboards
- WHEN she sends POST /api/dashboard with body `{"name": "Fourth Dashboard"}`
- THEN the system MUST allow the creation
- AND the response MUST return HTTP 201

#### Scenario: Existing dashboards preserved when setting is disabled
- GIVEN user "alice" has 3 dashboards
- AND the admin sets `allow_multiple_dashboards` to `false`
- WHEN alice views her dashboards via GET /api/dashboards
- THEN all 3 existing dashboards MUST still be returned
- AND alice MUST NOT be able to create additional dashboards
- AND alice MUST be able to delete dashboards to get down to 1

#### Scenario: Template distribution overrides multiple dashboard restriction
- GIVEN `allowMultipleDashboards` is set to `false`
- AND user "alice" has 1 dashboard
- AND a new admin template targets alice's group
- WHEN the template distribution triggers for alice
- THEN the system MUST still create the template copy for alice
- AND alice MUST have 2 dashboards (the restriction applies to user-initiated creation, not admin-initiated distribution)

### REQ-ASET-005: Default Permission Level Setting

The `defaultPermissionLevel` setting MUST be applied as a fallback when resolving effective permission levels. The factory default is `add_only` (Dashboard::PERMISSION_ADD_ONLY).

#### Scenario: Default permission level applied to new dashboard
- GIVEN `defaultPermissionLevel` is set to `add_only`
- WHEN user "alice" sends POST /api/dashboard with body `{"name": "My Dashboard"}`
- THEN the created dashboard MUST have `permissionLevel: "full"` (user-created dashboards use `DashboardFactory::create()` which hardcodes `PERMISSION_FULL`)
- AND the `defaultPermissionLevel` admin setting acts as a fallback in `PermissionService::getEffectivePermissionLevel()` only when the dashboard's own `permissionLevel` is empty

#### Scenario: Factory default permission (add_only)
- GIVEN `defaultPermissionLevel` is at its factory default of `add_only`
- WHEN `PermissionService::getEffectivePermissionLevel()` is called for a dashboard with no `permissionLevel` set and no `basedOnTemplate`
- THEN the effective level MUST resolve to `add_only` (the admin default)
- NOTE: The factory default is `add_only` (Dashboard::PERMISSION_ADD_ONLY), NOT `full`

#### Scenario: Default permission does not affect template copies
- GIVEN `defaultPermissionLevel` is set to `view_only`
- AND an admin template exists with `permissionLevel: "full"`
- WHEN a user receives a copy of that template
- THEN the copy MUST have `permissionLevel: "full"` (from the template)
- AND the global default MUST NOT override the template's permission level

#### Scenario: Admin can override default for individual templates
- GIVEN `defaultPermissionLevel` is set to `add_only`
- WHEN the admin creates a template with `permissionLevel: "full"`
- THEN the template MUST have `permissionLevel: "full"`
- AND the global default MUST NOT constrain template configuration

#### Scenario: Permission resolution chain
- GIVEN a dashboard with `basedOnTemplate: 42` and the template has `permissionLevel: "add_only"`
- WHEN `PermissionService::getEffectivePermissionLevel()` is called
- THEN the system MUST check in order: (1) source template's `permissionLevel`, (2) dashboard's own `permissionLevel`, (3) admin default setting
- AND the first non-empty value in the chain MUST be returned

### REQ-ASET-006: Default Grid Columns Setting

The `defaultGridColumns` setting MUST be applied to new dashboards when no explicit gridColumns is specified.

#### Scenario: Default grid columns applied
- GIVEN `defaultGridColumns` is set to `8`
- WHEN user "alice" sends POST /api/dashboard with body `{"name": "My Dashboard"}`
- THEN the created dashboard MUST have `gridColumns: 8`
- NOTE: `DashboardFactory::create()` currently hardcodes `gridColumns: 12` and does NOT read the `defaultGridColumns` admin setting. This is a known gap.

#### Scenario: Explicit grid columns overrides default
- GIVEN `defaultGridColumns` is set to `8`
- WHEN user "alice" sends POST /api/dashboard with body `{"name": "My Dashboard", "gridColumns": 12}`
- THEN the created dashboard MUST have `gridColumns: 12` (explicit value takes precedence)

#### Scenario: Default grid columns for template copies
- GIVEN `defaultGridColumns` is set to `8`
- AND an admin template exists with `gridColumns: 12`
- WHEN a user receives a copy of that template
- THEN the copy MUST have `gridColumns: 12` (from the template)
- AND the global default MUST NOT override the template's grid configuration

### REQ-ASET-007: Settings Persistence

Admin settings MUST be persisted across server restarts and app updates.

#### Scenario: Settings survive server restart
- GIVEN the admin has configured `allowUserDashboards: false`
- WHEN the Nextcloud server is restarted
- THEN GET /api/admin/settings MUST still return `allowUserDashboards: false`
- AND the setting MUST NOT revert to its default value

#### Scenario: Settings survive app update
- GIVEN the admin has configured custom settings
- WHEN the MyDash app is updated to a new version
- THEN all previously configured settings MUST be preserved
- AND new settings introduced in the update MUST use their default values

#### Scenario: Factory reset behavior
- GIVEN the admin wants to reset all settings to defaults
- WHEN they send PUT /api/admin/settings with all default values:
  ```json
  {
    "allowUserDash": true,
    "allowMultiDash": true,
    "defaultPermLevel": "add_only",
    "defaultGridCols": 12
  }
  ```
- THEN all settings MUST be reset to their factory defaults
- AND the response MUST confirm the update

### REQ-ASET-008: Admin Settings UI

The admin settings MUST be accessible via a Nextcloud admin panel page.

#### Scenario: Admin settings page is registered
- GIVEN a Nextcloud admin user
- WHEN they navigate to Settings > Administration
- THEN a "MyDash" entry MUST appear in the admin settings navigation
- AND clicking it MUST display the MyDash admin settings page

#### Scenario: Regular user cannot access admin settings page
- GIVEN a regular (non-admin) Nextcloud user
- WHEN they navigate to Settings
- THEN the "MyDash" entry MUST NOT appear in their settings navigation
- AND direct URL access to the admin settings page MUST return HTTP 403

#### Scenario: Settings form layout
- GIVEN the admin opens the MyDash admin settings page
- THEN the page MUST display:
  - A toggle for "Allow user dashboards" (on/off)
  - A toggle for "Allow multiple dashboards" (on/off)
  - A dropdown for "Default permission level" (view_only, add_only, full)
  - A number input for "Default grid columns" (1-24)
  - A "Save" button
- AND each setting MUST show its current value

#### Scenario: Settings saved via the UI
- GIVEN the admin changes "Allow user dashboards" to off and clicks Save
- WHEN the save completes
- THEN the system MUST display a success notification
- AND GET /api/admin/settings MUST reflect the change

### REQ-ASET-009: Settings Impact on Existing Data

Admin settings changes MUST only affect future operations and MUST NOT retroactively modify existing dashboards.

#### Scenario: Changing default permission level does not modify existing dashboards
- GIVEN user "alice" has a dashboard with `permissionLevel: "full"`
- WHEN the admin changes `defaultPermissionLevel` to `view_only`
- THEN alice's existing dashboard MUST retain `permissionLevel: "full"`
- AND the new default MUST only apply to dashboards created after the change

#### Scenario: Changing grid columns default does not modify existing dashboards
- GIVEN user "alice" has a dashboard with `gridColumns: 12`
- WHEN the admin changes `defaultGridColumns` to `8`
- THEN alice's existing dashboard MUST retain `gridColumns: 12`
- AND only newly created dashboards MUST use the new default of `8`

#### Scenario: Disabling user dashboards does not delete existing dashboards
- GIVEN 50 users each have personal dashboards
- WHEN the admin sets `allowUserDashboards` to `false`
- THEN all 50 existing dashboards MUST be preserved
- AND users MUST continue to access their existing dashboards
- AND only new dashboard creation MUST be blocked

### REQ-ASET-010: Settings Concurrency

Concurrent admin settings updates MUST be handled safely.

#### Scenario: Simultaneous updates from two admin sessions
- GIVEN admin user A sets `allowUserDash: false` at the same time admin user B sets `allowMultiDash: false`
- WHEN both PUT requests are processed
- THEN each setting MUST be independently updated without data loss
- AND the final state MUST reflect both changes (last-write-wins per individual setting key)

#### Scenario: Rapid successive updates
- GIVEN the admin clicks Save multiple times quickly
- WHEN multiple PUT requests are sent in rapid succession
- THEN the system MUST process each request independently
- AND the final state MUST reflect the last request's values

### REQ-ASET-011: Settings API Error Handling

The settings API MUST return consistent error responses for various failure scenarios.

#### Scenario: Database connection failure during settings retrieval
- GIVEN the database is temporarily unavailable
- WHEN the admin sends GET /api/admin/settings
- THEN the system MUST return HTTP 500 with an error message
- AND the error MUST NOT expose internal database details

#### Scenario: Database connection failure during settings update
- GIVEN the database is temporarily unavailable
- WHEN the admin sends PUT /api/admin/settings with valid data
- THEN the system MUST return HTTP 500 with an error message
- AND no partial updates MUST be persisted

#### Scenario: Empty request body for update
- GIVEN the admin sends PUT /api/admin/settings with an empty body `{}`
- WHEN the request is processed
- THEN the system MUST return HTTP 200 with `{"status": "ok"}`
- AND no settings MUST be modified (all parameters are null, so no updates are applied)

### REQ-ASET-015: Initial-state mirror of the allow-user-dashboards flag

The current value of `allow_user_dashboards` MUST be pushed as initial state `allowUserDashboards: bool` on every workspace and admin page render so the frontend can hide the "+ New Dashboard" affordance and any "Fork as personal" affordance without an extra round-trip. The push MUST happen via `InitialStateBuilder::setAllowUserDashboards()` (REQ-INIT-002) — direct calls to `IInitialState::provideInitialState()` are forbidden by the `lint:initial-state` CI guard.

#### Scenario: Initial state matches setting on the workspace page
- GIVEN admin setting `allow_user_dashboards = '1'`
- WHEN any user loads the workspace page (`PageController::index`)
- THEN the page initial state MUST include `allowUserDashboards: true`
- AND the workspace `provide` MUST expose the same value to descendants

#### Scenario: Initial state matches setting on the admin page
- GIVEN admin setting `allow_user_dashboards = '0'`
- WHEN an admin loads the MyDash admin settings page (`MyDashAdmin::getForm`)
- THEN the admin initial state MUST include `allowUserDashboards: false`

#### Scenario: Frontend honours the flag
- GIVEN initial state has `allowUserDashboards: false`
- WHEN the workspace renders
- THEN the "Create dashboard…" entry in `DashboardConfigMenu` MUST NOT be visible
- AND the empty-state "Create dashboard" button in `Views.vue` MUST NOT be visible
- AND any "Fork as personal" affordance MUST NOT be visible
- AND attempting to invoke the underlying actions via direct API call MUST still hit the 403 (defense in depth — REQ-ASET-003)

#### Scenario: Toast surfaces the stable error code on direct API invocation
- GIVEN the frontend `useDashboardStore.createDashboard` is invoked
- AND the backend returns HTTP 403 with `error: 'personal_dashboards_disabled'`
- THEN the store MUST surface a localised toast via `@nextcloud/dialogs::showError` reading the `Personal dashboards are not enabled by your administrator` translation
- AND the original error MUST be re-thrown so callers can short-circuit

## Non-Functional Requirements

- **Performance**: GET /api/admin/settings MUST return within 100ms. Settings lookups during user operations (e.g., `PermissionService::canCreateDashboard()`) query the `AdminSettingMapper` each time; caching is NOT currently implemented.
- **Security**: All admin settings endpoints MUST require Nextcloud admin authentication. Settings MUST NOT be exposed to non-admin users in any way (not even setting keys).
- **Data integrity**: Settings MUST survive server restarts and app updates. Missing settings MUST fall back to documented defaults without errors.
- **Accessibility**: The admin settings form MUST have proper labels, be keyboard-navigable, and meet WCAG AA standards. Toggle states MUST be communicated to screen readers.
- **Localization**: All setting labels, descriptions, validation messages, and success/error notifications MUST support English and Dutch.

### Current Implementation Status

**Fully implemented:**
- REQ-ASET-001 (Retrieve Admin Settings): `AdminSettingsService::getSettings()` in `lib/Service/AdminSettingsService.php` returns all 4 settings with documented defaults. `AdminController::getSettings()` in `lib/Controller/AdminController.php` exposes GET /api/admin/settings. Non-admin access is blocked because AdminController lacks `#[NoAdminRequired]`.
- REQ-ASET-002 (Update Admin Settings): `AdminSettingsService::updateSettings()` accepts abbreviated camelCase params (`defaultPermLevel`, `allowUserDash`, `allowMultiDash`, `defaultGridCols`). `AdminController::updateSettings()` returns `{"status": "ok"}`.
- REQ-ASET-003 (Allow User Dashboards — runtime gating): `DashboardService::getAllowUserDashboards()` and `DashboardService::assertPersonalDashboardsAllowed()` in `lib/Service/DashboardService.php` are the canonical reader and gate. `DashboardApiController::create()` calls the assert FIRST and returns the stable `{status:'error', error:'personal_dashboards_disabled', message:...}` envelope mapped from `PersonalDashboardsDisabledException` (`lib/Exception/PersonalDashboardsDisabledException.php`). The defense-in-depth `PermissionService::canCreateDashboard()` and `AdminSettingsService::getSettings()` defaults also return `false` so a missing row blocks creation everywhere.
- REQ-ASET-015 (Initial-state mirror): `PageController::index` and `MyDashAdmin::getForm` both call `DashboardService::getAllowUserDashboards()` and push the value via `InitialStateBuilder::setAllowUserDashboards()`. The frontend reads it via `loadInitialState('workspace' | 'admin')` and provides it down the tree (REQ-INIT-004); `DashboardConfigMenu`, `Views.vue`, and `AdminSettings.vue` inject it. `useDashboardStore.createDashboard` surfaces the 403 envelope as a localised toast via `@nextcloud/dialogs::showError`.
- REQ-ASET-004 (Allow Multiple Dashboards): `PermissionService::canHaveMultipleDashboards()` checks the setting. `DashboardApiController::checkCreatePermissions()` counts existing dashboards and returns 403 if multiples are disallowed.
- REQ-ASET-005 (Default Permission Level): `DashboardFactory::create()` in `lib/Service/DashboardFactory.php` hardcodes `PERMISSION_FULL` for user-created dashboards. The admin default setting is used as fallback by `PermissionService::getEffectivePermissionLevel()`.
- REQ-ASET-007 (Settings Persistence): Settings are stored in `oc_mydash_admin_settings` table via `AdminSettingMapper`. Defaults are returned in-code when DB rows are absent.
- REQ-ASET-008 (Admin Settings UI): `MyDashAdmin` in `lib/Settings/MyDashAdmin.php` implements `ISettings`, `MyDashAdminSection` in `lib/Settings/MyDashAdminSection.php` implements `IIconSection`. Frontend in `src/components/admin/AdminSettings.vue` renders toggles, dropdowns, and save logic.

**Not yet implemented:**
- REQ-ASET-002 validation: No server-side validation for permission level values (any string accepted), grid column range (any integer accepted), or boolean type coercion. Documented as NOTEs in the spec.
- REQ-ASET-006 default grid columns: `DashboardFactory::create()` hardcodes `gridColumns: 12` and does NOT read the `defaultGridColumns` admin setting. The admin setting exists but is not applied when creating user dashboards.
- REQ-ASET-003 fork endpoint: The `POST /api/dashboards/{uuid}/fork` route is owned by the separate `fork-current-as-personal` capability and does not yet exist; gating will be added there with the same `PersonalDashboardsDisabledException` envelope.
- REQ-ASET-008 localization: UI labels use `t('mydash', ...)` translation function but actual Dutch translations are not verified in l10n files.

**Partial implementations:**
- REQ-ASET-006 (Default Grid Columns): The setting can be stored and retrieved, but `DashboardFactory::create()` ignores it, hardcoding 12. Template copies correctly use the template's `gridColumns` via `TemplateService::buildDashboardFromTemplate()`.

### Standards & References
- Nextcloud Admin Settings API: `OCP\Settings\ISettings`, `OCP\Settings\IIconSection`
- Nextcloud AppConfig pattern (though this app uses a custom DB table instead of `IAppConfig`)
- WCAG 2.1 AA for the admin settings form (keyboard navigation, labels, focus indicators)
- WAI-ARIA: Toggle states for checkbox switches communicated via `NcCheckboxRadioSwitch`
