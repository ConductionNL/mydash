/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * useGridManager — combined GridStack configuration + Vue 2 composable.
 *
 * Responsibilities split into two halves:
 *
 * 1. Grid configuration constants and helpers (REQ-GRID-007 responsive
 *    breakpoints, REQ-GRID-012 cell geometry, REQ-GRID-013 GridStack v12 pin):
 *    - `CELL_HEIGHT`, `GRID_MARGIN`, `DEFAULT_COLUMNS`, `BREAKPOINTS`,
 *      `COLUMN_LAYOUT`, `CELL_HEIGHT_CSS_VAR`
 *    - `getColumnOpts()` — returns the `columnOpts` bag for `GridStack.init`
 *    - `syncCellHeightCssVar()` — mirrors `CELL_HEIGHT` into a CSS variable
 *    - `placeNewWidget(widgets, w, h, columns)` — collision-free placement
 *      helper used by REQ-WDG-013/014 (widget-collision-placement).
 *
 * 2. Right-click context-menu state for widget placements
 *    (REQ-WDG-015..017, widget-context-menu):
 *    - `useGridManager({canEdit, onEdit, onRemove, ...})` factory
 *    - Tracks `contextMenuOpen`, `contextMenuPosition`, `selectedWidget`
 *    - `onWidgetRightClick`, `closeContextMenu`, `triggerEdit`,
 *      `triggerRemove`, `attach`, `detach`
 *
 * Why combined: both halves were authored independently (responsive grid
 * breakpoints + widget context menu) but converged on a single composable
 * file as the natural home for grid-coupled logic. Keeping them together
 * means the host's GridStack init call and the right-click handler import
 * from one module.
 *
 * Naming note
 * -----------
 * GridStack v12 calls the responsive options bag `columnOpts` (not
 * `columnOptions` or `responsive`). The exported `getColumnOpts()` factory
 * returns an object that can be spread directly into `GridStack.init`.
 */

import Vue from 'vue'

// ---------------------------------------------------------------------------
// Grid configuration constants (REQ-GRID-007/012/013)
// ---------------------------------------------------------------------------

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
 * widget at 12 cols becomes a half-width widget at 8 cols).
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
 * Find the first non-overlapping `(x, y)` slot for a new widget of size
 * `w × h` cells inside a grid that already contains `widgets`. Used by
 * `addWidget` flows (REQ-WDG-013/014) so newly created widgets never
 * land on top of existing ones.
 *
 * Algorithm: row-major scan. For each row from `y=0` upward, try each
 * column `x` from `0` to `columns - w`; the first `(x, y)` whose footprint
 * does not intersect any existing placement wins. The scan is bounded by
 * the highest occupied row + height of the new widget, guaranteeing
 * termination on a finite (or empty) grid.
 *
 * @param {Array<{ x: number, y: number, w: number, h: number }>} widgets
 *   currently placed widgets (each must declare `x`, `y`, `w`, `h`).
 * @param {number} w  width of the new widget in cells (>= 1).
 * @param {number} h  height of the new widget in cells (>= 1).
 * @param {number} [columns=DEFAULT_COLUMNS]  total grid column count.
 * @return {{ x: number, y: number }} top-left of the chosen slot.
 */
export function placeNewWidget(widgets, w, h, columns = DEFAULT_COLUMNS) {
	const safeWidgets = Array.isArray(widgets) ? widgets : []
	const safeColumns = Math.max(1, columns)
	const safeW = Math.min(Math.max(1, w), safeColumns)
	const safeH = Math.max(1, h)

	const rectsOverlap = (ax, ay, aw, ah, bx, by, bw, bh) => {
		return ax < bx + bw && ax + aw > bx && ay < by + bh && ay + ah > by
	}

	const maxOccupiedRow = safeWidgets.reduce((max, p) => {
		const bottom = (Number(p.y) || 0) + (Number(p.h) || 1)
		return bottom > max ? bottom : max
	}, 0)

	const yLimit = maxOccupiedRow + safeH

	for (let y = 0; y <= yLimit; y++) {
		for (let x = 0; x <= safeColumns - safeW; x++) {
			const collides = safeWidgets.some(p => rectsOverlap(
				x, y, safeW, safeH,
				Number(p.x) || 0, Number(p.y) || 0,
				Number(p.w) || 1, Number(p.h) || 1,
			))
			if (!collides) {
				return { x, y }
			}
		}
	}

	// Fallback — should never fire because the y-loop is unbounded enough,
	// but keep an explicit return so callers always get a slot.
	return { x: 0, y: maxOccupiedRow }
}

// ---------------------------------------------------------------------------
// Right-click context-menu state (REQ-WDG-015..017)
// ---------------------------------------------------------------------------

