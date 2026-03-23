# Widget Placement — Technical Design

## Overview

Widgets in MyDash are a two-layer concept:

1. **Available widgets** — discovered at runtime from Nextcloud's `OCP\Dashboard\IManager::getWidgets()`. These are PHP objects registered by other Nextcloud apps. MyDash does not own them.
2. **Widget placements** — records in `mydash_widget_placements` that tie a discovered widget (or a custom tile) to a specific position on a user's dashboard grid.

This document describes every PHP class, Vue component, and Pinia store that implements the widget feature, and how they connect.

---

## Component Architecture

### PHP Backend

```
Controller
  WidgetApiController          lib/Controller/WidgetApiController.php
  DashboardApiController       lib/Controller/DashboardApiController.php  (batch update path)
  RequestDataExtractor         lib/Controller/RequestDataExtractor.php
  ResponseHelper               lib/Controller/ResponseHelper.php

Service
  WidgetService                lib/Service/WidgetService.php       (facade / entry point)
  PlacementService             lib/Service/PlacementService.php    (CRUD for placements)
  PlacementUpdater             lib/Service/PlacementUpdater.php    (apply grid/display updates)
  TileUpdater                  lib/Service/TileUpdater.php         (apply tile-specific fields)
  WidgetFormatter              lib/Service/WidgetFormatter.php     (format IWidget → array)
  WidgetItemLoader             lib/Service/WidgetItemLoader.php    (load v1/v2 items)
  PermissionService            lib/Service/PermissionService.php   (ownership + permission checks)

Db
  WidgetPlacement              lib/Db/WidgetPlacement.php          (entity)
  WidgetPlacementMapper        lib/Db/WidgetPlacementMapper.php    (QBMapper)

Migration
  PlacementTableBuilder        lib/Migration/PlacementTableBuilder.php
  Version001000Date20240101000000  (initial schema: core placement columns)
  Version001003Date20260204120000  (adds tile_* columns to placement table)
  Version001004Date20260204150000  (adds custom_icon column)
```

### JavaScript Frontend

```
Store
  useWidgetStore               src/stores/widgets.js        (available widgets + items)
  useDashboardStore            src/stores/dashboard.js      (active dashboard + widgetPlacements)

Service
  api                          src/services/api.js          (axios wrappers)
  widgetBridge                 src/services/widgetBridge.js (legacy callback intercept)

Components
  DashboardGrid.vue            src/components/DashboardGrid.vue
  WidgetWrapper.vue            src/components/WidgetWrapper.vue
  WidgetRenderer.vue           src/components/WidgetRenderer.vue
  WidgetPicker.vue             src/components/WidgetPicker.vue
  WidgetStyleEditor.vue        src/components/WidgetStyleEditor.vue
  TileWidget.vue               src/components/TileWidget.vue
```

---

## Data Flow

### 1. Discover available widgets (GET /api/widgets)

```
Browser
  api.getAvailableWidgets()
    → GET /apps/mydash/api/widgets
      → WidgetApiController::listAvailable()
          → WidgetService::getAvailableWidgets()
              → IManager::getWidgets()          [Nextcloud DI]
              → WidgetFormatter::format()        [per widget]
                  buildBaseData()               id, title, order, iconClass, widgetUrl
                  applyIconUrl()                if IIconWidget → iconUrl
                  applyApiVersions()            if IAPIWidget → [1], if IAPIWidgetV2 → [2]
                  applyButtons()                if IButtonWidget → buttons[]
                  applyOptions()                if IOptionWidget → itemIconsRound
                  applyReloadInterval()         if IReloadableWidget → reloadInterval
              usort by order
          → JSONResponse 200 [ {id, title, order, iconClass, iconUrl, itemApiVersions, ...} ]
useWidgetStore.availableWidgets = response.data
```

### 2. Fetch widget items (GET /api/widgets/items)

