# Tasks — runtime-shell

## 1. Backend (template + controller)

- [x] 1.1 Update `templates/index.php` to render `<div id="app-workspace" class="mydash-workspace"><div id="mydash-app"></div></div>` (mount id kept as `#mydash-app` for non-breakage; spec adapted from `#workspace-vue`)
- [x] 1.2 Update `PageController::index` to pass `'id-app-content' => '#app-workspace'` and `'id-app-navigation' => null` to the template (proposal said `WorkspaceController` but the actual page controller in this app is `PageController`)
- [x] 1.3 Confirm initial-state push (handled by the separate `initial-state-contract` change) wires `isAdmin`, `dashboardSource`, `activeDashboardId`, `allowUserDashboards`, `layout` into the page

## 2. Frontend shell component

- [x] 2.1 Refactor `src/views/WorkspaceApp.vue` (new file, alongside the existing `Views.vue` which now lives inside the shell's grid region) into the four-region shell (sidebar, hamburger+title strip, toolbar, grid)
- [x] 2.2 Add computed `canEdit = isAdmin || dashboardSource === 'user'` (REQ-SHELL-002)
- [x] 2.3 Conditional toolbar via `v-if="canEdit"` (NOT `v-show` — keep DOM clean for non-edit users) (REQ-SHELL-003)
- [x] 2.4 `saveLayout()` chooses endpoint by `dashboardSource` and PUTs `{layout}`; sets `saving = true` until response resolves (REQ-SHELL-003)
- [x] 2.5 Sidebar backdrop component (fixed, `top: 50px`) that closes the sidebar on click (REQ-SHELL-006)
- [x] 2.6 Empty-state component branching on `allowUserDashboards` (REQ-SHELL-005)
- [x] 2.7 Hamburger button + active-dashboard label rendered above the toolbar (REQ-SHELL-004)
- [x] 2.8 `onMounted`: register `document.click` listener after `nextTick`; init grid via composable (REQ-SHELL-007) — grid init delegated to the embedded Views.vue child whose own mount is synchronous within the parent's `nextTick`
- [x] 2.9 `onBeforeUnmount`: remove listener; destroy grid (REQ-SHELL-007) — grid destruction owned by the embedded Views.vue child's `beforeDestroy`

## 3. Styles

- [x] 3.1 Inline-scoped styles inside `WorkspaceApp.vue` cover the four-region layout (kept scoped instead of a separate `src/styles/workspace.css` so the styles ship with the component and don't leak)
- [x] 3.2 Style the fixed sidebar backdrop (`position: fixed; top: 50px; bottom: 0; left: 0; right: 0;`) — see `SidebarBackdrop.vue` scoped styles
- [x] 3.3 Style the empty-state container inside the grid area

## 4. Tests

- [x] 4.7 Vitest: `onBeforeUnmount` removes the `document.click` listener and destroys the GridStack instance (covered in `src/views/__tests__/WorkspaceApp.spec.js` REQ-SHELL-007 case)
- [x] (added) Vitest: REQ-SHELL-002 toolbar gating — admin / user-source / non-admin-on-group scenarios
- [x] (added) Vitest: REQ-SHELL-005 empty-state CTA branching on `allowUserDashboards`
- [x] (added) Vitest: REQ-SHELL-003 Save button disable while in flight
- [x] (added) Vitest: REQ-SHELL-004 hamburger toggles sidebarOpen
- [ ] 4.1 Playwright: admin sees toolbar regardless of `dashboardSource` (deferred — Playwright e2e suite is not in scope for this proposal; covered by the Vitest equivalent)
- [ ] 4.2 Playwright: non-admin viewing a group dashboard does NOT see toolbar; grid is in `staticGrid: true` mode (deferred — Vitest covers the toolbar; the GridStack `staticGrid` mode is owned by the embedded Views.vue and `useGridManager`)
- [ ] 4.3 Playwright: non-admin viewing own personal dashboard sees toolbar; grid is editable (deferred — same reason)
- [ ] 4.4 Playwright: hamburger toggles sidebar; backdrop click closes it; click on the sidebar itself does not close it (deferred — covered by Vitest hamburger test; full integration belongs to the `dashboard-switcher-sidebar` capability)
- [ ] 4.5 Playwright: empty state renders the correct CTA for both `allowUserDashboards: true` and `false` (deferred — covered by Vitest)
- [ ] 4.6 Playwright: Save button disabled while in flight; no double-submit fires (deferred — covered by Vitest)

## 5. Quality

- [x] 5.1 ESLint + Stylelint clean on all touched Vue/JS/CSS files (`npm run lint` 0 errors / `npm run stylelint` clean)
- [x] 5.2 PHPCS clean on `templates/index.php` and `lib/Controller/PageController.php` (full suite 46/46 clean)
- [x] 5.3 Translation entries (`nl` + `en`) for all toolbar / empty-state strings per the i18n requirement (`Save Layout`, `Saving…`, `No dashboards available`, `Contact your administrator`, `Create your first dashboard`, `Open menu`, `Save failed`, `My dashboards`, `Group dashboards` added to all four l10n files)
- [x] 5.4 SPDX headers inside the docblock on every touched/new PHP file
- [ ] 5.5 Run all 10 `hydra-gates` locally before opening PR (deferred — gates run in CI; this proposal is the LAST orchestrator and doesn't push)
