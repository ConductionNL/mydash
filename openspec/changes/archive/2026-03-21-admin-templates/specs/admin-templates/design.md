# Admin Templates — Technical Design

## Overview

Admin templates are pre-configured dashboards that Nextcloud administrators create and distribute to users by group membership or as a global default. At first access, the system creates a personal, independent copy of each applicable template for the user. After copy creation, the user's dashboard and the source template share no live relationship.

---

## Component Map

```
AdminController
  └── AdminTemplateService      (template CRUD)
        ├── DashboardMapper       (findAdminTemplates, clearDefaultTemplates)
        └── WidgetPlacementMapper (findByDashboardId, deleteByDashboardId)

DashboardService.getEffectiveDashboard()
  └── DashboardResolver
        ├── tryGetActiveDashboard()
        ├── tryActivateExistingDashboard()
        └── handleTemplateResult()
              └── TemplateService.createDashboardFromTemplate()
                    ├── buildDashboardFromTemplate()
                    ├── DashboardMapper.deactivateAllForUser()
                    ├── DashboardMapper.insert()
                    └── copyTemplatePlacements()
                          └── WidgetPlacementMapper.insert() (per placement)

TemplateService.getApplicableTemplate()
  ├── DashboardMapper.findAdminTemplates()
  ├── IUserManager.get()
  ├── IGroupManager.getUserGroupIds()
  └── DashboardMapper.findDefaultTemplate()

PermissionService.getEffectivePermissionLevel()
  └── DashboardMapper.find(basedOnTemplate) → template.permissionLevel
```

---

## Database Schema

### `oc_mydash_dashboards`

Stores both user dashboards and admin templates in the same table, discriminated by the `type` column.

| Column             | Type      | Notes                                                   |
|--------------------|-----------|---------------------------------------------------------|
| `id`               | BIGINT PK | Auto-increment.                                         |
| `uuid`             | VARCHAR36 | Unique, generated with Ramsey UUID v4.                  |
| `name`             | VARCHAR   | Template display name.                                  |
| `description`      | TEXT      | Optional description.                                   |
| `type`             | VARCHAR20 | `'admin_template'` or `'user'`.                         |
| `user_id`          | VARCHAR64 | NULL for admin templates; owner's user ID for copies.   |
| `based_on_template`| BIGINT    | FK-like reference to source template ID; NULL if none.  |
| `grid_columns`     | INTEGER   | Default 12.                                             |
| `permission_level` | VARCHAR20 | `'view_only'`, `'add_only'`, or `'full'`.               |
| `target_groups`    | TEXT      | JSON-encoded array of Nextcloud group IDs.              |
| `is_default`       | SMALLINT  | 1 = default template (distributed to all users).        |
| `is_active`        | SMALLINT  | 1 = currently active dashboard for the owning user.     |
| `created_at`       | DATETIME  |                                                         |
| `updated_at`       | DATETIME  |                                                         |

Indexes: `mydash_dashboard_uuid` (unique), `mydash_dashboard_user`, `mydash_dashboard_type`, `mydash_dashboard_active` (user_id + is_active).

### `oc_mydash_widget_placements`

Holds widget grid positions for both template dashboards and user copies.

| Column             | Type      | Notes                                                         |
|--------------------|-----------|---------------------------------------------------------------|
| `id`               | BIGINT PK |                                                               |
| `dashboard_id`     | BIGINT    | References a row in `mydash_dashboards`.                      |
| `widget_id`        | VARCHAR   | Nextcloud widget identifier string.                           |
| `grid_x/y`         | INTEGER   | Grid position.                                                |
| `grid_width/height`| INTEGER   | Widget dimensions in grid units.                              |
| `is_compulsory`    | SMALLINT  | 1 = user cannot remove this placement.                        |
| `is_visible`       | SMALLINT  | Default 1.                                                    |
| `style_config`     | TEXT      | JSON-encoded style overrides.                                 |
| `custom_title`     | VARCHAR   | Optional display title override.                              |
| `custom_icon`      | VARCHAR   | Optional icon override.                                       |
| `show_title`       | SMALLINT  | Default 1.                                                    |
| `sort_order`       | INTEGER   | Ordering hint; placements fetched ORDER BY sort_order, grid_y, grid_x. |
| `tile_*` columns   | VARCHAR   | Inline tile configuration (type, title, icon, colors, link).  |
| `created_at`       | DATETIME  |                                                               |
| `updated_at`       | DATETIME  |                                                               |

---

## Template Creation

`AdminController::createTemplate()` receives: `name`, `description?`, `targetGroups?`, `permissionLevel` (default `add_only`), `isDefault` (default `false`).

