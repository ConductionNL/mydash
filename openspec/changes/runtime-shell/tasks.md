# Tasks — runtime-shell

## Tasks

- [ ] Task 1: Update `templates/index.php` to render `<div id="app-workspace" class="mydash-workspace"><div id="workspace-vue"></div></div>`; update `WorkspaceController::index` to pass `'id-app-content' => '#app-workspace'` + `'id-app-navigation' => null` to the template; confirm initial-state push (separate `initial-state-contract` change) wires `isAdmin`, `dashboardSource`, `activeDashboardId`, `allowUserDashboards`, `layout`
- [ ] Task 2: Refactor `src/views/WorkspaceApp.vue` into the four-region shell (sidebar, hamburger+title strip, toolbar, grid)
- [ ] Task 3: Add computed `canEdit = isAdmin || dashboardSource === 'user'` (REQ-SHELL-002)
- [ ] Task 4: Toolbar uses `v-if="canEdit"` (NOT `v-show` — keep DOM clean for non-edit users) per REQ-SHELL-003
- [ ] Task 5: `saveLayout()` chooses the endpoint by `dashboardSource` and PUTs `{layout}`; sets `saving = true` until the response resolves (REQ-SHELL-003)
- [ ] Task 6: Add a fixed sidebar backdrop component (`top:50px; bottom:0; left:0; right:0`) that closes the sidebar on click (REQ-SHELL-006); add the empty-state component branching on `allowUserDashboards` (REQ-SHELL-005); add hamburger button + active-dashboard label above the toolbar (REQ-SHELL-004)
- [ ] Task 7: Lifecycle — `onMounted` registers the `document.click` listener after `nextTick` and inits the grid via the composable; `onBeforeUnmount` removes the listener and destroys the grid (REQ-SHELL-007)
- [ ] Task 8: Styles — add `src/styles/workspace.css` (or extend existing) with the four-region layout, the fixed-sidebar backdrop styling, and the empty-state container styling inside the grid area
- [ ] Task 9: Playwright — admin sees toolbar regardless of `dashboardSource`; non-admin viewing a group dashboard does NOT see the toolbar and grid is in `staticGrid: true`; non-admin viewing own personal dashboard sees the toolbar and grid is editable
- [ ] Task 10: Playwright — hamburger toggles sidebar; backdrop click closes it; click on the sidebar itself does NOT close it; empty state renders the correct CTA for both `allowUserDashboards: true` and `false`; Save button disabled while in flight (no double-submit)
- [ ] Task 11: Vitest — `onBeforeUnmount` removes the `document.click` listener AND destroys the GridStack instance (no leaks)
- [ ] Task 12: Quality + i18n — ESLint+Stylelint clean on touched Vue/JS/CSS; PHPCS clean on `templates/index.php` + `lib/Controller/WorkspaceController.php`; SPDX-in-docblock on every touched/new PHP file; `nl_NL`+`en_US` translations for all toolbar + empty-state strings; all 10 hydra-gates green

## Verification

`openspec validate` exits clean. Shell renders the four regions correctly and toolbar visibility tracks `canEdit` exactly per the test matrix.

## Tests (company-wide ADR-009)

Playwright per Tasks 9–10; Vitest per Task 11. Backend touched only via template + controller — no new endpoints.

## Documentation (company-wide ADR-010)

Changelog entry covering the new shell layout + the `canEdit` rule.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` for all toolbar + empty-state strings per Task 12.
