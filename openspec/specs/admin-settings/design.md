# Admin Settings — Technical Design

## Overview

Admin settings give Nextcloud administrators global control over MyDash behaviour. They are stored as JSON-encoded key-value pairs in a dedicated database table (`oc_mydash_admin_settings`) and are read on every relevant operation. There is no in-process cache layer; the mapper reads directly from the database on each call and relies on the database driver's connection pool.

---

## Storage Layer

### Table: `oc_mydash_admin_settings`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | BIGINT UNSIGNED | PK, auto-increment | Internal row ID |
| `setting_key` | VARCHAR(255) | NOT NULL, UNIQUE (`mydash_setting_key`) | String identifier for the setting |
| `setting_value` | TEXT | nullable | JSON-encoded value (bool, int, or string) |
| `updated_at` | DATETIME | NOT NULL | Last write timestamp |

Created by `SettingsTableBuilder::create()` via `MigrationTableBuilder::createAdminSettingsTable()`, which is called in the initial migration `Version001000Date20240101000000`.

### Value Encoding

All values are stored as JSON via `AdminSetting::setValueEncoded(mixed $value)` → `json_encode($value)`. Retrieval uses `AdminSetting::getValueDecoded()` → `json_decode($this->settingValue, associative: true)`. This means:
- Boolean `true`/`false` round-trips correctly (stored as `true`/`false`, not `"true"`/`"false"`).
- Integer `12` is stored as `12` and decoded as PHP `int`.
- String `"add_only"` is stored as `"add_only"` (with JSON quotes) and decoded as PHP `string`.

### Defined Setting Keys (constants on `AdminSetting`)

| Constant | Key string | PHP type after decode | Default |
|----------|-----------|----------------------|---------|
| `KEY_ALLOW_USER_DASHBOARDS` | `allow_user_dashboards` | `bool` | `true` |
| `KEY_ALLOW_MULTIPLE_DASHBOARDS` | `allow_multiple_dashboards` | `bool` | `true` |
| `KEY_DEFAULT_PERMISSION_LEVEL` | `default_permission_level` | `string` | `add_only` (see note) |
| `KEY_DEFAULT_GRID_COLUMNS` | `default_grid_columns` | `int` | `12` |

> **Note on default permission level**: The spec documents the factory default as `full`, but `AdminSettingsService::getSettings()` falls back to `Dashboard::PERMISSION_ADD_ONLY` when no row exists. The `DashboardFactory` hardcodes `PERMISSION_FULL` when creating dashboards from scratch. This divergence means the effective default depends on the code path; the stored-value default is `add_only`.

---

## Read Path

```
AdminSettingMapper::getValue(key, default)
  └─ findByKey(key) → AdminSetting::getValueDecoded()
  └─ DoesNotExistException → return $default
```

`AdminSettingMapper::getAllAsArray()` fetches all rows and returns `[setting_key => decoded_value]`.

`AdminSettingsService::getSettings()` calls `getAllAsArray()` and builds the camelCase response array with per-key defaults:

```php
return [
    'defaultPermissionLevel'  => $settings['default_permission_level'] ?? Dashboard::PERMISSION_ADD_ONLY,
    'allowUserDashboards'     => $settings['allow_user_dashboards']     ?? true,
    'allowMultipleDashboards' => $settings['allow_multiple_dashboards'] ?? true,
    'defaultGridColumns'      => $settings['default_grid_columns']      ?? 12,
];
```

---

## Write Path

```
AdminSettingsService::updateSettings(...)
  └─ for each non-null argument:
       AdminSettingMapper::setSetting(key, value)
         ├─ findByKey(key) → update existing row
         └─ DoesNotExistException → insert new row
```

`setSetting` is an upsert: it attempts a find first, then updates or inserts. `updated_at` is always set to `new DateTime()`.

---

## Enforcement Logic

Settings are enforced at two call sites:

### 1. `PermissionService` (creation guards)

`canCreateDashboard(string $userId): bool`
- Reads `allow_user_dashboards` via `AdminSettingMapper::getValue(KEY_ALLOW_USER_DASHBOARDS, default: true)`.
- Returns the boolean directly. `DashboardApiController::checkCreatePermissions()` calls this and returns HTTP 403 if `false`.
- **Admins bypass this**: the controller method has `#[NoAdminRequired]` but there is no explicit admin bypass in `PermissionService`. The frontend hides the create button when the setting is `false`.

`canHaveMultipleDashboards(string $userId): bool`
- Reads `allow_multiple_dashboards` via `AdminSettingMapper::getValue(KEY_ALLOW_MULTIPLE_DASHBOARDS, default: true)`.
- Called in `checkCreatePermissions()` only when the user already has at least one dashboard.

`getEffectivePermissionLevel(Dashboard $dashboard): string`
- Falls back to `AdminSettingMapper::getValue(KEY_DEFAULT_PERMISSION_LEVEL, default: Dashboard::PERMISSION_FULL)` when the dashboard has no permission level set and is not based on a template.

### 2. `DashboardService::tryCreateFromTemplate()`

When no active dashboard exists and a first-load auto-create is triggered:
- Reads `allow_user_dashboards` via `AdminSettingMapper::getValue(KEY_ALLOW_USER_DASHBOARDS, default: true)`.
- Passes the value to `DashboardResolver::handleTemplateResult()` to decide whether to create a read-only view or a full user copy.
- If no template applies and `allow_user_dashboards === true`, creates a blank "My Dashboard".

### 3. `DashboardFactory::create()` — default grid columns

