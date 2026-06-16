# Tasks — responsive-grid-breakpoints

## 1. Frontend

- [x] 1.1 Bump `gridstack` to `^12.2.1` in `package.json`; resolve any v10 -> v12 breaking changes
- [x] 1.2 Define constants `CELL_HEIGHT = 60`, `GRID_MARGIN = 8`, `BREAKPOINTS = [{w:1400,c:12},{w:1100,c:8},{w:768,c:4},{w:480,c:1}]` in grid composable (single shared module)
- [x] 1.3 Pass `cellHeight`, `margin`, `columnOpts: {breakpoints, layout: 'moveScale'}` to `GridStack.init` in `useGridManager.js`
- [x] 1.4 Update `WorkspaceApp.vue` (and any other GridStack mount sites) to import + use the shared constants — no inline literals (only mount site is `DashboardGrid.vue`; updated.)
- [x] 1.5 Update CSS `calc()` usage to read from CSS custom property `--mydash-cell-height` (set from JS at init time from `CELL_HEIGHT`)

## 2. Decision: keep 80 or move to 60?

- [x] 2.1 Resolved: defaulted to 60 per spec; user can flip with single-constant change in `src/composables/useGridManager.js` if 80 preferred
- [x] 2.2 Keeping 60 — REQ-GRID-012 height-math scenario `(4 * 60) + (3 * 8) = 264 px` already matches; no rewrite needed

## 3. Tests

- [x] 3.1 Playwright: assert column count via `grid.opts.column` at 1500 / 1200 / 900 / 640 / 320 px viewport widths (`tests/e2e/responsive-grid-breakpoints.spec.ts`, runs once cohort-wide Playwright bootstrap lands)
- [x] 3.2 Playwright: visual regression for a 6-widget layout at each of the four breakpoints (same file)
- [x] 3.3 Vitest: composable exposes `CELL_HEIGHT`, `GRID_MARGIN`, `BREAKPOINTS` with expected values (`src/composables/__tests__/useGridManager.spec.js`)

## 4. Quality

- [x] 4.1 ESLint + Stylelint clean
- [x] 4.2 Update `openspec/config.yaml` `cellHeight` documentation to match the resolved value (now reads "60px cell height, 8px margin, responsive breakpoints 1400/1100/768/480")
- [x] 4.3 CHANGELOG: GridStack major bump + responsive breakpoints noted under `## Unreleased`
