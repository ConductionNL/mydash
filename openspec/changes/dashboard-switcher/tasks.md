# Tasks — dashboard-switcher

## Component Implementation

- [ ] Task 1: Create `src/components/DashboardSwitcher.vue` — Vue 2 Options API, scoped styles, Pinia store for sidebar state
- [ ] Task 2: Props: `isOpen` (Boolean, required), `groupName` (String, optional, default `'Dashboards'`), `groupDashboards` (Array, required), `userDashboards` (Array, required), `activeDashboardId` (String, optional), `allowUserDashboards` (Boolean, optional)
- [ ] Task 3: Emits: `update:open(boolean)`, `switch(id, source)`, `create-dashboard()`, `delete-dashboard(id)`
- [ ] Task 4: REQ-SWITCH-001 — Implement three-section rendering: primary group (source !== 'default'), default group (source === 'default'), personal (userDashboards). Each section has a label (computed via `groupName || t('Dashboards')`, `t('Default')`, `t('My Dashboards')`). Omit empty sections entirely (no empty container, no label).
- [ ] Task 5: REQ-SWITCH-001 — Render horizontal dividers between adjacent non-empty sections (exactly 2 dividers when all 3 sections present; 1 divider when 2 sections; 0 when 1 section)
- [ ] Task 6: REQ-SWITCH-002 — Dashboard row click handler: emit `update:open(false)` first, then emit `switch(id, source)`. Source is derived from section context ('group'/'default'/'user'), not from the dashboard object's internal type field.
- [ ] Task 7: REQ-SWITCH-003 — Apply CSS class `.active` to the dashboard item where `id === activeDashboardId`. Only one item may be active. Update reactively when prop changes.
- [ ] Task 8: REQ-SWITCH-003 — Style active items with `background-color: var(--color-primary-element-light)` and icon tint `color: var(--color-primary)`
- [ ] Task 9: REQ-SWITCH-004 — Personal-dashboard rows (excluding the Add-Dashboard card): render a delete button (small close icon via `NcIcon`, component: `Close`). Button hidden by default (`display: none`), visible on row hover (`display: inline-flex`)
- [ ] Task 10: REQ-SWITCH-004 — Delete button click handler: use `@click.stop` to prevent row's switch event, emit `delete-dashboard(id)`, do NOT emit `update:open(false)`
- [ ] Task 11: REQ-SWITCH-006 — Container CSS: `position: fixed`, `top: 50px`, `left: 0`, `width: 280px`, `z-index: 1500`. Initial `transform: translateX(-100%)` when `isOpen: false`.
- [ ] Task 12: REQ-SWITCH-006 — When `isOpen: true`, add CSS class `.open` which sets `transform: translateX(0)`. Transition: `transform 0.25s ease`
- [ ] Task 13: REQ-SWITCH-006 — Sidebar height: calculate to account for header offset (top: 50px) and footer. Use `calc(100vh - 50px)` with `overflow-y: auto` on the list container
- [ ] Task 14: REQ-SWITCH-007 — Import `IconRenderer` from the `dashboard-icons` capability. Each dashboard row: render the icon via `<IconRenderer :icon="dashboard.icon" />`. No branching on `isCustomIconUrl`.
- [ ] Task 15: REQ-SWITCH-008 — Render a dedicated "Add Dashboard" button card only when `allowUserDashboards === true`. Card is inside the scroll container (not the footer), positioned below the personal-dashboards list
- [ ] Task 16: REQ-SWITCH-008 — Add-Dashboard card: `NcButton` with `type="outline"`, full sidebar width, render a `+` icon (use `Plus` component from @conduction/nextcloud-vue), label from `t('mydash', 'Add dashboard')`
- [ ] Task 17: REQ-SWITCH-008 — Add-Dashboard button click: emit `update:open(false)` first, then emit `create-dashboard()`
- [ ] Task 18: REQ-SWITCH-009 — Render a sticky footer at the bottom: `position: sticky`, `bottom: 0`, separated from the list above by a `<hr />` divider
- [ ] Task 19: REQ-SWITCH-009 — Footer: "Powered by" line with Sendent logo (link to sendent.nl) and Conduction logo (link to conduction.nl). Both links: `target="_blank"` and `rel="noopener noreferrer"`. Use static URLs or load from config if available.
- [ ] Task 20: REQ-SWITCH-009 — Footer: Documentation link rendered as an icon + label `t('mydash', 'Documentation')`. Link target: preserved from the gear menu's Documentation URL (retrieve from app config or settings endpoint)
- [ ] Task 21: REQ-SWITCH-009 — Footer styling: `position: sticky`, light background via Nextcloud CSS var `var(--color-background-secondary)`, padding and border-top divider

## Parent Integration