```
WidgetRenderer::initWidget()
  → loadWidgetItems([widgetId])   [Pinia action]
    → api.getWidgetItems(widgetIds)
      → GET /apps/mydash/api/widgets/items?widgets[]=foo&widgets[]=bar
        → WidgetApiController::getItems(widgets, limit)
            → WidgetService::getWidgetItems(userId, widgetIds, limit)
                → IManager::getWidgets()
                → WidgetItemLoader::loadItems(widgets, userId, widgetIds, limit)
                    foreach widgetId:
                      if IAPIWidgetV2 → loadV2Items()   getItemsV2() → serialize
                      elif IAPIWidget → loadV1Items()   getItems()   → serialize
                      else            → {items:[], empty:'', halfEmpty:''}
                → return { widgetId: {items, emptyContentMessage, halfEmptyContentMessage} }
          → JSONResponse 200 { widgetId: {...}, ... }
useWidgetStore.widgetItems[widgetId] = { items, emptyContentMessage, halfEmptyContentMessage, loading }
WidgetRenderer.setupStoreSubscription() fires → localWidgetItemsData updated → widgetItems computed re-runs
```

### 3. Add widget to dashboard (POST /api/dashboard/{dashboardId}/widgets)

```
useDashboardStore::addWidgetToDashboard(widgetId, position)
  → api.addWidget(dashboardId, { widgetId, gridX, gridY, gridWidth, gridHeight })
    → POST /apps/mydash/api/dashboard/{dashboardId}/widgets
      → WidgetApiController::addWidget(dashboardId, widgetId, gridX, gridY, gridWidth, gridHeight)
          PermissionService::canAddWidget(userId, dashboardId)
            DashboardMapper::find(dashboardId) → check userId === dashboard.userId
            getEffectivePermissionLevel() → must be add_only or full
          WidgetService::addWidget(...)
            PlacementService::addWidget(...)
              new WidgetPlacement()
              setDashboardId, setWidgetId, setGridX, setGridY, setGridWidth, setGridHeight
              setIsCompulsory(0), setIsVisible(1), setShowTitle(1)
              setCreatedAt, setUpdatedAt
              WidgetPlacementMapper::insert()
          → JSONResponse 201 placement.jsonSerialize()
widgetPlacements.push(response.data)
```

### 4. Update placement (PUT /api/widgets/{placementId})

```
useDashboardStore::updateWidgetPlacement(placementId, updates)
  → api.updateWidgetPlacement(placementId, updates)
    → PUT /apps/mydash/api/widgets/{placementId}
      → WidgetApiController::updatePlacement(placementId)
          PermissionService::canStyleWidget(userId, placementId)
            PlacementMapper::find(placementId) → DashboardMapper::find(dashboardId)
            check userId ownership + permission level (add_only or full)
          RequestDataExtractor::extractPlacementData(request)
            pulls: gridX, gridY, gridWidth, gridHeight, isVisible, showTitle,
                   customTitle, customIcon, styleConfig,
                   tileTitle, tileIcon, tileIconType, tileBackgroundColor,
                   tileTextColor, tileLinkType, tileLinkValue
          WidgetService::updatePlacement(placementId, data)
            PlacementService::updatePlacement(placementId, data)
              PlacementMapper::find(placementId)
              PlacementUpdater::applyGridUpdates()       gridX/Y/Width/Height
              PlacementUpdater::applyDisplayUpdates()    isVisible, showTitle, customTitle, customIcon, styleConfig
              TileUpdater::applyTileUpdates()            tile* fields
              setUpdatedAt
              PlacementMapper::update()
          → JSONResponse 200 placement.jsonSerialize()
widgetPlacements.splice(index, 1, response.data)   [reactive update]
```

### 5. Batch update after grid drag (PUT /api/dashboard/{id})

```
DashboardGrid → on GridStack 'change' event
  handleGridChange(items)
    updatedPlacements = placements.map(merge gridItem coords)
    $emit('update:placements', updatedPlacements)
  → DashboardGrid parent (Views.vue)
    useDashboardStore::updatePlacements(placements)
      widgetPlacements = placements   [optimistic update]
      api.updateDashboard(id, { placements: [...{id,gridX,gridY,gridWidth,gridHeight}] })
        → PUT /apps/mydash/api/dashboard/{id}
          → DashboardApiController::update()
              DashboardService::updateDashboard(...)
                WidgetPlacementMapper::updatePositions(updates)
                  foreach update: UPDATE grid_x, grid_y, grid_width, grid_height, updated_at WHERE id
```

### 6. Remove placement (DELETE /api/widgets/{placementId})

