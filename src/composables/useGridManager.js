/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared grid configuration for the MyDash GridStack instance.
 *
 * Single source of truth for the geometry constants and the responsive
 * breakpoint table referenced by REQ-GRID-007 (responsive breakpoints),
 * REQ-GRID-012 (cell geometry), and REQ-GRID-013 (GridStack version pin).
 * Also hosts the canonical add-widget placement helper required by
 * REQ-GRID-006 (Widget Auto-Layout) and REQ-GRID-014 (single placement
 * authority).
 *
 * Why this module exists
 * ----------------------
 * Before this module the cell height (80 px), the inter-cell margin (12 px)
 * and the implicit "always 12 columns" behaviour were each duplicated across
 * `DashboardGrid.vue`, `css/mydash.css`, the OpenSpec scenarios, and the
 * `openspec/config.yaml` documentation. Drift between any two of those
 * locations produced subtle layout regressions (a widget shorter than its
 * collision math expected, a media-query breakpoint that disagreed with the
 * GridStack engine, etc.). This composable centralises every value into a
 * single export so the GridStack init call, the CSS custom property, and the
 * Vitest assertions all read from one place.
 *
 * Naming note
 * -----------
 * GridStack v12 calls the responsive options bag `columnOpts` (not
 * `columnOptions` or `responsive`). The exported `getColumnOpts()` factory
 * returns an object that can be spread directly into `GridStack.init`.
 *
 * Placement-helper rule (REQ-GRID-014)
 * ------------------------------------
 * The exported `placeNewWidget(spec, placements, options?)` helper is the
 * SINGLE authority for "where does the next widget go?". All add-widget
 * code paths (toolbar dropdown, keyboard shortcut, drag-from-picker,
 * AddWidgetModal submit) MUST funnel through it. Inline calls to
 * `grid.addWidget(...)` outside this file are forbidden and enforced by a
 * grep test in `__tests__/useGridManager.spec.js`.
 */

/**
 * Cell height in pixels. Single source of truth for both the JS init call
 * and the `--mydash-cell-height` CSS custom property. Defaulted to 60 per
 * the responsive-grid-breakpoints proposal; flip to 80 here (single edit)
 * if the stakeholder later prefers more vertical breathing room.
 *
 * @type {number}
 */
export const CELL_HEIGHT = 60

/**
 * Inter-cell margin in pixels. Applied uniformly to all four sides by
 * GridStack when passed as a number (see GridStackOptions.margin).
 *
 * @type {number}
 */
export const GRID_MARGIN = 8

/**
 * Default column count for a dashboard that does not override `gridColumns`.
 * GridStack will use this whenever the viewport is wider than the largest
 * breakpoint entry below.
 *
 * @type {number}
 */
export const DEFAULT_COLUMNS = 12

/**
 * Responsive breakpoint table — four entries, monotonically descending
 * column counts. Each entry says: "for viewports up to `w` px wide, use
 * `c` columns". GridStack v12 matches the smallest entry whose `w` is
 * greater than or equal to the current viewport width; below the smallest
 * entry the smallest column count applies.
 *
 * The four entries map cleanly to:
 *   1400 px : full HD desktops (12 columns)
 *   1100 px : standard laptops with the Nextcloud sidebar visible (8)
 *    768 px : iPad portrait threshold (4)
 *    480 px : standard mobile breakpoint (1, single-column stack)
 *
 * @type {Array<{ w: number, c: number }>}
 */
export const BREAKPOINTS = Object.freeze([
	{ w: 1400, c: 12 },
	{ w: 1100, c: 8 },
	{ w: 768, c: 4 },
	{ w: 480, c: 1 },
])

/**
 * GridStack reflow algorithm name. `'moveScale'` proportionally rescales
 * widget widths and positions when the column count changes (a half-width
 * widget at 12 cols becomes a half-width widget at 8 cols). See decision
 * D2 in `responsive-grid-breakpoints/design.md` for alternatives.
 *
 * @type {'moveScale'}
 */
export const COLUMN_LAYOUT = 'moveScale'

/**
 * CSS custom-property name used to mirror `CELL_HEIGHT` into the cascade
 * so any `calc()` expression can stay in sync without re-importing the JS
 * constant.
 *
 * @type {string}
 */
export const CELL_HEIGHT_CSS_VAR = '--mydash-cell-height'

/**
 * Build the `columnOpts` object passed to `GridStack.init`. Returned as a
 * fresh shallow copy so callers can mutate without affecting the frozen
 * `BREAKPOINTS` constant.
 *
 * `breakpointForWindow: true` makes GridStack match against the window
 * (viewport) width rather than the grid container's own width. Our
 * scenarios are written against viewport widths, so we want the window
 * mode.
 *
 * @return {{ breakpoints: Array<{ w: number, c: number }>, layout: string, breakpointForWindow: boolean }}
 */
