# Container Widget — Nested Grid Composition

Introduce a `container` widget type that hosts a sub-grid of child widget placements inside a single outer-grid cell. Authors compose dashboards out of logical sections (a heading + four KPI tiles, a "tabs" surface, a card with grouped content) without losing the move-as-one-unit drag behaviour of a top-level placement.

## Affected code units

- `src/components/widgets/Container.vue` — container widget component with inner GridStack instance
- `src/composables/useNestedGridManager.js` — new composable wrapping `useGridManager` with inner-grid constants
- `src/components/forms/ContainerForm.vue` — form for collecting backgroundColor, padding, and title
- `src/constants/containerDefaults.js` — inner-grid config constants (column: 4, cellHeight: 40, margin: 4)
- `lib/Service/WidgetPlacementService.php` — add `validateContainerDepth()` helper for recursion validation
- Widget registry integration — register `container` type and include in picker (REQ-CONT-001)

## Why a new widget type

Containers solve the nesting problem: users need to group related widgets into logical sections on a single dashboard without breaking the drag-as-one-unit paradigm of the top-level grid. Today, dashboard authors must flatten all widgets into a 12-column grid, which limits expressiveness (no sub-sectioning) and makes dashboard layout brittle (moving a section requires moving all widgets in it individually).

## Approach

- Children are stored as nested `placements: WidgetPlacement[]` in the container's `content` blob
- Dispatched through the same widget registry as top-level widgets (recursive rendering)
- Inner grid uses `useNestedGridManager` with distinct constants (4 columns, 40px cells, 4px margin, locked to 4-column layout)
- Server-side recursion depth validation caps nesting at 3 container levels (REQ-CONT-006)
- Container placement uses the existing `oc_mydash_widget_placements.content` JSON column — no schema migration
- Edit-mode independence: inner grid mutations do not cascade to outer grid

## Notes

- The inner grid is non-interactive in view mode; clicks fall through to children (REQ-CONT-004)
- Any registered widget type — including another container — can live inside one (recursive)
- Form collects three optional fields: backgroundColor (hex), padding enum, and title (REQ-CONT-007)
- The recursion-depth invariant is enforced on the backend during `POST /api/dashboards/{uuid}/widgets` and `PUT` operations