`AdminTemplateService::createTemplate()` flow:

1. If `isDefault === true`: call `DashboardMapper::clearDefaultTemplates()` — sets `is_default = 0` on all rows where `type = 'admin_template'`. This enforces the single-default invariant.
2. Instantiate a new `Dashboard` entity with `type = 'admin_template'`, `userId = null`.
3. Serialize `targetGroups` array to JSON via `setTargetGroupsArray()`.
4. Persist via `DashboardMapper::insert()`.
5. Return HTTP 201 with the serialized template.

Validation note: `permissionLevel` must be one of `Dashboard::PERMISSION_VIEW_ONLY`, `PERMISSION_ADD_ONLY`, or `PERMISSION_FULL`. The controller passes the value through as-is; the caller is responsible for sending a valid value (no explicit guard in the service layer currently).

---

## Single-Default Invariant

The invariant "at most one template has `is_default = 1`" is enforced entirely at the service level — there is no unique database constraint.

- On `createTemplate(isDefault: true)`: `clearDefaultTemplates()` runs before `insert()`.
- On `updateTemplate($id, ['isDefault' => true])`: `clearDefaultTemplates()` runs inside `applyTemplateUpdates()` before `update()`.
- On `updateTemplate($id, ['isDefault' => false])`: only the named template is updated; no other rows are touched.
- `DashboardMapper::clearDefaultTemplates()` issues: `UPDATE mydash_dashboards SET is_default = 0 WHERE type = 'admin_template'`.

The two-step approach (clear all, then set one) does not use a transaction wrapper; a crash between steps would leave no default template, which is a safe degraded state.

---

## Target Group Matching

`TemplateService::getApplicableTemplate(userId)`:

1. Load all admin templates via `DashboardMapper::findAdminTemplates()` (ordered by name ASC).
2. Resolve the `IUser` from `IUserManager`; return `null` if user does not exist.
3. Fetch all group IDs for the user via `IGroupManager::getUserGroupIds(user)`.
4. Iterate templates:
   - Skip templates with an empty `targetGroups` array (these are candidates for the default path).
   - If `array_intersect(userGroups, targetGroups)` is non-empty, return that template immediately (first match wins).
5. If no group-targeted template matched, try `DashboardMapper::findDefaultTemplate()` (finds the one with `is_default = 1`).
6. Return `null` if no default exists.

**Important behaviour:** currently only one template is returned even when both a group-specific template and the default template apply. The spec scenario "Multiple templates match" (alice receives two dashboards) is not yet implemented — the current code returns the first group match and falls back to the default only if no group match was found. There is no mechanism to distribute multiple templates to a single user in the current implementation.

---

## Distribution on First Access

`DashboardService::getEffectiveDashboard(userId)` is the entry point for `GET /api/dashboard` and `GET /api/dashboards`.

Resolution order:

1. `DashboardResolver::tryGetActiveDashboard()` — query for `type = 'user'` AND `is_active = 1`. Returns immediately if found.
2. `DashboardResolver::tryActivateExistingDashboard()` — query for all `type = 'user'` dashboards belonging to the user. If any exist, set the first (by `created_at ASC`) as active and return it.
3. `DashboardService::tryCreateFromTemplate()` — called only when the user has no dashboards at all:
   a. Read `allow_user_dashboards` from `AdminSettingMapper` (default `true`).
   b. Call `TemplateService::getApplicableTemplate(userId)`.
   c. If a template was found: call `DashboardResolver::handleTemplateResult()`.
   d. If no template and `allowUserDashboards = true`: auto-create an empty "My Dashboard".
   e. If no template and `allowUserDashboards = false`: return `null` (empty response).

**Duplicate prevention:** step 2 activates an existing user dashboard if one already exists, so `createDashboardFromTemplate` is never reached for returning users. The `based_on_template` field on the copy records the source template ID but is not used for deduplication queries — deduplication is implicit because returning users always hit steps 1 or 2.

---

## Template Copy Mechanics

`TemplateService::createDashboardFromTemplate(userId, template)`:

1. `buildDashboardFromTemplate()` — creates a new `Dashboard` in memory:
   - `type = 'user'`, `userId = $userId`
   - `basedOnTemplate = $template->getId()` (soft reference)
   - Copies: `name`, `description`, `gridColumns`, `permissionLevel`
   - `isActive = true` (the new copy will be the active dashboard)
2. `DashboardMapper::deactivateAllForUser(userId)` — sets all existing user dashboards to `is_active = 0`.
3. `DashboardMapper::insert(dashboard)` — persists the copy; returns the entity with its new `id`.
4. `copyTemplatePlacements(templateId, dashboardId)` — iterates all placements from the template (fetched ordered by `sort_order, grid_y, grid_x`) and inserts a clone for each one via `clonePlacement()`.

