# Tasks — dashboard-switcher-sidebar

## Tasks

- [ ] Task 1: Create `src/components/Workspace/DashboardSwitcherSidebar.vue` with props (`isOpen`, `groupName`, `groupDashboards`, `userDashboards`, `activeDashboardId`, `allowUserDashboards`) and emits (`switch`, `create-dashboard`, `delete-dashboard`, `update:open`) per REQ-SWITCH-001..007
- [ ] Task 2: Internal computeds `matchedGroupDashboards = groupDashboards.filter(d => d.source !== 'default')` and `defaultGroupDashboards = groupDashboards.filter(d => d.source === 'default')` (REQ-SWITCH-001)
- [ ] Task 3: Every icon rendered via `<IconRenderer>` (no inline `v-if="iconUrl"` branches in this template) — REQ-SWITCH-007
- [ ] Task 4: CSS root: `position: fixed; top: 50px; width: 280px; z-index: 1500; transform: translateX(-100%); transition: transform .25s ease`; `&.open` selector toggles `transform: translateX(0)` when `isOpen === true` (REQ-SWITCH-006)
- [ ] Task 5: Personal-row delete button — `display:none` by default, `inline-flex` on row hover; click handler uses `@click.stop` and emits `delete-dashboard(id)` only (REQ-SWITCH-004)
- [ ] Task 6: Active-item highlight — row with `id === activeDashboardId` gets `.active` class with `--color-primary-element-light` background + `--color-primary` icon tint (REQ-SWITCH-003)
- [ ] Task 7: Dashboard-row click emits `update:open(false)` THEN `switch(id, source)` where `source` is derived from the row's section (REQ-SWITCH-002)
- [ ] Task 8: `+ New Dashboard` row only rendered when `allowUserDashboards === true`; click emits `update:open(false)` THEN `create-dashboard()` (REQ-SWITCH-005)
- [ ] Task 9: Add companion `src/components/Workspace/SidebarBackdrop.vue` (click-to-close backdrop) for the runtime shell to wire alongside the sidebar
- [ ] Task 10: Wire `<DashboardSwitcherSidebar>` from `src/views/WorkspaceApp.vue` with `v-model:open="sidebarOpen"`; parent maps `@switch (id, source)` to the correct API per REQ-DASH-013 (user/group/default-group endpoints), handles `@create-dashboard` (REQ-DASH-020) + `@delete-dashboard` (REQ-DASH-005), and renders `<SidebarBackdrop>` whose click sets `sidebarOpen = false`
- [ ] Task 11: Vitest — section visibility table (3 sections × empty/non-empty); emit order on switch (`update:open(false)` BEFORE `switch(id, source)`); `source` discriminator matches the section the row was rendered in; `delete-dashboard` does NOT also emit `switch` or `update:open`; `+ New Dashboard` absent when `allowUserDashboards: false`; `.active` class is reactive to `activeDashboardId` changes
- [ ] Task 12: Playwright — hover reveals delete button only on personal items (group/default have no delete affordance); clicking backdrop or topbar hamburger closes the sidebar (clicking the sidebar itself does not); open/close animation completes ~250ms via `transform: translateX`
- [ ] Task 13: Quality + a11y — ESLint + Stylelint clean; translations present (`'Dashboards'`, `'Default'`, `'My Dashboards'`, `'+ New Dashboard'`, `'Delete dashboard'`, `'Close'`); keyboard focus trap inside open sidebar; Esc closes (emits `update:open(false)`); every actionable row has an accessible name; delete button has `aria-label="Delete dashboard"`

## Verification

`openspec validate` exits clean. Sidebar opens/closes via keyboard + backdrop; all three sections render correctly and the active row stays highlighted across switches.

## Tests (company-wide ADR-009)

Vitest per Task 11; Playwright per Task 12. No backend surface.

## Documentation (company-wide ADR-010)

Changelog entry covering the new sidebar component and the parent wiring contract.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 13.
