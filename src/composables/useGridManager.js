/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared grid configuration for the MyDash GridStack instance.
 *
 * Single source of truth for the geometry constants and the responsive
 * breakpoint table referenced by REQ-GRID-007 (responsive breakpoints),
 * REQ-GRID-012 (cell geometry), and REQ-GRID-013 (GridStack version pin).
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
