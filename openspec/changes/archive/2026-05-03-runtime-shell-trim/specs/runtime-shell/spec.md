# Spec delta — runtime-shell

## REMOVED Requirements

### Requirement: REQ-SHELL-003 Toolbar contents

**Reason:** The toolbar's `Add Widget` dropdown is replaced by the action menu's "Add custom widget" entry (covered by `widget-add-edit-modal`). The `Save Layout` button is replaced by REQ-GRID-005 (300ms-debounced auto-save). Removing the toolbar reclaims vertical space and consolidates edit affordances into the action menu + per-widget context menu.

## MODIFIED Requirements

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

### Requirement: REQ-SHELL-002 Edit affordances gated by canEdit

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
