# Design — Widget Collision Placement

## Context

MyDash uses GridStack 10.3.1 as the grid engine for dashboard layout. The user can add widgets to a dashboard via three entry points: the toolbar dropdown, a keyboard shortcut, and drag-from-picker. Today, each entry point computes its own placement — some pass `autoPosition: true` to GridStack, some hard-code `(0, 0)`, and a few drop the widget at the cursor position. The result is inconsistent UX: in a densely packed dashboard, "Add widget" sometimes silently overflows the visible viewport (placing the new widget far below the fold), sometimes overlaps existing widgets, and sometimes fails outright when GridStack rejects the position.

REQ-GRID-006 already declares the intent ("widget auto-layout") but does not specify the algorithm or the fallback behaviour when the grid is fully occupied. This change formalises the algorithm so the UX is predictable across browsers, viewport sizes, and dashboard density.

## Goals / Non-Goals

**Goals:**

- Make widget placement deterministic and predictable: same dashboard state + same widget spec → same final position.
- Always place new widgets where the user can see them — never below the visible viewport rows.
- Centralise placement logic in a single composable function so it is testable, auditable, and impossible to bypass.
- Keep the existing `addWidget` GridStack call working for the common case (empty space available) — the change is additive, not a rewrite.

**Non-Goals:**

- Smart packing (bin-packing optimal placement). The push-down fallback is intentionally naive — predictable beats optimal for first-time discoverability.
- Animations or visual transitions when widgets are pushed. Out of scope; can be a follow-up purely-cosmetic change.
- Widget grouping or relative-position constraints (e.g. "always place next to widget X"). Out of scope.
- Per-widget-type custom placement strategies. All widgets follow the same algorithm — type-specific size hints come via `spec.w` / `spec.h`.
- Conflict resolution when two simultaneous "add widget" calls race. Frontend serialises modal interactions so this cannot happen in practice.

## Decisions

### D1: Top-left + push-down, not bottom-append

**Decision**: When auto-position fails, place the new widget at `(0, 0)` and push overlapping widgets down by `newH` rows.

**Alternatives considered:**

- Append to the bottom of the grid (max-y of all widgets + 1). Rejected because on dense dashboards the new widget lands below the viewport — users assume the click did nothing because they don't see the widget appear.
- Place at the cursor position. Rejected because the toolbar dropdown and keyboard shortcut have no meaningful cursor position.
- Open a "where do you want it?" dialog. Rejected as user-hostile for what should be a one-click action.

**Rationale**: "New things appear at the top-left" matches first-run expectations from spreadsheets, kanban boards, and most CMS layouts. Pushing existing widgets down preserves them all and keeps the new one immediately visible.

### D2: Single helper `placeNewWidget(spec)` is the only entry point

**Decision**: Export `placeNewWidget(spec)` from `useGridManager.js`. All three add-widget code paths route through it. Inline `grid.addWidget(...)` outside the helper is forbidden and enforced by a grep test.

**Alternatives considered:**

- Let each component compute placement and call `grid.addWidget` directly. Rejected — that's the status quo and the source of the inconsistency.
- Bake the algorithm into a Vue mixin. Rejected — composables are the modern pattern in Vue 2.7 + Composition API and align with REQ-GRID-005's existing helpers.

**Rationale**: One function = one place to test = one place to audit. The grep test is cheap insurance against regressions when a new "Add widget" path is added in future.

### D3: Default size `w=4, h=4` when caller omits dimensions

**Decision**: If `spec.w` or `spec.h` is undefined, default to `4, 4`.

**Alternatives considered:**

- Per-widget-type defaults (e.g. text widget = 3×2, chart = 6×4). Rejected — adds another lookup table and the widget-types config doesn't currently expose default sizes. Can be layered on later via the `spec` object.
- Smallest possible (1×1). Rejected — nearly every widget is unreadable at 1×1 cells.

**Rationale**: 4×4 in a 12-column grid is a third of the row width and 4 cells tall — readable for charts and lists, not so big that it dominates a half-empty dashboard. Matches the existing default in `AddWidgetModal.vue`.

### D4: Push by `newH`, not by 1 row

**Decision**: Overlapping widgets are pushed to `gridY = newH` (just below the new widget's bottom edge), not shifted by 1 row at a time.

**Alternatives considered:**

- One-row shift in a loop until no overlap. Rejected — produces O(n²) updates and visually janky reflows.
- Compute minimum required shift per overlapper. Rejected — the trade-off of "perfectly minimal disruption" isn't worth the algorithmic complexity for a fallback path that should fire rarely.

**Rationale**: Single-pass push by `newH` is O(n) and produces a visually clean result: every previously-overlapping widget snaps to one consistent y-coordinate.

### D5: Detect "no fit" by checking returned slot against `viewportRows`

**Decision**: After `grid.addWidget({autoPosition: true, ...})`, check the returned slot's `y` value. If `y >= viewportRows` (slot is below the fold), treat it as a failed auto-position and trigger the push-down fallback.

**Alternatives considered:**

- Trust GridStack's return value verbatim and never fall back. Rejected — this is the bug we're fixing.
- Always fall back to top-left and never use auto-position. Rejected — auto-position is great when there is empty space and avoids unnecessary widget movement.

**Rationale**: GridStack's `autoPosition` is well-suited for the common "empty space exists" case. The viewport check converts a UX-failure case (widget below fold) into the fallback path without changing the success path.

## Risks / Trade-offs

- **Risk:** Push-down on a dense dashboard disrupts layout the user spent time arranging. → **Mitigation:** Document the behaviour in the user-facing docs; the user can drag pushed widgets back. The alternative (silently placing off-screen) is worse.
- **Risk:** `viewportRows` definition varies by browser zoom and window size. → **Mitigation:** Compute `viewportRows` once at composable setup time from the GridStack container height; recompute on `resize` events. Tested at 720p, 1080p, and 1440p.
- **Trade-off:** Push-down is naive (single pass, fixed shift). On a dashboard that is dense everywhere (not just at top), some pushed widgets may now overlap others further down. We accept this — REQ-GRID-006 step 1 (auto-position) handles the common case, and step 2 only fires when the dashboard is already nearly-full at the top, where some disruption is unavoidable.
- **Trade-off:** Inline `grid.addWidget(...)` enforcement is grep-based, not type-system-based. A determined developer could rename the import to bypass it. We accept this — the grep test catches the common case and code review catches the exotic.

## Migration Plan

1. **Composable + tests land first** — add `placeNewWidget` and Vitest coverage in one PR. No behaviour change yet (no callers).
2. **Refactor `AddWidgetModal.vue` to call the helper** in the same PR or a follow-up.
3. **Audit other add-widget code paths** (toolbar dropdown, keyboard shortcut, drag-from-picker) and route them through the helper.
4. **Add the grep test** to CI as the final step so the enforcement is live before any new add-widget paths can land.
5. **Rollback**: pure frontend change, no schema migration. Reverting the PR restores the previous (buggy) behaviour with no data loss.

## Open Questions

- Should the helper return the final position synchronously, or wait for the persistence round-trip? Current decision: return synchronously (the caller gets `{x, y, w, h}` immediately for optimistic UI), and persistence happens in a fire-and-forget debounced batch. Revisit if the persistence layer ever needs to reject placements (e.g., quota enforcement).
- Should `viewportRows` be a configurable constant or auto-computed? Current decision: auto-compute from container height, with a fallback constant of `8` if the container is not yet measured (e.g., during initial mount).
