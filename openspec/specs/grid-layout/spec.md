---
status: reviewed
---

# Grid Layout Specification

## Purpose

The grid layout system powers the drag-and-drop dashboard experience in MyDash. Built on GridStack 10.3.1, it provides a 12-column responsive grid where users can position, resize, and rearrange widget placements and tiles. The grid operates in two modes: view mode (static, no interaction) and edit mode (drag-and-drop enabled). Position changes are emitted via Vue events and persisted via the API by the parent component.

## Technical Foundation

- **Library**: GridStack 10.3.1
- **Grid columns**: 12 (configurable per dashboard via `gridColumns`)
- **Cell height**: 80px (fixed)
- **Margins**: 12px horizontal and vertical between cells
- **Coordinate system**: 0-based (gridX: 0-11 for 12 columns, gridY: 0+)
- **Float mode**: Enabled (`float: true`) -- items do NOT auto-stack downward; they stay at their exact grid position
- **Animation**: Enabled (`animate: true`)
- **Minimum widget size**: 2 columns wide, 2 rows tall (`gs-min-w="2"`, `gs-min-h="2"`)

## Requirements

### REQ-GRID-001: Grid Initialization

The grid MUST initialize with the correct configuration when a dashboard is loaded.

#### Scenario: Initialize grid with default 12-column layout
- GIVEN user "alice" activates dashboard id 5 with `gridColumns: 12`
- WHEN the dashboard view loads
- THEN GridStack MUST be initialized with `column: 12`, `cellHeight: 80`, `margin: 12`, `float: true`, `animate: true`
- AND the grid MUST render all widget placements at their stored (gridX, gridY, gridWidth, gridHeight) coordinates using `gs-x`, `gs-y`, `gs-w`, `gs-h` attributes

#### Scenario: Initialize grid with custom column count
- GIVEN dashboard id 5 has `gridColumns: 6`
- WHEN the dashboard view loads
- THEN GridStack MUST be initialized with `column: 6`
- AND all widget placements MUST be constrained to the 6-column grid
- AND placements with `gridX + gridWidth > 6` MUST be automatically reflowed to fit

#### Scenario: Initialize grid with no widget placements
- GIVEN dashboard id 5 has no widget placements
- WHEN the dashboard view loads
- THEN GridStack MUST initialize an empty grid
- AND the empty grid MUST display a placeholder message or empty state (e.g., "Add widgets to get started")
- NOTE: Empty state placeholder is NOT currently implemented

#### Scenario: Grid renders placements in correct positions
- GIVEN dashboard id 5 has the following placements:
  | placement_id | widgetId       | gridX | gridY | gridWidth | gridHeight |
  |--------------|----------------|-------|-------|-----------|------------|
  | 10           | weather_status | 0     | 0     | 4         | 2          |
  | 11           | notes          | 4     | 0     | 4         | 3          |
  | 12           | calendar       | 8     | 0     | 4         | 2          |
  | 13           | (tile)         | 0     | 2     | 2         | 2          |
- WHEN the dashboard view loads
- THEN each placement MUST be rendered at its exact grid coordinates via `gs-id`, `gs-x`, `gs-y`, `gs-w`, `gs-h` attributes
- AND no placements MUST overlap
- AND the grid height MUST expand to accommodate all placements

#### Scenario: Grid initialization options match configuration
- GIVEN the DashboardGrid component receives props
- WHEN `initGrid()` is called
- THEN GridStack.init MUST be called with `disableDrag: !this.editMode` and `disableResize: !this.editMode`
- AND `removable: false` MUST prevent accidental widget removal via drag-out

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
- NOTE: The grid uses `disableDrag: !this.editMode` on initialization and `grid.enable()`/`grid.disable()` when editMode changes via a watcher.

#### Scenario: Drag respects grid boundaries
- GIVEN a widget with gridWidth 4 at position (0, 0)
- AND the grid has 12 columns
- WHEN the user drags it to position (10, 0)
- THEN the widget MUST NOT be placed at gridX=10 (since 10 + 4 = 14 > 12)
- AND GridStack MUST constrain the placement to gridX=8 (maximum valid position for gridWidth 4)

#### Scenario: Drag pushes other widgets
- GIVEN widget A at (0, 0) size 4x2 and widget B at (0, 2) size 4x2
- WHEN the user drags widget A to (0, 2) (overlapping with B)
- THEN GridStack MUST push widget B down to (0, 4) to make room
- AND no overlap MUST occur

