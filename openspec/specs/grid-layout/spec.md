---
status: implemented
---

# Grid Layout Specification

## Purpose

The grid layout system powers the drag-and-drop dashboard experience in MyDash. Built on GridStack 12.x, it provides a 12-column responsive grid that reflows at four explicit viewport breakpoints (1400/1100/768/480 px → 12/8/4/1 cols) where users can position, resize, and rearrange widget placements and tiles. The grid operates in two modes: view mode (static, no interaction) and edit mode (drag-and-drop enabled). Position changes are emitted via Vue events and persisted via the API by the parent component.

## Technical Foundation

- **Library**: GridStack 12.x (range `^12.2.1`, version floor `>= 10.0.0` per REQ-GRID-013)
- **Grid columns**: 12 at viewports >= 1400 px (configurable per dashboard via `gridColumns`); reflows to 8/4/1 at narrower viewports per REQ-GRID-007
- **Cell height**: 60 px (fixed; defined as `CELL_HEIGHT` in `src/composables/useGridManager.js`, mirrored to CSS variable `--mydash-cell-height`)
- **Margins**: 8 px on all four sides between cells (`GRID_MARGIN`)
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
- THEN GridStack MUST be initialized with `column: 12`, `cellHeight: 60`, `margin: 8`, `float: true`, `animate: true`, and `columnOpts` populated from `useGridManager.getColumnOpts()`
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

### REQ-GRID-006: Widget Auto-Layout (collision placement algorithm)

When a new widget is added to an existing dashboard, the system MUST place it without overlapping any existing widget and without leaving it outside the visible grid region. The algorithm MUST be:

1. **Try GridStack auto-position** — call `grid.addWidget({x: 0, y: 0, w: newW, h: newH, autoPosition: true, ...})`. GridStack scans for the first empty rectangle that fits and uses it.
2. **Fallback: top-left with push-down** — if step 1 fails (GridStack returns no slot, or the picked slot is below `viewportRows`), place the new widget at `(x: 0, y: 0)` with size `(newW, newH)` AND for every existing widget whose rectangle overlaps `[0..newW] × [0..newH]`, set its `gridY` to `newH` (pushing it just below the new one). Existing widgets that do not overlap MUST NOT be moved.

Default size when the caller omits `w`/`h` MUST be `w=4, h=4`. Position writes MUST trigger the persistence path of REQ-GRID-005.

#### Scenario: Auto-position into empty space

- **GIVEN** a dashboard with one widget at `(x:0, y:0, w:6, h:4)`
- **WHEN** a new 4×4 widget is added
- **THEN** GridStack MUST place it at `(x:6, y:0)` (the empty right-half)
- **AND** no existing widget MUST be moved

#### Scenario: Push-down fallback when grid is full at top

- **GIVEN** a dashboard with widgets occupying the entire `[0..12] × [0..4]` region
- **WHEN** a new 4×4 widget is added
- **THEN** it MUST be placed at `(x:0, y:0, w:4, h:4)`
- **AND** every previously-overlapping widget MUST have `gridY = 4` (just below the new one)
- **AND** non-overlapping widgets (already at `y >= 4`) MUST NOT have their `gridY` changed

#### Scenario: Default size on omitted dimensions

- **GIVEN** a caller invokes `placeNewWidget({type: 'text'})` with no `w` or `h`
- **WHEN** the placement runs
- **THEN** the placement MUST use `w=4, h=4`

#### Scenario: Persistence after placement

- **GIVEN** a new widget has been placed (via either step 1 or step 2)
- **WHEN** the placement completes
- **THEN** the new widget AND any pushed-down widgets MUST be persisted via the standard placement-update API (REQ-WDG-008 batch update or per-placement PUT)
- **AND** a single network round-trip SHOULD be used for the batch (debounce 300 ms per the design rule in `openspec/config.yaml`)

#### Scenario: Pushed widgets remain within their column lane

- **GIVEN** existing widget at `(x:8, y:0, w:4, h:2)` overlaps a new 6×3 widget being placed at top-left
- **WHEN** the push-down fallback runs
- **THEN** the existing widget's `gridX` and `gridW` MUST remain `8` and `4` respectively
- **AND** only `gridY` MUST be increased to `3`

