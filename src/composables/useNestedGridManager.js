/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * useNestedGridManager — Vue 2 composable that wraps an inner GridStack
 * instance for the `container` widget type (REQ-CONT-001..007).
 *
 * The inner grid is bounded by the container's outer cell and uses a
 * smaller, fixed configuration so child widgets fit inside a typical
 * container size (REQ-CONT-002):
 *
 *   - column      : 4   (vs 12 outer)
 *   - cellHeight  : 40  (vs 60 outer)
 *   - margin      : 4   (vs 8 outer)
 *   - disableOneColumnMode : true — nested grids do NOT respond to
 *     viewport breakpoints; they retain 4 columns regardless of viewport
 *     width (paired with grid-layout REQ-GRID-015 added by this change).
 *   - acceptWidgets : true — drag-from-picker into the inner grid works
 *     in edit mode without disturbing the outer grid (REQ-CONT-005).
 *
 * `placeNewWidget(spec, placements, options)` mirrors the outer-grid
 * helper but is bounded to 4 columns. The persistence callback wires
 * back into the parent ContainerWidget so the placement's
 * `content.placements[]` array is updated and the change can be saved
 * via the existing widget-update API.
 */

import {
	placeNewWidget as outerPlaceNewWidget,
} from './useGridManager.js'

/**
 * Inner-grid column count (REQ-CONT-002).
 *
 * @type {number}
 */
export const NESTED_COLUMNS = 4

/**
 * Inner-grid cell height in pixels (REQ-CONT-002).
 *
 * @type {number}
 */
export const NESTED_CELL_HEIGHT = 40

/**
 * Inner-grid inter-cell margin in pixels (REQ-CONT-002).
 *
 * @type {number}
 */
export const NESTED_MARGIN = 4

/**
 * Default child widget width inside a container (in inner-grid columns).
 *
 * @type {number}
 */
export const NESTED_DEFAULT_W = 2

/**
 * Default child widget height inside a container (in inner-grid rows).
 *
 * @type {number}
 */
export const NESTED_DEFAULT_H = 2

/**
 * Build the GridStack init options bag for an inner (nested) grid. Returned
 * as a fresh shallow copy so callers can mutate without aliasing.
 *
 * @return {{column: number, cellHeight: number, margin: number, acceptWidgets: boolean, disableOneColumnMode: boolean}}
 */
export function getNestedGridOptions() {
	return {
		column: NESTED_COLUMNS,
		cellHeight: NESTED_CELL_HEIGHT,
		margin: NESTED_MARGIN,
		acceptWidgets: true,
		disableOneColumnMode: true,
	}
}

/**
 * Compute the placement coordinates for a NEW child widget inside a
 * container's inner grid, bounded to {@link NESTED_COLUMNS} columns.
 *
 * Reuses the outer-grid `placeNewWidget` algorithm (top-left + push-down
 * fallback), passing the inner-grid column count via `options.gridColumns`
 * so the scan respects the 4-column ceiling.
 *
 * @param {object} spec target widget spec — `w`/`h` default to
 *   {@link NESTED_DEFAULT_W} / {@link NESTED_DEFAULT_H}.
 * @param {Array<object>} placements current child placements in MyDash
 *   field-name form (`gridX`, `gridY`, `gridWidth`, `gridHeight`, `id`).
 * @param {object} [options] optional knobs
 * @param {number} [options.viewportRows] visible rows on first paint
 * @param {object} [options.grid] live inner GridStack instance
 * @return {{ x: number, y: number, w: number, h: number, pushed: Array<{id: any, gridY: number}> }}
 */
export function placeNewWidget(spec, placements, options = {}) {
	const w = (spec && Number.isFinite(spec.w) && spec.w > 0) ? spec.w : NESTED_DEFAULT_W
	const h = (spec && Number.isFinite(spec.h) && spec.h > 0) ? spec.h : NESTED_DEFAULT_H
	return outerPlaceNewWidget(
		{ ...spec, w, h },
		placements,
		{
			...options,
			gridColumns: NESTED_COLUMNS,
		},
	)
}

/**
 * Factory: create an inner-grid manager bound to a DOM element ref and a
 * reactive `placements` array on the parent ContainerWidget.
 *
 * The composable does NOT itself import the GridStack runtime — that
 * lives in DashboardGrid + the container renderer's mounted hook so the
 * unit test surface stays JSDOM-friendly. Instead it returns the helpers
 * the renderer needs (init options, placement helper, persistence
 * callback) and lets the renderer hand the GridStack instance back via
 * `attach(grid)`.
 *
 * @param {object} options factory options
 * @param {Function} options.persistPlacements called with the new
 *   `placements[]` array whenever a child is added / moved / resized /
 *   removed. The renderer wires this to a `update:content` emit so the
 *   parent placement's `content.placements[]` is persisted via the
 *   existing widget-update path (REQ-CONT-005).
 * @return {{
 *   getOptions: () => object,
 *   placeNewWidget: (spec: object, placements: Array<object>, options?: object) => object,
 *   persist: (placements: Array<object>) => void,
 * }}
 */
export function useNestedGridManager(options = {}) {
	const persistPlacements = typeof options.persistPlacements === 'function'
		? options.persistPlacements
		: () => {}

	return {
		getOptions: getNestedGridOptions,
		placeNewWidget,
		persist(placements) {
			persistPlacements(Array.isArray(placements) ? placements : [])
		},
	}
}
