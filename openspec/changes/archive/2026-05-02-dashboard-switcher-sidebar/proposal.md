# Dashboard switcher sidebar

## Why

The runtime shell (`WorkspaceApp.vue`) currently has no first-class navigation surface for switching between the dashboards a user can see. With `multi-scope-dashboards` introducing three sources (personal, primary group, default group) plus a `source` discriminator, users need a single place to browse them all and pick one. Inline switching from the topbar would not scale beyond a handful of dashboards and could not host the personal-dashboard create/delete affordances that `allow_user_dashboards` gates. A dedicated slide-in sidebar gives every visible dashboard a row, groups them by origin, and isolates the animation + emit contract from the runtime shell so each can evolve independently (e.g. a future "shared with me" section).

## What Changes

- Add a new `dashboard-switcher` capability owning a slide-in left navigation panel.
- Render up to three sections in fixed order (primary group / default group / personal), with empty sections collapsed entirely (no orphan headings).
- Each row uses the shared `IconRenderer` from the `dashboard-icons` capability — no inline icon-URL branching in the sidebar template.
- Click on any row emits `update:open(false)` then `switch(id, source)`; the `source` discriminator is load-bearing because the parent uses it to pick the correct API endpoint (group vs default vs personal).
- Personal rows expose a hover-revealed delete button (CSS `display: none → inline-flex`) that emits `delete-dashboard(id)` with `@click.stop` so it never triggers a switch.
- A `+ New Dashboard` row appears at the end of the personal section when (and only when) `allowUserDashboards === true`; clicking it emits `update:open(false)` then `create-dashboard()`.
- Slide-in animation is CSS-only via `transform: translateX(-100%) ↔ translateX(0)` over 0.25s ease; the `.open` class is toggled from the `isOpen` prop.
- A companion `SidebarBackdrop.vue` is added so the runtime shell can wire click-to-close without polluting the sidebar's own DOM.

## Capabilities

### New Capabilities

- `dashboard-switcher` — owns REQ-SWITCH-001..007 covering three-section layout, switch/click semantics, active-item highlight, personal-row delete affordance, create-dashboard affordance, slide-in animation, and shared icon rendering.

### Modified Capabilities

(none — `dashboards` and `dashboard-icons` are consumed but not modified)

## Impact

**Affected code:**

- `src/components/Workspace/DashboardSwitcherSidebar.vue` — the new sidebar component
- `src/components/Workspace/SidebarBackdrop.vue` — click-to-close backdrop consumed by `runtime-shell`
- `src/views/WorkspaceApp.vue` — wire `v-model:open`, handle `@switch`, `@create-dashboard`, `@delete-dashboard`
- Translation catalogue entries: `'Dashboards'`, `'Default'`, `'My Dashboards'`, `'+ New Dashboard'`, `'Delete dashboard'`, `'Close'`

**Affected APIs:**

- None directly. The sidebar emits `switch(id, source)` and the parent (`runtime-shell`) routes to the correct endpoint already defined in `multi-scope-dashboards` (REQ-DASH-013/REQ-DASH-014).

**Dependencies:**

- Consumes `IconRenderer` from the `dashboard-icons` capability (built-in icons + URL discriminator)
- No new npm dependencies

**Migration:**

- Pure additive frontend change. No schema or API migration required.

## Notes

- The `source` discriminator on each emit is load-bearing — it tells the runtime shell which fetch endpoint to use (group vs user vs default group).
- All section labels are translated via `t()`.
- A future "(read-only)" affix on group/default dashboards is out of scope; tracked separately if needed.
