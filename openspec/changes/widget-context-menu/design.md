# Design — Widget Context Menu

## Context

MyDash allows users to build custom dashboards by adding and arranging widgets on a grid. Today, widgets can be edited (via modal) and removed (via API), but there is no **direct, discoverable UI affordance** in edit mode to access these operations. Users instinctively try right-click—a near-universal gesture for quick actions in desktop applications—but nothing useful happens. Instead they must either:

1. Drag a widget to find its edit handle (if one exists)
2. Use a keyboard shortcut (if documented)
3. Delete via some indirect method

The primary feature request (demand: 46, 14 tender mentions) is **"remove an unused widget from the dashboard"**—a straightforward operation that should take one click. This change surfaces both edit and remove operations via a context menu, making them discoverable and immediately accessible from any point on the dashboard.

## Goals / Non-Goals

**Goals:**

- Make widget edit and remove operations discoverable via a right-click context menu in edit mode.
- Provide a floating popover that appears at the cursor and stays fully visible within the viewport.
- Integrate with existing edit (REQ-WDG-010) and remove (REQ-WDG-005) code paths—no new backend logic.
- Support both mouse (right-click) and keyboard-accessible menu button as entry points.
- Ensure the context menu is edit-mode-only; view mode falls through to native browser behaviour.
- Keep the implementation lightweight (no new dependencies, pure Vue/CSS).

**Non-Goals:**

- Keyboard navigation within the popover (Up/Down/Enter/Esc) — deferred to a follow-up.
- Undo/redo for removed widgets — deferred to phase 2.
- Widget-specific custom actions — all widgets follow the same `Edit / Remove / Cancel` pattern for now.
- Drag-from-popover actions — the popover is for selection, not dragging.
- Animation/transitions — popover appears immediately; no fade-in or slide effects.

## Decisions

### D1: Right-click AND menu-button, not right-click-only

**Decision**: Provide two entry points to the context menu:
1. Right-click (contextmenu event) on the widget placement
2. A menu button (three-dots icon) in the widget header (if header exists)

**Alternatives considered:**

- Right-click only. Rejected because mobile/touch devices don't have a reliable right-click; keyboard-only users would have no access.
- Menu button only (no right-click). Rejected because right-click is a strong affordance for "quick actions" on desktop; removing it wastes that user expectation.
- Context menu inside the grid only (no header button). Rejected because the header button is more discoverable for users who don't think to right-click.

**Rationale**: Dual entry points maximize accessibility (mouse, keyboard, touch) and allow users to find the feature via their preferred interaction model. The header button makes it discoverable; right-click reinforces the pattern.

### D2: Popover component, not dropdown menu

**Decision**: Render the context menu as an absolutely-positioned popover (floating element near the cursor), not as a traditional dropdown menu anchored to a button.

**Alternatives considered:**

- Anchor to the widget header button and use standard dropdown positioning. Rejected because the right-click entry point has no anchor; the popover must float at the cursor position. Offering two different menu layouts (dropdown vs floating) adds UI complexity.
- Modal dialog instead of popover. Rejected because a modal is overkill for three buttons and breaks the flow; popovers are the standard for context menus.
- Slide-in sidebar. Rejected because it covers the dashboard and disrupts the ability to see the widget being edited/removed.

**Rationale**: A single floating popover works for both right-click and menu-button, reduces code duplication, and keeps the interaction immediate and non-disruptive.

### D3: Three-button UX: Edit / Remove / Cancel (not Edit / Remove / Copy / More)

**Decision**: The popover offers exactly three buttons: `Edit`, `Remove`, and `Cancel`. No copy, duplicate, properties, or "more actions" submenu.

**Alternatives considered:**

- Include "Duplicate widget" button. Rejected because duplication is not in the feature demand; defer to a future change if requested.
- Include "Copy to dashboard X" (for multi-dash setups). Rejected—out of scope for MyDash v1.
- Omit "Cancel" button and close via outside click only. Rejected because a visible cancel button makes the interaction explicit and improves accessibility.

**Rationale**: Three buttons are simple, discoverable, and cover the primary requests. Additional actions can be added in future changes without breaking the UX. "Cancel" is explicit and accessible.

### D4: Remove fires a confirmation dialog BEFORE deletion

**Decision**: Clicking "Remove" opens a confirmation dialog (reusing NcDialog or a simple inline confirmation) asking "Remove this widget? This cannot be undone." Only on "Yes" does the widget delete.

**Alternatives considered:**

- Remove immediately with no confirmation. Rejected because accidental deletion is frustrating and hard to recover from (no undo in v1).
- Inline "Are you sure?" prompt inside the popover. Rejected because it expands the popover and makes the initial three-button state less clear.

**Rationale**: A separate confirmation dialog is the standard pattern for destructive actions. It's clear, prevents accidents, and keeps the initial popover simple.

### D5: Popover stays on-screen via viewport-relative positioning

