# Dashboard Tasks

## Database Layer

- [x] **T01**: Define Dashboard entity with all fields (uuid, name, description, type, userId, basedOnTemplate, gridColumns, permissionLevel, targetGroups, isDefault, isActive, createdAt, updatedAt), constants for type and permission-level values, `getTargetGroupsArray()` / `setTargetGroupsArray()` helpers, and `jsonSerialize()` — `mydash/lib/Db/Dashboard.php`
- [x] **T02**: Implement `DashboardTableBuilder` with full DDL: BIGINT PK, VARCHAR uuid (UNIQUE), VARCHAR name, TEXT description, VARCHAR type (default 'user'), VARCHAR user_id, BIGINT based_on_template, INTEGER grid_columns (default 12), VARCHAR permission_level (default 'full'), TEXT target_groups, SMALLINT is_default, SMALLINT is_active, DATETIME created_at / updated_at — `mydash/lib/Migration/DashboardTableBuilder.php`
- [x] **T03**: Add indexes to `oc_mydash_dashboards`: PRIMARY KEY on id, UNIQUE on uuid, INDEX on user_id, INDEX on type, composite INDEX on (user_id, is_active) — `mydash/lib/Migration/DashboardTableBuilder.php`
- [x] **T04**: Write initial migration `Version001000Date20240101000000` that calls `MigrationTableBuilder` to create all four tables (dashboards, widget_placements, admin_settings, conditional_rules) — `mydash/lib/Migration/Version001000Date20240101000000.php`
- [x] **T05**: Implement `DashboardMapper` extending `QBMapper<Dashboard>`: `find(id)`, `findByUuid(uuid)`, `findByUserId(userId)` (type=user, ordered by created_at ASC), `findActiveByUserId(userId)`, `findAdminTemplates()`, `findDefaultTemplate()`, `deactivateAllForUser(userId)` (bulk UPDATE is_active=0), `setActive(dashboardId, userId)` (deactivate-all then activate-one), `clearDefaultTemplates()` — `mydash/lib/Db/DashboardMapper.php`

## Service Layer

