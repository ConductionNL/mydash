# Dashboards — Technical Design

## Overview

Dashboards are implemented as a full-stack feature spanning a PHP backend (Nextcloud AppFramework) and a Vue 2 / Pinia frontend. The PHP layer follows the standard Nextcloud pattern of Entity → Mapper → Service → Controller. The frontend uses a Pinia store that mirrors every backend endpoint, and a set of Vue components that render the dashboard grid and management UI.

---

## Component Architecture

### PHP Backend

| Class | Namespace | Role |
|---|---|---|
| `Dashboard` | `OCA\MyDash\Db` | ORM entity; maps to `oc_mydash_dashboards` |
| `DashboardMapper` | `OCA\MyDash\Db` | QBMapper subclass; all DB queries for dashboards |
| `DashboardFactory` | `OCA\MyDash\Service` | Creates new `Dashboard` entities with validated defaults |
| `DashboardResolver` | `OCA\MyDash\Service` | Resolves the *effective* dashboard for a user (active → fallback → template) |
| `DashboardService` | `OCA\MyDash\Service` | Orchestrates CRUD and activation; owns the business rules |
| `TemplateService` | `OCA\MyDash\Service` | Handles admin template matching and copy-on-first-use distribution |
| `PermissionService` | `OCA\MyDash\Service` | Evaluates create / edit / widget-level permissions against `AdminSetting` |
| `DashboardApiController` | `OCA\MyDash\Controller` | HTTP layer for user-facing dashboard routes |
| `AdminController` | `OCA\MyDash\Controller` | HTTP layer for admin template management routes |
| `ResponseHelper` | `OCA\MyDash\Controller` | Static factory for standard JSON responses (success, error, forbidden, unauthorized) |
| `DashboardTableBuilder` | `OCA\MyDash\Migration` | Encapsulates the DDL for `oc_mydash_dashboards` |
| `Version001000Date20240101000000` | `OCA\MyDash\Migration` | Initial migration — calls `DashboardTableBuilder::create()` |
| `AdminSetting` | `OCA\MyDash\Db` | Entity for key/value admin config (permission defaults, feature flags) |
| `AdminSettingMapper` | `OCA\MyDash\Db` | Mapper for `oc_mydash_admin_settings` |

### Vue Frontend

| File | Role |
|---|---|
| `src/stores/dashboard.js` | Pinia store — holds `dashboards`, `activeDashboard`, `widgetPlacements`, `permissionLevel`; exposes all async actions |
| `src/services/api.js` | Thin Axios wrapper around every backend endpoint; produces plain Promise results |
| `src/views/Views.vue` | Root application view; orchestrates edit mode, modal state, and delegates to store actions |
| `src/components/DashboardGrid.vue` | GridStack-based drag-and-drop grid; emits `update:placements` on every move/resize |
| `src/components/DashboardSwitcher.vue` | `NcSelect` dropdown listing all dashboards; emits `switch` with the chosen dashboard id |
| `src/components/WidgetPicker.vue` | Sidebar panel; exposes "Create dashboard", "Edit dashboard", "Delete dashboard" actions alongside widget/tile selection |

---

## Data Flow

### GET /api/dashboards (list)

```
Browser
  └─ api.getDashboards()
       └─ GET /apps/mydash/api/dashboards
            └─ DashboardApiController::list()
                 └─ DashboardService::getUserDashboards(userId)
                      └─ DashboardMapper::findByUserId(userId)
                           SQL: SELECT * FROM oc_mydash_dashboards
                                WHERE user_id = ? AND type = 'user'
                                ORDER BY created_at ASC
                      └─ returns Dashboard[]
                 └─ ResponseHelper::serializeList(dashboards)
            └─ JSON 200 [ {...}, {...} ]
  └─ dashboardStore.dashboards = response.data
```

### GET /api/dashboard (get active)

