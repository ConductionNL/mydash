# Responsive grid breakpoints

Add explicit responsive breakpoints to the GridStack instance so dashboards reflow proportionally at four viewport widths instead of staying fixed-12-column on narrow screens. Specifies the column counts, the reflow algorithm (`moveScale`), and the cell/margin geometry.

## Affected code units

- `src/composables/useGridManager.js` (or equivalent) — pass `columnOpts.breakpoints` and `columnOpts.layout` to `GridStack.init`
- `src/views/WorkspaceApp.vue` and any other GridStack mount sites
- Modifies `grid-layout` REQ-GRID-007 (Grid Responsiveness) which currently is under-specified

## Why a delta

REQ-GRID-007 already says the grid SHOULD be responsive. This change pins down concrete breakpoints, target column counts, the reflow strategy, and the cell/margin constants — making the requirement testable.

## Approach

- Pin GridStack to the `gridstack` v12.x line (the `columnOpts.breakpoints` shape used here is v10+; we already use 10.3.1 per config.yaml — bumping to v12 is in scope of this change).
- Four breakpoints, monotonically descending column counts: 1400→12, 1100→8, 768→4, 480→1.
- Cell height: 60 px; margins: 8 px (this differs from the 80 px documented in `openspec/config.yaml` — see Notes).
- Reflow algorithm: `'moveScale'` — proportional widget scaling on column transitions.

## Notes

- **Geometry change vs current config.yaml.** `config.yaml` documents `cellHeight: 80` for the existing `grid-layout` capability. This change proposes 60 px, matching the smaller cells better suited to dense dashboards with multi-row widgets. Decide before applying: keep 80 (more vertical breathing room) or move to 60 (denser). Spec'd as 60; flip in tasks if desired.
- GridStack v10 → v12 has a few breaking changes around column option shape — handled as part of the bump.
- `acceptWidgets`, `staticGrid`, and `float` settings are not touched (those belong to REQ-GRID-004 view-vs-edit).