```
useDashboardStore::removeWidgetFromDashboard(placementId)
  check: if compulsory + permissionLevel !== 'full' → abort
  api.removeWidget(placementId)
    → DELETE /apps/mydash/api/widgets/{placementId}
      → WidgetApiController::removePlacement(placementId)
          PermissionService::canRemoveWidget(userId, placementId)
            if permissionLevel === view_only → false
            if permissionLevel === full      → true
            if permissionLevel === add_only  → placement.isCompulsory === false
          WidgetService::removePlacement(placementId)
            PlacementService::removePlacement(placementId)
              PlacementMapper::find(placementId)
              PlacementMapper::delete(entity)
          → JSONResponse 200 {status: 'ok'}
widgetPlacements = widgetPlacements.filter(p => p.id !== placementId)
```

---

## Database Schema

### Table: `mydash_widget_placements`

| Column                | Type        | Nullable | Default  | Notes |
|-----------------------|-------------|----------|----------|-------|
| `id`                  | BIGINT UNSIGNED | NO   | AI       | Primary key |
| `dashboard_id`        | BIGINT UNSIGNED | NO   |          | FK to `mydash_dashboards.id` (cascade on app layer) |
| `widget_id`           | VARCHAR(255)| NO       |          | Nextcloud widget id (e.g. `weather_status`) or `tile-<uniqid>` for tiles |
| `grid_x`              | INTEGER     | NO       | 0        | Zero-based column |
| `grid_y`              | INTEGER     | NO       | 0        | Zero-based row |
| `grid_width`          | INTEGER     | NO       | 4        | Column span |
| `grid_height`         | INTEGER     | NO       | 4        | Row span |
| `is_compulsory`       | SMALLINT UNSIGNED | NO | 0       | 1 = cannot be removed without full permission |
| `is_visible`          | SMALLINT UNSIGNED | NO | 1       | 0 = hidden; note: spec also describes "conditional" visibility but DB stores 0/1 |
| `style_config`        | TEXT        | YES      | NULL     | JSON: `{backgroundColor, borderStyle, borderColor, borderWidth, borderRadius, padding:{top,right,bottom,left}}` |
| `custom_title`        | VARCHAR(255)| YES      | NULL     | Override title; null = use widget's own title |
| `show_title`          | SMALLINT UNSIGNED | NO | 1       | 1 = show header bar |
| `sort_order`          | INTEGER     | NO       | 0        | Sequential order within dashboard |
| `tile_type`           | VARCHAR(20) | YES      | NULL     | `'custom'` for tiles, null for regular widgets (added migration 003) |
| `tile_title`          | VARCHAR(255)| YES      | NULL     | Display title for custom tiles |
| `tile_icon`           | VARCHAR(2000)| YES     | NULL     | Icon class, URL, emoji, or SVG path (added migration 003) |
| `tile_icon_type`      | VARCHAR(20) | YES      | NULL     | `class`, `url`, `emoji`, `svg` |
| `tile_background_color`| VARCHAR(7) | YES      | NULL     | Hex color e.g. `#0082c9` |
| `tile_text_color`     | VARCHAR(7)  | YES      | NULL     | Hex color e.g. `#ffffff` |
| `tile_link_type`      | VARCHAR(20) | YES      | NULL     | `app` or `url` |
| `tile_link_value`     | VARCHAR(1000)| YES     | NULL     | App ID or URL |
| `custom_icon`         | TEXT        | YES      | NULL     | SVG path or icon data for widget icon override (added migration 004) |
| `created_at`          | DATETIME    | NO       |          | |
| `updated_at`          | DATETIME    | NO       |          | |

**Indexes:**
- PRIMARY KEY `id`
- INDEX `mydash_placement_dashboard` on `dashboard_id`
- INDEX `mydash_placement_widget` on `widget_id`

**Cascade behavior:** Deleting a dashboard cascades placement deletion at the application layer via `WidgetPlacementMapper::deleteByDashboardId()`, called from `DashboardService`. Conditional rules are cascade-deleted at the DB level via the `ConditionalRule` mapper when a placement is deleted.

**Migration history:**
- `Version001000` — creates table with core columns (id through updated_at)
- `Version001003` — adds tile_type through tile_link_value columns
- `Version001004` — adds custom_icon column

