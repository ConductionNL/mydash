# Design — responsive-grid-breakpoints

## Context

MyDash uses GridStack 10.3.1 (per `openspec/config.yaml`) initialised on a 12-column grid with `cellHeight: 80` and a small inter-cell margin. REQ-GRID-007 currently asserts only that the grid SHOULD be responsive — there are no concrete breakpoints, no reflow algorithm, and no unit on the geometry constants. In practice the grid stays 12-column even at 480 px viewport, which makes widgets unreadably narrow on tablets and phones.

This change pins down four explicit breakpoints, the `moveScale` reflow algorithm, the cell/margin geometry, and the GridStack major-version dependency — converting an aspirational requirement into a testable one.

## Goals / Non-Goals

**Goals:**

- Make REQ-GRID-007 testable (concrete column counts at concrete viewport widths).
- Centralise the geometry constants (`CELL_HEIGHT`, `GRID_MARGIN`, `BREAKPOINTS`) in the grid composable so no component duplicates literals.
- Pick a reflow algorithm (`moveScale`) that scales widgets proportionally rather than reordering or wrapping.
- Pin the GridStack major to a version (v12+) that supports the `columnOpts.breakpoints` shape used here.

**Non-Goals:**

- Changing `acceptWidgets`, `staticGrid`, or `float` — those belong to REQ-GRID-004 (view-vs-edit) and are unaffected.
- Per-widget responsive overrides (e.g. "this widget keeps width 12 even at 4-col grid"). Future work; tracked as roadmap item if needed.
- Changing widget collision rules — REQ-GRID-006 / REQ-GRID-014 are unaffected.

## Decisions

### D1: Breakpoint set — 1400 / 1100 / 768 / 480

**Decision**: Four entries: `[{w:1400,c:12}, {w:1100,c:8}, {w:768,c:4}, {w:480,c:1}]`.

**Alternatives considered:**

- Three breakpoints (desktop / tablet / mobile, e.g. 1200/768/480): rejected — leaves too large a step from 12 to 4 columns, widgets jerk visually.
- Five+ breakpoints with finer steps: rejected — extra reflow events on common laptop widths (1280, 1366) without a meaningful layout improvement.

**Rationale**: 1400 covers full HD+ desktops; 1100 catches the common 1280/1366 laptop range (after sidebar); 768 is the iPad portrait threshold; 480 is the standard mobile breakpoint. Monotonically descending column counts (12, 8, 4, 1) keep the math even — every column count is a divisor of 24, so half/third widgets stay snapped.

### D2: Reflow algorithm — `'moveScale'`

**Decision**: Set `columnOpts.layout = 'moveScale'`.

**Alternatives considered:**

- `'list'` — collapses to a single column on column change. Rejected because it ignores the carefully picked column counts.
- `'compact'` — re-flows top-to-bottom, may reorder widgets. Rejected because user-arranged layout is meaningful (left-of-right semantics).
- `'none'` — preserves x/w; widgets overflow at smaller column counts. Rejected — the entire point is to reflow.

**Rationale**: `'moveScale'` proportionally rescales widget widths so a half-width widget at 12 cols (`w=6`) becomes a half-width widget at 8 cols (`w=4`) and at 4 cols (`w=2`). User intent is preserved.

### D3: Cell height — 60 px (vs current 80 px in config.yaml)

**Decision**: `CELL_HEIGHT = 60` (px), `GRID_MARGIN = 8` (px). Spec'd as 60; flag for stakeholder confirmation in tasks 2.1.

**Alternatives considered:**

- Keep 80 px (current `openspec/config.yaml` documented value): more vertical breathing room per row; existing widget designs may already assume 80.
- Move to 60 px: denser dashboards, better for multi-row info widgets common in MyDash usage.

**Rationale**: 60 px fits the modern dashboard density that recent UX feedback has favoured, but the 80 vs 60 trade-off is a real product call. Tasks 2.1/2.2 hold this open until stakeholder sign-off; if 80 wins the constants and the REQ-GRID-012 height-math scenario flip together (one place each).

### D4: GridStack v12 pin (vs current 10.3.1)

**Decision**: Bump `gridstack` to `^12.2.1`.

**Alternatives considered:**

- Stay on 10.3.1: `columnOpts.breakpoints` is supported on v10+, so technically possible. Rejected because v12 ships fixes for the `moveScale` algorithm we depend on, plus security/maintenance updates.
- Skip to v13 if released: rejected as out of scope — bump deliberately to a tested major.

**Rationale**: v10 -> v12 has a small set of breaking changes (column option shape, a few removed callbacks) handled in task 1.1. REQ-GRID-013 codifies the version floor as `>= 10.0.0` so a future regression to v11 still satisfies the spec, but `package.json` declares the actual `^12.2.1` range we ship.

### D5: Constants shared from the grid composable, not from a CSS file

**Decision**: Constants live in JS (`useGridManager.js` or equivalent composable) and are mirrored to a CSS custom property `--mydash-cell-height` at init time.

**Rationale**: GridStack reads JS values; CSS `calc()` reads CSS values. Single source-of-truth in JS, with a one-way sync to CSS, prevents the two from drifting. Avoids a SCSS/JS dual-definition that has bitten other capabilities.

## Risks

- **GridStack v10 -> v12 breakages.** Some callbacks were renamed; addressed in task 1.1, covered by Playwright regression in task 3.2.
- **80 vs 60 cell height ambiguity.** Existing widget templates may visually break at 60 px if they baked-in 80 px row heights. Task 2.1 forces an explicit decision before apply; visual regression tests at task 3.2 catch any breakage.
- **Reflow at resize is visually jarring** if the user has many widgets. `moveScale` is the least jarring algorithm available — we accept the small jump-cut.

## Migration

No data migration. Existing `gridX/gridY/gridWidth/gridHeight` values stay as authored at the 12-column reference scale; GridStack's `moveScale` derives the smaller-column placements at render time without rewriting persisted positions.
