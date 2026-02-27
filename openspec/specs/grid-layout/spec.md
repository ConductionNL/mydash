# Grid Layout Specification

## Purpose

The grid layout system powers the drag-and-drop dashboard experience in MyDash. Built on GridStack 10.3.1, it provides a 12-column responsive grid where users can position, resize, and rearrange widget placements and tiles. The grid operates in two modes: view mode (static, no interaction) and edit mode (drag-and-drop enabled). Position changes are debounced and persisted via the API to minimize server load during rapid rearrangements.

## Technical Foundation

- **Library**: GridStack 10.3.1
- **Grid columns**: 12 (configurable per dashboard via `grid_columns`)
- **Cell height**: 80px (fixed)
- **Margins**: 12px horizontal and vertical between cells
- **Coordinate system**: 0-based (x: 0-11 for 12 columns, y: 0+)

## Requirements

### REQ-GRID-001: Grid Initialization

The grid MUST initialize with the correct configuration when a dashboard is loaded.

#### Scenario: Initialize grid with default 12-column layout
- GIVEN user "alice" activates dashboard id 5 with `grid_columns: 12`
- WHEN the dashboard view loads
- THEN GridStack MUST be initialized with 12 columns
- AND cell height MUST be set to 80px
- AND margins MUST be set to 12px
- AND the grid MUST render all widget placements at their stored (x, y, width, height) coordinates

#### Scenario: Initialize grid with custom column count
- GIVEN dashboard id 5 has `grid_columns: 6`
- WHEN the dashboard view loads
- THEN GridStack MUST be initialized with 6 columns
- AND all widget placements MUST be constrained to the 6-column grid
- AND placements with `x + width > 6` MUST be automatically reflowed to fit

#### Scenario: Initialize grid with no widget placements
- GIVEN dashboard id 5 has no widget placements
- WHEN the dashboard view loads
- THEN GridStack MUST initialize an empty grid
- AND the empty grid MUST display a placeholder message or empty state (e.g., "Add widgets to get started")

#### Scenario: Grid renders placements in correct positions
- GIVEN dashboard id 5 has the following placements:
  | placement_id | widget_id      | x  | y  | width | height |
  |--------------|----------------|----|----|-------|--------|
  | 10           | weather_status | 0  | 0  | 4     | 2      |
  | 11           | notes          | 4  | 0  | 4     | 3      |
  | 12           | calendar       | 8  | 0  | 4     | 2      |
  | 13           | (tile)         | 0  | 2  | 2     | 2      |
- WHEN the dashboard view loads
- THEN each placement MUST be rendered at its exact grid coordinates
- AND no placements MUST overlap
- AND the grid height MUST expand to accommodate all placements

### REQ-GRID-002: Drag to Reposition

Users MUST be able to drag widgets to new positions on the grid in edit mode.

#### Scenario: Drag a widget to a new position
- GIVEN the dashboard is in edit mode
- AND widget "weather_status" is at position (0, 0)
- WHEN the user drags it to position (4, 2)
- THEN the widget MUST snap to the grid at position (4, 2)
- AND other widgets MUST NOT overlap with the repositioned widget
- AND GridStack MUST automatically push conflicting widgets down if needed

#### Scenario: Drag is disabled in view mode
- GIVEN the dashboard is in view mode
- WHEN the user attempts to drag a widget
- THEN the widget MUST NOT move
- AND the cursor MUST NOT change to a drag cursor
- AND no drag handles MUST be visible

#### Scenario: Drag respects grid boundaries
- GIVEN a widget with width 4 at position (0, 0)
- AND the grid has 12 columns
- WHEN the user drags it to position (10, 0)
- THEN the widget MUST NOT be placed at x=10 (since 10 + 4 = 14 > 12)
- AND GridStack MUST constrain the placement to x=8 (maximum valid position for width 4)

