# Tasks — dashboard-icons

## 1. Frontend registry module

- [x] 1.1 Create `src/constants/dashboardIcons.js` exporting `DASHBOARD_ICONS`, `DEFAULT_ICON`, `getIconComponent`, `isCustomIconUrl`
- [x] 1.2 Add 15 separate `import …Icon from 'vue-material-design-icons/<Name>.vue'` statements (no wildcard / barrel imports — see REQ-ICON-004)
- [x] 1.3 Implement `getIconComponent(name)` so it returns the registry component, falling back to `DASHBOARD_ICONS[DEFAULT_ICON]` on null / undefined / empty / unknown (REQ-ICON-001, REQ-ICON-002)
- [x] 1.4 Implement `isCustomIconUrl(name)` returning true when name is a non-empty string starting with `/` or `http` (consumed by `custom-icon-upload-pattern`)
- [x] 1.5 Set `DEFAULT_ICON = 'ViewDashboard'` and assert `DASHBOARD_ICONS[DEFAULT_ICON]` exists at module load

## 2. Reusable renderer component

- [x] 2.1 Create `src/components/Dashboard/IconRenderer.vue` accepting prop `name: string|null` and `size: number = 20`
- [x] 2.2 Branch in template: when `isCustomIconUrl(name)` render `<img :src="name" :width="size" :height="size" alt="">`
- [x] 2.3 Otherwise render `<component :is="getIconComponent(name)" :size="size" />`
- [x] 2.4 Add component-level docblock noting the URL branch is foundation for `custom-icon-upload-pattern`

## 3. Refactor existing consumers

- [x] 3.1 Replace hardcoded MDI icon imports inside `DashboardConfigMenu` (the actual switcher list) with `<IconRenderer :name="dashboard.icon" />`. (DashboardSwitcher.vue is the NcSelect wrapper that does not render per-item icons; REQ-ICON-003 only applies to the actual icon-rendering surfaces.)
- [x] 3.2 Admin dashboard list does not currently render dashboard icons (`AdminSettings.vue` lists templates without icons) — no refactor needed; left intact.
- [x] 3.3 Tile editor (`TileEditor.vue`) operates on a separate icon system (SVG paths via `@mdi/js` and NlDesign URLs with an `iconType` discriminator on `mydash_tiles.icon`). It is NOT a `dashboards.icon`-convention consumer and the spec context only mentions `widget_placements.tileIcon` as a hypothetical follow-up — out of scope for this base change. Documented in REVIEW notes.
- [x] 3.4 Add an icon picker `<select>` driven by `Object.keys(DASHBOARD_ICONS)` in `DashboardConfigModal` (the dashboard create/edit form) (REQ-ICON-003)
- [x] 3.5 Grep audit: no `vue-material-design-icons/<Name>.vue` import that resolves a `dashboard.icon` value remains outside `dashboardIcons.js` (the AccountGroup import in `DashboardConfigMenu` survives only as a fallback glyph for shared dashboards with no per-dashboard icon set; documented inline)

## 4. Backend (`icon` column + entity)

- [x] 4.1 Add a typed `?string $icon` field with a docblock on `lib/Db/Dashboard.php` describing the convention (NULL or registry key or URL)
- [x] 4.2 Add `Version001006Date20260502000000.php` to add the `icon` column on `mydash_dashboards` (the proposal's "no migration needed" was incorrect — the column did not previously exist; confirmed via `DashboardTableBuilder.php`). Also added the column to `DashboardTableBuilder` for fresh installs.
- [x] 4.3 Plumb `icon` through `DashboardApiController::create/update`, `DashboardService::createDashboard/applyDashboardUpdates`, and `Dashboard::jsonSerialize()`

## 5. Tests

- [x] 5.1 Vitest: `getIconComponent` resolution table — built-in name, default, null, undefined, empty string, unknown name
- [x] 5.2 Vitest: `DASHBOARD_ICONS` length is at least 15 and contains every name from REQ-ICON-001
- [x] 5.3 Vitest: `isCustomIconUrl` returns true for `/foo.svg` and `https://x/y.png`, false for `'Star'`, `''`, `null`
- [ ] 5.4 Visual snapshot (Storybook or equivalent): mydash has no Storybook infrastructure, deferred — Vitest covers behaviour and the bundle audit covers tree-shake; visual coverage is best added in a follow-up Storybook spec.

## 6. Quality gates

- [x] 6.1 ESLint clean on `src/constants/dashboardIcons.js` and `src/components/Dashboard/IconRenderer.vue`
- [x] 6.2 Bundle-size check: production build inspected; the 15 icons land in the bundle exactly once via the registry (no parallel imports introduced).
- [x] 6.3 PHPCS clean on `lib/Db/Dashboard.php`, the migration, the table builder, and the controller/service edits
