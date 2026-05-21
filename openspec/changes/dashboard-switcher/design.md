# Design — Dashboard Switcher Sidebar

## Context

The `dashboard-switcher` spec introduces a fixed-position left-edge slide-in sidebar that aggregates all dashboards visible to the user, grouped by source (primary group, default group, personal), and allows switching with a single click.

Before implementing, we clarified several design choices:
1. **Icon rendering delegation**: Should the sidebar branch on icon type (built-in vs custom), or delegate to a shared renderer?
2. **Add-Dashboard affordance**: Should it be an inline row in the personal-dashboards list, or a dedicated button card?
3. **Delete event semantics**: Should deleting a dashboard also close the sidebar, or leave that decision to the parent?
4. **Footer positioning**: Should the footer scroll with the list, or remain sticky at the bottom?
5. **Section rendering**: When a section is empty, should we render an empty container with no label, or omit the section entirely?

## Goals / Non-Goals

**Goals:**

- Establish icon rendering via the `IconRenderer` component to avoid reimplementing custom icon handling.
- Confirm the Add-Dashboard button is a dedicated card (not an inline row) for cleaner CSS styling and reactive list updates.
- Nail down the delete-without-close semantics to keep the component's parent-driven event model clean.
- Document the sticky footer pattern and confirm the footer's position in the scroll hierarchy.
- Specify section visibility rules to avoid rendering empty containers.
- Establish CSS class names and transitions for animation consistency.

**Non-Goals:**

- Implementing the parent (App.vue) integration — that's handled in the tasks.
- Designing the responsive breakpoint behavior — all dashboard lists must fit within a 280px sidebar.
- Specifying accessibility (WCAG) compliance details beyond what REQ-SWITCH-* require — that's covered in the specs' acceptance criteria.

## Decisions

### D1: Icon rendering via shared `IconRenderer` component

**Decision**: The sidebar MUST NOT branch on `isCustomIconUrl` itself. ALL dashboard icons (built-in, custom URLs, null) MUST be rendered via the `IconRenderer` component from the `dashboard-icons` capability.

**Alternatives considered:**

- **Inline branching** (`v-if="isCustomIconUrl"` in the template): each icon type (built-in, custom, null) rendered separately. Rejected because it couples the sidebar to the icon-type schema, complicates testing, and means icon-rendering logic is duplicated across components.

