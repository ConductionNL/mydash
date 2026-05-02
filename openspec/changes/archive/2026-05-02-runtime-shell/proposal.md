# Runtime workspace shell

## Why

The mydash runtime page chrome — the GridStack mount, the admin/edit toolbar, the save button, the dashboard-name header, the hamburger that toggles the sidebar, and the `canEdit` permission rule — has no formal capability home. It currently lives bundled inside a generic dashboard view component, conflating page-level coordination with dashboard data and the grid surface. The shell coordinates four sibling capabilities (`dashboard-switcher-sidebar`, `widget-add-edit-modal`, `widget-context-menu`, `grid-layout`) and gates editing affordances based on user role and active dashboard scope; it is the page-level orchestrator and warrants its own capability so the page-level interactions (sidebar open/close, save, toolbar visibility, empty state, lifecycle cleanup) can be specified in one place. This is the LAST capability to land — it depends on virtually everything else.

## What Changes

- Introduce a new `runtime-shell` capability owning the user-facing workspace page chrome.
- Add REQ-SHELL-001 (single mount point under `<div id="app-workspace">`).
- Add REQ-SHELL-002 (computed `canEdit = isAdmin || dashboardSource === 'user'` gates the toolbar, context menu, and `staticGrid` mode).
- Add REQ-SHELL-003 (toolbar contents: Add Widget dropdown + Save Layout button, with in-flight disable and PUT to `/api/dashboards/{uuid}`).
- Add REQ-SHELL-004 (hamburger sidebar toggle plus active-dashboard label).
- Add REQ-SHELL-005 (empty-state UI branching on `allowUserDashboards`).
- Add REQ-SHELL-006 (fixed sidebar backdrop starting at `top: 50px`).
- Add REQ-SHELL-007 (lifecycle: register `document.click` listener + GridStack init on mount, cleanup on unmount).
- Refactor `src/views/WorkspaceApp.vue` into a four-region shell (sidebar, hamburger+title strip, toolbar, grid).
- Update `templates/index.php` to render the dual-div mount (`#app-workspace > #workspace-vue`) and `WorkspaceController::index` to pass `'id-app-content' => '#app-workspace'` and `'id-app-navigation' => null`.

## Capabilities

### New Capabilities

- `runtime-shell` — page-level Vue component for the workspace experience; coordinates sibling capabilities and owns the page chrome. REQ-SHELL-001..007.

### Modified Capabilities

(none — this change is purely additive)

## Impact

**Affected code:**

- `templates/index.php` — Nextcloud page template: load the runtime bundle and provide `<div id="app-workspace" class="mydash-workspace"><div id="workspace-vue"></div></div>`
- `lib/Controller/WorkspaceController.php` — `GET /` page renderer; pass the new chrome slot ids in the template parameters (initial-state push lives in the separate `initial-state-contract` change)
- `src/views/WorkspaceApp.vue` — the shell component itself, refactored from a generic dashboard view
- `src/styles/workspace.css` — global styles for the shell (sidebar backdrop, toolbar, empty state)
- Consumes (no source changes here, just integration): `dashboard-switcher-sidebar`, `widget-add-edit-modal`, `widget-context-menu`, `dashboards`, `grid-layout`

**Affected APIs:**

- No new HTTP routes; the shell consumes existing `PUT /api/dashboards/{uuid}` and `POST /api/dashboards` endpoints.

**Dependencies:**

- Inherits initial-state contract from the `initial-state-contract` change (provides `isAdmin`, `dashboardSource`, `activeDashboardId`, `allowUserDashboards`, `layout` via `provide`/`inject`).
- Sibling capabilities (`dashboard-switcher-sidebar`, `widget-add-edit-modal`, `widget-context-menu`, `grid-layout`) MUST be in place before this capability ships — this is the LAST change to land.
- No new composer or npm dependencies.

**Notes:**

- The shell deliberately keeps NO local persistence layer of its own — every save delegates to existing dashboard endpoints.
- The Add Widget dropdown is consumed from `widget-add-edit-modal` (it owns the type → submit pipeline).
- The shell holds only local UI state (`sidebarOpen`, `saving`, `showAddDropdown`, `layout`, `activeDashboardId`, `dashboardSource`); all source-of-truth data flows from initial state via `inject()`.
- Conditional toolbar uses `v-if="canEdit"` (NOT `v-show`) so the DOM stays clean for non-edit users.

**Migration:**

- No data migration; pure frontend refactor + template + controller signature update.