#### Scenario: Drag emits position update
- GIVEN the dashboard is in edit mode
- WHEN the user drags a widget to a new position
- THEN the GridStack `change` event MUST fire
- AND `handleGridChange()` MUST emit `update:placements` with all current placement positions

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
- WHEN the user tries to resize it smaller than 2x2
- THEN the widget MUST NOT be smaller than the minimum size (2 columns wide, 2 rows tall)
- AND GridStack MUST enforce minimum dimensions via `gs-min-w="2"` and `gs-min-h="2"` attributes

#### Scenario: Resize constrained by grid columns
- GIVEN a widget at position (8, 0) with size 4x2 on a 12-column grid
- WHEN the user tries to resize it to gridWidth 6
- THEN the widget width MUST be constrained to 4 (since 8 + 6 = 14 > 12)
- OR GridStack MUST reposition the widget to allow the resize

#### Scenario: Resize handles not visible in view mode
- GIVEN the dashboard is in view mode
- WHEN the user hovers over a widget edge
- THEN no resize handles MUST be displayed
- AND the cursor MUST NOT change to a resize cursor
- NOTE: `disableResize: !this.editMode` on initialization.

### REQ-GRID-004: View Mode vs Edit Mode

The grid MUST support two distinct interaction modes controlled by the `editMode` prop.

#### Scenario: Enter edit mode
- GIVEN the dashboard is in view mode
- WHEN the user clicks the "Edit" button (handled by parent component)
- THEN the grid MUST transition to edit mode via `grid.enable()`
- AND drag handles MUST become visible on all widget placements
- AND resize handles MUST become active on widget edges

#### Scenario: Exit edit mode
- GIVEN the dashboard is in edit mode
- AND the user has repositioned 2 widgets
- WHEN the user clicks the "Done" button
- THEN the grid MUST transition to view mode via `grid.disable()`
- AND all drag and resize interactions MUST be disabled
- AND the final positions MUST be persisted via the API (handled by parent component)

#### Scenario: View mode is the default
- GIVEN the user navigates to their active dashboard
- WHEN the dashboard loads
- THEN the grid MUST be in view mode by default (`editMode: false`)
- AND widgets MUST be static and non-interactive from a grid perspective

#### Scenario: View-only permission prevents edit mode
- GIVEN dashboard id 5 has `permissionLevel: "view_only"`
- WHEN the user views the dashboard
- THEN the "Edit" button MUST NOT be displayed (handled by parent component based on permissionLevel)
- AND the grid MUST always remain in view mode

#### Scenario: Edit mode watcher responds to prop changes
- GIVEN the DashboardGrid component is mounted
- WHEN the `editMode` prop changes from false to true
- THEN the watcher MUST call `grid.enable()`
- AND when it changes from true to false, the watcher MUST call `grid.disable()`

### REQ-GRID-005: Position Persistence

Grid position changes MUST be communicated to the parent component for API persistence.

#### Scenario: Save after grid change
- GIVEN the user drags a widget to a new position
- WHEN the GridStack `change` event fires
- THEN the DashboardGrid component MUST emit an `update:placements` event with all updated placement positions
- NOTE: Debouncing is NOT implemented in DashboardGrid. `handleGridChange` emits immediately on every GridStack change event.

#### Scenario: Multiple rapid changes
- GIVEN the user rapidly repositions 3 widgets
- WHEN each repositioning triggers a GridStack change event
- THEN each change MUST trigger an `update:placements` emit
- NOTE: Since there is no debounce, rapid changes result in multiple emit calls. The parent component is responsible for coalescing API calls.

#### Scenario: Save failure with retry
- GIVEN the user repositions a widget
- AND the API request to save positions fails
- WHEN the failure is detected
- THEN the system MUST display an error notification
- AND the system MUST retry the save automatically (up to 3 retries)
- NOTE: Save failure/retry is NOT currently implemented in the frontend.

#### Scenario: Concurrent edits from multiple tabs
- GIVEN user "alice" has the same dashboard open in two browser tabs
- AND she repositions a widget in tab 1
- WHEN she repositions a different widget in tab 2
- THEN each tab MUST independently save its changes
- AND the last save MUST win (no merge conflict resolution required)

#### Scenario: Change event maps grid items to placements
- GIVEN the GridStack `change` event fires with an array of updated grid items
- WHEN `handleGridChange(items)` processes the items
- THEN each grid item's `id` MUST be matched to a placement's `id` via string comparison
- AND the placement's gridX, gridY, gridWidth, gridHeight MUST be updated from the grid item's x, y, w, h values

### REQ-GRID-006: Widget Auto-Layout

New widgets added to the dashboard MUST be positioned by GridStack's auto-placement algorithm.