- [x] **T06**: Implement `DashboardFactory::create(userId, name, description)` to build a new `Dashboard` entity with defaults: type=user, gridColumns=12, permissionLevel=full, isActive=1, generated UUID v4, current timestamps — `mydash/lib/Service/DashboardFactory.php`
- [x] **T07**: Implement `DashboardResolver::tryGetActiveDashboard(userId)` — queries `findActiveByUserId`, loads placements, builds result; returns null on `DoesNotExistException` — `mydash/lib/Service/DashboardResolver.php`
- [x] **T08**: Implement `DashboardResolver::tryActivateExistingDashboard(userId)` — falls back to first user dashboard, calls `setActive`, loads placements, builds result; returns null if no dashboards exist — `mydash/lib/Service/DashboardResolver.php`
- [x] **T09**: Implement `DashboardResolver::buildResult(dashboard, placements)` and `getEffectivePermissionLevel(dashboard)` — resolves permission level from parent template if `basedOnTemplate` is set, falls back to dashboard own level, then to `AdminSetting` default — `mydash/lib/Service/DashboardResolver.php`
- [x] **T10**: Implement `DashboardResolver::handleTemplateResult(template, allowUserDashboards, userId)` — if allowed, copies template to user dashboard and returns it; otherwise returns template with `view_only` permission — `mydash/lib/Service/DashboardResolver.php`
- [x] **T11**: Implement `TemplateService::getApplicableTemplate(userId)` — fetches all admin templates, resolves user's Nextcloud groups, matches group-targeted templates first, falls back to default template — `mydash/lib/Service/TemplateService.php`
- [x] **T12**: Implement `TemplateService::createDashboardFromTemplate(userId, template)` — builds dashboard entity from template fields, deactivates existing dashboards, inserts new dashboard, copies all widget placements via `clonePlacement()` — `mydash/lib/Service/TemplateService.php`
- [x] **T13**: Implement `DashboardService::getUserDashboards(userId)` — delegates to `DashboardMapper::findByUserId` — `mydash/lib/Service/DashboardService.php`
- [x] **T14**: Implement `DashboardService::getEffectiveDashboard(userId)` — orchestrates the three-step resolver waterfall (active → existing → template/create) — `mydash/lib/Service/DashboardService.php`
- [x] **T15**: Implement `DashboardService::createDashboard(userId, name, description)` — uses `DashboardFactory`, deactivates all user dashboards, inserts new dashboard as active — `mydash/lib/Service/DashboardService.php`
- [x] **T16**: Implement `DashboardService::updateDashboard(dashboardId, userId, data)` — ownership check, selective field update (name, description, gridColumns), optional placement position batch-update, timestamp refresh — `mydash/lib/Service/DashboardService.php`
- [x] **T17**: Implement `DashboardService::deleteDashboard(dashboardId, userId)` — ownership check, cascade-delete placements via `WidgetPlacementMapper::deleteByDashboardId`, delete dashboard entity — `mydash/lib/Service/DashboardService.php`
- [x] **T18**: Implement `DashboardService::activateDashboard(dashboardId, userId)` — ownership check, calls `DashboardMapper::setActive` (deactivate-all then activate-one), returns updated dashboard — `mydash/lib/Service/DashboardService.php`
- [x] **T19**: Implement `PermissionService::canCreateDashboard(userId)` and `canHaveMultipleDashboards(userId)` — read `KEY_ALLOW_USER_DASHBOARDS` and `KEY_ALLOW_MULTIPLE_DASHBOARDS` from `AdminSettingMapper` — `mydash/lib/Service/PermissionService.php`
- [x] **T20**: Implement `PermissionService::canEditDashboard(userId, dashboardId)` — rejects admin templates, ownership check, and permission-level check (add_only or full required) — `mydash/lib/Service/PermissionService.php`
- [x] **T21**: Implement `PermissionService::canAddWidget`, `canRemoveWidget`, `canStyleWidget` — widget-level permission checks that delegate to `getEffectivePermissionLevel` with compulsory-widget guard for add_only — `mydash/lib/Service/PermissionService.php`
- [x] **T22**: Implement `PermissionService::verifyDashboardOwnership(userId, dashboardId)` and `verifyPlacementOwnership(userId, placementId)` — throw `Exception('Access denied')` if userId does not match — `mydash/lib/Service/PermissionService.php`

## HTTP Controller Layer

- [x] **T23**: Implement `ResponseHelper` static class with `unauthorized()`, `forbidden(message)`, `error(exception, statusCode)`, `success(data, statusCode)`, and `serializeList(entities)` factory methods — `mydash/lib/Controller/ResponseHelper.php`
- [x] **T24**: Register all dashboard routes in `routes.php`: `GET /api/dashboards`, `GET /api/dashboard`, `POST /api/dashboard`, `PUT /api/dashboard/{id}`, `DELETE /api/dashboard/{id}`, `POST /api/dashboard/{id}/activate` — `mydash/appinfo/routes.php`
- [x] **T25**: Implement `DashboardApiController::list()` — auth guard, `getUserDashboards`, serialize and return 200 — `mydash/lib/Controller/DashboardApiController.php`
- [x] **T26**: Implement `DashboardApiController::getActive()` — auth guard, `getEffectiveDashboard`, returns composite `{ dashboard, placements, permissionLevel }` or 404 — `mydash/lib/Controller/DashboardApiController.php`
- [x] **T27**: Implement `DashboardApiController::create(name, description)` — auth guard, `resolveCreateParams` (handles array body or individual params), permission checks, `createDashboard`, return 201 — `mydash/lib/Controller/DashboardApiController.php`
- [x] **T28**: Implement `DashboardApiController::update(id, name, description, placements)` — auth guard, `canEditDashboard` check, `buildUpdateData` filter, `updateDashboard`, return 200 — `mydash/lib/Controller/DashboardApiController.php`
- [x] **T29**: Implement `DashboardApiController::delete(id)` — auth guard, `deleteDashboard`, return 200 `{ status: "ok" }` — `mydash/lib/Controller/DashboardApiController.php`
- [x] **T30**: Implement `DashboardApiController::activate(id)` — auth guard, `activateDashboard`, return 200 with activated dashboard — `mydash/lib/Controller/DashboardApiController.php`
- [x] **T31**: Register admin template routes in `routes.php` and implement `AdminController` with `listTemplates()`, `getTemplate(id)`, `createTemplate(...)`, `updateTemplate(...)`, `deleteTemplate(id)`, `getSettings()`, `updateSettings(...)` — `mydash/lib/Controller/AdminController.php`, `mydash/appinfo/routes.php`

