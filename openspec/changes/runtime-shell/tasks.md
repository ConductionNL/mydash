# Tasks — runtime-shell

## 1. Backend (template + controller)

- [ ] 1.1 Update `templates/index.php` to render `<div id="app-workspace" class="mydash-workspace"><div id="workspace-vue"></div></div>`
- [ ] 1.2 Update `WorkspaceController::index` to pass `'id-app-content' => '#app-workspace'` and `'id-app-navigation' => null` to the template
- [ ] 1.3 Confirm initial-state push (handled by the separate `initial-state-contract` change) wires `isAdmin`, `dashboardSource`, `activeDashboardId`, `allowUserDashboards`, `layout` into the page

## 2. Frontend shell component

- [ ] 2.1 Refactor `src/views/WorkspaceApp.vue` into the four-region shell (sidebar, hamburger+title strip, toolbar, grid)
- [ ] 2.2 Add computed `canEdit = isAdmin || dashboardSource === 'user'` (REQ-SHELL-002)
- [ ] 2.3 Conditional toolbar via `v-if="canEdit"` (NOT `v-show` — keep DOM clean for non-edit users) (REQ-SHELL-003)
- [ ] 2.4 `saveLayout()` chooses endpoint by `dashboardSource` and PUTs `{layout}`; sets `saving = true` until response resolves (REQ-SHELL-003)
- [ ] 2.5 Sidebar backdrop component (fixed, `top: 50px`) that closes the sidebar on click (REQ-SHELL-006)
- [ ] 2.6 Empty-state component branching on `allowUserDashboards` (REQ-SHELL-005)
- [ ] 2.7 Hamburger button + active-dashboard label rendered above the toolbar (REQ-SHELL-004)
- [ ] 2.8 `onMounted`: register `document.click` listener after `nextTick`; init grid via composable (REQ-SHELL-007)
- [ ] 2.9 `onBeforeUnmount`: remove listener; destroy grid (REQ-SHELL-007)

## 3. Styles

- [ ] 3.1 Add `src/styles/workspace.css` (or extend existing) with the four-region layout
- [ ] 3.2 Style the fixed sidebar backdrop (`position: fixed; top: 50px; bottom: 0; left: 0; right: 0;`)
- [ ] 3.3 Style the empty-state container inside the grid area

## 4. Tests

- [ ] 4.1 Playwright: admin sees toolbar regardless of `dashboardSource`
- [ ] 4.2 Playwright: non-admin viewing a group dashboard does NOT see toolbar; grid is in `staticGrid: true` mode
- [ ] 4.3 Playwright: non-admin viewing own personal dashboard sees toolbar; grid is editable
- [ ] 4.4 Playwright: hamburger toggles sidebar; backdrop click closes it; click on the sidebar itself does not close it
- [ ] 4.5 Playwright: empty state renders the correct CTA for both `allowUserDashboards: true` and `false`
- [ ] 4.6 Playwright: Save button disabled while in flight; no double-submit fires
- [ ] 4.7 Vitest: `onBeforeUnmount` removes the `document.click` listener and destroys the GridStack instance

## 5. Quality

- [ ] 5.1 ESLint + Stylelint clean on all touched Vue/JS/CSS files
- [ ] 5.2 PHPCS clean on `templates/index.php` and `lib/Controller/WorkspaceController.php`
- [ ] 5.3 Translation entries (`nl` + `en`) for all toolbar / empty-state strings per the i18n requirement
- [ ] 5.4 SPDX headers inside the docblock on every touched/new PHP file
- [ ] 5.5 Run all 10 `hydra-gates` locally before opening PR