export function getColumnOpts() {
	return {
		breakpoints: BREAKPOINTS.map(b => ({ ...b })),
		layout: COLUMN_LAYOUT,
		breakpointForWindow: true,
	}
}

/**
 * Mirror the JS `CELL_HEIGHT` value into the CSS `--mydash-cell-height`
 * custom property on the document root so `calc()` expressions in
 * stylesheets stay in lock-step with the GridStack init value. Safe to
 * call repeatedly; idempotent.
 *
 * No-op when `document` is unavailable (SSR / Vitest jsdom without a body).
 *
 * @return {void}
 */
export function syncCellHeightCssVar() {
	if (typeof document === 'undefined' || !document.documentElement) {
		return
	}
	document.documentElement.style.setProperty(
		CELL_HEIGHT_CSS_VAR,
		`${CELL_HEIGHT}px`,
	)
}

/**
 * Default widget width in grid columns when the caller omits `spec.w`.
 * 4 of 12 columns = roughly one third of the row, matches the modal's
 * pre-existing default and is wide enough for charts and lists without
 * dominating an otherwise empty dashboard. See design.md D3.
 *
 * @type {number}
 */
export const DEFAULT_W = 4

/**
 * Default widget height in grid rows when the caller omits `spec.h`.
 * 4 rows ≈ 264 px at the canonical (CELL_HEIGHT 60, GRID_MARGIN 8) geometry
 * (REQ-GRID-012), readable for most renderers. See design.md D3.
 *
 * @type {number}
 */
export const DEFAULT_H = 4

/**
 * Fallback "viewport rows" used when the caller does not pass a measured
 * value (e.g. composable invoked before the grid container is mounted).
 * 8 rows × 60 px ≈ 480 px which is the smallest first-paint surface we
 * expect on the 480 px breakpoint. See design.md "Open Questions".
 *
 * @type {number}
 */
export const DEFAULT_VIEWPORT_ROWS = 8

/**
 * Pure rectangle-overlap test on integer grid coordinates. Two rects
 * overlap when each axis interval [start, start+size) intersects.
 *
 * @param {{x: number, y: number, w: number, h: number}} a first rect
 * @param {{x: number, y: number, w: number, h: number}} b second rect
 * @return {boolean} true when the rects share at least one cell
 */
function rectsOverlap(a, b) {
	return (
		a.x < b.x + b.w
		&& b.x < a.x + a.w
		&& a.y < b.y + b.h
		&& b.y < a.y + a.h
	)
}

/**
 * Engine-free emulation of GridStack's `findEmptyPosition` scan: walk the
 * grid row-by-row, column-by-column, and return the first `(x, y)` where
 * a `(w, h)` rectangle does not collide with any node in `nodes`.
 *
 * Mirrors the v12 engine search order so a Vitest run without a real
 * GridStack instance produces the same answer as a live grid would.
 *
 * @param {{w: number, h: number}} sz target widget size in cells
 * @param {Array<{x: number, y: number, w: number, h: number}>} nodes existing widgets in `{x,y,w,h}` form
 * @param {number} columns total grid columns
 * @param {number} maxScanRows scan ceiling — stops searching past this row
 * @return {{x: number, y: number} | null} found slot, or null when nothing fits within `maxScanRows`
 */
function scanForEmptySlot(sz, nodes, columns, maxScanRows) {
	if (sz.w > columns) {
		return null
	}
	for (let y = 0; y < maxScanRows; y++) {
		for (let x = 0; x <= columns - sz.w; x++) {
			const candidate = { x, y, w: sz.w, h: sz.h }
			const collides = nodes.some(n => rectsOverlap(candidate, n))
			if (!collides) {
				return { x, y }
			}
		}
	}
	return null
}

