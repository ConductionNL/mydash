---
status: reviewed
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
- **isVisible**: SMALLINT (0/1), whether the widget is visible (default 1). Conditional visibility is handled by evaluating ConditionalRule records at render time rather than via a string enum.
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
- AND each widget object MUST include: id, title, icon_url
- AND the list MUST include widgets from all installed and enabled Nextcloud apps

#### Scenario: Widget list includes v1 and v2 widgets
- GIVEN widget "weather_status" implements v2 API and "notes" implements only v1
- WHEN the user sends GET /api/widgets
- THEN both widgets MUST appear in the response
- AND each widget SHOULD indicate its API version capability

#### Scenario: Widget list updates when apps are installed
- GIVEN the "calendar" app is installed and registers a dashboard widget
- WHEN the user sends GET /api/widgets
- THEN the "calendar" widget MUST appear in the response
- AND previously listed widgets MUST still be present

### REQ-WDG-002: Fetch Widget Items

The system MUST provide an API to fetch the content items for widgets that support item loading.

#### Scenario: Fetch items for a v2 widget
- GIVEN widget "recommendations" supports v2 item loading
- WHEN the user sends GET /api/widgets/items with widget IDs
- THEN the system MUST return the items for each requested widget
- AND items MUST be structured according to Nextcloud's widget item format (title, subtitle, link, icon)

#### Scenario: Fetch items for a v1 widget
- GIVEN widget "notes" only supports v1 API
- WHEN the user sends GET /api/widgets/items requesting "notes"
- THEN the system MUST return items using the v1 callback mechanism
- OR the system MUST indicate that this widget does not support item loading

#### Scenario: Fetch items for unknown widget
- GIVEN widget ID "nonexistent_widget" is not registered
- WHEN the user sends GET /api/widgets/items with that widget ID
- THEN the system MUST return an empty result or skip that widget
- AND the response MUST NOT cause an error for other valid widget IDs in the same request

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
- AND `sortOrder` MUST default to 0 (auto-assignment of sequential values is not currently implemented)
- AND the response MUST return HTTP 201 with the full placement object
- NOTE: Default `gridWidth` and `gridHeight` are both 4 in the code, not 2

#### Scenario: Add a widget with custom title and styling
- GIVEN user "alice" has dashboard id 5
- WHEN she sends POST /api/dashboard/5/widgets with body:
  ```json
  {
    "widgetId": "recommendations",
    "gridX": 4, "gridY": 0, "gridWidth": 4, "gridHeight": 3,
    "customTitle": "My Picks",
    "showTitle": 1,
    "styleConfig": {"background_color": "#f0f0f0", "border_radius": "8px"}
  }
  ```
- THEN the system MUST create the placement with the custom title and styleConfig
- AND `styleConfig` MUST be stored as a JSON blob
- NOTE: The `addWidget` controller method only accepts `widgetId`, `gridX`, `gridY`, `gridWidth`, `gridHeight` parameters. Custom title and style config must be set via a subsequent PUT /api/widgets/{placementId} call.

#### Scenario: Add widget to another user's dashboard
- GIVEN user "alice" has dashboard id 5
- WHEN user "bob" sends POST /api/dashboard/5/widgets
- THEN the system MUST return HTTP 403

#### Scenario: Add widget with invalid coordinates
- GIVEN dashboard id 5 has gridColumns 12
- WHEN the user sends POST /api/dashboard/5/widgets with `gridX: 10, gridWidth: 4` (exceeds column count)
- THEN the system SHOULD return HTTP 400 with a validation error indicating the placement exceeds the grid bounds
- AND `gridX + gridWidth` SHOULD NOT exceed `gridColumns`
- NOTE: Grid bounds validation is NOT currently implemented in the backend -- GridStack on the frontend handles constraint enforcement

#### Scenario: Add widget with non-existent widgetId
- GIVEN widget "fake_widget" is not registered in Nextcloud
- WHEN the user sends POST /api/dashboard/5/widgets with `widgetId: "fake_widget"`
- THEN the system MUST return HTTP 400 with an error indicating the widget was not found
- OR the system MAY allow it (for forward compatibility if apps are temporarily disabled)

### REQ-WDG-004: Update Widget Placement

Users MUST be able to update a widget placement's position, size, title, visibility, and styling.

#### Scenario: Update widget position and size
- GIVEN widget placement id 10 on alice's dashboard at position (0, 0) with size 4x4
- WHEN she sends PUT /api/widgets/10 with body `{"gridX": 4, "gridY": 2, "gridWidth": 6, "gridHeight": 3}`
- THEN the system MUST update the placement coordinates and size
- AND return HTTP 200 with the updated placement object