## Frontend — API Client

- [x] **T32**: Implement `api.js` with Axios-based methods for all dashboard endpoints: `getDashboards()`, `getActiveDashboard()`, `createDashboard(data)`, `updateDashboard(id, data)`, `deleteDashboard(id)`, `activateDashboard(id)` — `mydash/src/services/api.js`

## Frontend — Pinia Store

- [x] **T33**: Define `useDashboardStore` with state (`dashboards`, `activeDashboard`, `widgetPlacements`, `permissionLevel`, `loading`, `saving`), getters (`activeDashboardId`, `getPlacementById`, `compulsoryPlacements`) — `mydash/src/stores/dashboard.js`
- [x] **T34**: Implement `loadDashboards()` store action — parallel fetch of dashboard list and active dashboard; populates all state fields — `mydash/src/stores/dashboard.js`
- [x] **T35**: Implement `switchDashboard(dashboardId)` store action — calls `activateDashboard`, then re-fetches active dashboard and placements — `mydash/src/stores/dashboard.js`
- [x] **T36**: Implement `createDashboard(name)` store action — POST to backend, push result to `dashboards`, set as `activeDashboard` — `mydash/src/stores/dashboard.js`
- [x] **T37**: Implement `updatePlacements(placements)` store action — optimistic local update followed by async `updateDashboard` API call with position data only — `mydash/src/stores/dashboard.js`
- [x] **T38**: Implement `addWidgetToDashboard(widgetId, position)` and `addTileToDashboard(tileData, position)` store actions — POST to widget/tile endpoints, push returned placement to `widgetPlacements` — `mydash/src/stores/dashboard.js`
- [x] **T39**: Implement `removeWidgetFromDashboard(placementId)` store action — compulsory-widget guard, DELETE endpoint, filter placement from local state — `mydash/src/stores/dashboard.js`
- [x] **T40**: Implement `updateWidgetPlacement(placementId, updates)` store action — PUT endpoint, reactive splice update of `widgetPlacements` array — `mydash/src/stores/dashboard.js`

## Frontend — Components

- [x] **T41**: Implement `DashboardSwitcher.vue` — `NcSelect` dropdown driven by `dashboards` prop; maps to `{ id, label }` option objects; emits `switch` with selected dashboard id — `mydash/src/components/DashboardSwitcher.vue`
- [x] **T42**: Implement `DashboardGrid.vue` — GridStack initialisation using `gridColumns` prop; renders `WidgetWrapper` or `TileWidget` per placement based on `tileType`; emits `update:placements` on GridStack `change` events; `syncGridItems` for reactive add/remove — `mydash/src/components/DashboardGrid.vue`
- [x] **T43**: Wire dashboard management into `WidgetPicker.vue` — exposes "Create dashboard", "Edit dashboard", "Delete dashboard" controls that emit events consumed by `Views.vue` — `mydash/src/components/WidgetPicker.vue`
- [x] **T44**: Implement `Views.vue` root view — initialises all three stores on `created`, maps dashboard store state and actions, implements edit mode toggle, `handleCreateDashboard` (prompt + createDashboard), `handleEditDashboard` (prompt + API + reload), `handleDeleteDashboard` (confirm + API + reload), conditionally renders `DashboardSwitcher` when more than one dashboard exists — `mydash/src/views/Views.vue`
