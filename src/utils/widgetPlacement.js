/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Widget placement helper implementing REQ-GRID-006 (modified) + REQ-GRID-014.
 *
 * This module provides the single placement authority for all "add widget" code paths.
 * Inline GridStack add-widget calls outside this file (and useGridManager.js)
 * are forbidden per REQ-GRID-014 — the test grep enforces it.
 *
 * Algorithm:
 * 1. Try GridStack auto-position: invoke the grid's add-widget API with
 *    `{autoPosition: true, ...spec}`
 *    - If it finds an empty slot within the visible viewport (y < viewportRows), use it
 * 2. Fallback: place at top-left (0, 0) and push overlapping widgets down by newH rows
 *    - For every existing widget whose rect overlaps [0..newW] × [0..newH],
 *      set its gridY = newH (just below the new widget)
 *    - Non-overlapping widgets are NOT moved
 *
 * Rationale: Top-left + push-down matches first-run user expectations ("new things at the top")
 * while preserving all existing widgets and keeping the new one visible (never below fold).
 */

const DEFAULT_W = 4
const DEFAULT_H = 4

/**
 * Detects if two axis-aligned rectangles overlap.
 * Rectangle A: [aX..aX+aW] × [aY..aY+aH]
 * Rectangle B: [bX..bX+bW] × [bY..bY+bH]
 *
 * @param {number} aX rect A x-start
 * @param {number} aY rect A y-start
 * @param {number} aW rect A width
 * @param {number} aH rect A height
 * @param {number} bX rect B x-start
 * @param {number} bY rect B y-start
 * @param {number} bW rect B width
 * @param {number} bH rect B height
 * @return {boolean} true if rectangles overlap
 */
function rectsOverlap(aX, aY, aW, aH, bX, bY, bW, bH) {
	return !(aX + aW <= bX || bX + bW <= aX || aY + aH <= bY || bY + bH <= aY)
}

/**
 * Place a new widget on the dashboard using GridStack auto-position + fallback push-down.
 *
 * @param {object} spec widget spec with optional {w, h} dimensions
 * @param {number} spec.w widget width in grid columns (default: 4)
 * @param {number} spec.h widget height in grid rows (default: 4)
 * @param {Array} layout current layout array (placement objects with gridX, gridY, gridWidth, gridHeight)
 * @param {object} gridInstance GridStack instance (with addWidget and update methods)
 * @param {number} viewportRows visible grid rows (used to detect "below fold" auto-position)
 *
 * @return {object} placement position {x, y, w, h} for the new widget
 *   Note: the caller MUST persist this position AND any pushed-down widgets via the standard API.
 */
export function placeNewWidget(spec, layout, gridInstance, viewportRows = 8) {
	const newW = spec.w ?? DEFAULT_W
	const newH = spec.h ?? DEFAULT_H

	// Step 1: Try GridStack auto-position
	if (gridInstance && gridInstance.addWidget) {
		try {
			const tempEl = document.createElement('div')
			tempEl.setAttribute('gs-w', String(newW))
			tempEl.setAttribute('gs-h', String(newH))

			gridInstance.addWidget(tempEl, {
				x: 0,
				y: 0,
				w: newW,
				h: newH,
				autoPosition: true,
			})

			// Check if the placement is within the viewport
			const engineNode = gridInstance.engine.nodes.find(n => n.el === tempEl)
			if (engineNode && engineNode.y < viewportRows) {
				// Success: GridStack found a slot within the visible region
				const result = {
					x: engineNode.x,
					y: engineNode.y,
					w: newW,
					h: newH,
				}

				// Clean up the temp element
				gridInstance.removeWidget(tempEl, false)
				return result
			}

			// Auto-position placed below viewport; fall through to step 2
			gridInstance.removeWidget(tempEl, false)
		} catch (error) {
			// Grid instance not ready or error occurred; fall through to step 2
			console.warn('[widgetPlacement] GridStack auto-position failed, using fallback:', error)
		}
	}

	// Step 2: Fallback - place at top-left and push overlapping widgets down
	const newPlacements = []

	// Identify overlapping widgets and prepare push-down updates
	for (const widget of layout) {
		const {
			gridX: wX,
			gridY: wY,
			gridWidth: wW,
			gridHeight: wH,
		} = widget

		// Check if this widget overlaps the new widget's footprint [0..newW] × [0..newH]
		if (rectsOverlap(0, 0, newW, newH, wX, wY, wW, wH)) {
			// Push this widget down to y = newH
			newPlacements.push({
				id: widget.id,
				gridY: newH,
			})
		}
	}

	// Apply push-down updates to the grid instance
	for (const update of newPlacements) {
		const widget = layout.find(w => w.id === update.id)
		if (widget && gridInstance && gridInstance.update) {
			const el = document.querySelector(`[gs-id="${update.id}"]`)
			if (el) {
				gridInstance.update(el, {
					y: update.gridY,
				})
			}
		}
	}

	return {
		x: 0,
		y: 0,
		w: newW,
		h: newH,
	}
}
