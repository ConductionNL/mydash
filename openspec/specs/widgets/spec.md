---
status: implemented
---

# Widgets Specification

## Purpose

Widgets are the primary content blocks on MyDash dashboards. MyDash integrates with the Nextcloud Dashboard Widget API (v1 and v2) via `OCP\Dashboard\IManager::getWidgets()` to discover all registered dashboard widgets across installed Nextcloud apps. Users can add these discovered widgets to their dashboards as "placements" -- records that track the widget's position on the grid, display configuration, and custom styling. Widget placements bridge the Nextcloud widget ecosystem with the MyDash grid layout system.

## Data Model

### Widget Discovery
Widgets are discovered at runtime from Nextcloud's `IManager::getWidgets()`. Each widget provides:
- **id**: Widget identifier (e.g., `weather_status`, `recommendations`)
- **title**: Display name
- **icon_url**: Widget icon
- **url**: Optional widget URL
- **v2 support**: Whether it supports the v2 API with item loading

### Widget Placements (oc_mydash_widget_placements)
- **id**: Auto-increment integer primary key (BIGINT)
- **dashboardId**: Foreign key to oc_mydash_dashboards (BIGINT)
- **widgetId**: Reference to the Nextcloud widget id (STRING, NOT NULL; for tiles set to `'tile-' + uniqid()`)
- **gridX**: Grid column position, 0-based (INTEGER, default 0)
- **gridY**: Grid row position, 0-based (INTEGER, default 0)
- **gridWidth**: Number of grid columns the widget spans (INTEGER, default 4)
- **gridHeight**: Number of grid rows the widget spans (INTEGER, default 4)
- **customTitle**: Optional override for the widget's default title (STRING, nullable)
- **customIcon**: Optional custom icon override (TEXT, nullable)
- **showTitle**: SMALLINT (0/1), whether to display the title bar (default 1)
- **isVisible**: SMALLINT (0/1), whether the widget is visible (default 1). Conditional visibility is handled by evaluating ConditionalRule records at render time.
- **styleConfig**: JSON blob for custom styling (TEXT, nullable)
- **sortOrder**: Integer for ordering within the dashboard (default 0)
- **isCompulsory**: SMALLINT (0/1), whether the widget can be removed (default 0, set by admin templates)
- **tileType**: Nullable STRING -- set to `'custom'` for tile placements, null for regular widgets
- **tileTitle**, **tileIcon**, **tileIconType**, **tileBackgroundColor**, **tileTextColor**, **tileLinkType**, **tileLinkValue**: Tile-specific fields stored directly on the placement (nullable STRING)
- **createdAt**: Timestamp string (DATETIME)
- **updatedAt**: Timestamp string (DATETIME)

## Requirements

### REQ-WDG-001: Discover Available Widgets

The system MUST provide an API to list all Nextcloud dashboard widgets available for placement.

#### Scenario: List all available widgets
- GIVEN Nextcloud has the following dashboard widgets registered: weather_status, recommendations, user_status, notes
- WHEN the user sends GET /api/widgets
- THEN the system MUST return HTTP 200 with an array of all 4 widgets
- AND each widget object MUST include at minimum: id, title, iconUrl
- AND the list MUST include widgets from all installed and enabled Nextcloud apps

#### Scenario: Widget list includes v1 and v2 widgets
- GIVEN widget "weather_status" implements `IAPIWidgetV2` and "notes" implements only `IAPIWidget`
- WHEN the user sends GET /api/widgets
- THEN both widgets MUST appear in the response
- AND each widget SHOULD indicate its API version capability

#### Scenario: Widget list updates when apps are installed
- GIVEN the "calendar" app is installed and registers a dashboard widget
- WHEN the user sends GET /api/widgets
- THEN the "calendar" widget MUST appear in the response
- AND previously listed widgets MUST still be present

#### Scenario: Widget formatting via WidgetFormatter
- GIVEN a raw widget object from `IManager::getWidgets()`
- WHEN `WidgetFormatter::format()` processes it
- THEN the output MUST include standardized fields for the frontend
- AND widgets MUST be sorted by their order property

### REQ-WDG-002: Fetch Widget Items

The system MUST provide an API to fetch the content items for widgets that support item loading via the Nextcloud Widget API.

#### Scenario: Fetch items for a v2 widget
- GIVEN widget "recommendations" supports `IAPIWidgetV2` item loading
- WHEN the user sends GET /api/widgets/items with widget IDs
- THEN the system MUST return the items for each requested widget via `WidgetItemLoader::loadItems()`
- AND items MUST be structured according to Nextcloud's widget item format (title, subtitle, link, iconUrl)

