# Widget Tasks

## Database Layer

- [x] **T01**: Define `WidgetPlacement` entity with all grid, display, and tile fields — `lib/Db/WidgetPlacement.php`
- [x] **T02**: Implement `getStyleConfigArray()` / `setStyleConfigArray()` helpers for JSON encode/decode on the entity — `lib/Db/WidgetPlacement.php`
- [x] **T03**: Implement `jsonSerialize()` on `WidgetPlacement`, conditionally including tile fields when `tileType !== null` — `lib/Db/WidgetPlacement.php`
- [x] **T04**: Create `PlacementTableBuilder` with core placement columns (id, dashboard_id, widget_id, grid_x/y/width/height, is_compulsory, is_visible, style_config, custom_title, show_title, sort_order, created_at, updated_at) — `lib/Migration/PlacementTableBuilder.php`
- [x] **T05**: Write initial migration `Version001000` that invokes `PlacementTableBuilder::create()` — `lib/Migration/Version001000Date20240101000000.php`
- [x] **T06**: Write migration `Version001003` adding tile-specific columns to `mydash_widget_placements` (tile_type, tile_title, tile_icon, tile_icon_type, tile_background_color, tile_text_color, tile_link_type, tile_link_value) — `lib/Migration/Version001003Date20260204120000.php`
- [x] **T07**: Write migration `Version001004` adding `custom_icon` (TEXT) column to `mydash_widget_placements` — `lib/Migration/Version001004Date20260204150000.php`
- [x] **T08**: Create `WidgetPlacementMapper` extending `QBMapper<WidgetPlacement>` with `find()`, `findByDashboardId()`, `findByDashboardAndWidget()`, `deleteByDashboardId()` — `lib/Db/WidgetPlacementMapper.php`
- [x] **T09**: Implement `updatePositions(array $updates)` on `WidgetPlacementMapper` for efficient batch grid saves — `lib/Db/WidgetPlacementMapper.php`
- [x] **T10**: Implement `getMaxSortOrder(int $dashboardId)` on `WidgetPlacementMapper` for auto-assigning sort order — `lib/Db/WidgetPlacementMapper.php`

## Service Layer

- [x] **T11**: Create `WidgetFormatter` service that converts `IWidget` instances to API response arrays, handling `IIconWidget`, `IAPIWidget`, `IAPIWidgetV2`, `IButtonWidget`, `IOptionWidget`, and `IReloadableWidget` interfaces — `lib/Service/WidgetFormatter.php`
- [x] **T12**: Create `WidgetItemLoader` service with `loadItems()`, `loadV1Items()` (via `IAPIWidget::getItems()`), and `loadV2Items()` (via `IAPIWidgetV2::getItemsV2()`) — `lib/Service/WidgetItemLoader.php`
- [x] **T13**: Create `PlacementUpdater` service with `applyGridUpdates()` (gridX/Y/Width/Height) and `applyDisplayUpdates()` (isVisible, showTitle, customTitle, customIcon, styleConfig) — `lib/Service/PlacementUpdater.php`
- [x] **T14**: Create `TileUpdater` service with `applyTileConfig()` (set all tile fields on new placement) and `applyTileUpdates()` (apply partial tile field updates) — `lib/Service/TileUpdater.php`
- [x] **T15**: Create `PlacementService` with `addWidget()`, `addTileFromArray()`, `updatePlacement()`, `removePlacement()`, `getPlacement()`, `getDashboardPlacements()` — `lib/Service/PlacementService.php`
- [x] **T16**: Create `WidgetService` as facade that wires together `IManager`, `PlacementService`, `WidgetFormatter`, `WidgetItemLoader`, and `IUserSession` — `lib/Service/WidgetService.php`
- [x] **T17**: Implement `PermissionService::canAddWidget()`, `canRemoveWidget()`, `canStyleWidget()` with ownership and permission level checks — `lib/Service/PermissionService.php`
- [x] **T18**: Implement `PermissionService::getEffectivePermissionLevel()` with template inheritance logic — `lib/Service/PermissionService.php`
- [x] **T19**: Implement `PermissionService::verifyDashboardOwnership()` and `verifyPlacementOwnership()` helpers — `lib/Service/PermissionService.php`

## Controller Layer