#### Scenario: Add widget to partially filled grid
- GIVEN the grid has widgets occupying rows 0-2 in columns 0-8
- AND columns 8-11 in row 0 are empty
- WHEN the user adds a new widget with gridWidth 4 and gridHeight 2
- THEN `syncGridItems()` MUST detect the new placement and call `grid.makeWidget()` on the next tick
- AND GridStack MUST place the widget at an available position
- NOTE: With `float: true`, auto-placement behavior may differ from non-float mode.

#### Scenario: Add widget to a full row
- GIVEN all 12 columns in rows 0-3 are occupied
- WHEN the user adds a new widget with gridWidth 4 and gridHeight 2
- THEN GridStack MUST place it in the next available row (gridY=4 or later)
- AND the grid MUST expand vertically to accommodate the new widget

#### Scenario: Remove widget syncs grid
- GIVEN widget placement id 10 is removed from the placements array
- WHEN the placements watcher triggers `syncGridItems()`
- THEN `syncGridItems()` MUST find the orphaned grid node and call `grid.removeWidget()` with `removeDOM: false`
- AND the grid MUST update its layout accordingly

### REQ-GRID-007: Grid Responsiveness

The grid MUST adapt to the container width while maintaining the configured column count.

#### Scenario: Grid fills container width
- GIVEN the dashboard container is 1200px wide
- AND the grid has 12 columns with 12px margins
- WHEN the grid renders
- THEN each column MUST be proportionally sized to fill the container width
- AND the grid MUST fill the full container width

#### Scenario: Grid adapts to container resize
- GIVEN the user resizes their browser window
- WHEN the container width changes
- THEN the grid column width MUST recalculate proportionally
- AND widget positions MUST remain in their grid coordinates (columns and rows)
- AND no widget content MUST overflow its cell boundaries

#### Scenario: Minimum grid height
- GIVEN the dashboard has no widgets or very few widgets
- WHEN the grid renders
- THEN the grid container MUST maintain a minimum height of 400px (`.mydash-grid { min-height: 400px }`)

### REQ-GRID-008: Grid Accessibility

The grid MUST support keyboard navigation and screen reader compatibility.

#### Scenario: Keyboard navigation between widgets
- GIVEN the dashboard is in view mode
- WHEN the user presses Tab
- THEN focus MUST move sequentially through widget placements
- AND each focused widget MUST have a visible focus indicator
- NOTE: Keyboard navigation is NOT currently implemented.

#### Scenario: Keyboard widget movement in edit mode
- GIVEN the dashboard is in edit mode
- AND a widget has keyboard focus
- WHEN the user presses Arrow keys while holding a modifier key (e.g., Ctrl+Arrow)
- THEN the widget MUST move one grid cell in the arrow direction
- NOTE: Keyboard movement is NOT currently implemented.

#### Scenario: Screen reader announces widget positions
- GIVEN a screen reader is active
- WHEN a widget receives focus
- THEN the screen reader MUST announce: the widget title, its grid position, and its size
- NOTE: ARIA attributes for grid positions are NOT currently implemented.

### REQ-GRID-009: Tile vs Widget Rendering

The grid MUST distinguish between tile placements and widget placements for rendering.

#### Scenario: Tile placement renders TileWidget
- GIVEN a placement with `tileType: "custom"` and inline tile data (tileTitle, tileIcon, etc.)
- WHEN the grid renders
- THEN the placement MUST be rendered using the `TileWidget` component (not `WidgetWrapper`)
- AND `isTilePlacement()` check uses `placement.tileType === 'custom'`
- AND `getTileData()` extracts inline tile data from placement fields into a tile object

#### Scenario: Regular widget placement renders WidgetWrapper
- GIVEN a placement with `tileType: null` and `widgetId: "weather_status"`
- WHEN the grid renders
- THEN the placement MUST be rendered using the `WidgetWrapper` component
- AND the widget data MUST be resolved via `getWidget(placement.widgetId)` from the available widgets array

#### Scenario: TileWidget receives edit mode prop
- GIVEN a tile placement on a dashboard in edit mode
- WHEN the tile is rendered
- THEN `TileWidget` MUST receive `editMode: true`
- AND an edit button MUST be visible on the tile
- AND clicking the edit button MUST emit `tile-edit` via the grid component

#### Scenario: WidgetWrapper receives placement and widget data
- GIVEN a widget placement with `widgetId: "recommendations"`
- AND the available widgets array contains a widget with `id: "recommendations"`
- WHEN the grid renders
- THEN `WidgetWrapper` MUST receive both the `placement` object and the resolved `widget` object
- AND if no matching widget is found, `widget` MUST be null (graceful degradation)