/**
 * Compute the placement coordinates and any required push-down side effects
 * for a new widget being added to the dashboard.
 *
 * Algorithm (REQ-GRID-006):
 *   1. **Primary** — try `grid.addWidget({...spec, autoPosition: true})`
 *      when a live GridStack instance is supplied; otherwise emulate the
 *      same scan with `scanForEmptySlot`. GridStack picks the first empty
 *      `(w × h)` rectangle in row-major order.
 *   2. **Fallback** — when step 1 returns no slot OR the picked slot is
 *      below `viewportRows` (off-screen on first paint), place the new
 *      widget at `(0, 0)` and shift every overlapping existing widget to
 *      `gridY = h`. Non-overlapping widgets are not moved (REQ-GRID-006
 *      "Pushed widgets remain within their column lane").
 *
 * **Single placement authority (REQ-GRID-014)**: this function is the only
 * legal caller of `grid.addWidget(...)` in the codebase. A grep test in
 * `__tests__/useGridManager.spec.js` enforces the rule.
 *
 * The helper is pure with respect to its inputs — it never mutates
 * `placements`. Callers receive `{ x, y, w, h, pushed }` and are
 * responsible for routing the result through the existing batch
 * persistence path (REQ-GRID-005 + REQ-WDG-008, debounce 300 ms).
 *
 * @param {object} spec target widget spec — `w`/`h` default to {@link DEFAULT_W}/{@link DEFAULT_H}
 * @param {number} [spec.w] desired width in cells, defaults to DEFAULT_W
 * @param {number} [spec.h] desired height in cells, defaults to DEFAULT_H
 * @param {Array<object>} placements current placements in MyDash field-name form (`gridX`, `gridY`, `gridWidth`, `gridHeight`, `id`)
 * @param {object} [options] optional knobs
 * @param {number} [options.gridColumns] column count of the active dashboard, defaults to {@link DEFAULT_COLUMNS}
 * @param {number} [options.viewportRows] visible rows on first paint; below this is treated as "off-screen", defaults to {@link DEFAULT_VIEWPORT_ROWS}
 * @param {object} [options.grid] live GridStack instance — when supplied the engine is used directly; otherwise a JS scan emulates it
 * @return {{ x: number, y: number, w: number, h: number, pushed: Array<{id: any, gridY: number}> }} computed placement and the list of existing-placement push-downs
 */
export function placeNewWidget(spec, placements, options = {}) {
	const w = (spec && Number.isFinite(spec.w) && spec.w > 0) ? spec.w : DEFAULT_W
	const h = (spec && Number.isFinite(spec.h) && spec.h > 0) ? spec.h : DEFAULT_H
	const columns = options.gridColumns || DEFAULT_COLUMNS
	const viewportRows = options.viewportRows || DEFAULT_VIEWPORT_ROWS

	const safePlacements = Array.isArray(placements) ? placements : []
	const nodes = safePlacements.map(p => ({
		id: p.id,
		x: Number.isFinite(p.gridX) ? p.gridX : 0,
		y: Number.isFinite(p.gridY) ? p.gridY : 0,
		w: Number.isFinite(p.gridWidth) ? p.gridWidth : 1,
		h: Number.isFinite(p.gridHeight) ? p.gridHeight : 1,
	}))

	// Step 1 — primary path: ask GridStack (or our emulation) for the
	// first empty rectangle that fits. We deliberately do NOT touch the
	// DOM here; the caller owns the rendering pipeline (Vue reactivity
	// inserts the placement node and `syncGridItems` calls
	// `makeWidget(el)`). This helper only computes coordinates.
	let primaryHit = null
	if (options.grid && options.grid.engine) {
		// Probe the live engine without committing. We clone the node so
		// `findEmptyPosition` can mutate `node.x`/`node.y` without
		// leaking into the engine's internal node list.
		const probe = { w, h, _id: '__mydash_probe__' }
		// Build a node list snapshot the engine can scan. Excluding the
		// probe itself avoids a self-collision false positive.
		const liveNodes = options.grid.engine.nodes.filter(n => n._id !== probe._id)
		const found = options.grid.engine.findEmptyPosition(probe, liveNodes, columns)
		if (found) {
			primaryHit = { x: probe.x, y: probe.y }
		}
	} else {
		// JS-emulated scan — same row-major order GridStack uses, capped
		// at a generous depth so we don't loop forever on pathological
		// inputs. The cap is intentionally large (4× viewport) so dense
		// dashboards can still legitimately use vertical space.
		primaryHit = scanForEmptySlot({ w, h }, nodes, columns, viewportRows * 4)
	}

	// Treat "below the fold" as a primary-step failure (design.md D5):
	// users assume the click did nothing when the new widget lands off
	// the visible area, so we fall back to top-left + push-down instead.
	const primaryAcceptable = primaryHit !== null && primaryHit.y < viewportRows

	if (primaryAcceptable) {
		return { x: primaryHit.x, y: primaryHit.y, w, h, pushed: [] }
	}

	// Step 2 — fallback: top-left + push-down (design.md D1 + D4).
	// Place at (0, 0) and shift every widget that overlaps the new
	// rectangle to `gridY = h`. Non-overlappers are untouched so the
	// blast radius stays minimal. We push by `h` (one consistent
	// y-coordinate, single-pass O(n)) rather than per-widget minimum
	// shift — see design.md D4 for the trade-off.
	const newRect = { x: 0, y: 0, w, h }
	const pushed = []
	for (const node of nodes) {
		if (rectsOverlap(newRect, node)) {
			pushed.push({ id: node.id, gridY: h })
		}
	}

	return { x: 0, y: 0, w, h, pushed }
}
