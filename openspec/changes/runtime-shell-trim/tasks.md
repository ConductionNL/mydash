# Tasks — runtime-shell-trim

## 1. Remove top toolbar

- [ ] 1.1 Delete the toolbar region (`<div class="mydash-workspace__toolbar">` or equivalent) from `src/views/WorkspaceApp.vue`
- [ ] 1.2 Remove `saving` local state, `saveLayout()` method, and any related computed/watch hooks now that the manual save button is gone
- [ ] 1.3 Drop the toolbar CSS grid row from `src/styles/workspace.css`; collapse the 4-region layout to 3 regions (sidebar / title strip / grid)
- [ ] 1.4 Verify auto-save (REQ-GRID-005, 300ms debounce) still fires on widget moves/resizes — no regression

## 2. Remove standalone active-dashboard select

- [ ] 2.1 Delete the `NcSelect` (or equivalent) bound to `injectedActiveDashboardId` from the title strip in `WorkspaceApp.vue`
- [ ] 2.2 Keep the active-dashboard NAME as a static `<h1>`/`<h2>` label (per REQ-SHELL-004 keeps the active-dashboard label, just not as a switcher control)
- [ ] 2.3 Remove the matching i18n keys for the select's placeholder/no-results states

## 3. Restyle hamburger toggle

- [ ] 3.1 Replace the bespoke hamburger button with `<NcButton type="tertiary" :aria-label="t('mydash', 'Open menu')"><template #icon><MenuIcon :size="20" /></template></NcButton>` matching the Nextcloud account-button shape
- [ ] 3.2 Hover/focus/active states inherit from NcButton; drop bespoke CSS
- [ ] 3.3 Verify keyboard focus ring matches the account button's ring exactly

## 4. Trim action menu

- [ ] 4.1 In the gear menu (`DashboardConfigMenu.vue` or equivalent), remove the "Add tile…" `NcActionButton`
- [ ] 4.2 Remove the "Add widget…" `NcActionButton` (legacy NC-widget picker — stays reachable via "Add custom widget" → NC-widget type per `unified-add-widget-flow`)
- [ ] 4.3 Remove the dashboards-list section (`NcActionRouter` or similar) — sidebar owns navigation
- [ ] 4.4 Remove the "Powered by Sendent / Conduction" footer block (moved to sidebar per `dashboard-switcher-extensions`)
- [ ] 4.5 Keep "Add custom widget…", "Save dashboard", "Dashboard configuration…", "Documentation" — these stay in the gear menu

## 5. Tests

- [ ] 5.1 Update `src/views/__tests__/WorkspaceApp.spec.js` — drop assertions on toolbar elements; add a negative assertion that no element with `data-test="add-widget-toolbar-button"` exists
- [ ] 5.2 Update `src/components/admin/__tests__/DashboardConfigMenu.spec.js` (or equivalent) — drop assertions on removed menu items; assert "Add custom widget" still present
- [ ] 5.3 Vitest: hamburger renders with NcButton (tertiary variant)
- [ ] 5.4 Vitest: title strip shows dashboard name as label, NOT as select control

## 6. Quality gates

- [ ] 6.1 ESLint clean on touched files
- [ ] 6.2 `npm run build` clean
- [ ] 6.3 Full vitest suite green
- [ ] 6.4 Stylelint clean on `workspace.css` after row removal
