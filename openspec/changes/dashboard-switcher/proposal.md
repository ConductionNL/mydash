# Dashboard Switcher

## Why

The dashboard switcher provides a fast, always-accessible way to navigate between the dashboards visible to a user. Previously, dashboard switching required navigating through dropdown menus or page refreshes. A dedicated left-edge sidebar exposes all available dashboards (grouped by source) with a single click, dramatically improving discoverability and switch speed.

The sidebar also surfaces personal-dashboard creation and deletion affordances when permissions allow, eliminating the need to navigate to a separate admin page for these operations.

## What Changes

- Add a new Vue component `DashboardSwitcher.vue` that renders a fixed-position left-edge slide-in sidebar.
- The sidebar displays dashboards grouped into up to three sections: primary group dashboards, default-group dashboards, and personal dashboards.
- Each section is separated by a horizontal divider when both adjacent sections are populated.
- The active dashboard is highlighted visually to show the user their current location.
- Personal dashboards display a hover-triggered delete button; clicking it emits a delete event to the parent.
- A dedicated "Add Dashboard" button card appears in the personal section when personal-dashboard creation is allowed.
- The sidebar animates in/out via CSS transform over 250 ms (ease timing).
- A sticky footer at the bottom displays brand attribution (Sendent and Conduction logos with links) and a Documentation link.
- The component accepts props for dashboard lists, active dashboard ID, and feature flags; emits events for switching, creating, deleting, and toggling the sidebar.
- All dashboard icons render via the shared `IconRenderer` component from the `dashboard-icons` capability.

## Capabilities

### New Capabilities

(none — this is a UI component for the existing `dashboards` capability)

### Modified Capabilities

- `dashboard-ui`: adds UI requirements for the dashboard switcher sidebar, active-dashboard highlighting, and delete affordances.

## Impact

**Affected code:**

- `src/components/DashboardSwitcher.vue` — new component with template, script, and scoped styles
- `src/App.vue` — integrate the switcher component, wire v-model, and handle emitted events
- `src/styles/dashboard-switcher.css` — global styles for slide-in animation and Nextcloud CSS variable usage

**Affected APIs:**

- No new backend APIs — the component consumes existing `GET /api/dashboards/visible` data and emits client-side events that the parent handles

**Dependencies:**

- `@conduction/nextcloud-vue` — for `NcButton`, `NcIcon`, and other shared components
- `dashboard-icons` capability — for `IconRenderer` component

**Migration:**

- Zero backend impact — the component is purely frontend
- No schema or data changes

## Notes

- The sidebar is stateless apart from the `isOpen` flag; all dashboard data is passed as props from the parent.
- Icon rendering deliberately delegates to `IconRenderer` to avoid branching on `isCustomIconUrl` in the component.
- The "Add Dashboard" card is a dedicated button affordance (not an inline row) to match modern UI patterns and simplify DOM updates on list changes.
- Delete events do NOT close the sidebar — the parent decides whether to close after handling the deletion.
- The sidebar footer uses `position: sticky` to remain visible while the dashboards list scrolls, keeping brand and documentation links always accessible.
