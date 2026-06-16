# Container widget — widgets inside widgets

## Why

Today every widget placement occupies one cell on the GridStack grid. There's no way to group widgets into a logical section, no way to lay out a sub-grid inside a widget, and no way to nest dashboards-within-dashboards visually. Real-world dashboard authors regularly want:

- A "section" with a heading + 4 small KPI tiles below it, treated as one unit
- A widget that holds its own internal grid (e.g., a "tabs" container with one widget per tab — out of scope for v1, but the architecture should not preclude it)
- Drag-to-reorder that moves the section + its children together as a single placement

The cleanest way to support this is a new `container` widget type whose `content` includes an array of child widget placements rendered inside a nested GridStack instance. The container is itself a placement on the parent grid; its children are placements inside the container's own internal grid.

## What Changes

- Introduce a new capability `container-widget` (`openspec/specs/container-widget/spec.md`).
- Add `container` widget type to `widgetRegistry.js`:
  - **Renderer** `ContainerWidget.vue` — renders an inner GridStack instance bounded by the container's outer cell
  - **Form** `ContainerForm.vue` — minimal config (background colour, padding, optional title); the children are managed via the inner grid's own add-widget flow, not via this form
- Define the **nested grid** behaviour:
  - The inner GridStack uses the same composable (`useGridManager`) as the top-level grid, but scoped to the container's DOM element
  - Inner grid configuration: smaller default columns (4 vs 12), reduced `cellHeight` (40px vs 60px), smaller margins (4px vs 8px) — so children fit within a typical container size
  - Inner-grid widgets are stored as nested `placements: WidgetPlacement[]` array inside the container placement's `content`
  - Each child placement has the SAME shape as a top-level placement (uuid, type, content, gridX/Y/W/H) — the renderer recursively delegates to `WidgetRenderer` for each child
- **Recursion limit:** containers MAY hold containers, but a **maximum nesting depth of 3** is enforced server-side (per REQ-CONT-006) to prevent runaway nesting. Exceeded depth returns HTTP 400 on save.
- **Click-through delegation:** clicking inside the container falls through to the child widget; the container itself is non-interactive in view mode (only its background renders)
- **Edit-mode interaction:** in edit mode, the container's inner grid becomes editable independently of the outer grid. The user can add/remove/move child widgets inside the container without disturbing siblings on the outer grid.

## Capabilities

### Added Capabilities

- `container-widget` — new capability defining the container widget type, the nested-grid render model, the recursion-depth invariant, and the edit-mode independence rule.

### Modified Capabilities

- `widgets` — registry includes `container` as a built-in widget type alongside label/text/image/link/nc-widget/tile/video.
- `widget-add-edit-modal` — picker MUST include the `container` type entry. Container sub-form added.
- `grid-layout` — the responsive-breakpoint config (REQ-GRID-007) applies to outer grids only; nested grids use a fixed 4-column layout (no responsive resize) to keep child layout predictable inside the bounded container cell. (This is documented as REQ-GRID-015 added by this change.)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/ContainerWidget.vue` — new renderer (recursive — uses WidgetRenderer for children)
- `src/components/Widgets/Forms/ContainerForm.vue` — new sub-form (background colour, padding, optional title)
- `src/composables/useNestedGridManager.js` — new composable (extends useGridManager with the inner-grid config: 4 cols, 40px cellHeight, 4px margin)
- `src/constants/widgetRegistry.js` — register `container` type
- `src/components/WidgetRenderer.vue` — already-recursive (delegates by type lookup); needs to handle the `container` type by mounting `ContainerWidget` which itself mounts another `WidgetRenderer` per child
- `lib/Service/WidgetPlacementService.php` (or wherever placement validation lives) — REQ-CONT-006 max-depth enforcement on POST /api/dashboards/{uuid}/widgets

**Affected APIs:**

- `POST /api/dashboards/{uuid}/widgets` and `PUT /api/dashboards/{uuid}/widgets/{id}` MUST validate that `content.placements[]` arrays are no more than 3 levels deep (recursive). HTTP 400 with `{status: 'error', error: 'container_depth_exceeded', maxDepth: 3}` if violated.
- Internal-only: `WidgetPlacementService::validateContainerDepth(content)` — recursive depth checker.

**Dependencies:**

- Soft-depends on `unified-add-widget-flow` (the unified picker is where `container` becomes selectable; before that change lands, container is reachable only from the legacy "Add custom widget" entry).

**Migration:**

- None — net-new feature. Existing placements unaffected.

## Notes

The nested grid is bounded by the container's outer cell — if the user resizes the container (via the outer grid's drag handles), the inner grid's render area changes accordingly. Child widgets keep their relative positions inside the inner grid (gridX/Y in the inner coordinate system); they don't reflow on container resize. This is consistent with how GridStack treats nested grids when configured with a fixed column count.