- [x] **T20**: Create `ResponseHelper` with `success()`, `error()`, `forbidden()`, `unauthorized()`, and `serializeList()` static methods — `lib/Controller/ResponseHelper.php`
- [x] **T21**: Create `RequestDataExtractor` with `extractPlacementData()` (pulls all updatable placement fields from request params) and `extractTileData()` — `lib/Controller/RequestDataExtractor.php`
- [x] **T22**: Create `WidgetApiController` with `listAvailable()` endpoint (`GET /api/widgets`) — `lib/Controller/WidgetApiController.php`
- [x] **T23**: Implement `WidgetApiController::getItems()` endpoint (`GET /api/widgets/items`) with `widgets[]` array param and `limit` — `lib/Controller/WidgetApiController.php`
- [x] **T24**: Implement `WidgetApiController::addWidget()` endpoint (`POST /api/dashboard/{dashboardId}/widgets`) with permission check — `lib/Controller/WidgetApiController.php`
- [x] **T25**: Implement `WidgetApiController::addTile()` endpoint (`POST /api/dashboard/{dashboardId}/tile`) — `lib/Controller/WidgetApiController.php`
- [x] **T26**: Implement `WidgetApiController::updatePlacement()` endpoint (`PUT /api/widgets/{placementId}`) with `canStyleWidget` permission check — `lib/Controller/WidgetApiController.php`
- [x] **T27**: Implement `WidgetApiController::removePlacement()` endpoint (`DELETE /api/widgets/{placementId}`) with `canRemoveWidget` permission check — `lib/Controller/WidgetApiController.php`
- [x] **T28**: Register all widget and placement routes in `routes.php` — `appinfo/routes.php`

## Frontend Store

- [x] **T29**: Create `useWidgetStore` Pinia store with `availableWidgets`, `widgetItems`, `loading` state — `src/stores/widgets.js`
- [x] **T30**: Implement `useWidgetStore::loadAvailableWidgets()` action calling `api.getAvailableWidgets()` — `src/stores/widgets.js`
- [x] **T31**: Implement `useWidgetStore::loadWidgetItems(widgetIds)` action with per-widget loading flags — `src/stores/widgets.js`
- [x] **T32**: Implement `useWidgetStore::refreshWidgetItems(widgetId)` action for auto-refresh support — `src/stores/widgets.js`
- [x] **T33**: Implement `useWidgetStore::getWidgetById` and `getWidgetItems` getters — `src/stores/widgets.js`
- [x] **T34**: Add `widgetPlacements` and `permissionLevel` state to `useDashboardStore` — `src/stores/dashboard.js`
- [x] **T35**: Implement `useDashboardStore::addWidgetToDashboard(widgetId, position)` action — `src/stores/dashboard.js`
- [x] **T36**: Implement `useDashboardStore::addTileToDashboard(tileData, position)` action — `src/stores/dashboard.js`
- [x] **T37**: Implement `useDashboardStore::removeWidgetFromDashboard(placementId)` with compulsory guard — `src/stores/dashboard.js`
- [x] **T38**: Implement `useDashboardStore::updateWidgetPlacement(placementId, updates)` with reactive splice update — `src/stores/dashboard.js`
- [x] **T39**: Implement `useDashboardStore::updatePlacements(placements)` for optimistic batch grid save — `src/stores/dashboard.js`

## Frontend Services

- [x] **T40**: Create `api.js` axios service with `getAvailableWidgets()`, `getWidgetItems()`, `addWidget()`, `addTile()`, `updateWidgetPlacement()`, `removeWidget()` — `src/services/api.js`
- [x] **T41**: Create `widgetBridge.js` singleton that intercepts `window.OCA.Dashboard.register` calls for legacy widget support, with `mountWidget()` and `hasWidgetCallback()` methods — `src/services/widgetBridge.js`

## Frontend Components

- [x] **T42**: Create `DashboardGrid.vue` that initialises GridStack, renders placements as `grid-stack-item` elements, differentiates tile vs. widget placements, handles `change` events, and emits `update:placements` — `src/components/DashboardGrid.vue`
- [x] **T43**: Create `WidgetWrapper.vue` that shows the optional header (icon + title + edit button), delegates content to `WidgetRenderer`, applies `styleConfig` CSS properties, and handles the tile special case (no header, transparent background) — `src/components/WidgetWrapper.vue`
- [x] **T44**: Create `WidgetRenderer.vue` that routes between TileWidget, `NcDashboardWidget` (API v1/v2), legacy DOM-mount, and loading/empty states — `src/components/WidgetRenderer.vue`
- [x] **T45**: Implement `WidgetRenderer` store subscription pattern so `localWidgetItemsData` reacts to `useWidgetStore.widgetItems` changes without breaking Vue 2 reactivity — `src/components/WidgetRenderer.vue`
- [x] **T46**: Implement legacy widget mounting in `WidgetRenderer` with exponential backoff retry loop (up to 20 attempts, max 1 s delay) for late-loading widget scripts — `src/components/WidgetRenderer.vue`
- [x] **T47**: Implement auto-refresh in `WidgetRenderer` using `setInterval` when `widget.reloadInterval > 0`, clearing on `beforeDestroy` — `src/components/WidgetRenderer.vue`
- [x] **T48**: Create `WidgetPicker.vue` slide-in panel with Widgets tab (searchable list, already-added indicator) and Dashboards tab (switch, edit, delete) — `src/components/WidgetPicker.vue`
- [x] **T49**: Create `WidgetStyleEditor.vue` modal with title toggle/override, background color picker, icon selector (MDI + NL Design System icons), and save/reset/delete actions — `src/components/WidgetStyleEditor.vue`
- [x] **T50**: Wire NL Design System icons into `WidgetStyleEditor` icon selector via `/apps/nldesign/img/icons/{Name}.svg` URL pattern — `src/components/WidgetStyleEditor.vue`
