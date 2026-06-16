# Dashboard switcher sidebar — extensions

## Why

`runtime-shell-trim` collapses navigation, edit affordances, and brand attribution into the gear menu and the left sidebar. The sidebar (`dashboard-switcher` capability) currently ships with three sections (primary group / default group / personal) and a `+ New Dashboard` row inside the personal section. After the trim:

- The gear menu's "Powered by Sendent / Conduction" footer disappears — the brand attribution needs a new home, and the sidebar's footer is the natural place (it's the persistent left-edge navigation surface).
- The Documentation link disappears from the gear menu in some flows — surfacing it in the sidebar footer ensures it's always reachable.
- The current conditional `+ New Dashboard` row inside the personal section is easy to miss when scanning a long list. Promoting it to a dedicated card-style button under the dashboard list (always visible when `allowUserDashboards === true`) makes the create affordance more discoverable.

## What Changes

- Add a **persistent footer block** at the bottom of the sidebar (always visible, sticks to the bottom) containing:
  - "Powered by" line with the Sendent and Conduction logos (links to the respective sites, `target="_blank" rel="noopener noreferrer"`)
  - A "Documentation" link (icon + label) pointing at the same URL the gear menu currently uses
- Replace the conditional `+ New Dashboard` row inside the personal section with a **dedicated "Add Dashboard" card button** rendered immediately below the personal dashboards list (still gated on `allowUserDashboards === true`). The card uses NcButton's `outline` variant, fills the sidebar width, and has a `+` icon plus "Add dashboard" label.
- The footer is fixed-positioned within the sidebar (stays visible while the dashboards list scrolls).

## Capabilities

### Modified Capabilities

- `dashboard-switcher` — REQ-SWITCH-005 (the personal-section `+ New Dashboard` row) is REMOVED in favour of REQ-SWITCH-008 (dedicated add-dashboard card). New REQ-SWITCH-009 specifies the brand+docs footer.

## Impact

**Affected code:**

- `src/components/Workspace/DashboardSwitcherSidebar.vue` — adds a footer slot pinned to the bottom; replaces the inline `+ New Dashboard` row with a card button below the personal section
- `src/components/Workspace/SidebarFooter.vue` — new sub-component containing the brand block + Documentation link
- `img/sendent-logo.svg`, `img/conduction-logo.svg` — bundled brand assets (or imported from `@conduction/nextcloud-vue` if already present)
- `l10n/{en,nl}.{js,json}` — Add dashboard, Documentation, Powered by

**Affected APIs:** none.

**Dependencies:**

- Soft-depends on `runtime-shell-trim` removing the gear-menu's powered-by + Documentation entries so they're not duplicated.

**Migration:** None.