```
Browser
  └─ api.getActiveDashboard()
       └─ GET /apps/mydash/api/dashboard
            └─ DashboardApiController::getActive()
                 └─ DashboardService::getEffectiveDashboard(userId)
                      ├─ DashboardResolver::tryGetActiveDashboard(userId)
                      │    └─ DashboardMapper::findActiveByUserId(userId)  [may throw DoesNotExistException]
                      │    └─ WidgetPlacementMapper::findByDashboardId(id)
                      │    └─ DashboardResolver::buildResult(dashboard, placements)
                      │         └─ DashboardResolver::getEffectivePermissionLevel(dashboard)
                      │              └─ if basedOnTemplate → DashboardMapper::find(templateId) → template.permissionLevel
                      │              └─ else → dashboard.permissionLevel or AdminSettingMapper default
                      ├─ (if null) DashboardResolver::tryActivateExistingDashboard(userId)
                      │    └─ DashboardMapper::findByUserId(userId) → take first
                      │    └─ DashboardMapper::setActive(id, userId)
                      │    └─ WidgetPlacementMapper::findByDashboardId(id)
                      └─ (if null) DashboardService::tryCreateFromTemplate(userId)
                           └─ AdminSettingMapper::getValue(KEY_ALLOW_USER_DASHBOARDS)
                           └─ TemplateService::getApplicableTemplate(userId)
                                └─ DashboardMapper::findAdminTemplates()
                                └─ IGroupManager::getUserGroupIds(user)
                                └─ group-match loop → DashboardMapper::findDefaultTemplate()
                           └─ if template: DashboardResolver::handleTemplateResult(...)
                                └─ if allowUserDashboards:
                                     TemplateService::createDashboardFromTemplate(userId, template)
                                     → DashboardMapper::insert(dashboard)
                                     → copyTemplatePlacements(templateId, dashboardId)
                                └─ else: return template directly with PERMISSION_VIEW_ONLY
                           └─ if no template and allowUserDashboards:
                                DashboardService::createDashboard(userId, 'My Dashboard')
            └─ JSON 200 { dashboard: {...}, placements: [...], permissionLevel: "full" }
  └─ dashboardStore.activeDashboard / widgetPlacements / permissionLevel updated
```

### POST /api/dashboard (create)

```
Browser
  └─ api.createDashboard({ name })
       └─ POST /apps/mydash/api/dashboard
            └─ DashboardApiController::create(name, description)
                 └─ resolveCreateParams(name, description) — handles both JSON body and individual params
                 └─ checkCreatePermissions(userId)
                      └─ PermissionService::canCreateDashboard(userId)
                           └─ AdminSettingMapper::getValue(KEY_ALLOW_USER_DASHBOARDS, default=true)
                      └─ PermissionService::canHaveMultipleDashboards(userId)
                           └─ AdminSettingMapper::getValue(KEY_ALLOW_MULTIPLE_DASHBOARDS, default=true)
                 └─ DashboardService::createDashboard(userId, name, description)
                      └─ DashboardFactory::create(userId, name, description)
                           → new Dashboard(); sets uuid, type='user', gridColumns=12,
                             permissionLevel='full', isActive=1, createdAt, updatedAt
                      └─ DashboardMapper::deactivateAllForUser(userId)
                           SQL: UPDATE oc_mydash_dashboards SET is_active=0, updated_at=?
                                WHERE user_id = ?
                      └─ DashboardMapper::insert(dashboard)
            └─ JSON 201 { dashboard: {...} }
  └─ dashboardStore.dashboards.push(dashboard); activeDashboard = dashboard
```

### PUT /api/dashboard/{id} (update)

```
Browser
  └─ api.updateDashboard(id, { name?, description?, placements? })
       └─ PUT /apps/mydash/api/dashboard/{id}
            └─ DashboardApiController::update(id, name, description, placements)
                 └─ PermissionService::canEditDashboard(userId, dashboardId)
                      └─ DashboardMapper::find(id) — checks type != admin_template, userId match,
                           and permission level is add_only or full
                 └─ DashboardService::updateDashboard(dashboardId, userId, data)
                      └─ DashboardMapper::find(id) — ownership check
                      └─ applyDashboardUpdates(dashboard, data)
                           → setName / setDescription / setGridColumns selectively
                           → setUpdatedAt
                           → if placements array: WidgetPlacementMapper::updatePositions(updates)
                      └─ DashboardMapper::update(dashboard)
            └─ JSON 200 { dashboard: {...} }
```

### DELETE /api/dashboard/{id} (delete)