#### Scenario: Drag pushes other widgets
- GIVEN widget A at (0, 0) size 4x2 and widget B at (0, 2) size 4x2
- WHEN the user drags widget A to (0, 2) (overlapping with B)
- THEN GridStack MUST push widget B down to (0, 4) to make room
- AND no overlap MUST occur

### REQ-GRID-003: Resize by Edge Dragging

Users MUST be able to resize widgets by dragging their edges or corners in edit mode.

#### Scenario: Resize a widget horizontally
- GIVEN the dashboard is in edit mode
- AND widget "notes" is at position (4, 0) with size 4x3
- WHEN the user drags the right edge to increase width by 2 columns
- THEN the widget size MUST update to 6x3
- AND the widget MUST remain at position (4, 0)
- AND neighboring widgets MUST be pushed if they conflict

#### Scenario: Resize a widget vertically
- GIVEN widget "weather_status" at position (0, 0) with size 4x2
- WHEN the user drags the bottom edge to increase height by 1
- THEN the widget size MUST update to 4x3
- AND widgets below MUST be pushed down if they conflict

#### Scenario: Resize constrained by minimum size
- GIVEN a widget with size 4x3
- WHEN the user tries to resize it smaller than 1x1
- THEN the widget MUST NOT be smaller than the minimum size (1 column wide, 1 row tall)
- AND GridStack MUST enforce minimum dimensions

#### Scenario: Resize constrained by grid columns
- GIVEN a widget at position (8, 0) with size 4x2 on a 12-column grid
- WHEN the user tries to resize it to width 6
- THEN the widget width MUST be constrained to 4 (since 8 + 6 = 14 > 12)
- OR GridStack MUST reposition the widget to allow the resize (e.g., move to x=6)

#### Scenario: Resize handles not visible in view mode
- GIVEN the dashboard is in view mode
- WHEN the user hovers over a widget edge
- THEN no resize handles MUST be displayed
- AND the cursor MUST NOT change to a resize cursor

### REQ-GRID-004: View Mode vs Edit Mode

The grid MUST support two distinct interaction modes.

#### Scenario: Enter edit mode
- GIVEN the dashboard is in view mode
- WHEN the user clicks the "Edit" button
- THEN the grid MUST transition to edit mode
- AND drag handles MUST become visible on all widget placements
- AND resize handles MUST become active on widget edges
- AND the "Edit" button MUST change to a "Done" or "Save" label

#### Scenario: Exit edit mode
- GIVEN the dashboard is in edit mode
- AND the user has repositioned 2 widgets
- WHEN the user clicks the "Done" button
- THEN the grid MUST transition to view mode
- AND all drag and resize interactions MUST be disabled
- AND the final positions MUST be persisted via the API

#### Scenario: View mode is the default
- GIVEN the user navigates to their active dashboard
- WHEN the dashboard loads
- THEN the grid MUST be in view mode by default
- AND widgets MUST be static and non-interactive (from a grid perspective)

#### Scenario: View-only permission prevents edit mode
- GIVEN dashboard id 5 has `permission_level: "view_only"`
- WHEN the user views the dashboard
- THEN the "Edit" button MUST NOT be displayed
- AND the grid MUST always remain in view mode
- AND no drag or resize interactions MUST be possible

### REQ-GRID-005: Position Persistence

Grid position changes MUST be saved to the server with debouncing to reduce API calls.

#### Scenario: Debounced save after drag
- GIVEN the user drags a widget to a new position
- WHEN 500ms pass without further grid changes
- THEN the system MUST send a batch update API request with all changed placement positions
- AND the request MUST include the updated (x, y, width, height) for each changed placement

#### Scenario: Multiple rapid changes coalesced
- GIVEN the user rapidly repositions 3 widgets within 400ms
- WHEN the debounce timer expires (500ms after the last change)
- THEN the system MUST send a single batch update with all 3 placement changes
- AND NOT send 3 separate API requests

