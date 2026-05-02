---
capability: runtime-shell
delta: true
status: draft
---

# Runtime Shell — Delta from change `runtime-shell`

## ADDED Requirements

### Requirement: REQ-SHELL-001 Single mount point

The system MUST render the workspace Vue app into exactly one DOM element with id `workspace-vue`, located inside a `<div id="app-workspace" class="mydash-workspace">` provided by `templates/index.php`. Nextcloud's chrome MUST treat `#app-workspace` as the main content slot (`'id-app-content' => '#app-workspace'`). No left navigation slot MUST be allocated by the chrome (`'id-app-navigation' => null`) — the shell renders its own slide-in sidebar instead.

#### Scenario: Mount point present

- GIVEN the user has navigated to the workspace page
- WHEN the page HTML is rendered
- THEN the rendered HTML MUST contain exactly one `<div id="workspace-vue">`
- AND it MUST be a child of `<div id="app-workspace">`
- AND no Nextcloud chrome navigation panel MUST be rendered

### Requirement: REQ-SHELL-002 Edit-mode permission rule

The shell MUST expose a computed `canEdit` evaluated as `isAdmin || dashboardSource === 'user'`. When `canEdit` is `false`, the edit toolbar (Add Widget + Save buttons) and the right-click context menu MUST NOT be reachable; the GridStack instance MUST be in `staticGrid: true` mode. When `canEdit` is `true`, all editing affordances MUST be visible and the grid MUST permit drag/resize.

#### Scenario: Admin can edit any dashboard

- GIVEN injected initial state `isAdmin: true, dashboardSource: 'group'`
- WHEN the workspace renders
- THEN `canEdit` MUST be `true`
- AND the toolbar MUST be visible
- AND the grid MUST allow drag/resize

#### Scenario: User can edit own personal dashboard

- GIVEN initial state `isAdmin: false, dashboardSource: 'user'`
- WHEN the workspace renders
- THEN `canEdit` MUST be `true`
- AND the toolbar MUST be visible
- AND the grid MUST allow drag/resize

#### Scenario: User cannot edit a group-shared dashboard

- GIVEN initial state `isAdmin: false, dashboardSource: 'group'`
- WHEN the workspace renders
- THEN `canEdit` MUST be `false`
- AND the toolbar MUST NOT be present in the DOM (`v-if`, not `v-show`)
- AND right-clicking a widget MUST NOT open the context menu
- AND the grid MUST be in `staticGrid: true` mode

### Requirement: REQ-SHELL-003 Toolbar contents

When `canEdit` is true, the toolbar MUST render exactly two affordances: an **Add Widget** dropdown button (sourced from the widget type registry — see `widget-add-edit-modal`) and a **Save Layout** button. Selecting an Add Widget option opens the modal pre-filled with that type. The Save Layout button MUST be disabled while a save request is in flight, and on click it MUST call `saveLayout()` which PUTs to `/api/dashboards/{uuid}` with `{layout: layout.value}` then toasts success or error.

#### Scenario: Add-widget dropdown lists all widget types

- GIVEN the widget-type registry contains 5 entries
- WHEN the user opens the Add Widget dropdown
- THEN it MUST display 5 menu items, one per registered type
- AND each item MUST be labelled with the type's translated display name

#### Scenario: Save sends layout to correct endpoint

- GIVEN `dashboardSource: 'user'` and `activeDashboardId: 'abc'`
- WHEN the user clicks Save
- THEN the system MUST send `PUT /api/dashboards/abc` with body `{layout: <current widgets>}`
- AND show a success toast on 200
- AND show an error toast on 4xx or 5xx

#### Scenario: Save button disabled while in flight

- GIVEN a Save request is in flight
- WHEN the user attempts to click Save again
- THEN the button MUST be disabled (HTML `disabled` attribute set)
- AND no second request MUST fire

### Requirement: REQ-SHELL-004 Sidebar toggle and active-dashboard label

The shell MUST render a hamburger button plus a label showing the active dashboard's name (when one exists), placed immediately above the toolbar. The hamburger button MUST toggle `sidebarOpen`. The label MUST be empty when no active dashboard is resolved.

#### Scenario: Hamburger toggles sidebar

- GIVEN `sidebarOpen` is `false`
- WHEN the user clicks the hamburger
- THEN `sidebarOpen` MUST become `true`
- AND the sidebar MUST animate in
- AND clicking the hamburger again MUST close it

#### Scenario: Active-dashboard name visible

- GIVEN active dashboard `D` has `name = "Marketing Overview"`
- WHEN the workspace renders
- THEN the label next to the hamburger MUST display `"Marketing Overview"`

#### Scenario: Empty label on empty state

- GIVEN no active dashboard is resolved (resolver returned null)
- WHEN the workspace renders
- THEN the label MUST be empty
- AND the empty-state component MUST render in the grid area instead

### Requirement: REQ-SHELL-005 Empty state

When the resolver returned no active dashboard, the shell MUST render an empty-state UI inside the grid container with: a friendly message ("You have no dashboards yet"), an explanation, and — if `allowUserDashboards` is `true` — a primary "Create your first dashboard" button that calls the create-personal flow. When `allowUserDashboards` is `false` no Create button MUST be shown.

#### Scenario: Empty state with creation enabled

- GIVEN no active dashboard is resolved
- AND `allowUserDashboards` is `true`
- WHEN the workspace renders
- THEN the empty-state MUST render with a "Create your first dashboard" button
- AND clicking it MUST call `POST /api/dashboards` with a default name

#### Scenario: Empty state with creation disabled

- GIVEN no active dashboard is resolved
- AND `allowUserDashboards` is `false`
- WHEN the workspace renders
- THEN the empty-state MUST render with a message explaining personal dashboards are disabled
- AND no "Create" button MUST be present

### Requirement: REQ-SHELL-006 Sidebar backdrop

When `sidebarOpen` is `true`, the shell MUST render a fixed-position backdrop that intercepts clicks and closes the sidebar. The backdrop MUST start at the same `top` offset as the Nextcloud header (50 px) and span the rest of the viewport. Clicks on the sidebar itself MUST NOT close the sidebar.

#### Scenario: Backdrop closes sidebar on click

- GIVEN `sidebarOpen` is `true`
- WHEN the user clicks anywhere in the backdrop area
- THEN `sidebarOpen` MUST become `false`

#### Scenario: Click on the sidebar itself does not close it

- GIVEN `sidebarOpen` is `true`
- WHEN the user clicks on a non-actionable area of the sidebar panel
- THEN `sidebarOpen` MUST remain `true`

### Requirement: REQ-SHELL-007 Lifecycle hooks

The shell MUST register a global `document.click` listener on mount (delegated to the grid composable's `handleClickOutside`) and remove it on unmount. The GridStack instance MUST be initialised after `nextTick()` (so the grid container ref is non-null) and destroyed on unmount.

#### Scenario: Listener and grid registered after mount

- GIVEN the shell component is being mounted
- WHEN the `onMounted` hook runs
- THEN `document.addEventListener('click', handleClickOutside)` MUST have been called
- AND after `nextTick()` the GridStack instance MUST be initialised against the grid container ref

#### Scenario: Listener cleanup on unmount

- GIVEN the shell has mounted and registered the click listener
- WHEN the shell unmounts (e.g. user navigates away)
- THEN `document.removeEventListener('click', handleClickOutside)` MUST be called
- AND the GridStack instance MUST be destroyed (no DOM leftover, no memory leak)