```
Browser
  └─ api.deleteDashboard(id)
       └─ DELETE /apps/mydash/api/dashboard/{id}
            └─ DashboardApiController::delete(id)
                 └─ DashboardService::deleteDashboard(dashboardId, userId)
                      └─ DashboardMapper::find(id) — ownership check
                      └─ WidgetPlacementMapper::deleteByDashboardId(dashboardId)
                           SQL: DELETE FROM oc_mydash_widget_placements WHERE dashboard_id = ?
                      └─ DashboardMapper::delete(dashboard)
            └─ JSON 200 { status: "ok" }
  └─ Views.vue: loadDashboards() refresh
```

### POST /api/dashboard/{id}/activate (activate)

```
Browser
  └─ api.activateDashboard(id)
       └─ POST /apps/mydash/api/dashboard/{id}/activate
            └─ DashboardApiController::activate(id)
                 └─ DashboardService::activateDashboard(dashboardId, userId)
                      └─ DashboardMapper::find(id) — ownership check
                      └─ DashboardMapper::setActive(dashboardId, userId)
                           → deactivateAllForUser(userId)  [SQL bulk UPDATE is_active=0]
                           → UPDATE oc_mydash_dashboards SET is_active=1, updated_at=?
                             WHERE id=? AND user_id=?
                      └─ dashboard.setIsActive(true)
            └─ JSON 200 { dashboard: {...} }
  └─ dashboardStore.switchDashboard → getActiveDashboard refresh
```

---

## Database Schema

### Table: `oc_mydash_dashboards`

| Column | Type | Constraints | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NOT NULL, AUTO_INCREMENT, PK | — | Integer primary key |
| `uuid` | VARCHAR(36) | NOT NULL, UNIQUE INDEX `mydash_dashboard_uuid` | — | UUID v4 |
| `name` | VARCHAR(255) | NOT NULL | — | Human-readable label |
| `description` | TEXT | NULL | — | Optional description |
| `type` | VARCHAR(20) | NOT NULL, INDEX `mydash_dashboard_type` | `'user'` | `'user'` or `'admin_template'` |
| `user_id` | VARCHAR(64) | NULL, INDEX `mydash_dashboard_user` | — | NULL for admin templates |
| `based_on_template` | BIGINT UNSIGNED | NULL | — | FK reference to parent template id |
| `grid_columns` | INTEGER | NOT NULL | 12 | Number of grid columns (1–24) |
| `permission_level` | VARCHAR(20) | NOT NULL | `'full'` | `'view_only'`, `'add_only'`, or `'full'` |
| `target_groups` | TEXT | NULL | — | JSON-encoded array of Nextcloud group IDs |
| `is_default` | SMALLINT UNSIGNED | NOT NULL | 0 | Boolean (0/1); only meaningful on admin_template rows |
| `is_active` | SMALLINT UNSIGNED | NOT NULL, INDEX `mydash_dashboard_active(user_id, is_active)` | 0 | Boolean (0/1); the single active dashboard per user |
| `created_at` | DATETIME | NOT NULL | — | ISO-8601 creation timestamp |
| `updated_at` | DATETIME | NOT NULL | — | ISO-8601 last-modified timestamp |

**Indexes:**
- PRIMARY KEY on `id`
- UNIQUE on `uuid` (`mydash_dashboard_uuid`)
- INDEX on `user_id` (`mydash_dashboard_user`)
- INDEX on `type` (`mydash_dashboard_type`)
- Composite INDEX on `(user_id, is_active)` (`mydash_dashboard_active`)

---

## Key Implementation Decisions

### 1. SMALLINT for boolean fields
`is_active` and `is_default` are stored as `SMALLINT UNSIGNED` rather than `BOOLEAN`. Nextcloud's DBAL abstraction maps PHP `boolean` inconsistently across database engines; using `integer` type registration in the entity constructor (`$this->addType('isActive', 'integer')`) avoids ambiguity. The `setActive()` mapper method therefore uses `IQueryBuilder::PARAM_INT` explicitly.

### 2. Deactivate-then-activate pattern for single-active invariant
The single-active-dashboard invariant is enforced in `DashboardMapper::setActive()` via a two-step SQL approach: first a bulk `UPDATE … SET is_active=0 WHERE user_id=?`, then `UPDATE … SET is_active=1 WHERE id=? AND user_id=?`. This avoids any window where two rows could have `is_active=1`. The same `deactivateAllForUser()` call is made in `DashboardService::createDashboard()` so newly created dashboards activate atomically.

