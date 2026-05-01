# Tasks — dashboard-switcher-sidebar

## 1. Component

- [ ] 1.1 Create `src/components/Workspace/DashboardSwitcherSidebar.vue` with props (`isOpen`, `groupName`, `groupDashboards`, `userDashboards`, `activeDashboardId`, `allowUserDashboards`) and emits (`switch`, `create-dashboard`, `delete-dashboard`, `update:open`) per REQ-SWITCH-001..007
- [ ] 1.2 Add internal computed `matchedGroupDashboards = groupDashboards.filter(d => d.source !== 'default')` and `defaultGroupDashboards = groupDashboards.filter(d => d.source === 'default')` (REQ-SWITCH-001)
- [ ] 1.3 Use `<IconRenderer>` for every icon (no inline `v-if="iconUrl"` branches in this template) (REQ-SWITCH-007)
- [ ] 1.4 CSS root: `position: fixed; top: 50px; width: 280px; z-index: 1500; transform: translateX(-100%); transition: transform .25s ease` (REQ-SWITCH-006)
- [ ] 1.5 `&.open` selector toggles `transform: translateX(0)` when `isOpen === true` (REQ-SWITCH-006)
- [ ] 1.6 Personal-row delete button: `display: none` by default, `inline-flex` on row hover; click handler uses `@click.stop` and emits `delete-dashboard(id)` only (REQ-SWITCH-004)
- [ ] 1.7 Active-item highlight: row with `id === activeDashboardId` gets `.active` class with `--color-primary-element-light` background and `--color-primary` icon tint (REQ-SWITCH-003)
- [ ] 1.8 Click on dashboard row emits `update:open(false)` THEN `switch(id, source)` where `source` is derived from the row's section (REQ-SWITCH-002)
- [ ] 1.9 `+ New Dashboard` row only rendered when `allowUserDashboards === true`; click emits `update:open(false)` THEN `create-dashboard()` (REQ-SWITCH-005)
- [ ] 1.10 Add companion `src/components/Workspace/SidebarBackdrop.vue` (click-to-close backdrop) for the runtime shell to wire alongside the sidebar

## 2. Integration

- [ ] 2.1 Wire `<DashboardSwitcherSidebar>` from `src/views/WorkspaceApp.vue` (runtime-shell) with `v-model:open="sidebarOpen"`
- [ ] 2.2 Parent maps `@switch` payload `(id, source)` to the correct API endpoint per REQ-DASH-013 (`source === 'user' → personal endpoint`, `'group' → group endpoint`, `'default' → default-group endpoint`)
- [ ] 2.3 Parent handles `@create-dashboard` per REQ-DASH-020 (or current personal-create endpoint) and `@delete-dashboard` per REQ-DASH-005
- [ ] 2.4 Parent renders `<SidebarBackdrop>` when `sidebarOpen === true`; clicking the backdrop sets `sidebarOpen = false`

## 3. Tests

- [ ] 3.1 Vitest: section visibility table — three sections × empty/non-empty matrix (REQ-SWITCH-001)
- [ ] 3.2 Vitest: emit order on switch — `update:open(false)` MUST be emitted before `switch(id, source)` (REQ-SWITCH-002)
- [ ] 3.3 Vitest: `source` discriminator on switch matches the section the row was rendered in (REQ-SWITCH-002)
- [ ] 3.4 Vitest: `delete-dashboard` does not also emit `switch` or `update:open` (REQ-SWITCH-004)
- [ ] 3.5 Vitest: `+ New Dashboard` row absent from the DOM when `allowUserDashboards: false` (REQ-SWITCH-005)
- [ ] 3.6 Vitest: `.active` class is on exactly the row whose id matches `activeDashboardId`, and updates reactively when the prop changes (REQ-SWITCH-003)
- [ ] 3.7 Playwright: hover reveals delete button on personal items only; group/default items have no delete affordance (REQ-SWITCH-004)
- [ ] 3.8 Playwright: clicking the backdrop or the topbar hamburger closes the sidebar; clicking the sidebar itself does not
- [ ] 3.9 Playwright: open/close animation completes in ~250 ms with `transform: translateX` driving the slide (REQ-SWITCH-006)

## 4. Quality

- [ ] 4.1 ESLint + Stylelint clean
- [ ] 4.2 Translation entries present in catalogue: `'Dashboards'`, `'Default'`, `'My Dashboards'`, `'+ New Dashboard'`, `'Delete dashboard'`, `'Close'`
- [ ] 4.3 WCAG: keyboard focus trap inside open sidebar, Esc key closes the sidebar (emits `update:open(false)`)
- [ ] 4.4 WCAG: every actionable row exposes an accessible name; delete button has `aria-label="Delete dashboard"`