#### Scenario: Fetch items for a v1 widget
- GIVEN widget "notes" only supports `IAPIWidget` (v1)
- WHEN the user sends GET /api/widgets/items requesting "notes"
- THEN the system MUST return items using the v1 callback mechanism
- OR indicate that this widget does not support item loading

#### Scenario: Fetch items for unknown widget
- GIVEN widget ID "nonexistent_widget" is not registered
- WHEN the user sends GET /api/widgets/items with that widget ID
- THEN the system MUST return an empty result or skip that widget
- AND the response MUST NOT cause an error for other valid widget IDs in the same request

#### Scenario: Widget items endpoint requires no CSRF
- GIVEN a dashboard rendering request
- WHEN widget items are fetched
- THEN the endpoint MUST have `#[NoCSRFRequired]` to support async loading from the frontend

### REQ-WDG-003: Add Widget to Dashboard

Users MUST be able to place a discovered widget onto their dashboard with grid coordinates.

#### Scenario: Add a widget to a dashboard
- GIVEN user "alice" has dashboard id 5 with gridColumns 12
- WHEN she sends POST /api/dashboard/5/widgets with body:
  ```json
  {"widgetId": "weather_status", "gridX": 0, "gridY": 0, "gridWidth": 4, "gridHeight": 4}
  ```
