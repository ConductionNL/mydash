# Tasks — dashboard-icons

## Tasks

- [ ] Task 1: Create `src/constants/dashboardIcons.js` exporting `DASHBOARD_ICONS`, `DEFAULT_ICON`, `getIconComponent`, `isCustomIconUrl` with 15 explicit `import …Icon from 'vue-material-design-icons/<Name>.vue'` statements (no wildcard/barrel imports — REQ-ICON-004)
- [ ] Task 2: Implement `getIconComponent(name)` returning the registry component and falling back to `DASHBOARD_ICONS[DEFAULT_ICON]` on null/undefined/empty/unknown (REQ-ICON-001 + REQ-ICON-002)
- [ ] Task 3: Implement `isCustomIconUrl(name)` returning true when `name` is a non-empty string starting with `/` or `http` (consumed by `custom-icon-upload-pattern`)
- [ ] Task 4: Set `DEFAULT_ICON = 'ViewDashboard'` and assert `DASHBOARD_ICONS[DEFAULT_ICON]` exists at module load
- [ ] Task 5: Build `src/components/Dashboard/IconRenderer.vue` with props `name: string|null` + `size: number = 20`; template branches `<img :src="name" :width="size" :height="size" alt="">` when `isCustomIconUrl(name)`, otherwise `<component :is="getIconComponent(name)" :size="size" />`; docblock notes the URL branch is foundation for `custom-icon-upload-pattern`
- [ ] Task 6: Refactor `DashboardSwitcher`, the admin dashboard list, and the tile editor to use `<IconRenderer :name="dash.icon" />` / `<IconRenderer :name="tile.icon" />`; add an icon picker `<select>` driven by `Object.keys(DASHBOARD_ICONS)` in the dashboard create/edit form (REQ-ICON-003)
- [ ] Task 7: Grep audit — no `vue-material-design-icons/<Name>.vue` import remains outside `dashboardIcons.js` for dashboard contexts
- [ ] Task 8: Add a docblock on `lib/Db/Dashboard.php`'s `icon` field describing the convention (NULL or registry name or URL); confirm no migration is needed (column already exists on `oc_mydash_dashboards`)
- [ ] Task 9: Vitest — `getIconComponent` resolution table (built-in name, default, null, undefined, empty string, unknown name); `DASHBOARD_ICONS` length ≥ 15 and contains every name from REQ-ICON-001; `isCustomIconUrl` returns true for `/foo.svg` and `https://x/y.png`, false for `'Star'`, `''`, `null`
- [ ] Task 10: Visual snapshot (Storybook or equivalent) of all 15 icons rendered at size 20 and 32
- [ ] Task 11: Quality gates — ESLint clean on the new module + component; production `main.js` delta ≤ 8 KB gzipped (the 15 icon SVGs); PHPCS clean on `lib/Db/Dashboard.php`

## Verification

`openspec validate` exits clean. Icon picker covers all 15 registry entries and fallback returns the default icon on unknown names.

## Tests (company-wide ADR-009)

Vitest per Task 9; visual snapshot per Task 10. No backend surface.

## Documentation (company-wide ADR-010)

Changelog entry covering the new icon registry + renderer; PHP docblock per Task 8.

## i18n (company-wide ADR-005)

No user-facing strings introduced — icon labels surface via existing dashboard-name fields.