`clonePlacement()` copies: `dashboardId` (replaced with the new copy's ID), `widgetId`, `gridX`, `gridY`, `gridWidth`, `gridHeight`, `isCompulsory`, `isVisible`, `styleConfig`, `customTitle`, `showTitle`, `sortOrder`. Timestamps are reset to `new DateTime()`.

**Atomicity note:** the copy operation is not wrapped in an explicit transaction. If `copyTemplatePlacements` fails partway through, the dashboard row will exist with only a partial set of placements. The spec requires full rollback on failure — this is a known gap between spec and implementation.

---

## Copy Independence

After `createDashboardFromTemplate` completes:

- The copy is a fully independent row in `mydash_dashboards` with `type = 'user'`.
- The placement rows are independent rows in `mydash_widget_placements` pointing to the copy's `dashboard_id`.
- `based_on_template` records the origin but has no cascading behaviour — it is only consulted when resolving the effective `permissionLevel` (see below).
- Deleting the template does not cascade to copies; `DashboardMapper::delete()` and `WidgetPlacementMapper::deleteByDashboardId()` operate on the template's own row and placements only.
- Updating or deleting template placements has no effect on existing copies.

---

## Effective Permission Level Resolution

`PermissionService::getEffectivePermissionLevel(dashboard)` and `DashboardResolver::getEffectivePermissionLevel(dashboard)` implement the same logic (both exist; `PermissionService` is used by widget/placement checks, `DashboardResolver` is used when building the dashboard response):

1. If `dashboard->getBasedOnTemplate()` is not null: look up the source template.
   - If the template still exists: return `template->getPermissionLevel()` (live inheritance from template).
   - If the template was deleted (`DoesNotExistException`): fall through to the dashboard's own level.
2. Return `dashboard->getPermissionLevel()` if set.
3. Fall back to `AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL` (default: `'full'`).

This means permission level is **not** frozen at copy time — as long as the source template exists, the effective level always reflects the template's current `permissionLevel`. Changing the template's `permissionLevel` after distribution **does** affect existing copies at runtime, which is a divergence from REQ-TMPL-003 (spec says existing copies must not be affected retroactively).

---

## Permission Enforcement at the API Layer

Admin endpoints (`/api/admin/templates/*`, `/api/admin/settings`) are registered without middleware-level admin guards. Enforcement depends on `AdminController` being mapped to Nextcloud's admin-only routes and/or the Nextcloud framework's `SubAdminMiddleware`. The controller itself does not call `IGroupManager` or `OCP\IUserSession` to verify admin status — it relies on the routing layer.

`PermissionService` is used by `WidgetApiController` to gate widget additions, removals, and style updates:
- `canAddWidget` / `canEditDashboard`: permission level must be `add_only` or `full`.
- `canRemoveWidget`: `view_only` → denied; `full` → always allowed; `add_only` → denied if placement `is_compulsory = 1`.
- `canStyleWidget`: same as `canAddWidget`.

---

## Key Files

| File | Role |
|------|------|
| `lib/Controller/AdminController.php` | REST endpoints for template CRUD and admin settings |
| `lib/Service/AdminTemplateService.php` | Template CRUD logic, single-default enforcement |
| `lib/Service/TemplateService.php` | Group matching and copy creation |
| `lib/Service/DashboardService.php` | Effective dashboard resolution entry point |
| `lib/Service/DashboardResolver.php` | Resolution steps; `handleTemplateResult`; effective permission level |
| `lib/Service/DashboardFactory.php` | Factory for plain user dashboards (not template-derived) |
| `lib/Service/PermissionService.php` | Widget-level permission checks; effective permission level |
| `lib/Db/Dashboard.php` | Entity with type/permission/targetGroups constants and JSON helpers |
| `lib/Db/DashboardMapper.php` | `findAdminTemplates`, `findDefaultTemplate`, `clearDefaultTemplates`, `deactivateAllForUser` |
| `lib/Db/WidgetPlacement.php` | Placement entity including `isCompulsory` flag |
| `lib/Db/WidgetPlacementMapper.php` | `findByDashboardId`, `deleteByDashboardId` |
| `lib/Db/AdminSetting.php` | Setting keys: `allow_user_dashboards`, `default_permission_level`, etc. |
| `lib/Migration/DashboardTableBuilder.php` | DB schema for `mydash_dashboards` |
| `appinfo/routes.php` | Route definitions for admin template endpoints |
