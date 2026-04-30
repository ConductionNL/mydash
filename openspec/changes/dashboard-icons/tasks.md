# Tasks — dashboard-icons

## 1. Frontend registry module

- [ ] 1.1 Create `src/constants/dashboardIcons.js` exporting `DASHBOARD_ICONS`, `DEFAULT_ICON`, `getIconComponent`, `isCustomIconUrl`
- [ ] 1.2 Add 15 separate `import …Icon from 'vue-material-design-icons/<Name>.vue'` statements (no wildcard / barrel imports — see REQ-ICON-004)
- [ ] 1.3 Implement `getIconComponent(name)` so it returns the registry component, falling back to `DASHBOARD_ICONS[DEFAULT_ICON]` on null / undefined / empty / unknown (REQ-ICON-001, REQ-ICON-002)
- [ ] 1.4 Implement `isCustomIconUrl(name)` returning true when name is a non-empty string starting with `/` or `http` (consumed by `custom-icon-upload-pattern`)
- [ ] 1.5 Set `DEFAULT_ICON = 'ViewDashboard'` and assert `DASHBOARD_ICONS[DEFAULT_ICON]` exists at module load

## 2. Reusable renderer component

- [ ] 2.1 Create `src/components/Dashboard/IconRenderer.vue` accepting prop `name: string|null` and `size: number = 20`
- [ ] 2.2 Branch in template: when `isCustomIconUrl(name)` render `<img :src="name" :width="size" :height="size" alt="">`
- [ ] 2.3 Otherwise render `<component :is="getIconComponent(name)" :size="size" />`
- [ ] 2.4 Add component-level docblock noting the URL branch is foundation for `custom-icon-upload-pattern`

## 3. Refactor existing consumers

- [ ] 3.1 Replace hardcoded MDI icon imports inside `DashboardSwitcher` with `<IconRenderer :name="dash.icon" />`
- [ ] 3.2 Replace hardcoded MDI imports in the admin dashboard list with `<IconRenderer :name="dash.icon" />`
- [ ] 3.3 Replace hardcoded MDI imports in the tile editor with `<IconRenderer :name="tile.icon" />`
- [ ] 3.4 Add an icon picker `<select>` driven by `Object.keys(DASHBOARD_ICONS)` in dashboard create/edit form (REQ-ICON-003)
- [ ] 3.5 Grep audit: no `vue-material-design-icons/<Name>.vue` import remains outside `dashboardIcons.js` for dashboard contexts

## 4. Backend annotation (no schema change)

- [ ] 4.1 Add a docblock on `lib/Db/Dashboard.php`'s `icon` field describing the convention (NULL or registry name or URL)
- [ ] 4.2 Confirm no migration is needed (column already exists on `oc_mydash_dashboards`)

## 5. Tests

- [ ] 5.1 Vitest: `getIconComponent` resolution table — built-in name, default, null, undefined, empty string, unknown name
- [ ] 5.2 Vitest: `DASHBOARD_ICONS` length is at least 15 and contains every name from REQ-ICON-001
- [ ] 5.3 Vitest: `isCustomIconUrl` returns true for `/foo.svg` and `https://x/y.png`, false for `'Star'`, `''`, `null`
- [ ] 5.4 Visual snapshot (Storybook or equivalent): all 15 icons rendered at size 20 and 32

## 6. Quality gates

- [ ] 6.1 ESLint clean on `src/constants/dashboardIcons.js` and `src/components/Dashboard/IconRenderer.vue`
- [ ] 6.2 Bundle-size check: production `main.js` delta ≤ 8 KB gzipped (the 15 icon SVGs)
- [ ] 6.3 PHPCS clean on `lib/Db/Dashboard.php` if the docblock change touches it