#### Scenario: Add widget to partially filled grid (incremental render)
- GIVEN the grid has widgets occupying rows 0-2 in columns 0-8
- AND columns 8-11 in row 0 are empty
- WHEN the user adds a new widget with gridWidth 4 and gridHeight 2
- THEN `syncGridItems()` MUST detect the new placement and call `grid.makeWidget()` on the next tick
- AND GridStack MUST render the widget at the position computed by `placeNewWidget` (auto-position or fallback)
- NOTE: With `float: true`, auto-placement behavior may differ from non-float mode.

#### Scenario: Remove widget syncs grid
- GIVEN widget placement id 10 is removed from the placements array
- WHEN the placements watcher triggers `syncGridItems()`
- THEN `syncGridItems()` MUST find the orphaned grid node and call `grid.removeWidget()` with `removeDOM: false`
- AND the grid MUST update its layout accordingly

### REQ-GRID-007: Grid Responsiveness (concrete breakpoints)

The GridStack instance MUST be initialised with `columnOpts.breakpoints` containing exactly four entries, each `{w: <viewportWidthPx>, c: <columnCount>}`, applied in descending viewport order:

| Viewport width >= | Column count |
|---|---|
| 1400 px | 12 |
| 1100 px | 8 |
| 768 px | 4 |
| 480 px | 1 |

`columnOpts.layout` MUST be set to `'moveScale'` so widgets scale proportionally when the column count changes (rather than wrapping or collapsing). Below the smallest entry the smallest column count (1) MUST apply.

The grid MUST also adapt to the container width while maintaining the active column count: column widths recalculate proportionally on resize, widget positions remain in their grid coordinates, and no widget content overflows its cell boundaries.

#### Scenario: 12 columns on wide desktop

- GIVEN viewport width is 1500 px
- WHEN the workspace renders
- THEN the grid MUST use 12 columns
- AND a widget originally placed at `{x: 6, w: 6}` MUST occupy the right half of the viewport

#### Scenario: Reflow at 1100 px

- GIVEN the workspace was rendered at 1500 px (12 columns) with widgets occupying the full grid width
- WHEN the viewport is resized to 1100 px (8 columns)
- THEN GridStack MUST proportionally rescale widget widths via `moveScale`
- AND a widget originally `{x: 0, w: 6}` (half) MUST become approximately `{x: 0, w: 4}` (still half, in the 8-col grid)

#### Scenario: Single-column on mobile

- GIVEN viewport width is 480 px
- WHEN the workspace renders
- THEN the grid MUST use 1 column
- AND every widget MUST occupy the full row width
- AND widgets MUST stack vertically

#### Scenario: Below smallest breakpoint

- GIVEN viewport width is 320 px (below the 480 entry)
- WHEN the workspace renders
- THEN the grid MUST use the column count of the smallest matching breakpoint (1 column)

#### Scenario: Grid fills container width
- GIVEN the dashboard container is 1200px wide at the 8-column breakpoint
- WHEN the grid renders
- THEN each column MUST be proportionally sized to fill the container width
- AND the grid MUST fill the full container width

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

### REQ-GRID-012: Cell geometry constants

The grid MUST be initialised with `cellHeight: 60` (px) and `margin: 8` (px). These constants MUST live in a single shared module exported from the grid composable, not duplicated in component templates.

#### Scenario: Cell height read from constant

- GIVEN the grid composable defines `CELL_HEIGHT = 60`
- WHEN any other module needs the cell height (e.g. for collision math or CSS calc)
- THEN it MUST import the constant
- AND grep for hardcoded `60` in grid contexts MUST return only the composable definition

#### Scenario: 12-col, 60px, 8px margin renders predictable height

- GIVEN a widget with `gridHeight: 4` is placed
- WHEN it renders at the 12-column breakpoint
- THEN its DOM height MUST be `(4 * 60) + (3 * 8) = 264 px` (4 rows + 3 inter-row margins)

### REQ-GRID-013: GridStack version pin

The system MUST pin `gridstack` to a major version that supports `columnOpts.breakpoints` and the `moveScale` layout (currently v10 or later, target v12+). Bumping the major version MUST be a deliberate change with a regression-test pass on this capability.

