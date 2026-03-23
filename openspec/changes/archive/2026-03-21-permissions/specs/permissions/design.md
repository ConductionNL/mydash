# Permission System — Technical Design

## Overview

The permission system controls what editing operations a user may perform on a dashboard. It is enforced at two layers: the **API/service layer** (server-side, authoritative) and the **frontend layer** (UI hiding, non-authoritative). The three permission levels form a strict hierarchy: `view_only` < `add_only` < `full`.

---

## Data Model

### `mydash_dashboards` table

| Column | Type | Notes |
|---|---|---|
| `permission_level` | `VARCHAR(20)` | `'full'` (default), `'add_only'`, `'view_only'` |
| `based_on_template` | `BIGINT NULL` | FK to the originating admin template row |

The `permission_level` column is defined in `DashboardTableBuilder::addColumns()` with `default: 'full'`.

### `mydash_widget_placements` table

| Column | Type | Notes |
|---|---|---|
| `is_compulsory` | `SMALLINT UNSIGNED` | `0` = removable, `1` = protected |

Stored as a SMALLINT (0/1) in the DB, exposed as an integer in `WidgetPlacement::jsonSerialize()`, and compared as `=== false` in the service layer (`$placement->getIsCompulsory() === false`).

**Relevant entities:**
- `lib/Db/Dashboard.php` — constants `PERMISSION_VIEW_ONLY`, `PERMISSION_ADD_ONLY`, `PERMISSION_FULL`; property `$permissionLevel` defaulting to `PERMISSION_FULL`
- `lib/Db/WidgetPlacement.php` — property `$isCompulsory` defaulting to `0`

---

## Effective Permission Level Resolution

`PermissionService::getEffectivePermissionLevel(Dashboard $dashboard): string` (and the identical implementation in `DashboardResolver::getEffectivePermissionLevel()`) resolves permission in this priority order:

1. If `$dashboard->getBasedOnTemplate()` is set → look up the template row and return **the template's** `permissionLevel`. This means the template always overrides whatever is stored on the user's copy.
2. If the template row no longer exists (deleted) → fall through to the dashboard's own `permissionLevel`.
3. If the dashboard has an explicit non-empty `permissionLevel` → return it.
4. Fall back to the admin setting `AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL` (default: `'full'`).

This logic lives in two places that must stay in sync:
- `lib/Service/PermissionService.php` — used for write-operation checks
- `lib/Service/DashboardResolver.php` — used when building the `getActive` response payload

The `permissionLevel` key is surfaced to the frontend in the `GET /api/dashboard` response:

```json
{
  "dashboard": { ... },
  "placements": [ ... ],
  "permissionLevel": "add_only"
}
```

---

## Permission Level Semantics

| Level | `canEditDashboard` | `canAddWidget` / `canStyleWidget` | `canRemoveWidget` (non-compulsory) | `canRemoveWidget` (compulsory) |
|---|---|---|---|---|
| `view_only` | false | false | false | false |
| `add_only` | true | true | true | false |
| `full` | true | true | true | true |

`canEditDashboard` gates the `PUT /api/dashboard/{id}` endpoint (grid layout saves, name changes via that endpoint). Note: dashboard **deletion** (`DELETE /api/dashboard/{id}`) and **metadata-only updates** bypass this check — ownership alone is sufficient.

---

## PermissionService — Method Reference

**File:** `lib/Service/PermissionService.php`

| Method | Checks |
|---|---|
| `canEditDashboard(userId, dashboardId)` | Ownership + not admin template + level is `add_only` or `full` |
| `canAddWidget(userId, dashboardId)` | Ownership + level is `add_only` or `full` |
| `canRemoveWidget(userId, placementId)` | Ownership + level logic: `view_only` → false; `full` → true; `add_only` → `!isCompulsory` |
| `canStyleWidget(userId, placementId)` | Ownership + level is `add_only` or `full` |
| `canCreateDashboard(userId)` | Admin setting `KEY_ALLOW_USER_DASHBOARDS` (default true) |
| `canHaveMultipleDashboards(userId)` | Admin setting `KEY_ALLOW_MULTIPLE_DASHBOARDS` (default true) |
| `getEffectivePermissionLevel(dashboard)` | Template-override → own level → global default |
| `verifyDashboardOwnership(userId, dashboardId)` | Throws `Exception('Access denied')` if not owner |
| `verifyPlacementOwnership(userId, placementId)` | Delegates to `verifyDashboardOwnership` |

---

## API Enforcement Points

### `DashboardApiController` (`lib/Controller/DashboardApiController.php`)

| Endpoint | Permission check |
|---|---|
| `PUT /api/dashboard/{id}` | `PermissionService::canEditDashboard()` → HTTP 403 if false |
| `POST /api/dashboard` | `PermissionService::canCreateDashboard()` + `canHaveMultipleDashboards()` |
| `DELETE /api/dashboard/{id}` | Ownership only (no permission level check — users always own their dashboards) |

### `WidgetApiController` (`lib/Controller/WidgetApiController.php`)