### 3. DashboardResolver waterfall for getEffectiveDashboard
Rather than one large method, resolution is split into three named strategies invoked in order: `tryGetActiveDashboard` → `tryActivateExistingDashboard` → `tryCreateFromTemplate`. Each returns `null` on miss, making the waterfall trivially readable and each strategy independently testable.

### 4. DashboardFactory for entity construction
Dashboard entity creation is isolated in `DashboardFactory` (no DB dependency) so that unit tests can verify default field assignment without a database connection. The factory always sets `type='user'`, `gridColumns=12`, `permissionLevel='full'`, and `isActive=1` for new user dashboards.

### 5. Permission delegation to AdminSetting
Rather than hard-coding permission defaults, `PermissionService` and `DashboardResolver` consult `AdminSettingMapper` for `KEY_ALLOW_USER_DASHBOARDS`, `KEY_ALLOW_MULTIPLE_DASHBOARDS`, and `KEY_DEFAULT_PERMISSION_LEVEL`. This allows administrators to restrict dashboard creation globally without code changes.

### 6. Template inheritance of permission_level
Dashboards created from admin templates store the template's id in `based_on_template`. At read time, `getEffectivePermissionLevel()` looks up the parent template and returns its `permission_level`. If the template has been deleted, the dashboard's own `permission_level` field is used as fallback. This means permission changes on a template propagate immediately to all derived dashboards.

### 7. resolveCreateParams handles both JSON body and individual params
`DashboardApiController::create()` accepts `$name` as `mixed` and checks `is_array($name)` to support clients sending a JSON object as the top-level body (Nextcloud's controller parameter injection collapses the body into `$name` when it is an associative array). This provides forward compatibility for richer create payloads.

### 8. Placement cascade delete
Widget placements are deleted explicitly before deleting the dashboard (`WidgetPlacementMapper::deleteByDashboardId()`), rather than relying on a database foreign key cascade. This is a Nextcloud convention because the app must support multiple database backends where FK cascade behavior differs.

### 9. Frontend optimistic placement update
`DashboardStore::updatePlacements()` updates local `widgetPlacements` state immediately before the API call completes. This gives instant visual feedback when dragging/resizing while the HTTP request runs in the background.

---

## File Paths

### PHP Backend

| File | Description |
|---|---|
| `mydash/appinfo/routes.php` | Route definitions for all dashboard endpoints |
| `mydash/lib/Db/Dashboard.php` | Dashboard entity |
| `mydash/lib/Db/DashboardMapper.php` | Database mapper |
| `mydash/lib/Db/AdminSetting.php` | Admin settings entity (keys: feature flags, defaults) |
| `mydash/lib/Db/AdminSettingMapper.php` | Admin settings mapper |
| `mydash/lib/Service/DashboardFactory.php` | Entity factory |
| `mydash/lib/Service/DashboardResolver.php` | Effective-dashboard resolution waterfall |
| `mydash/lib/Service/DashboardService.php` | Business logic orchestration |
| `mydash/lib/Service/TemplateService.php` | Admin template matching and copy-on-use distribution |
| `mydash/lib/Service/PermissionService.php` | Permission evaluation for all dashboard operations |
| `mydash/lib/Service/AdminTemplateService.php` | CRUD for admin templates |
| `mydash/lib/Service/AdminSettingsService.php` | Read/write of admin settings |
| `mydash/lib/Controller/DashboardApiController.php` | User-facing dashboard HTTP controller |
| `mydash/lib/Controller/AdminController.php` | Admin template and settings HTTP controller |
| `mydash/lib/Controller/ResponseHelper.php` | Static JSON response factory |
| `mydash/lib/Migration/DashboardTableBuilder.php` | DDL for `oc_mydash_dashboards` |
| `mydash/lib/Migration/Version001000Date20240101000000.php` | Initial migration (creates all tables) |

### Vue Frontend

| File | Description |
|---|---|
| `mydash/src/services/api.js` | Axios-based API client for all endpoints |
| `mydash/src/stores/dashboard.js` | Pinia store: state, getters, and async actions |
| `mydash/src/views/Views.vue` | Root view; edit mode state machine, modal orchestration |
| `mydash/src/components/DashboardGrid.vue` | GridStack drag-and-drop grid |
| `mydash/src/components/DashboardSwitcher.vue` | NcSelect dropdown for switching dashboards |
| `mydash/src/components/WidgetPicker.vue` | Sidebar with dashboard management actions |
