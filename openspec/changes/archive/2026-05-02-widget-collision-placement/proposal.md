# Widget collision placement

When a user adds a new widget to a non-empty dashboard, MyDash MUST decide where to place it without overlapping existing widgets and without dropping it off-grid. This change formalises the algorithm: try GridStack's `autoPosition: true` first; if no fit is found (or the picked slot is below the visible viewport), fall back to top-left `(x:0, y:0)` and push overlapping widgets down by `newH` rows. All add-widget code paths MUST funnel through a single placement helper so the behaviour is testable and auditable.

## Affected code units

- `src/composables/useGridManager.js` — new exported helper `placeNewWidget(spec)` is the only legal caller of `grid.addWidget(...)`
- `src/components/AddWidgetModal.vue` — submit handler delegates to `placeNewWidget`, no longer touches GridStack directly
- Toolbar dropdown, keyboard shortcut, and drag-from-picker code paths — all routed through `placeNewWidget`
- Modifies `grid-layout` REQ-GRID-006 (Widget Auto-Layout)
- Adds `grid-layout` REQ-GRID-014 (Placement helper is the single placement authority)

## Why a delta

REQ-GRID-006 already declares "auto-layout" but does not specify the algorithm or the fallback behaviour when the grid is fully occupied at the top. Today, three separate add-widget code paths each compute placement differently — some hard-code `(0, 0)`, some pass `autoPosition: true`, some use the cursor position. The result is inconsistent UX and silent failures (widgets landing below the viewport on dense dashboards). This change spells out the algorithm and centralises the entry point so the UX is predictable across browsers and entry methods.

## Approach

- **Primary** — call `grid.addWidget({...spec, autoPosition: true})`. GridStack scans for the first empty rectangle that fits.
- **Fallback** — when GridStack returns no slot OR the picked slot is below `viewportRows`, place the new widget at `(x:0, y:0)` with size `(newW, newH)` and shift every overlapping existing widget to `gridY = newH`. Non-overlapping widgets are not moved. This matches the user expectation "new things appear at the top-left" without losing any existing widget.
- **Default size** — `w=4, h=4` when caller omits `spec.w` / `spec.h`.
- **Single entry point** — `placeNewWidget(spec)` exported from `useGridManager.js`. Inline `grid.addWidget(...)` outside the helper is forbidden and enforced by a grep test.
- **Persistence** — all position writes (new widget + pushed widgets) MUST be persisted via the existing REQ-GRID-005 + REQ-WDG-008 batch update path with the standard 300ms debounce.

## Capabilities

**Modified Capabilities:**

- `grid-layout` (modifies REQ-GRID-006, adds REQ-GRID-014)

## Notes

- We deliberately do NOT push to the bottom of the grid (which would put the new widget off-screen on small grids) — top-left + push-down is more discoverable for first-time users.
- The shift-down algorithm is naive (single pass, push to `newH`) and may cause minor layout disruption when the dashboard is densely packed everywhere. Acceptable trade-off; documented in the user-facing docs and in a scenario.
- See `design.md` for the detailed decision log (D1 through D5) covering algorithm choice, default size rationale, and the helper-as-single-authority rule.
