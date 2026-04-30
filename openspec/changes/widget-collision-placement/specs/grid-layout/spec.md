---
capability: grid-layout
delta: true
status: draft
---

# Grid Layout — Delta from change `widget-collision-placement`

## MODIFIED Requirements

### Requirement: REQ-GRID-006 Widget Auto-Layout (collision placement algorithm)

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

## ADDED Requirements

### Requirement: REQ-GRID-014 Placement helper is the single placement authority

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
