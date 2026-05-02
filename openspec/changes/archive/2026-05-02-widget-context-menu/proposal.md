# Widget context menu

## Why

In edit mode users need a quick, discoverable way to edit or remove an existing widget placement. Today the only affordances are drag handles and (implicit) keyboard flows; there is no right-click surface, so users instinctively try right-click and either get the browser's native menu (in view mode that's fine, in edit mode it's noise) or nothing useful. A small popover with `Edit / Remove / Cancel` brings widget management to the cursor and makes the existing REQ-WDG-004 (edit) and REQ-WDG-005 (remove) operations directly reachable from any placement on the grid.

## What Changes

- Add a `WidgetContextMenu.vue` popover component (three buttons, absolute-positioned at cursor, `min-width: 150px`, `z-index: 10000`).
- Add `onWidgetRightClick(event, widget)` to `useGridManager.js` — early-returns when `!canEdit`, calls `event.preventDefault()`, captures `clientX/Y`, sets `selectedWidget`, opens the popover.
- Wire `@contextmenu.prevent="onWidgetRightClick($event, widget)"` on every grid item in the workspace shell.
- Manage a single document-level `click` listener (mount/unmount in the composable) that closes the popover on outside click; right-clicking a different widget switches the popover to the new position rather than stacking.
- Clamp the computed `left` / `top` so the popover stays fully visible at viewport edges.
- `Edit` reuses the existing `AddWidgetModal` (REQ-WDG-010) with `editingWidget`; `Remove` calls the placement-delete path of REQ-WDG-005; `Cancel` is a no-op close.
- View mode is untouched: the right-click event falls through to the browser's native context menu.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `widgets`: adds REQ-WDG-015 (right-click context menu in edit mode), REQ-WDG-016 (auto-close on outside interaction), REQ-WDG-017 (position constraints). Existing REQ-WDG-001..014 are untouched — this change is purely additive UI surface around the already-specified edit (REQ-WDG-004) and remove (REQ-WDG-005) operations.

## Impact

**Affected code:**

- `src/components/Widgets/WidgetContextMenu.vue` (new) — the popover component, three buttons, absolute-positioned, viewport-clamped
- `src/composables/useGridManager.js` — adds `onWidgetRightClick(event, widget)`, `closeContextMenu()`; extends `handleClickOutside` to close the popover when the click target is not inside `.widget-context-menu`; mounts/unmounts the document-level `click` listener
- `src/views/Workspace.vue` (or wherever grid items render) — adds `@contextmenu.prevent` binding on each placement
- Translation entries: `Edit`, `Remove`, `Cancel` (en + nl)

**Affected APIs:**

- None. This change is purely frontend; `Edit` reuses REQ-WDG-010 (modal) and `Remove` reuses REQ-WDG-005 (`DELETE /api/placements/{id}`) — no new endpoints, no schema changes.

**Dependencies:**

- Builds on `widget-add-edit-modal` (L2, done) for the edit modal surface.
- Soft-depends on `runtime-shell` for the `canEdit` flag (REQ-SHELL-002). The validation of this change does not require runtime-shell to be archived; integration testing does.
- No new npm or composer dependencies.

**Migration:**

- None — purely additive UI; no data, no schema, no route changes.

**Out of scope (follow-ups):**

- Keyboard navigation in the popover (Up/Down/Enter/Esc) is deferred to a future change. v1 is mouse-only by design.