The factory currently hardcodes `grid_columns: 12` and `permission_level: PERMISSION_FULL` on new dashboards, **without consulting admin settings**. The `default_grid_columns` and `default_permission_level` settings are not applied by the factory; they are only read by `PermissionService::getEffectivePermissionLevel()` as a fallback for dashboards that have no level stored.

> This is a gap between the spec (which says the default should be applied at creation time) and the current implementation (where the factory uses hardcoded values).

---

## API Endpoints

| Method | URL | Auth | Controller method |
|--------|-----|------|-------------------|
| `GET` | `/apps/mydash/api/admin/settings` | Admin only | `AdminController::getSettings()` |
| `PUT` | `/apps/mydash/api/admin/settings` | Admin only | `AdminController::updateSettings()` |

Route names: `admin#getSettings`, `admin#updateSettings` (defined in `appinfo/routes.php`).

`AdminController` has no `#[NoAdminRequired]` attribute on either method, so Nextcloud's default admin-only enforcement applies.

### GET response shape

```json
{
  "defaultPermissionLevel": "add_only",
  "allowUserDashboards": true,
  "allowMultipleDashboards": true,
  "defaultGridColumns": 12
}
```

### PUT request parameters (camelCase, all optional)

| Parameter | Type | Notes |
|-----------|------|-------|
| `defaultPermLevel` | `string\|null` | Stored to `default_permission_level` |
| `allowUserDash` | `bool\|null` | Stored to `allow_user_dashboards` |
| `allowMultiDash` | `bool\|null` | Stored to `allow_multiple_dashboards` |
| `defaultGridCols` | `int\|null` | Stored to `default_grid_columns` |

> **Note on validation**: The controller accepts any string for `defaultPermLevel` and any int for `defaultGridCols`. There is no server-side validation enforcing allowed enum values (`view_only`, `add_only`, `full`) or the range constraint (1–24). The spec requires 400 responses for invalid values, but this validation is not yet implemented.

PUT response on success: `{"status": "ok"}` with HTTP 200.

---

## Admin Settings UI Panel

### Registration

Registered in `appinfo/info.xml`:

```xml
<settings>
    <admin>OCA\MyDash\Settings\MyDashAdmin</admin>
    <admin-section>OCA\MyDash\Settings\MyDashAdminSection</admin-section>
</settings>
```

`MyDashAdminSection` implements `IIconSection`:
- ID: `mydash`
- Name: `MyDash` (localised via `IL10N`)
- Priority: `80`
- Icon: `img/mydash.svg` (resolved via `IURLGenerator`)

`MyDashAdmin` implements `ISettings`:
- Section: `mydash`
- Priority: `10`
- Renders template `templates/settings/admin.php`, which is a single `<div id="mydash-admin-settings"></div>` mount point.
- Enqueues the compiled asset `mydash-admin` via `Util::addScript()`.

### Frontend Entry Point

`src/admin.js` bootstraps a standalone Vue 2 + Pinia app mounted on `#mydash-admin-settings`, rendering `AdminSettings.vue`.

### `AdminSettings.vue` Component

The component is a single-file Vue component with no Pinia store; it manages all state locally.

**Settings section fields:**

| Field | Component | Binding | Auto-save trigger |
|-------|-----------|---------|-------------------|
| Default permission level | `NcSelect` (options: `view_only`, `add_only`, `full`) | `settings.defaultPermissionLevel` (object `{id, label}`) | `@input="saveSettings"` |
| Allow user dashboards | `NcCheckboxRadioSwitch` | `settings.allowUserDashboards` (bool) | `@update:checked="updateSetting(...)"` |
| Allow multiple dashboards | `NcCheckboxRadioSwitch` | `settings.allowMultipleDashboards` (bool) | `@update:checked="updateSetting(...)"` |
| Default grid columns | `NcSelect` (options: `[6, 8, 12]`) | `settings.defaultGridColumns` (int) | `@input="saveSettings"` |

**Behaviour:**
- On mount (`created()`), calls `api.getAdminSettings()` and `api.getAdminTemplates()` in parallel.
- Each settings change triggers an immediate PUT to `api/admin/settings` (auto-save, no explicit Save button).
- `defaultPermissionLevel` is stored in the component as an option object `{id, label}` and serialised to its `id` string on save.
- No success toast or error toast is shown; errors are logged to console only.

**Template management section** (also in the same panel):
- Lists admin templates with edit and delete actions.
- Opens a modal (`NcModal`) for create/edit with fields: name, description, target groups (`NcSelectTags`), permission level, isDefault toggle.
- Available groups array is always empty (group fetching is stubbed as a TODO comment).

### API calls (from `src/services/api.js`)

```js
api.getAdminSettings()        // GET /apps/mydash/api/admin/settings
api.updateAdminSettings(data) // PUT /apps/mydash/api/admin/settings
```

---

## Non-Functional Notes

- **No caching**: Settings are read directly from the database on each enforcement check (no APCu or in-memory layer). For high-traffic installations this could cause noticeable overhead on the `create` endpoint.
- **No input validation server-side**: Invalid enum values or out-of-range integers are silently accepted and stored. Frontend constrains choices to valid options via dropdown, but the API is unguarded.
- **No reset endpoint**: Factory reset is achieved by sending a PUT with all default values.
- **Localisation**: Labels and descriptions in the Vue component use `t('mydash', ...)`. PHP classes use `IL10N::t()`. Dutch translations depend on `l10n/nl.js` and `l10n/nl.php` existing in the app.
- **Accessibility**: `NcCheckboxRadioSwitch` provides built-in ARIA roles. `NcSelect` wraps `vue-select` with accessible labelling. No additional `aria-*` attributes are added beyond what the library provides.