#### Scenario: Save failure with retry
- GIVEN the user repositions a widget
- AND the API request to save positions fails
- WHEN the failure is detected
- THEN the system MUST display an error notification (e.g., "Failed to save layout. Retrying...")
- AND the system MUST retry the save automatically (up to 3 retries)
- AND if all retries fail, the system MUST display a persistent error with a manual retry button

#### Scenario: Concurrent edits from multiple tabs
- GIVEN user "alice" has the same dashboard open in two browser tabs
- AND she repositions a widget in tab 1
- WHEN she repositions a different widget in tab 2
- THEN each tab MUST independently save its changes
- AND the last save MUST win (no merge conflict resolution required)

### REQ-GRID-006: Widget Auto-Layout

New widgets added to the dashboard MUST be placed in the first available grid position.

#### Scenario: Add widget to partially filled grid
- GIVEN the grid has widgets occupying rows 0-2 in columns 0-8
- AND columns 8-11 in row 0 are empty
- WHEN the user adds a new widget with width 4 and height 2
- THEN GridStack MUST place the widget at the first available position that fits (e.g., x=8, y=0)
- AND the widget MUST NOT overlap existing placements

#### Scenario: Add widget to a full row
- GIVEN all 12 columns in rows 0-3 are occupied
- WHEN the user adds a new widget with width 4 and height 2
- THEN GridStack MUST place it in the next available row (y=4 or later)
- AND the grid MUST expand vertically to accommodate the new widget

#### Scenario: Auto-layout respects widget size
- GIVEN columns 0-7 in row 0 are occupied (8 columns used)
- WHEN the user adds a widget with width 6
- THEN the widget MUST NOT be placed at x=8 (since 8 + 6 = 14 > 12)
- AND it MUST be placed at x=0, y at the next available row

### REQ-GRID-007: Grid Responsiveness

The grid MUST adapt to the container width while maintaining the configured column count.

#### Scenario: Grid fills container width
- GIVEN the dashboard container is 1200px wide
- AND the grid has 12 columns with 12px margins
- WHEN the grid renders
- THEN each column MUST be approximately (1200 - 13*12) / 12 = 87px wide
- AND the grid MUST fill the full container width

#### Scenario: Grid adapts to container resize
- GIVEN the user resizes their browser window
- WHEN the container width changes
- THEN the grid column width MUST recalculate proportionally
- AND widget positions MUST remain in their grid coordinates (columns and rows)
- AND no widget content MUST overflow its cell boundaries

### REQ-GRID-008: Grid Accessibility

The grid MUST support keyboard navigation and screen reader compatibility.

#### Scenario: Keyboard navigation between widgets
- GIVEN the dashboard is in view mode
- WHEN the user presses Tab
- THEN focus MUST move sequentially through widget placements in sort_order
- AND each focused widget MUST have a visible focus indicator

#### Scenario: Keyboard widget movement in edit mode
- GIVEN the dashboard is in edit mode
- AND a widget has keyboard focus
- WHEN the user presses Arrow keys while holding a modifier key (e.g., Ctrl+Arrow)
- THEN the widget MUST move one grid cell in the arrow direction
- AND the movement MUST respect grid boundaries and collision avoidance

#### Scenario: Screen reader announces widget positions
- GIVEN a screen reader is active
- WHEN a widget receives focus
- THEN the screen reader MUST announce: the widget title, its grid position (e.g., "column 1, row 1"), and its size (e.g., "spans 4 columns and 2 rows")

## Non-Functional Requirements

- **Performance**: Grid initialization MUST complete within 500ms for dashboards with up to 30 widget placements. Drag and resize interactions MUST maintain 60fps with no visible lag.
- **Library version**: GridStack 10.3.1 MUST be used. Upgrades require spec review for breaking changes.
- **Browser support**: The grid MUST function in all browsers supported by Nextcloud (Chrome, Firefox, Safari, Edge -- latest 2 versions).
- **Debounce timing**: Position save debounce MUST be configurable but default to 500ms.
- **Accessibility**: Grid interactions MUST provide keyboard alternatives for all mouse-based operations. WCAG AA compliance is required.