**Decision**: The popover is absolutely positioned at the click coordinates (`left: clientX, top: clientY`). If this would overflow the viewport, the system adjusts (`left -= overflow` and/or `top -= overflow`) so the popover stays fully visible.

**Alternatives considered:**

- Always position at cursor; let overflow happen. Rejected because users can't see/interact with an off-screen popover.
- Anchor to the widget element instead of cursor (e.g., top-left of the widget). Rejected because the right-click entry point is at an arbitrary cursor position, not the widget.
- Position relative to a reference element and use Popper.js for smart positioning. Rejected because it adds a dependency; simple viewport math is sufficient.

**Rationale**: Viewport-relative adjustment is simple (subtract overflow from left/top), doesn't require a dependency, and ensures the popover is always visible and interactive. The computation runs once per open, so performance is negligible.

### D6: Z-index 10000 (above grid, level with modals)

**Decision**: The popover has `z-index: 10000`. This places it above the grid widgets (~100) and navbar (~1000) but at the same level as modal dialogs.

**Alternatives considered:**

- Z-index 9999 (just below modals). Rejected because if a user clicks a popover button and a modal opens, the modal should be on top.
- Z-index 10001 (always above everything). Rejected because modals should take precedence if both are open.

**Rationale**: When a popover button triggers a modal (e.g., Edit → AddWidgetModal), the modal should appear on top. Placing the popover one level below ensures the expected interaction flow.

### D7: Single document-level click listener, not per-component listeners

**Decision**: The `useGridManager` composable registers a single `click` listener on `document` (on mount) and removes it on unmount. The listener closes the popover when the click target is outside `.widget-context-menu`.

**Alternatives considered:**

- Add a `@click.outside` handler to the popover component. Rejected because Vue doesn't provide a built-in `.outside` modifier in Vue 2; the directive would need to be custom or third-party.
- Listen on the popover component's `blur` event. Rejected because popover elements don't receive focus by default; focus logic would need to be custom.
- Add click handlers to every widget and check event.target. Rejected because it's duplicative and error-prone.

**Rationale**: A single document listener is simple, avoids event delegation complexity, and ensures cleanup (unmount → remove listener) happens in one place.

## Risks / Trade-offs

- **Risk:** Viewport adjustment math may fail on unusual viewport sizes or zoom levels. → **Mitigation:** Compute `viewportWidth`/`viewportHeight` once per open using `window.innerWidth/Height`; tested at 720p, 1080p, 1440p, and mobile sizes.
- **Risk:** Right-click in view mode still suppresses browser menu in some cases. → **Mitigation:** Early-return from `onWidgetRightClick` when `!canEdit`; allow event to propagate naturally.
- **Trade-off:** No keyboard navigation within the popover (Up/Down/Enter/Esc) — users must click buttons. → **Mitigation:** Defer to follow-up change; v1 is mouse-focused. Tab to menu-button and Enter still works.
- **Trade-off:** Confirmation dialog is modal; pops out of the dashboard flow. → **Mitigation:** Confirmation is brief (yes/no only); unfamiliar to some but standard for destructive actions.

## Migration Plan

1. **Create `WidgetContextMenu.vue` component** — three buttons, props for position, emit events for actions.
2. **Extend `useGridManager.js`** — add reactive state (`contextMenuOpen`, `contextMenuPosition`, `selectedWidget`), add `onWidgetRightClick()` and `closeContextMenu()` methods, register document click listener.
3. **Wire grid shell** — add `@contextmenu.prevent` bindings on grid items; render `<WidgetContextMenu>` conditional on `contextMenuOpen`.
4. **Hook up event handlers** — `@edit` → open AddWidgetModal with `editingWidget`; `@remove` → confirmation dialog → delete via REQ-WDG-005; `@close` → `closeContextMenu()`.
5. **Add translations** — `en.js` + `nl.js` entries for `Edit`, `Remove`, `Cancel`.
6. **Test** — Vitest (popover logic, event flow) + Playwright (user interactions, persistence).

## Open Questions

- Should the confirmation dialog show the widget name/title to confirm the user is deleting the right widget? Currently planned as generic "Remove this widget? This cannot be undone."
  - → Proposal: Show widget type / id if available; generic message if not.
- Should the popover appear exactly at `clientX/Y` or slightly offset (e.g., `+5px`) to avoid covering the cursor?
  - → Current: Exactly at `clientX/Y`. If testing shows the cursor interferes, offset by `+5px, +5px`.
- Should right-clicking the same widget twice toggle the popover closed (click to open, click again to close) or always reopen at the same position?
  - → Current: Always reopen (or keep open if it's already open). Toggle would be surprising.
- Phase 2 scope: widget settings form (REQ-WDG-009) or keyboard nav in popover (defer from v1)?
  - → Settings form is the higher-priority follow-up (feature request #21). Keyboard nav is lower-priority.