#### Scenario: Update custom title
- GIVEN widget placement id 10 with customTitle null
- WHEN the user sends PUT /api/widgets/10 with body `{"customTitle": "Weather Today"}`
- THEN the system MUST update the customTitle
- AND the widget MUST display "Weather Today" instead of the default widget title

#### Scenario: Toggle title visibility
- GIVEN widget placement id 10 with showTitle 1 (true)
- WHEN the user sends PUT /api/widgets/10 with body `{"showTitle": 0}`
- THEN the system MUST update showTitle to 0 (false)
- AND the widget MUST render without a title bar

#### Scenario: Update style configuration
- GIVEN widget placement id 10 with empty styleConfig
- WHEN the user sends PUT /api/widgets/10 with body:
  ```json
  {"styleConfig": {"background_color": "#ffffff", "border_radius": "12px", "shadow": "none"}}
  ```
- THEN the system MUST replace the entire styleConfig with the new JSON
- AND individual style properties from the previous config MUST NOT be merged (full replacement)

#### Scenario: Update placement on another user's dashboard
- GIVEN widget placement id 10 belongs to alice's dashboard
- WHEN user "bob" sends PUT /api/widgets/10
- THEN the system MUST return HTTP 403

### REQ-WDG-005: Remove Widget from Dashboard

Users MUST be able to remove widget placements from their dashboards.

#### Scenario: Remove a widget placement
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends DELETE /api/widgets/10
- THEN the system MUST delete the placement record
- AND all associated conditional rules for placement 10 MUST be cascade-deleted
- AND the response MUST return HTTP 200

#### Scenario: Remove a compulsory widget with full permission
- GIVEN widget placement id 10 with `isCompulsory: 1` on a dashboard with `permissionLevel: full`
- WHEN the user sends DELETE /api/widgets/10
- THEN the system MUST allow the deletion
- AND the placement MUST be removed

#### Scenario: Remove a compulsory widget without full permission
- GIVEN widget placement id 10 with `isCompulsory: 1` on a dashboard with `permissionLevel: add_only`
- WHEN the user sends DELETE /api/widgets/10
- THEN the system MUST return HTTP 403 with a message indicating compulsory widgets cannot be removed
- AND the placement MUST NOT be deleted

#### Scenario: Remove another user's widget placement
- GIVEN widget placement id 10 belongs to alice's dashboard
- WHEN user "bob" sends DELETE /api/widgets/10
- THEN the system MUST return HTTP 403

### REQ-WDG-006: Widget Placement Visibility

Widget placements use an `isVisible` SMALLINT (0/1) flag plus optional ConditionalRule records to control rendering.

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
- THEN the ConditionalService MUST evaluate all conditional rules for this placement via VisibilityChecker
- AND the widget MUST be displayed only if all rules evaluate to show
- NOTE: There is no separate `visibility: "conditional"` string state. The presence of ConditionalRule records on a placement triggers conditional evaluation.

### REQ-WDG-007: Widget Sort Order

Widget placements MUST maintain a sort order for consistent rendering and tab navigation.

#### Scenario: Auto-assign sort order on creation
- GIVEN dashboard id 5 has 3 existing placements with sortOrder 1, 2, 3
- WHEN a new widget is added to the dashboard
- THEN the new placement currently receives sortOrder 0 (default)
- NOTE: Auto-incrementing sort order is NOT currently implemented. The sortOrder field defaults to 0 for all new placements.

#### Scenario: Reorder widgets
- GIVEN dashboard id 5 has placements with sortOrder 1 (weather), 2 (notes), 3 (calendar)
- WHEN the user rearranges them so calendar is first
- THEN sortOrder MUST be updated to: calendar (1), weather (2), notes (3)

### REQ-WDG-008: Batch Update Placements

The system MUST support updating multiple widget placements in a single request for efficient grid saves.

#### Scenario: Batch update after grid rearrangement
- GIVEN dashboard id 5 has 4 widget placements
- AND the user drags widgets to new positions via GridStack
- WHEN the frontend sends a batch update with all 4 placement positions
- THEN the system MUST update all 4 placements in a single transaction
- AND the response MUST confirm all updates succeeded
- AND partial failures MUST roll back the entire batch

## Non-Functional Requirements

- **Performance**: GET /api/widgets MUST return within 1 second even with 50+ registered widgets. Widget item fetching SHOULD be parallelized across widget types.
- **Compatibility**: The system MUST support both Nextcloud Dashboard Widget API v1 and v2 without requiring widget developers to make changes.
- **Data integrity**: Deleting a dashboard MUST cascade-delete all its widget placements. Deleting a widget placement MUST cascade-delete its conditional rules.
- **Accessibility**: Widget placements MUST be navigable via keyboard in the grid. Each widget MUST have an accessible label derived from custom_title or the widget's default title.
- **Localization**: Widget titles from Nextcloud are pre-localized. Custom titles and error messages MUST support English and Dutch.
