# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Changed

- **GridStack bumped from `^10.3.1` to `^12.2.1`** (resolved 12.6.0). Major
  version bump bundled with the responsive-grid-breakpoints change. The
  `GridStack.init` signature, the `change` event payload, the
  `engine.nodes` accessor, the `removeWidget(el, removeDOM)` call, and the
  `enable()`/`disable()` lifecycle methods used by `DashboardGrid.vue` are
  unchanged across the v10 -> v12 jump, so no caller-side breakage was
  observed during the bump. Downstream forks pinning a narrower
  `gridstack` range will need to widen their dependency.

### Added

- **Responsive grid breakpoints** (REQ-GRID-007 / REQ-GRID-012 /
  REQ-GRID-013): the GridStack instance now reflows proportionally at four
  viewport widths instead of staying fixed-12-column on narrow screens.
  Breakpoints `[{w:1400,c:12},{w:1100,c:8},{w:768,c:4},{w:480,c:1}]` with
  the `moveScale` layout algorithm. Geometry constants (`CELL_HEIGHT = 60`,
  `GRID_MARGIN = 8`, `BREAKPOINTS`) live in
  `src/composables/useGridManager.js` as the single source of truth and
  are mirrored to the CSS custom property `--mydash-cell-height` at
  init time so `calc()` expressions stay in sync. Cell height moved from
  the previously documented 80 px to 60 px to better support multi-row
  info widgets; flip the `CELL_HEIGHT` constant in the composable (single
  edit) if a denser/looser default is preferred.

- **Initial-state contract** (REQ-INIT-001..006): `lib/Service/InitialStateBuilder.php`
  centralises the per-page initial-state payload pushed via Nextcloud's
  `IInitialState` service. The matching JS reader at
  `src/utils/loadInitialState.js` returns a typed default-filled object for
  the workspace and admin pages. Both sides stamp / validate a
  `_schemaVersion` constant; deploy skew between PHP and JS surfaces as a
  console warning at runtime.

  Adding, removing, or renaming a key requires four coordinated edits in
  the same commit:
  1. update the spec Data Model in
     `openspec/specs/initial-state-contract/spec.md`,
  2. bump `INITIAL_STATE_SCHEMA_VERSION` in
     `lib/Service/InitialStateBuilder.php` AND
     `src/utils/loadInitialState.js`,
  3. add (or remove) the typed setter in the PHP builder and the matching
     entry in the JS reader's `PAGE_KEYS` table,
  4. update the controller(s) that call the builder.

  CI guards (`composer lint:initial-state`, `npm run lint:initial-state`)
  forbid direct `IInitialState::provideInitialState()` calls outside the
  builder and direct `loadState('mydash', ...)` calls outside the reader.

## 0.1.0 - Initial Release

- Initial app structure
- Basic Nextcloud integration
