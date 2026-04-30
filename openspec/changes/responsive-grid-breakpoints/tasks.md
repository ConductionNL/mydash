# Tasks — responsive-grid-breakpoints

## 1. Frontend

- [ ] 1.1 Bump `gridstack` to `^12.2.1` in `package.json`; resolve any v10 -> v12 breaking changes
- [ ] 1.2 Define constants `CELL_HEIGHT = 60`, `GRID_MARGIN = 8`, `BREAKPOINTS = [{w:1400,c:12},{w:1100,c:8},{w:768,c:4},{w:480,c:1}]` in grid composable (single shared module)
- [ ] 1.3 Pass `cellHeight`, `margin`, `columnOpts: {breakpoints, layout: 'moveScale'}` to `GridStack.init` in `useGridManager.js`
- [ ] 1.4 Update `WorkspaceApp.vue` (and any other GridStack mount sites) to import + use the shared constants — no inline literals
- [ ] 1.5 Update CSS `calc()` usage to read from CSS custom property `--mydash-cell-height` (set from JS at init time from `CELL_HEIGHT`)

## 2. Decision: keep 80 or move to 60?

- [ ] 2.1 Resolve cell-height value with stakeholder before applying — flip `CELL_HEIGHT` constant if 80 wins
- [ ] 2.2 If keeping 80, update REQ-GRID-012 height-math scenario accordingly (replace `(4 * 60) + (3 * 8) = 264 px` with the 80-px equivalent)

## 3. Tests

- [ ] 3.1 Playwright: assert column count via `grid.opts.column` at 1500 / 1200 / 900 / 640 / 320 px viewport widths
- [ ] 3.2 Playwright: visual regression for a 6-widget layout at each of the four breakpoints
- [ ] 3.3 Vitest: composable exposes `CELL_HEIGHT`, `GRID_MARGIN`, `BREAKPOINTS` with expected values

## 4. Quality

- [ ] 4.1 ESLint + Stylelint clean
- [ ] 4.2 Update `openspec/config.yaml` `cellHeight` documentation to match the resolved value (60 or 80)
- [ ] 4.3 CHANGELOG: note the GridStack major bump for downstream consumers