| Endpoint | Permission check |
|---|---|
| `POST /api/dashboard/{dashboardId}/widgets` | `canAddWidget()` → HTTP 403 |
| `POST /api/dashboard/{dashboardId}/tile` | `canAddWidget()` → HTTP 403 |
| `PUT /api/widgets/{placementId}` | `canStyleWidget()` → HTTP 403 |
| `DELETE /api/widgets/{placementId}` | `canRemoveWidget()` → HTTP 403 |

### `RuleApiController` (`lib/Controller/RuleApiController.php`)

| Endpoint | Permission check |
|---|---|
| `GET /api/widgets/{placementId}/rules` | `verifyPlacementOwnership()` — ownership only |
| `POST /api/widgets/{placementId}/rules` | `verifyPlacementOwnership()` — ownership only |

Note: Rule creation has no permission level check beyond ownership; an `add_only` user can add conditional rules to their placements.

---

## Compulsory Widget Handling

### Setting the flag

`is_compulsory` is set to `0` for all user-created placements (see `PlacementService::addWidget()` and `PlacementService::addTileFromArray()`). It is only ever set to `1` by admins, either directly on a template placement or via the Admin API.

### Inheritance during template copy

`TemplateService::clonePlacement()` copies `isCompulsory` verbatim from the template placement:

```php
$placement->setIsCompulsory(isCompulsory: $source->getIsCompulsory());
```

This runs inside `TemplateService::createDashboardFromTemplate()`, which is called by `DashboardResolver::handleTemplateResult()` when `$allowUserDashboards === true`.

### Enforcement

`PermissionService::canRemoveWidget()` performs the compulsory check:

```php
if ($permissionLevel === Dashboard::PERMISSION_ADD_ONLY) {
    return $placement->getIsCompulsory() === false;
}
```

`PlacementUpdater::applyDisplayUpdates()` does **not** handle `is_compulsory` in its update array — the field is silently ignored on `PUT /api/widgets/{placementId}`, satisfying REQ-PERM-004.

### Frontend indication

`WidgetWrapper.vue` computes `canRemove`:

```js
canRemove() {
    return !this.placement.isCompulsory
}
```

This is used to conditionally hide the remove/delete action in the widget context. The `is_compulsory` field is included in the `jsonSerialize()` output of `WidgetPlacement`, so the frontend always has the current value.

---

## Admin-Template-Only Dashboards (no copy mode)

When `allow_user_dashboards` is `false` and a template applies to the user, `DashboardResolver::handleTemplateResult()` serves the admin template directly without creating a user copy:

```php
return [
    'dashboard'       => $template,
    'placements'      => $placements,
    'permissionLevel' => Dashboard::PERMISSION_VIEW_ONLY,
];
```

The permission level is hard-coded to `view_only` regardless of the template's own `permissionLevel` setting. This is the strictest mode: users see the dashboard read-only and cannot interact with it.

---

## Default Permission Level for New Dashboards

`DashboardFactory::create()` always sets `PERMISSION_FULL` on freshly created user dashboards. The admin global default setting (`KEY_DEFAULT_PERMISSION_LEVEL`) is consulted by `getEffectivePermissionLevel()` only when `basedOnTemplate` is null and `permissionLevel` is empty/null — in practice this means orphaned or manually-created rows without a level.

---

## Frontend Permission Gating

**File:** `src/views/Views.vue`

The `permissionLevel` from the store drives UI visibility:

```js
canEdit() {
    return this.permissionLevel !== 'view_only'
}
```

- The Edit/Customize toggle button is rendered only when `canEdit` is true.
- The Add button inside the picker is rendered only while `isEditMode` is true, which requires `canEdit`.
- The `WidgetWrapper` component receives `editMode` as a prop and only shows the configure (Cog) button when `editMode` is true.

**File:** `src/components/DashboardGrid.vue`

GridStack is initialized with `disableDrag: !editMode` and `disableResize: !editMode`. When `editMode` toggles, a watcher calls `grid.enable()` or `grid.disable()`.

---

## Permission Level Immutability

`DashboardService::applyDashboardUpdates()` only applies `name`, `description`, `gridColumns`, and `placements` from the update payload. The `permissionLevel` field is never read from user-supplied data in `updateDashboard`, satisfying REQ-PERM-005. Similarly, `PlacementUpdater::applyDisplayUpdates()` never reads `isCompulsory` from update data.

---

## Admin Settings Involved

Stored in `mydash_admin_settings` table, accessed via `AdminSettingMapper::getValue()`:

| Key | Default | Effect on permissions |
|---|---|---|
| `default_permission_level` | `'full'` | Fallback level when dashboard has no explicit level and no template |
| `allow_user_dashboards` | `true` | When false, template is served read-only at `view_only` level |
| `allow_multiple_dashboards` | `true` | Controls dashboard creation, not permission level |

---

## Admin Template Permission Level

`AdminTemplateService::createTemplate()` defaults `permissionLevel` to `PERMISSION_ADD_ONLY` when not specified:

```php
string $permissionLevel = Dashboard::PERMISSION_ADD_ONLY,
```

This means templates created without an explicit level grant users `add_only` access — they can add/remove non-compulsory widgets but cannot remove compulsory ones.