### REQ-GRID-010: Grid Styling

The grid MUST apply consistent visual styling to all grid items.

#### Scenario: Grid item content styling
- GIVEN a grid item is rendered
- WHEN the item is displayed
- THEN `.grid-stack-item-content` MUST have: background blur via `backdrop-filter`, large border radius via `--border-radius-large`, and `overflow: hidden`

#### Scenario: Placeholder styling during drag
- GIVEN the user is dragging a widget in edit mode
- WHEN a placeholder appears showing the drop target
- THEN `.grid-stack-placeholder > .placeholder-content` MUST have: a primary element light background and a 2px dashed border in primary color with large border radius

#### Scenario: Placement key regeneration
- GIVEN a widget placement is updated (e.g., style change)
- WHEN the grid re-renders
- THEN the placement key MUST include the `updatedAt` timestamp and `styleConfig` hash to force re-rendering via `getPlacementKey()`

### REQ-GRID-011: Grid Synchronization

The grid MUST stay synchronized with the placements prop when items are added or removed externally.

#### Scenario: New placement added to props
- GIVEN the placements array receives a new placement via the parent component
- WHEN the `placements` watcher triggers `syncGridItems()`
- THEN the new placement MUST be added to the grid via `grid.makeWidget()` on `$nextTick()`

#### Scenario: Placement removed from props
- GIVEN a placement is removed from the placements array
- WHEN the `placements` watcher triggers `syncGridItems()`
- THEN the orphaned grid node MUST be detected by comparing placement IDs
- AND the element MUST be removed via `grid.removeWidget(el, false)`

#### Scenario: Grid destruction on component unmount
- GIVEN the DashboardGrid component is about to be destroyed
- WHEN `beforeDestroy` lifecycle hook fires
- THEN `grid.destroy(false)` MUST be called (false = do not remove DOM elements)

## Non-Functional Requirements

- **Performance**: Grid initialization MUST complete within 500ms for dashboards with up to 30 widget placements. Drag and resize interactions MUST maintain 60fps with no visible lag.
- **Library version**: GridStack 10.3.1 MUST be used. Upgrades require spec review for breaking changes.
- **Browser support**: The grid MUST function in all browsers supported by Nextcloud (Chrome, Firefox, Safari, Edge -- latest 2 versions).
- **Debouncing**: Debouncing is NOT currently implemented in DashboardGrid. The `handleGridChange` method emits immediately on every change event. Debouncing SHOULD be added.
- **Accessibility**: Grid interactions MUST provide keyboard alternatives for all mouse-based operations. WCAG AA compliance is required.

### Current Implementation Status

**Fully implemented:**
- REQ-GRID-001 (Grid Initialization): `DashboardGrid.vue` initializes GridStack with all specified options.
- REQ-GRID-002 (Drag to Reposition): Drag enabled/disabled via `grid.enable()`/`grid.disable()` in editMode watcher.
- REQ-GRID-003 (Resize by Edge Dragging): Resize controlled via `disableResize: !this.editMode`. Min sizes via `gs-min-w="2"`, `gs-min-h="2"`.
- REQ-GRID-004 (View Mode vs Edit Mode): `editMode` prop controls grid state.
- REQ-GRID-005 (Position Persistence): `handleGridChange()` emits `update:placements` on every change event.
- REQ-GRID-006 (Widget Auto-Layout): `syncGridItems()` adds/removes items.
- REQ-GRID-009 (Tile vs Widget Rendering): `isTilePlacement()`, `getTileData()` handle rendering distinction.
- REQ-GRID-010 (Grid Styling): CSS applied via scoped styles with deep selectors.
- REQ-GRID-011 (Grid Synchronization): `placements` watcher triggers `syncGridItems()`.

**Not yet implemented:**
- REQ-GRID-005 save failure/retry: No retry logic in frontend.
- REQ-GRID-005 debouncing: No debounce on handleGridChange.
- REQ-GRID-007 (Grid Responsiveness): No explicit responsive handling or breakpoints.
- REQ-GRID-008 (Grid Accessibility): No keyboard navigation, keyboard movement, or ARIA attributes.
- REQ-GRID-001 empty state: No empty state placeholder.

### Standards & References
- GridStack 10.3.1: https://gridstackjs.com/
- WAI-ARIA Grid pattern: https://www.w3.org/WAI/ARIA/apg/patterns/grid/
- WCAG 2.1 AA: Focus indicators, keyboard operability, screen reader compatibility
- Nextcloud Vue components: `NcButton` used in parent components for edit/done toggle
