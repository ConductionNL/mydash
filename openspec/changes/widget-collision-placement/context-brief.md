# Widget collision placement

When a user adds a new widget to a non-empty dashboard, MyDash MUST decide where to place it without overlapping existing widgets and without dropping it off-grid. This change formalises the algorithm: try GridStack's auto-place first; if it fails, push existing widgets out of the way at the top-left.

## Affected code units

- `src/composables/useGridManager.js` — `placeNewWidget(widget)` helper called by the add-widget submit handler
- `src/components/AddWidgetModal.vue` — passes the desired w/h to the placer
- Modifies `grid-layout` REQ-GRID-006 (Widget Auto-Layout)

## Why a delta

REQ-GRID-006 already declares "auto-layout" but doesn't specify the algorithm or the fallback behaviour when the grid is fully occupied. This change spells it out so the UX is predictable across browsers.

## Approach

- Primary: call `grid.addWidget({...newWidget, autoPosition: true})` — GridStack scans for the first empty rectangle that fits.
- Fallback (when GridStack has no fit at viewport-visible region): place at `(x:0, y:0)` and shift overlapping widgets down by `newH` rows. This matches the user expectation "new things appear at the top-left" without losing any existing widget.
- All position writes MUST be persisted immediately (REQ-GRID-005 already covers position persistence).

## Notes

- We deliberately do not push *to* the bottom of the grid (which would put the new widget off-screen on small grids) — top-left + push-down is more discoverable for first-time users.
- The shift-down algorithm is naive (one pass, push to `newH`) and may cause a small layout disruption when the dashboard is densely packed. Acceptable trade-off; document in the scenario.



## Design

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



## Tasks

# Tasks — widget-collision-placement

## 1. Frontend composable

- [ ] 1.1 Add `placeNewWidget(spec): {x, y, w, h}` exported from `src/composables/useGridManager.js`
- [ ] 1.2 Step 1 — try `grid.addWidget({autoPosition: true, ...spec})` and capture the resulting position
- [ ] 1.3 Step 2 — if step 1 returns no slot OR slot is below `viewportRows`, fall back to push-down
- [ ] 1.4 Push-down implementation — iterate `layout.value`, compute overlap with `[0..newW] × [0..newH]`, set `widget.gridY = newH` for overlappers, call `grid.update(el, {y})` for each
- [ ] 1.5 Define default size constants `DEFAULT_W = 4`, `DEFAULT_H = 4` and use them when caller omits `w`/`h`
- [ ] 1.6 Inline comment on the helper linking REQ-GRID-006 + REQ-GRID-014 and the "top-left + push-down" rationale from design.md

## 2. Add-widget UI integration

- [ ] 2.1 Refactor `src/components/AddWidgetModal.vue` submit handler to call `placeNewWidget(spec)` only
- [ ] 2.2 Remove any direct `grid.addWidget(...)` calls from component templates
- [ ] 2.3 Confirm toolbar dropdown, keyboard shortcut, and drag-from-picker code paths all funnel through the helper

## 3. Persistence

- [ ] 3.1 Trigger batch placement-update API after placement (debounced 300ms — reuse existing pattern from REQ-WDG-008)
- [ ] 3.2 Single round-trip carrying both the new widget and all pushed-down widgets
- [ ] 3.3 Verify the persisted positions match the in-memory `layout.value` after the round-trip resolves

## 4. Vitest unit coverage

- [ ] 4.1 `placeNewWidget` auto-positions into empty space when GridStack finds a slot (no pushes)
- [ ] 4.2 Push-down fallback runs when the top region is full — overlapping widgets gain `gridY = newH`
- [ ] 4.3 Non-overlapping widgets are unchanged after fallback runs
- [ ] 4.4 Default size `(4, 4)` is applied when `spec.w` / `spec.h` are omitted
- [ ] 4.5 Pushed widgets keep their `gridX` and `gridW` (only `gridY` changes)

## 5. Playwright e2e

- [ ] 5.1 Add 5 widgets in sequence to a 12-col empty dashboard; verify visually-correct positions
- [ ] 5.2 Add a 6th widget to a top-full dashboard; verify the push-down fallback shifts overlappers down
- [ ] 5.3 Verify position writes survive a page reload (REQ-GRID-005 persistence)

## 6. Architectural enforcement

- [ ] 6.1 Add a grep test (Vitest or CI script) asserting `grid.addWidget` only appears inside `useGridManager.js` and its test file
- [ ] 6.2 Document the rule in the composable file's header comment so reviewers see it during code review

## 7. Quality gates

- [ ] 7.1 ESLint clean on touched JS / Vue files
- [ ] 7.2 No new PHPCS/PHPMD/PHPStan/Psalm regressions (frontend-only change, but run `composer check:strict` to confirm)
- [ ] 7.3 i18n review — no new user-facing strings expected; if any are introduced, add them to both `nl` and `en` per the i18n requirement