---

## Widget Discovery via Nextcloud IManager

`OCP\Dashboard\IManager` is injected into `WidgetService` via Nextcloud's DI container. `IManager::getWidgets()` returns an associative array keyed by widget ID, where each value is an `IWidget` instance.

`WidgetFormatter` inspects each widget against a set of optional capability interfaces:

| Interface            | Added Field         | Meaning |
|----------------------|---------------------|---------|
| `IWidget` (base)     | id, title, order, iconClass, widgetUrl | Always present |
| `IIconWidget`        | iconUrl             | Widget provides a URL to its icon asset |
| `IAPIWidget`         | itemApiVersions: [1] | Widget has v1 `getItems()` method |
| `IAPIWidgetV2`       | itemApiVersions: [2] | Widget has v2 `getItemsV2()` method |
| `IButtonWidget`      | buttons[]           | Widget exposes action buttons |
| `IOptionWidget`      | itemIconsRound      | Widget requests round item icon rendering |
| `IReloadableWidget`  | reloadInterval      | Widget requests periodic refresh (seconds) |

Both v1 and v2 can coexist. `WidgetItemLoader` prefers v2 when both are present.

---

## Key Implementation Decisions

### Single table for widgets and tiles

Rather than a separate `mydash_tile_placements` table, custom tiles are stored as rows in `mydash_widget_placements` with `tile_type = 'custom'` and a synthetic `widget_id` value of the form `tile-<uniqid>`. This simplifies the grid model: `DashboardGrid` operates on a single `placements` array regardless of type, and differentiates by checking `placement.tileType === 'custom'` (PHP) or `placement.widgetId.startsWith('tile-')` (JS).

### Visibility stored as integer, not enum

The spec defines three visibility states (`visible`, `hidden`, `conditional`), but the database column `is_visible` is a SMALLINT 0/1. Full conditional visibility evaluation is handled separately via `ConditionalRule` records and `VisibilityChecker`/`RuleEvaluatorService`. The `is_visible` column represents the simple show/hide toggle; conditional logic is layered on top.

### Optimistic UI for grid drag

`DashboardGrid` emits updated placement coordinates to the parent on every GridStack `change` event. `useDashboardStore::updatePlacements()` applies them immediately to `widgetPlacements` (optimistic) and then persists via `PUT /api/dashboard/{id}` using `WidgetPlacementMapper::updatePositions()`. This keeps the grid responsive during drag operations without waiting for a server round-trip.

### Legacy widget support via WidgetBridge

Nextcloud apps that only implement the legacy callback pattern (`window.OCA.Dashboard.register`) are supported by `widgetBridge.js`, which intercepts those calls at boot time and stores the callbacks. When `WidgetRenderer` detects a placement whose widget has no `itemApiVersions` (not `IAPIWidget`/`IAPIWidgetV2`), it falls back to `mountLegacyWidget()`, which retrieves the stored callback and calls it with the DOM container element. Retry logic with exponential backoff (up to 20 attempts, max 1 second delay) handles late-loading widget scripts.

### Style config is a full-replacement JSON blob

`PlacementUpdater::applyDisplayUpdates()` calls `setStyleConfigArray($data['styleConfig'])` which JSON-encodes the entire incoming array, replacing the previous value. There is intentionally no merge — the frontend `WidgetStyleEditor` always sends the complete style object.

### Sort order auto-assignment

`WidgetPlacementMapper::getMaxSortOrder(dashboardId)` queries `MAX(sort_order)` for the dashboard. The service layer (currently `PlacementService::addWidget`) does not yet auto-call this to set sort_order on creation; it defaults to 0. The batch update path (`updatePositions`) does not include sort_order updates either — sort_order management is a pending enhancement.

### Permission levels govern all placement operations

`PermissionService` resolves the effective permission level by checking whether the dashboard was created from an admin template and, if so, inheriting the template's `permission_level`. Three levels exist on the `Dashboard` entity:
- `full` — create, move, style, remove anything
- `add_only` — can add and style, cannot remove compulsory widgets
- `view_only` — read-only, no modifications

`canRemoveWidget` is the most nuanced check: it allows removal only if the user has full permission OR the widget is not compulsory.