- THEN the system MUST create a widget placement with the specified coordinates
- AND `customTitle` MUST default to null (use widget's own title)
- AND `showTitle` MUST default to 1 (true)
- AND `isVisible` MUST default to 1 (true)
- AND `isCompulsory` MUST default to 0 (false)
- AND `sortOrder` MUST default to 0
- AND the response MUST return HTTP 201 with the full placement object
- NOTE: Default `gridWidth` and `gridHeight` are both 4 in the code

#### Scenario: Add a widget with custom title and styling
- GIVEN user "alice" has dashboard id 5
- WHEN she wants to add a widget with a custom title and style
- THEN she MUST first add the widget via POST /api/dashboard/5/widgets (with position only)
- AND then send PUT /api/widgets/{placementId} with `customTitle` and `styleConfig`
- NOTE: The `addWidget` controller method only accepts `widgetId`, `gridX`, `gridY`, `gridWidth`, `gridHeight`. Custom title and style config require a subsequent PUT call.

#### Scenario: Add widget to another user's dashboard
- GIVEN user "alice" has dashboard id 5
- WHEN user "bob" sends POST /api/dashboard/5/widgets
- THEN the system MUST return HTTP 403 (via `canAddWidget()` ownership check)

#### Scenario: Add widget with invalid coordinates
- GIVEN dashboard id 5 has gridColumns 12
- WHEN the user sends POST /api/dashboard/5/widgets with `gridX: 10, gridWidth: 4` (exceeds column count)
- THEN the system SHOULD return HTTP 400 with a validation error
- NOTE: Grid bounds validation is NOT currently implemented in the backend. GridStack on the frontend handles constraint enforcement.

#### Scenario: Add widget with non-existent widgetId
- GIVEN widget "fake_widget" is not registered in Nextcloud
- WHEN the user sends POST /api/dashboard/5/widgets with `widgetId: "fake_widget"`
- THEN the system MUST accept the request (for forward compatibility if apps are temporarily disabled)
- NOTE: Widget ID validation against registered widgets is NOT currently implemented.

### REQ-WDG-004: Update Widget Placement

Users MUST be able to update a widget placement's position, size, title, visibility, and styling via `PlacementUpdater`.

#### Scenario: Update widget position and size
- GIVEN widget placement id 10 on alice's dashboard at position (0, 0) with size 4x4
- WHEN she sends PUT /api/widgets/10 with body `{"gridX": 4, "gridY": 2, "gridWidth": 6, "gridHeight": 3}`
- THEN the system MUST update the placement coordinates and size via `PlacementUpdater::applyGridUpdates()`
- AND return HTTP 200 with the updated placement object

#### Scenario: Update custom title
- GIVEN widget placement id 10 with customTitle null
- WHEN the user sends PUT /api/widgets/10 with body `{"customTitle": "Weather Today"}`
- THEN the system MUST update the customTitle via `PlacementUpdater::applyDisplayUpdates()`
- AND the widget MUST display "Weather Today" instead of the default widget title

#### Scenario: Toggle title visibility
- GIVEN widget placement id 10 with showTitle 1 (true)
- WHEN the user sends PUT /api/widgets/10 with body `{"showTitle": 0}`
- THEN the system MUST update showTitle to 0 (false)
- AND the widget MUST render without a title bar (controlled by `showHeader` computed property in `WidgetWrapper.vue`)

#### Scenario: Update style configuration
- GIVEN widget placement id 10 with empty styleConfig
- WHEN the user sends PUT /api/widgets/10 with body:
  ```json
  {"styleConfig": {"backgroundColor": "#ffffff", "borderRadius": "12", "borderStyle": "solid", "borderColor": "#cccccc", "borderWidth": 1}}
  ```
- THEN the system MUST replace the entire styleConfig with the new JSON (full replacement, not merge)

#### Scenario: Update placement on another user's dashboard
- GIVEN widget placement id 10 belongs to alice's dashboard
- WHEN user "bob" sends PUT /api/widgets/10
- THEN the system MUST return HTTP 403 (via `canStyleWidget()` ownership check)

#### Scenario: Update tile-specific fields
- GIVEN widget placement id 10 is a tile placement (`tileType: "custom"`)
- WHEN the user sends PUT /api/widgets/10 with tile fields (tileTitle, tileIcon, etc.)
- THEN `TileUpdater::applyTileUpdates()` MUST update the tile-specific fields
- AND both grid and tile updates can be applied in a single request

### REQ-WDG-005: Remove Widget from Dashboard

Users MUST be able to remove widget placements from their dashboards, subject to permission level and compulsory widget checks.

#### Scenario: Remove a widget placement
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends DELETE /api/widgets/10
- THEN the system MUST delete the placement record via `PlacementService::removePlacement()`
- AND the response MUST return HTTP 200

#### Scenario: Remove a compulsory widget with full permission
- GIVEN widget placement id 10 with `isCompulsory: 1` on a dashboard with `permissionLevel: full`
- WHEN the user sends DELETE /api/widgets/10
- THEN the system MUST allow the deletion
- AND `canRemoveWidget()` MUST return true for full permission regardless of compulsory status

#### Scenario: Remove a compulsory widget without full permission
- GIVEN widget placement id 10 with `isCompulsory: 1` on a dashboard with `permissionLevel: add_only`
- WHEN the user sends DELETE /api/widgets/10
- THEN the system MUST return HTTP 403 with a message indicating compulsory widgets cannot be removed
- AND `canRemoveWidget()` MUST check `placement.getIsCompulsory()` for add_only

#### Scenario: Remove another user's widget placement
- GIVEN widget placement id 10 belongs to alice's dashboard
- WHEN user "bob" sends DELETE /api/widgets/10
- THEN the system MUST return HTTP 403

#### Scenario: Remove widget cascade deletes conditional rules
- GIVEN widget placement id 10 has 3 conditional rules
- WHEN the placement is deleted
- THEN all 3 conditional rules MUST also be deleted
- NOTE: `PlacementService::removePlacement()` does NOT explicitly cascade-delete conditional rules. This depends on database-level cascade constraints.

### REQ-WDG-006: Widget Placement Visibility

The system MUST support widget placement visibility via an `isVisible` SMALLINT (0/1) flag plus optional ConditionalRule records to control rendering.

#### Scenario: Visible widget always renders
- GIVEN widget placement id 10 with `isVisible: 1` and no conditional rules
- WHEN the dashboard is rendered
- THEN the widget MUST always be displayed

#### Scenario: Hidden widget never renders
- GIVEN widget placement id 10 with `isVisible: 0`
- WHEN the dashboard is rendered
- THEN the widget MUST NOT be displayed
- AND the grid cell MUST remain empty (no placeholder)

#### Scenario: Conditional widget evaluated at render time
- GIVEN widget placement id 10 with `isVisible: 1` and associated conditional rules exist
- WHEN the dashboard is rendered
- THEN `ConditionalService::isWidgetVisible()` MUST evaluate all conditional rules for this placement
- AND the widget MUST be displayed only if rules evaluate to show

#### Scenario: Visibility toggle via API
- GIVEN widget placement id 10 with `isVisible: 1`
- WHEN the user sends PUT /api/widgets/10 with body `{"isVisible": 0}`
- THEN the system MUST update `isVisible` to 0
- AND the widget MUST be hidden on next render regardless of conditional rules

### REQ-WDG-007: Widget Sort Order

Widget placements MUST maintain a sort order for consistent rendering and tab navigation.

#### Scenario: Auto-assign sort order on creation
- GIVEN dashboard id 5 has 3 existing placements with sortOrder 1, 2, 3
- WHEN a new widget is added to the dashboard
- THEN the new placement receives `sortOrder: 0` (default)
- NOTE: Auto-incrementing sort order is NOT currently implemented.

#### Scenario: Reorder widgets
- GIVEN dashboard id 5 has placements with sortOrder 1 (weather), 2 (notes), 3 (calendar)
- WHEN the user rearranges them so calendar is first
- THEN sortOrder MUST be updated to: calendar (1), weather (2), notes (3)

#### Scenario: Sort order used for tab navigation
- GIVEN a dashboard with multiple placements ordered by sortOrder
- WHEN the user presses Tab in view mode
- THEN focus MUST move through widgets in sortOrder sequence

### REQ-WDG-008: Batch Update Placements

The system MUST support updating multiple widget placements via the dashboard update endpoint for efficient grid saves.

#### Scenario: Batch update after grid rearrangement
- GIVEN dashboard id 5 has 4 widget placements
- AND the user drags widgets to new positions via GridStack
- WHEN the frontend sends PUT /api/dashboard/5 with a `placements` array containing updated positions
- THEN `applyDashboardUpdates()` MUST call `placementMapper->updatePositions()` with the updates array
- AND all 4 placements MUST be updated

#### Scenario: Batch update with mixed placement types
- GIVEN dashboard id 5 has 3 widget placements and 2 tile placements
- WHEN positions are updated via the batch endpoint
- THEN all 5 placements (widgets and tiles) MUST be updated correctly
- AND tile-specific data MUST NOT be affected by position updates

#### Scenario: Batch update via dashboard update endpoint
- GIVEN the user rearranges the grid
- WHEN DashboardGrid emits `update:placements` with updated positions
- THEN the parent component MUST send PUT /api/dashboard/{id} with `{"placements": [{"id": 10, "gridX": 0, "gridY": 0, "gridWidth": 4, "gridHeight": 4}, ...]}`

### REQ-WDG-009: Widget Rendering Architecture

The frontend MUST use a layered rendering architecture: `DashboardGrid` -> `WidgetWrapper` -> `WidgetRenderer`.

#### Scenario: WidgetWrapper renders header and chrome
- GIVEN a widget placement with `showTitle: 1` and a matching widget object
- WHEN the widget is rendered
- THEN `WidgetWrapper.vue` MUST display:
  - A header with widget icon (from `widget.iconUrl`) and title (from `customTitle` or `widget.title`)
  - An actions area with edit button (only in edit mode)
  - A content area rendered by `WidgetRenderer`
  - An optional footer with widget buttons (from `widget.buttons`)

#### Scenario: WidgetWrapper applies style configuration
- GIVEN a placement with `styleConfig: {"backgroundColor": "#f0f0f0", "borderStyle": "solid", "borderWidth": 1, "borderColor": "#ccc", "borderRadius": 12}`
- WHEN the widget is rendered
- THEN `widgetStyles` computed property MUST generate inline CSS from the styleConfig
- AND `headerStyles` MUST apply `headerStyle.backgroundColor` and `headerStyle.textColor` if present

#### Scenario: WidgetWrapper handles missing widget
- GIVEN a placement with `widgetId: "uninstalled_widget"` and no matching widget in the available widgets array
- WHEN the widget is rendered
- THEN `WidgetWrapper` MUST receive `widget: null` (from `getWidget()` returning undefined)
- AND the title MUST fall back to the `t('mydash', 'Widget')` translation
- AND the widget content area MUST handle the null widget gracefully

#### Scenario: Tile placement bypasses WidgetWrapper
- GIVEN a placement with `tileType: "custom"`
- WHEN the grid renders
- THEN `DashboardGrid.vue` MUST use `isTilePlacement()` to detect the tile
- AND render `TileWidget` directly instead of `WidgetWrapper`
- AND WidgetWrapper applies transparent background and no padding for tile-type widgetIds

### REQ-WDG-010: Widget Picker

Users MUST be able to browse and select widgets to add to their dashboard.

#### Scenario: Widget picker displays available widgets
- GIVEN the user wants to add a widget
- WHEN the widget picker opens via `WidgetPicker.vue`
- THEN all available Nextcloud widgets MUST be listed
- AND each widget MUST show its icon and title

#### Scenario: Widget picker filters installed widgets
- GIVEN 10 Nextcloud widgets are registered
- WHEN the widget picker opens
- THEN all 10 widgets MUST be shown
- AND widgets already on the dashboard SHOULD still be available (duplicates allowed)

#### Scenario: Widget selection creates placement
- GIVEN the user selects "weather_status" from the picker
- WHEN the selection is confirmed
- THEN POST /api/dashboard/{id}/widgets MUST be sent with the selected widgetId
- AND GridStack MUST auto-place the new widget at the next available position

### REQ-WDG-011: Widget Style Editor

Users MUST be able to customize widget appearance through a style editor.

#### Scenario: Style editor opens for a widget
- GIVEN a widget placement in edit mode
- WHEN the user clicks the style/edit button on the widget
- THEN `WidgetStyleEditor.vue` MUST open
- AND current style configuration MUST be pre-populated

#### Scenario: Style editor supports background and border options
- GIVEN the style editor is open
- WHEN the user configures styling
- THEN they MUST be able to set:
  - Background color
  - Border style (none, solid, dashed, dotted)
  - Border width and color
  - Border radius
  - Padding (top, right, bottom, left)
  - Header background color and text color

#### Scenario: Style changes saved via API
- GIVEN the user changes the background color to "#f0f0f0"
- WHEN they save
- THEN PUT /api/widgets/{placementId} MUST be sent with updated `styleConfig`
- AND the widget MUST immediately reflect the new style

## Non-Functional Requirements

- **Performance**: GET /api/widgets MUST return within 1 second even with 50+ registered widgets. Widget item fetching SHOULD be parallelized across widget types.
- **Compatibility**: The system MUST support both Nextcloud Dashboard Widget API v1 (`IAPIWidget`) and v2 (`IAPIWidgetV2`) without requiring widget developers to make changes.
- **Data integrity**: Deleting a dashboard MUST cascade-delete all its widget placements. Deleting a widget placement MUST cascade-delete its conditional rules.
- **Accessibility**: Widget placements MUST be navigable via keyboard in the grid. Each widget MUST have an accessible label derived from customTitle or the widget's default title.
- **Localization**: Widget titles from Nextcloud are pre-localized. Custom titles and error messages MUST support English and Dutch.

### Current Implementation Status

**Fully implemented:**
- REQ-WDG-001 (Discover Available Widgets): `WidgetService::getAvailableWidgets()` calls `IManager::getWidgets()`, formats via `WidgetFormatter::format()`, sorts by order.
- REQ-WDG-002 (Fetch Widget Items): `WidgetService::getWidgetItems()` via `WidgetItemLoader::loadItems()`. Supports v1 and v2 APIs.
- REQ-WDG-003 (Add Widget to Dashboard): `PlacementService::addWidget()` with defaults: gridWidth/gridHeight 4, isCompulsory 0, isVisible 1, showTitle 1.
- REQ-WDG-004 (Update Widget Placement): `PlacementService::updatePlacement()` via `PlacementUpdater::applyGridUpdates()`, `applyDisplayUpdates()`, and `TileUpdater::applyTileUpdates()`.
- REQ-WDG-005 (Remove Widget from Dashboard): `PlacementService::removePlacement()` with permission check via `canRemoveWidget()`.
- REQ-WDG-006 (Widget Placement Visibility): `isVisible` flag + `ConditionalService::isWidgetVisible()`.
- REQ-WDG-007 (Widget Sort Order): `sortOrder` field exists with default 0.
- REQ-WDG-008 (Batch Update): Via `DashboardService::applyDashboardUpdates()` with `placements` array.
- REQ-WDG-009 (Rendering Architecture): `DashboardGrid.vue` -> `WidgetWrapper.vue` -> `WidgetRenderer.vue` chain. `TileWidget.vue` for tile placements.
- REQ-WDG-010 (Widget Picker): `WidgetPicker.vue` component exists.
- REQ-WDG-011 (Widget Style Editor): `WidgetStyleEditor.vue` component exists.

**Not yet implemented:**
- REQ-WDG-003 grid bounds validation: No server-side validation for gridX + gridWidth <= gridColumns.
- REQ-WDG-003 widgetId validation: No check against registered Nextcloud widgets.
- REQ-WDG-003 custom title/styleConfig on creation: Only position params in addWidget; custom fields require subsequent PUT.
- REQ-WDG-005 cascade-delete conditional rules: Not explicitly handled by `removePlacement()`.
- REQ-WDG-007 auto-assign sort order: New placements always get sortOrder 0.
- REQ-WDG-008 transactional rollback: No explicit transaction on batch position updates.

### Standards & References
- Nextcloud Dashboard Widget API: `OCP\Dashboard\IManager::getWidgets()`, `OCP\Dashboard\IWidget`, `OCP\Dashboard\IAPIWidget` (v1), `OCP\Dashboard\IAPIWidgetV2` (v2)
- Nextcloud Widget Item format: title, subtitle, link, iconUrl (from `IWidgetItem`)
- WCAG 2.1 AA: Widget labels via customTitle or default widget title for screen readers
- WAI-ARIA: Widget placements should have appropriate landmark roles for keyboard navigation
