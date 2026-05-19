# Runtime shell trim

## Why

The shipped `runtime-shell` capability (REQ-SHELL-001..007) renders a top toolbar with `Add Widget` + `Save Layout` buttons and a separate "Active dashboard" select dropdown next to the hamburger. After we shipped the left sidebar (`dashboard-switcher`) and the action menu (gear), these top-of-page affordances duplicate functionality already reachable via two more discoverable surfaces:

- Active dashboard switching → already in the left sidebar
- Add Widget → already in the action menu's "Add custom widget"
- Save Layout → already auto-saved on edit per REQ-GRID-005 (300ms debounce)

The toolbar wastes vertical space, the active-dashboard select competes with the sidebar for navigation responsibility, and the hamburger button doesn't visually match the account-button styling Nextcloud uses elsewhere. This change removes the toolbar, removes the standalone active-dashboard select, and restyles the hamburger to match.

## What Changes

- **Remove the top toolbar entirely.** The DOM region that hosts the `Add Widget` dropdown + `Save Layout` button + saving spinner is deleted. `canEdit` users still get full edit functionality via the action menu (`Add custom widget`) and the existing auto-save path.
- **Remove the "Active dashboard" select dropdown** from the title strip. The left sidebar is the only dashboard-switching surface.
- **Restyle the sidebar-toggle hamburger** to match the Nextcloud account-menu button (same NcButton variant, size, and hover affordance).
- **Trim the action menu (gear)**: remove the "Add tile…" and "Add widget…" menu items (their flows are removed; `unified-add-widget-flow` covers them via "Add custom widget"). Remove the "Powered by Sendent / Conduction" footer (moved to the left sidebar's footer per `dashboard-switcher-extensions`). Remove the inline list of dashboards (the sidebar owns dashboard navigation).

## Capabilities

### Modified Capabilities

- `runtime-shell` — REQ-SHELL-003 (toolbar) is REMOVED; REQ-SHELL-004 (hamburger + title strip) is MODIFIED to drop the active-dashboard select and restyle the toggle. REQ-SHELL-002 (`canEdit`) is unchanged in semantics but no longer gates a toolbar — it gates per-widget context-menu affordances and the action menu's edit-related entries.

## Impact

**Affected code:**

- `src/views/WorkspaceApp.vue` — drops the toolbar region, drops the active-dashboard `NcSelect`, swaps the hamburger to `NcButton variant="tertiary"` with the account-menu icon-only treatment.
- `src/components/admin/AdminMenu.vue` (or wherever the gear menu lives) — removes the dashboards list, "Add tile", "Add widget", and the powered-by footer.
- `src/styles/workspace.css` — drops toolbar grid row + spacing.

**Affected APIs:** none. All endpoints stay; only the UI affordances change.

**Dependencies:**

- Soft-depends on `unified-add-widget-flow` for the Add-Custom-Widget replacement of the toolbar's Add Widget button.
- Soft-depends on `dashboard-switcher-extensions` for the powered-by footer's new home.

**Migration:** None.