/**
 * Default popover dimensions used when clamping. A real popover is
 * `min-width: 150px` (REQ-WDG-017) and approximately three buttons tall;
 * the actual rendered height varies with theme padding so we use a
 * conservative estimate that keeps the menu fully on-screen even when the
 * theme bumps line-height. Hosts can override these by passing
 * `{ menuWidth, menuHeight }` to the factory.
 */
const DEFAULT_MENU_WIDTH = 150
const DEFAULT_MENU_HEIGHT = 132

/**
 * Create a grid-manager state container.
 *
 * @param {object} options factory options
 * @param {{value: boolean}} options.canEdit reactive boolean (Vue.observable
 *   wrapper, ref, or computed) controlling whether right-click opens the
 *   popover; when `.value` is false the right-click falls through to the
 *   browser's native context menu (REQ-WDG-015 view-mode scenario).
 * @param {Function} [options.onEdit] called with `(widget)` when the user
 *   clicks the Edit item. Hosts wire this to open `AddWidgetModal` with
 *   `editingWidget` set (REQ-WDG-010).
 * @param {Function} [options.onRemove] called with `(widget)` when the user
 *   clicks Remove. Hosts wire this to the placement-delete path of
 *   REQ-WDG-005 (`DELETE /api/placements/{id}`).
 * @param {number} [options.menuWidth] override for clamp width (px)
 * @param {number} [options.menuHeight] override for clamp height (px)
 * @param {{innerWidth: number, innerHeight: number}} [options.viewport]
 *   override for the viewport (defaults to `window`); injectable for tests.
 * @return {{
 *   state: {contextMenuOpen: boolean, contextMenuPosition: {x: number, y: number}, selectedWidget: (object|null)},
 *   onWidgetRightClick: (event: MouseEvent, widget: object) => void,
 *   closeContextMenu: () => void,
 *   triggerEdit: () => void,
 *   triggerRemove: () => void,
 *   attach: () => void,
 *   detach: () => void,
 * }}
 */
export function useGridManager(options = {}) {
	const {
		canEdit,
		onEdit,
		onRemove,
		menuWidth = DEFAULT_MENU_WIDTH,
		menuHeight = DEFAULT_MENU_HEIGHT,
		viewport,
	} = options

	const state = Vue.observable({
		contextMenuOpen: false,
		contextMenuPosition: { x: 0, y: 0 },
		selectedWidget: null,
	})

	function getViewport() {
		const v = viewport || (typeof window !== 'undefined' ? window : null)
		if (!v) {
			return { width: Infinity, height: Infinity }
		}
		return { width: v.innerWidth, height: v.innerHeight }
	}

	function clampToViewport(x, y) {
		const { width, height } = getViewport()
		let clampedX = x
		let clampedY = y
		if (clampedX + menuWidth > width) {
			clampedX = Math.max(0, width - menuWidth)
		}
		if (clampedY + menuHeight > height) {
			clampedY = Math.max(0, height - menuHeight)
		}
		return { x: clampedX, y: clampedY }
	}

	function onWidgetRightClick(event, widget) {
		if (!canEdit || !canEdit.value) {
			return
		}
		event.preventDefault()
		const { x, y } = clampToViewport(event.clientX, event.clientY)
		state.contextMenuPosition = { x, y }
		state.selectedWidget = widget
		state.contextMenuOpen = true
	}

	function closeContextMenu() {
		state.contextMenuOpen = false
		state.selectedWidget = null
	}

	function triggerEdit() {
		const widget = state.selectedWidget
		closeContextMenu()
		if (typeof onEdit === 'function' && widget) {
			onEdit(widget)
		}
	}

	function triggerRemove() {
		const widget = state.selectedWidget
		closeContextMenu()
		if (typeof onRemove === 'function' && widget) {
			onRemove(widget)
		}
	}

	function handleDocumentClick(event) {
		if (!state.contextMenuOpen) {
			return
		}
		const target = event.target
		if (target && typeof target.closest === 'function' && target.closest('.widget-context-menu')) {
			return
		}
		closeContextMenu()
	}

	let attached = false

	function attach() {
		if (attached || typeof document === 'undefined') {
			return
		}
		document.addEventListener('click', handleDocumentClick)
		attached = true
	}

	function detach() {
		if (!attached || typeof document === 'undefined') {
			return
		}
		document.removeEventListener('click', handleDocumentClick)
		attached = false
		closeContextMenu()
	}

	return {
		state,
		onWidgetRightClick,
		closeContextMenu,
		triggerEdit,
		triggerRemove,
		attach,
		detach,
	}
}