- [ ] Task 22: Integrate `DashboardSwitcher.vue` into `src/App.vue` — import the component and register in `components: {}`
- [ ] Task 23: Wire `v-model:open` binding to the sidebar state: `:isOpen="sidebarOpen"` and `@update:open="sidebarOpen = $event"`
- [ ] Task 24: Pass dashboard data props from the component's data/store: `:groupName`, `:groupDashboards`, `:userDashboards`, `:activeDashboardId`, `:allowUserDashboards`
- [ ] Task 25: Handle `@switch` event in App.vue: call the store action to switch dashboards and navigate if needed; update `activeDashboardId` in the store
- [ ] Task 26: Handle `@create-dashboard` event in App.vue: emit or call the parent's create flow (may open a dialog, navigate to a form, etc.)
- [ ] Task 27: Handle `@delete-dashboard` event in App.vue: call the delete endpoint or store action; show a success/error toast; conditionally close the sidebar after deletion

## Styling & CSS

- [ ] Task 28: Create `src/styles/dashboard-switcher.css` with all component styles. Use ONLY Nextcloud CSS variables (`--color-*`). NO hardcoded colors, NO `--nldesign-*` refs.
- [ ] Task 29: Styles: section headings, dividers, dashboard rows (padding, hover state), delete button (hidden/visible), active class styling, animation, footer styling
- [ ] Task 30: Test CSS in light and dark modes (if nldesign is integrated) — colors must respect `prefers-color-scheme`

## Translations

- [ ] Task 31: Add English (en.json) translations: `'Dashboards'`, `'Default'`, `'My Dashboards'`, `'Add dashboard'`, `'Documentation'`, `'Powered by'`
- [ ] Task 32: Add Dutch (nl.json) translations: `'Dashboards'` → `'Dashboards'`, `'Default'` → `'Standaard'`, `'My Dashboards'` → `'Mijn dashboards'`, `'Add dashboard'` → `'Dashboard toevoegen'`, `'Documentation'` → `'Documentatie'`, `'Powered by'` → `'Mogelijk gemaakt door'`
- [ ] Task 33: Extract translation keys from the component into `xgettext` scanning (ensure `.vue` files are scanned by the build's i18n extraction)

## Testing

- [ ] Task 34: Playwright test — all three sections render when present; dividers appear only between non-empty sections
- [ ] Task 35: Playwright test — click a dashboard row emits `update:open(false)` then `switch(id, source)` with correct source ('group'/'default'/'user')
- [ ] Task 36: Playwright test — active item highlighted correctly; highlight updates when `activeDashboardId` prop changes
- [ ] Task 37: Playwright test — personal dashboard item hover shows delete button; delete button click emits `delete-dashboard(id)` without emitting `switch` or `update:open(false)`
- [ ] Task 38: Playwright test — sidebar animates from off-screen (`translateX(-100%)`) to on-screen (`translateX(0)`) over 250 ms when `isOpen` toggles; computed style matches after animation completes
- [ ] Task 39: Playwright test — all three dashboard icons render correctly (built-in MDI, custom URL, null) via `IconRenderer` without branching in the template
- [ ] Task 40: Playwright test — Add-Dashboard button is visible only when `allowUserDashboards: true`; clicking it emits `update:open(false)` then `create-dashboard()`
- [ ] Task 41: Playwright test — footer always visible; brand logos and Documentation link render with correct target/rel attributes; footer remains visible while scrolling 30+ dashboards
- [ ] Task 42: Playwright test — mobile viewport (320px width): sidebar's 280px width + gap is within bounds; all interactive elements remain clickable at touch size (44px+ preferred)

## Quality & Integration

- [ ] Task 43: ESLint + Stylelint — all `.vue` and `.css` files pass linting with zero warnings
- [ ] Task 44: Type checking — if using TypeScript, `tsc --noEmit` passes; Vue component types are inferred correctly or explicitly annotated
- [ ] Task 45: Security gate `hydra-gate-modal-isolation` — if the component opens any modal/dialog, it MUST be in its own `.vue` file under `src/modals/` or `src/dialogs/`, not inline
- [ ] Task 46: Nextcloud CSS compliance — all colors use `var(--color-*)` variables; no hardcoded `#hex` or `rgb()` colors; theme switching works (if nldesign is available, test with different token sets)
- [ ] Task 47: Accessibility (WCAG AA) — all interactive elements are keyboard-navigable (Tab/Shift+Tab); delete button has sufficient contrast and labels; section headings are semantic (`<h3>` or appropriate level); focus indicator is visible
- [ ] Task 48: Documentation — add a brief entry to the changelog mentioning the new dashboard switcher sidebar, its location, and key features (switching, create/delete personal dashboards, footer branding)

## Verification

`openspec validate` exits clean. Sidebar renders all three sections correctly; dashboard switching closes the sidebar and calls the switch handler; personal dashboards can be deleted; Add-Dashboard affordance is visible and works; footer stays visible while scrolling; all interactive elements are responsive and accessible.

## Tests (company-wide ADR-009)

Playwright end-to-end tests per Tasks 34–42. Unit tests for computed properties (section filtering, active-item detection, label localization) if complexity warrants; otherwise integration tests via Playwright.

## Documentation (company-wide ADR-010)

Changelog entry per Task 48. Inline component docstring (JSDoc) documenting props, emits, and main behavioral features. No separate design document needed beyond `design.md`.

## i18n (company-wide ADR-005)

English and Dutch per Tasks 31–33. Extraction via existing i18n build pipeline.
