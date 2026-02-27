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
- **id**: Auto-increment integer primary key
- **dashboard_id**: Foreign key to oc_mydash_dashboards
- **widget_id**: Reference to the Nextcloud widget id (or null for tiles)
- **x**: Grid column position (0-based)
- **y**: Grid row position (0-based)
- **width**: Number of grid columns the widget spans
- **height**: Number of grid rows the widget spans
- **custom_title**: Optional override for the widget's default title
- **show_title**: Boolean, whether to display the title bar
- **visibility**: One of `visible`, `hidden`, `conditional`
- **style_config**: JSON blob for custom styling (background color, border radius, etc.)
- **sort_order**: Integer for ordering within the dashboard
- **is_compulsory**: Boolean, whether the widget can be removed (set by admin templates)

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
- GIVEN user "alice" has dashboard id 5 with grid_columns 12
- WHEN she sends POST /api/dashboard/5/widgets with body:
  ```json
  {"widget_id": "weather_status", "x": 0, "y": 0, "width": 4, "height": 2}
  ```
- THEN the system MUST create a widget placement with the specified coordinates
- AND `custom_title` MUST default to null (use widget's own title)
- AND `show_title` MUST default to true
- AND `visibility` MUST default to "visible"
- AND `is_compulsory` MUST default to false
- AND `sort_order` MUST be auto-assigned as the next sequential value
- AND the response MUST return HTTP 201 with the full placement object

#### Scenario: Add a widget with custom title and styling
- GIVEN user "alice" has dashboard id 5
- WHEN she sends POST /api/dashboard/5/widgets with body:
  ```json
  {
    "widget_id": "recommendations",
    "x": 4, "y": 0, "width": 4, "height": 3,
    "custom_title": "My Picks",
    "show_title": true,
    "style_config": {"background_color": "#f0f0f0", "border_radius": "8px"}
  }
  ```
- THEN the system MUST create the placement with the custom title and style_config
- AND `style_config` MUST be stored as a JSON blob

#### Scenario: Add widget to another user's dashboard
- GIVEN user "alice" has dashboard id 5
- WHEN user "bob" sends POST /api/dashboard/5/widgets
- THEN the system MUST return HTTP 403

#### Scenario: Add widget with invalid coordinates
- GIVEN dashboard id 5 has grid_columns 12
- WHEN the user sends POST /api/dashboard/5/widgets with `x: 10, width: 4` (exceeds column count)
- THEN the system MUST return HTTP 400 with a validation error indicating the placement exceeds the grid bounds
- AND `x + width` MUST NOT exceed `grid_columns`

#### Scenario: Add widget with non-existent widget_id
- GIVEN widget "fake_widget" is not registered in Nextcloud
- WHEN the user sends POST /api/dashboard/5/widgets with `widget_id: "fake_widget"`
- THEN the system MUST return HTTP 400 with an error indicating the widget was not found
- OR the system MAY allow it (for forward compatibility if apps are temporarily disabled)

### REQ-WDG-004: Update Widget Placement

Users MUST be able to update a widget placement's position, size, title, visibility, and styling.

#### Scenario: Update widget position and size
- GIVEN widget placement id 10 on alice's dashboard at position (0, 0) with size 4x2
- WHEN she sends PUT /api/widgets/10 with body `{"x": 4, "y": 2, "width": 6, "height": 3}`
- THEN the system MUST update the placement coordinates and size
- AND return HTTP 200 with the updated placement object

#### Scenario: Update custom title
- GIVEN widget placement id 10 with custom_title null
- WHEN the user sends PUT /api/widgets/10 with body `{"custom_title": "Weather Today"}`
- THEN the system MUST update the custom_title
- AND the widget MUST display "Weather Today" instead of the default widget title

#### Scenario: Toggle title visibility
- GIVEN widget placement id 10 with show_title true
- WHEN the user sends PUT /api/widgets/10 with body `{"show_title": false}`
- THEN the system MUST update show_title to false
- AND the widget MUST render without a title bar

#### Scenario: Update style configuration
- GIVEN widget placement id 10 with empty style_config
- WHEN the user sends PUT /api/widgets/10 with body:
  ```json
  {"style_config": {"background_color": "#ffffff", "border_radius": "12px", "shadow": "none"}}
  ```
- THEN the system MUST replace the entire style_config with the new JSON
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
- GIVEN widget placement id 10 with `is_compulsory: true` on a dashboard with `permission_level: full`
- WHEN the user sends DELETE /api/widgets/10
- THEN the system MUST allow the deletion
- AND the placement MUST be removed

#### Scenario: Remove a compulsory widget without full permission
- GIVEN widget placement id 10 with `is_compulsory: true` on a dashboard with `permission_level: add_only`
- WHEN the user sends DELETE /api/widgets/10
- THEN the system MUST return HTTP 403 with a message indicating compulsory widgets cannot be removed
- AND the placement MUST NOT be deleted

#### Scenario: Remove another user's widget placement
- GIVEN widget placement id 10 belongs to alice's dashboard
- WHEN user "bob" sends DELETE /api/widgets/10
- THEN the system MUST return HTTP 403

### REQ-WDG-006: Widget Placement Visibility

Widget placements MUST support three visibility states that control rendering behavior.

#### Scenario: Visible widget always renders
- GIVEN widget placement id 10 with `visibility: "visible"`
- WHEN the dashboard is rendered
- THEN the widget MUST always be displayed regardless of any conditional rules

#### Scenario: Hidden widget never renders
- GIVEN widget placement id 10 with `visibility: "hidden"`
- WHEN the dashboard is rendered
- THEN the widget MUST NOT be displayed
- AND the grid cell MUST remain empty (no placeholder)

#### Scenario: Conditional widget evaluated at render time
- GIVEN widget placement id 10 with `visibility: "conditional"`
- AND the placement has associated conditional rules
- WHEN the dashboard is rendered
- THEN the system MUST evaluate all conditional rules for this placement
- AND the widget MUST be displayed only if the rules evaluate to show

### REQ-WDG-007: Widget Sort Order

Widget placements MUST maintain a sort order for consistent rendering and tab navigation.

#### Scenario: Auto-assign sort order on creation
- GIVEN dashboard id 5 has 3 existing placements with sort_order 1, 2, 3
- WHEN a new widget is added to the dashboard
- THEN the new placement MUST receive sort_order 4

#### Scenario: Reorder widgets
- GIVEN dashboard id 5 has placements with sort_order 1 (weather), 2 (notes), 3 (calendar)
- WHEN the user rearranges them so calendar is first
- THEN sort_order MUST be updated to: calendar (1), weather (2), notes (3)

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
