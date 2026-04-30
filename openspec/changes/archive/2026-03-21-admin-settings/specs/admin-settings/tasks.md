# Admin Settings Tasks

- [x] **T01**: Define `AdminSetting` entity with `setting_key`, `setting_value` (JSON-encoded TEXT), `updated_at`, key constants, `getValueDecoded()`, and `setValueEncoded()` — `lib/Db/AdminSetting.php`

- [x] **T02**: Implement `AdminSettingMapper` extending `QBMapper` with `findByKey()`, `getAllAsArray()`, `setSetting()` (upsert), and `getValue()` with default — `lib/Db/AdminSettingMapper.php`

- [x] **T03**: Create `SettingsTableBuilder::create()` to define the `mydash_admin_settings` table schema (BIGINT PK, VARCHAR(255) unique key, TEXT nullable value, DATETIME updated_at) — `lib/Migration/SettingsTableBuilder.php`

- [x] **T04**: Add `MigrationTableBuilder::createAdminSettingsTable()` facade method and wire it into the initial migration — `lib/Migration/MigrationTableBuilder.php`, `lib/Migration/Version001000Date20240101000000.php`

- [x] **T05**: Implement `AdminSettingsService::getSettings()` returning camelCase settings array with per-key defaults, and `updateSettings()` with nullable parameter per setting — `lib/Service/AdminSettingsService.php`

- [x] **T06**: Add `getSettings()` and `updateSettings()` controller actions to `AdminController`, injecting `AdminSettingsService` via constructor — `lib/Controller/AdminController.php`

- [x] **T07**: Register GET and PUT routes for `/api/admin/settings` in the app routes file — `appinfo/routes.php`

- [x] **T08**: Implement `PermissionService::canCreateDashboard()` reading `allow_user_dashboards` via `AdminSettingMapper::getValue()` — `lib/Service/PermissionService.php`

- [x] **T09**: Implement `PermissionService::canHaveMultipleDashboards()` reading `allow_multiple_dashboards` via `AdminSettingMapper::getValue()` — `lib/Service/PermissionService.php`

- [x] **T10**: Implement `PermissionService::getEffectivePermissionLevel()` falling back to `default_permission_level` from `AdminSettingMapper` when the dashboard has no explicit level — `lib/Service/PermissionService.php`

- [x] **T11**: Wire creation guards into `DashboardApiController::checkCreatePermissions()` calling `canCreateDashboard()` (HTTP 403 if false) and `canHaveMultipleDashboards()` when user already has dashboards — `lib/Controller/DashboardApiController.php`

- [x] **T12**: Inject `AdminSettingMapper` into `DashboardService` and read `allow_user_dashboards` in `tryCreateFromTemplate()` to gate auto-creation of a blank dashboard on first load — `lib/Service/DashboardService.php`

- [x] **T13**: Implement `MyDashAdminSection` (`IIconSection`) registering the `mydash` admin navigation section with icon, name, and priority — `lib/Settings/MyDashAdminSection.php`

- [x] **T14**: Implement `MyDashAdmin` (`ISettings`) that enqueues the `mydash-admin` script bundle and returns the `settings/admin` template response — `lib/Settings/MyDashAdmin.php`

- [x] **T15**: Register `MyDashAdmin` and `MyDashAdminSection` in `appinfo/info.xml` under `<settings>` — `appinfo/info.xml`

- [x] **T16**: Create the admin settings template mount-point file — `templates/settings/admin.php`

- [x] **T17**: Add `getAdminSettings()` and `updateAdminSettings()` methods to the frontend API service — `src/services/api.js`

- [x] **T18**: Build `AdminSettings.vue` single-file component with `NcSettingsSection`, `NcCheckboxRadioSwitch` toggles for allow-user-dashboards and allow-multiple-dashboards, `NcSelect` dropdowns for permission level and grid columns, auto-save on every change, and parallel load of settings + templates on `created()` — `src/components/admin/AdminSettings.vue`

- [x] **T19**: Create `src/admin.js` entry point bootstrapping Vue 2 + Pinia app mounting `AdminSettings.vue` on `#mydash-admin-settings` — `src/admin.js`
