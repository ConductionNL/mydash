---
status: implemented
---

# Runtime Shell Specification

## Purpose

The `runtime-shell` capability owns the user-facing workspace page chrome — the mount point, the sidebar toggle, the active-dashboard label strip, the empty-state branch, and the lifecycle hooks that bind it all together. It is the page-level orchestrator that coordinates four sibling capabilities (`dashboard-switcher`, `widget-add-edit-modal`, `widget-context-menu`, `grid-layout`) and gates editing affordances based on user role and active dashboard scope.

The shell deliberately holds NO source-of-truth data of its own — every key it consumes flows through the typed initial-state contract via `provide` / `inject`, and every persistence call routes through existing dashboard endpoints. Local state is restricted to UI-only fields (currently just `sidebarOpen` after `runtime-shell-trim` removed the toolbar's `saving` + `showAddDropdown` fields).

## Requirements

### Requirement: Single mount point (REQ-SHELL-001)

The system MUST render the workspace Vue app into exactly one DOM element (id `mydash-app`), located inside a `<div id="app-workspace" class="mydash-workspace">` provided by `templates/index.php`. Nextcloud's chrome MUST treat `#app-workspace` as the main content slot (`'id-app-content' => '#app-workspace'`). No left navigation slot MUST be allocated by the chrome (`'id-app-navigation' => null`) — the shell renders its own slide-in sidebar instead.

#### Scenario: Mount point present

- GIVEN the user has navigated to the workspace page
- WHEN the page HTML is rendered
- THEN the rendered HTML MUST contain exactly one `<div id="mydash-app">`
- AND it MUST be a child of `<div id="app-workspace">`
- AND no Nextcloud chrome navigation panel MUST be rendered

### Requirement: REQ-SHELL-002 Edit affordances gated by canEdit

@e2e exclude edit affordances gated by canEdit requires a non-admin user session — test fixture is single-user admin; canEdit=false scenario not available headlessly

`canEdit` (`isAdmin || dashboardSource === 'user'`) MUST gate:

- The per-widget right-click context menu (REQ-WDG-015)
- GridStack's `staticGrid` mode (false when `canEdit`, true otherwise)
- The action menu's edit entries: "Add custom widget…", "Save dashboard", "Dashboard configuration…"

`canEdit` MUST NOT gate any toolbar (none exists) or any sidebar entries (the sidebar shows the same dashboards regardless; per-row create/delete affordances have their own gating per REQ-SWITCH-005).

#### Scenario: Non-edit user has no edit affordances visible

- **GIVEN** a non-admin user viewing a `group_shared` dashboard (`dashboardSource === 'group'`)
- **WHEN** they open the action menu
- **THEN** no "Add custom widget", "Save dashboard", or "Dashboard configuration" entries MUST be visible
- **AND** right-clicking any widget MUST fall through to the browser's native context menu

#### Scenario: canEdit user sees full edit menu

- **GIVEN** an admin user viewing any dashboard
- **WHEN** they open the action menu
- **THEN** "Add custom widget…", "Save dashboard", "Dashboard configuration…", and "Documentation" MUST all be present
- **AND** right-clicking a widget MUST open the widget context menu (REQ-WDG-015)

### Requirement: REQ-SHELL-004 Hamburger toggle and active-dashboard label

The shell MUST render, in the title strip:

- A sidebar-toggle button using `NcButton` with `type="tertiary"`, an `aria-label` of `t('mydash', 'Open menu')`, and a 20-px menu icon. The button's visual treatment (size, hover/focus/active rings) MUST match the Nextcloud account-menu button so the workspace chrome reads as native Nextcloud UI.
- The active dashboard's name as a plain `<h1>` (or `<h2>`) text label — NOT as a `<select>` or any other interactive switcher control. Switching between dashboards happens exclusively via the left sidebar (`dashboard-switcher` capability).

The shell MUST NOT render a standalone "Active dashboard" select dropdown anywhere in its surface area.

#### Scenario: Toggle button matches account-button styling

- **GIVEN** the workspace shell rendered for an authenticated user
- **WHEN** the page loads
- **THEN** the sidebar-toggle button MUST be a `NcButton type="tertiary"` element
- **AND** its rendered class list MUST include the same `button-vue--vue-tertiary` (or current equivalent) classes the Nextcloud account button uses
- **AND** clicking it MUST toggle the sidebar's `isOpen` state

#### Scenario: No active-dashboard select control

- **GIVEN** the workspace shell rendered with multiple dashboards visible to the user
- **WHEN** the page is inspected
- **THEN** no `<select>` element with `name="activeDashboard"` (or equivalent) MUST be present
- **AND** the active dashboard's name MUST appear as a heading in the title strip
- **AND** dashboard switching MUST happen only via the left sidebar's row click handlers (REQ-SWITCH-002)

#### Scenario: Active-dashboard name visible

- GIVEN active dashboard `D` has `name = "Marketing Overview"`
- WHEN the workspace renders
- THEN the label next to the hamburger MUST display `"Marketing Overview"`

#### Scenario: Empty label on empty state

- GIVEN no active dashboard is resolved (resolver returned null)
- WHEN the workspace renders
- THEN the label MUST be empty
- AND the empty-state component MUST render in the grid area instead

### Requirement: Empty state (REQ-SHELL-005)

@e2e exclude empty state requires no dashboards — test fixture always has at least one dashboard; empty state cannot be triggered without destructive setup

When the resolver returned no active dashboard, the shell MUST render an empty-state UI inside the grid container with: a friendly message ("No dashboards available"), an explanation, and — if `allowUserDashboards` is `true` — a primary "Create your first dashboard" button that calls the create-personal flow. When `allowUserDashboards` is `false` no Create button MUST be shown and the message MUST direct the user to contact their administrator.

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

### Requirement: Sidebar backdrop (REQ-SHELL-006)

@e2e exclude sidebar backdrop close tests a fixed-position overlay click — backdrop behaviour is tested end-to-end via PR #111 wave3 tests

When `sidebarOpen` is `true`, the shell MUST render a fixed-position backdrop that intercepts clicks and closes the sidebar. The backdrop MUST start at the same `top` offset as the Nextcloud header (50 px) and span the rest of the viewport. Clicks on the sidebar itself MUST NOT close the sidebar.

#### Scenario: Backdrop closes sidebar on click

- GIVEN `sidebarOpen` is `true`
- WHEN the user clicks anywhere in the backdrop area
- THEN `sidebarOpen` MUST become `false`

#### Scenario: Click on the sidebar itself does not close it

- GIVEN `sidebarOpen` is `true`
- WHEN the user clicks on a non-actionable area of the sidebar panel
- THEN `sidebarOpen` MUST remain `true`

### Requirement: Lifecycle hooks (REQ-SHELL-007)

@e2e exclude lifecycle hooks (addEventListener + GridStack destroy) are internal Vue onMounted/onUnmounted calls — not observable from Playwright without page-unload automation

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