#### Scenario: Lockfile version

- GIVEN a developer inspects `package-lock.json`
- WHEN reading the resolved `gridstack` entry
- THEN the resolved version MUST be `>= 10.0.0`
- AND `package.json` MUST declare a constrained range like `"gridstack": "^12.2.1"` (or whichever major is current at apply time)

### REQ-GRID-014: Placement helper is the single placement authority

All "add widget" code paths (toolbar dropdown, keyboard shortcut, drag-from-picker) MUST go through a single `placeNewWidget(spec)` helper exported from the grid composable. Inline calls to `grid.addWidget` outside this helper are forbidden.

#### Scenario: Single source of truth

- **GIVEN** the codebase under `src/`
- **WHEN** `grep -r 'grid.addWidget' src/` is run
- **THEN** matches MUST occur only inside `useGridManager.js` (or its test file)
- **AND** component templates MUST NOT call GridStack APIs directly

#### Scenario: Add-widget submit handler routes through the helper

- **GIVEN** a user clicks the "Add widget" button in `AddWidgetModal.vue`
- **WHEN** the submit handler runs
- **THEN** it MUST call `placeNewWidget(spec)` exported from `useGridManager.js`
- **AND** MUST NOT call `grid.addWidget(...)` directly

## Non-Functional Requirements

- **Performance**: Grid initialization MUST complete within 500ms for dashboards with up to 30 widget placements. Drag and resize interactions MUST maintain 60fps with no visible lag.
- **Library version**: GridStack `^12.2.1` MUST be used. The version floor is `>= 10.0.0` (REQ-GRID-013); future major bumps require spec review for breaking changes.
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
- REQ-GRID-006 (Widget Auto-Layout — collision placement algorithm): `placeNewWidget()` in `src/composables/useGridManager.js` computes the position via the autoPosition primary path and the top-left + push-down fallback; `syncGridItems()` then renders the new placement via `grid.makeWidget()`. Push-down side effects flow through the existing `updatePlacements` batch path.
- REQ-GRID-009 (Tile vs Widget Rendering): `isTilePlacement()`, `getTileData()` handle rendering distinction.
- REQ-GRID-010 (Grid Styling): CSS applied via scoped styles with deep selectors.
- REQ-GRID-011 (Grid Synchronization): `placements` watcher triggers `syncGridItems()`.

**Not yet implemented:**
- REQ-GRID-005 save failure/retry: No retry logic in frontend.
- REQ-GRID-005 debouncing: No debounce on handleGridChange.
- REQ-GRID-008 (Grid Accessibility): No keyboard navigation, keyboard movement, or ARIA attributes.
- REQ-GRID-001 empty state: No empty state placeholder.

**Recently implemented:**
- REQ-GRID-007 (Grid Responsiveness): four explicit `columnOpts.breakpoints` entries (1400/1100/768/480 → 12/8/4/1) with `moveScale` reflow. Constants exported from `src/composables/useGridManager.js` and consumed by `DashboardGrid.vue`. Mirrored to CSS via the `--mydash-cell-height` custom property.
- REQ-GRID-012 (Cell geometry constants): `CELL_HEIGHT = 60`, `GRID_MARGIN = 8` shared from the composable.
- REQ-GRID-013 (GridStack version pin): `package.json` declares `"gridstack": "^12.2.1"` (resolves to 12.6.0); floor `>= 10.0.0`.
- REQ-GRID-014 (Single placement authority): `placeNewWidget(spec, placements, options)` exported from `src/composables/useGridManager.js`. The dashboard store's `addWidgetToDashboard` and `addTileToDashboard` actions both delegate to it; push-down side effects flow through `applyPushedPlacements` → `updatePlacements`. Architectural enforcement: a Vitest grep guard in `__tests__/useGridManager.spec.js` asserts no other `.js`/`.vue` file under `src/` references `grid.addWidget(`.

### Standards & References
- GridStack 12.x: https://gridstackjs.com/
- WAI-ARIA Grid pattern: https://www.w3.org/WAI/ARIA/apg/patterns/grid/
- WCAG 2.1 AA: Focus indicators, keyboard operability, screen reader compatibility
- Nextcloud Vue components: `NcButton` used in parent components for edit/done toggle