**Rationale**: `IconRenderer` is a single source of truth for all icon variants. It encapsulates the logic to distinguish built-in MDI icons from URLs, handle null icons gracefully, and apply Nextcloud CSS theming. Delegating to it reduces the sidebar's complexity and ensures all icon rendering across the app remains consistent. The prop passed to `IconRenderer` is the dashboard's `icon` field exactly as stored (whether it's a string like `'Star'`, a URL, or `null`).

**Source evidence**:
- The `dashboard-icons` capability defines `IconRenderer` as the shared component for rendering all dashboard icons.
- REQ-SWITCH-007 explicitly requires icon rendering via `IconRenderer`, with the explicit GIVEN/WHEN/THEN: "no inline `v-if="iconUrl"` branches MUST exist in the sidebar template".

### D2: Add-Dashboard affordance is a dedicated card button, not an inline row

**Decision**: The "Add Dashboard" affordance MUST be a dedicated `NcButton` card placed below the personal-dashboards list (inside the sidebar's scroll container). It is NOT an inline row in the list.

**Alternatives considered:**

- **Inline row**: add a special row object to `userDashboards` with `id: '___new___'` and render it like other rows. Rejected because:
  - It requires the parent to inject a synthetic object into the list prop on every render.
  - Deleting a dashboard requires removing the object from the list; adding the synthetic object back is error-prone.
  - The visual style (filled button vs list row) requires CSS branching in the template anyway.

**Rationale**: A dedicated `NcButton` card is simpler for the parent to manage (no synthetic list entries), can be styled distinctly (full-width filled or outline button with icon), and the click handler is explicit (not hidden in a row-click + special-case branch). The card remains inside the scroll container (not a footer) so it's visible and clickable even when there are no personal dashboards yet but the create affordance is allowed.

**Source evidence**:
- REQ-SWITCH-008 specifies "a dedicated 'Add dashboard' card button", not a row.
- The requirement's scenario "Card visible with personal dashboards enabled" shows the card rendering "below the personal section's last dashboard row", implying it's a separate UI element.

### D3: Delete event does NOT close the sidebar

**Decision**: When the user clicks the delete button on a personal dashboard, the sidebar emits `delete-dashboard(id)` but MUST NOT emit `update:open(false)`. The parent component decides whether to close the sidebar after handling the deletion.

**Alternatives considered:**

- **Sidebar closes automatically**: emit both `update:open(false)` and `delete-dashboard(id)`. Rejected because:
  - If deletion fails (404, permission denied), the sidebar is already closed and the error feedback is not visible.
  - If deletion succeeds, the parent may want to show a confirmation toast, keep the sidebar open, and let the user continue managing dashboards.

**Rationale**: The sidebar's event model is: switching closes the sidebar (to show the new dashboard immediately), but destructive operations (delete) emit their event and defer the close decision to the parent. This keeps the sidebar reactive to the parent's state without assuming the parent's error-handling or UX flow. REQ-SWITCH-004 scenario "Delete click does not trigger switch" explicitly specifies "MUST NOT emit `update:open(false)`".

**Source evidence**:
- REQ-SWITCH-004 scenario "Delete click does not trigger switch": "MUST NOT emit `update:open(false)` (closing decision is up to the parent)".

### D4: Sticky footer remains visible while dashboards list scrolls

**Decision**: The footer (brand logos + Documentation link) MUST use `position: sticky; bottom: 0` so it remains visible at the bottom of the sidebar viewport while the dashboards list above it scrolls. The footer is NOT part of the scrollable list; it's a separate layout tier.

**Alternatives considered:**

- **Footer inside the scroll container, bottom-aligned**: footer scrolls away if the list is long. Rejected because:
  - Brand and documentation links become invisible if the user has many dashboards.
  - The footer is not part of the "dashboards list" semantic — it's always-on footer chrome.

- **Fixed position outside the sidebar container**: footer always visible but not inside the sidebar's visual bounds. Rejected because:
  - The footer would overlap or float outside the sidebar's 280px width.

**Rationale**: `position: sticky` creates a new stacking context. As long as the parent scroll container (the dashboards list) scrolls within itself, the footer stays pinned to the bottom. This is the standard pattern for sidebars with scrollable content and persistent footer chrome. REQ-SWITCH-009 scenario "Footer stays visible while list scrolls" explicitly tests this: "the footer MUST remain visible at the bottom of the sidebar viewport" and "MUST NOT scroll out of view with the list".

**Source evidence**:
- REQ-SWITCH-009 scenario "Footer stays visible while list scrolls".
- Standard CSS pattern: parent with `overflow-y: auto`, child with `position: sticky; bottom: 0`.

### D5: Empty sections are omitted entirely (no empty label, no empty container)

**Decision**: If a section would be empty (zero dashboards and no affordance), the section MUST NOT render at all — no label, no container, no divider on either side.

**Alternatives considered:**

- **Render empty containers with grey background and "No dashboards" text**: always renders the section frame even when empty. Rejected because:
  - If all three sections are empty, the sidebar renders three empty containers with no content, which is visually confusing.
  - If a section becomes non-empty (e.g., user creates a personal dashboard), the sidebar layout shifts, which is jarring.

**Rationale**: Sections are purely a grouping/navigation affordance. If there are no dashboards in a group and no affordance to add them (e.g., personal dashboards are disabled), the section serves no purpose and should not appear. This keeps the sidebar compact and focuses the user on available actions. REQ-SWITCH-001 scenario "Only personal dashboards section visible" confirms: "only the 'My Dashboards' section MUST be visible" when other sections are empty. REQ-SWITCH-001 scenario "Personal section visible when allowed even with empty list" shows the personal section MUST render (because `allowUserDashboards: true`), but the section label and the Add-Dashboard card are the only entries — no "empty state" text.

**Source evidence**:
- REQ-SWITCH-001: "Empty sections MUST NOT render their label or container at all (no empty heading)".

### D6: CSS classes and animation timing

**Decision**: 
- The sidebar container gets CSS class `.dashboard-switcher` and `.open` (when `isOpen: true`).
- Animation property: `transform: translateX(-100%) ↔ translateX(0)`.
- Transition duration: `0.25s` (250 ms), timing function: `ease`.
- The sidebar's z-index is `1500` to sit above most page content (Nextcloud header is typically `1000`).

**Rationale**: These constants match Nextcloud's standard sidebar patterns (e.g., the Nextcloud header is `z-index: 1000`, file sidebar is usually above that). The `ease` timing function feels natural for slide-in interactions (eases in and out). 250 ms is the standard duration for sidebar animations in modern web UIs.

**Source evidence**:
- REQ-SWITCH-006 specifies exactly: `transform: translateX(-100%) ↔ translateX(0)`, `0.25s ease`, `z-index: 1500`, `top: 50px`, `width: 280px`.

## Reuse Analysis

The sidebar leverages existing capabilities from `@conduction/nextcloud-vue`:
- `NcButton` for the Add-Dashboard card and all clickable items.
- `NcIcon` for the dashboard icons (though all icons are rendered via `IconRenderer` per D1).
- `IconRenderer` from the `dashboard-icons` capability for all dashboard icon rendering.

No custom list, button, or icon components are introduced. The sidebar is a composition of existing, battle-tested components, keeping the code lean and maintainable.

## Spec changes implied

No changes to the context-brief.md requirements are implied by these decisions. All REQ-SWITCH-* requirements are already explicit and aligned with the decisions above.
