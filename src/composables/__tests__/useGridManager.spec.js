/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `useGridManager.js` covering:
 *
 *   - REQ-GRID-007 (Responsive breakpoints): the BREAKPOINTS table has the
 *     four expected entries, monotonically descending column counts, and
 *     `getColumnOpts()` wires the `moveScale` layout + `breakpointForWindow`
 *     flag.
 *   - REQ-GRID-012 (Cell geometry constants): `CELL_HEIGHT === 60`,
 *     `GRID_MARGIN === 8`, and the height-math scenario `(4 * 60) + (3 * 8)
 *     === 264 px` holds.
 *   - syncCellHeightCssVar() writes the `--mydash-cell-height` custom
 *     property on `:root` from the JS constant.
 *   - REQ-GRID-006 (Widget Auto-Layout) + REQ-GRID-014 (single placement
 *     authority): `placeNewWidget()` returns the auto-position slot when
 *     space exists, falls back to top-left + push-down when the top is
 *     full or the auto-position slot lands below the viewport, applies
 *     the 4×4 default size, and is the ONLY caller of `grid.addWidget(`
 *     in `src/` (architectural enforcement via grep test).
 *
 * These constants are the single source of truth referenced by
 * `DashboardGrid.vue` and `css/mydash.css`; flipping any value here will
 * fail this spec and force an explicit downstream update.
 */

import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'fs'
import { join, relative } from 'path'
import {
	BREAKPOINTS,
	CELL_HEIGHT,
	CELL_HEIGHT_CSS_VAR,
	COLUMN_LAYOUT,
	DEFAULT_COLUMNS,
	DEFAULT_H,
	DEFAULT_VIEWPORT_ROWS,
	DEFAULT_W,
	GRID_MARGIN,
	getColumnOpts,
	placeNewWidget,
	syncCellHeightCssVar,
} from '../useGridManager.js'

describe('useGridManager', () => {
	describe('REQ-GRID-012 cell geometry constants', () => {
		it('CELL_HEIGHT is 60 px', () => {
			expect(CELL_HEIGHT).toBe(60)
		})

		it('GRID_MARGIN is 8 px', () => {
			expect(GRID_MARGIN).toBe(8)
		})

		it('DEFAULT_COLUMNS is 12', () => {
			expect(DEFAULT_COLUMNS).toBe(12)
		})

		it('height math: 4 rows + 3 inter-row margins = 264 px', () => {
			// Mirrors the height-math scenario in the spec delta.
			const rows = 4
			const innerMargins = rows - 1
			expect((rows * CELL_HEIGHT) + (innerMargins * GRID_MARGIN)).toBe(264)
		})
	})

	describe('REQ-GRID-007 responsive breakpoints', () => {
		it('BREAKPOINTS has exactly four entries', () => {
			expect(BREAKPOINTS).toHaveLength(4)
		})

		it('BREAKPOINTS entries match the spec table 1400/1100/768/480', () => {
			expect(BREAKPOINTS).toEqual([
				{ w: 1400, c: 12 },
				{ w: 1100, c: 8 },
				{ w: 768, c: 4 },
				{ w: 480, c: 1 },
			])
		})

		it('column counts descend monotonically as widths shrink', () => {
			for (let i = 1; i < BREAKPOINTS.length; i++) {
				expect(BREAKPOINTS[i].w).toBeLessThan(BREAKPOINTS[i - 1].w)
				expect(BREAKPOINTS[i].c).toBeLessThan(BREAKPOINTS[i - 1].c)
			}
		})

		it('BREAKPOINTS is frozen so callers cannot mutate the canonical table', () => {
			expect(Object.isFrozen(BREAKPOINTS)).toBe(true)
		})

		it('COLUMN_LAYOUT is "moveScale"', () => {
			expect(COLUMN_LAYOUT).toBe('moveScale')
		})

		it('getColumnOpts() returns a fresh deep copy of breakpoints + the moveScale layout + breakpointForWindow', () => {
			const opts = getColumnOpts()
			expect(opts.layout).toBe('moveScale')
			expect(opts.breakpointForWindow).toBe(true)
			expect(opts.breakpoints).toEqual([
				{ w: 1400, c: 12 },
				{ w: 1100, c: 8 },
				{ w: 768, c: 4 },
				{ w: 480, c: 1 },
			])
			// Mutating the returned breakpoints array MUST NOT affect the
			// frozen canonical table — getColumnOpts must hand back a copy.
			opts.breakpoints[0].c = 99
			expect(BREAKPOINTS[0].c).toBe(12)
		})
	})

	describe('CSS custom-property sync', () => {
		it('syncCellHeightCssVar writes the cell height to documentElement', () => {
			// Reset first to make the assertion meaningful.
			document.documentElement.style.removeProperty(CELL_HEIGHT_CSS_VAR)
			syncCellHeightCssVar()
			const value = document.documentElement.style.getPropertyValue(CELL_HEIGHT_CSS_VAR)
			expect(value).toBe(`${CELL_HEIGHT}px`)
		})

		it('CELL_HEIGHT_CSS_VAR is the documented `--mydash-cell-height` name', () => {
			expect(CELL_HEIGHT_CSS_VAR).toBe('--mydash-cell-height')
		})
	})

	describe('REQ-GRID-006 + REQ-GRID-014 placeNewWidget()', () => {
		it('exports the documented default constants', () => {
			expect(DEFAULT_W).toBe(4)
			expect(DEFAULT_H).toBe(4)
			expect(DEFAULT_VIEWPORT_ROWS).toBe(8)
		})

		it('auto-positions into empty space when the top region has room (no pushes)', () => {
			// Single existing widget at top-left occupying half the row.
			const placements = [
				{ id: 'a', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 },
			]
			const result = placeNewWidget({ w: 4, h: 4 }, placements, { gridColumns: 12 })
			// First empty row-major slot at y=0 is x=6 (right of the existing widget).
			expect(result).toMatchObject({ x: 6, y: 0, w: 4, h: 4, pushed: [] })
		})

		it('applies the 4×4 default size when the spec omits w/h', () => {
			const result = placeNewWidget({}, [], { gridColumns: 12 })
			expect(result).toMatchObject({ x: 0, y: 0, w: 4, h: 4, pushed: [] })
		})

		it('honours an explicit smaller size (e.g. tiles default to 2×2)', () => {
			const result = placeNewWidget({ w: 2, h: 2 }, [], { gridColumns: 12 })
			expect(result).toMatchObject({ w: 2, h: 2 })
		})

		it('falls back to top-left + push-down when the top region is fully occupied', () => {
			// Three 4-wide widgets fill the entire (0..12) × (0..4) region.
			const placements = [
				{ id: 'a', gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 4 },
				{ id: 'b', gridX: 4, gridY: 0, gridWidth: 4, gridHeight: 4 },
				{ id: 'c', gridX: 8, gridY: 0, gridWidth: 4, gridHeight: 4 },
				// Non-overlapping widget that already lives below — must not be touched.
				{ id: 'd', gridX: 0, gridY: 6, gridWidth: 4, gridHeight: 2 },
			]
			const result = placeNewWidget({ w: 4, h: 4 }, placements, {
				gridColumns: 12,
				viewportRows: 4, // tighten so y=4 (the next empty slot) is treated as off-screen
			})
			expect(result.x).toBe(0)
			expect(result.y).toBe(0)
			expect(result.w).toBe(4)
			expect(result.h).toBe(4)
			// Only widgets `a` (overlaps) is in the new rect's column lane;
			// `b` and `c` are in columns ≥ 4 so they do NOT overlap [0..4).
			// Confirm only the overlapper gained gridY.
			const pushedIds = result.pushed.map(p => p.id).sort()
			expect(pushedIds).toEqual(['a'])
			expect(result.pushed.find(p => p.id === 'a').gridY).toBe(4)
			// `d` was at y=6 — outside the new rect — so it stays put.
			expect(result.pushed.find(p => p.id === 'd')).toBeUndefined()
		})

		it('push-down on a widget that overlaps the top region keeps gridX/gridWidth intact (only gridY changes)', () => {
			// Existing widget straddles the right half but overlaps the new 6×3 placement at top-left.
			const placements = [
				{ id: 'right', gridX: 8, gridY: 0, gridWidth: 4, gridHeight: 2 },
			]
			const result = placeNewWidget({ w: 6, h: 3 }, placements, {
				gridColumns: 12,
				viewportRows: 1, // forces fallback even though there IS room at y=0,x=0
			})
			// Note: x=0..6 of the new widget DOES NOT overlap x=8..12 of `right`,
			// so `right` should NOT be pushed under the design rule. Validate this
			// stays true even with an aggressively tight viewport.
			expect(result.x).toBe(0)
			expect(result.y).toBe(0)
			expect(result.pushed).toEqual([])
		})

		it('actually pushes a wide overlapper at top to gridY = newH and keeps gridX/gridWidth', () => {
			// Wide existing widget that overlaps a 6-wide new placement.
			const placements = [
				{ id: 'wide', gridX: 4, gridY: 0, gridWidth: 8, gridHeight: 2 },
			]
			const result = placeNewWidget({ w: 6, h: 3 }, placements, {
				gridColumns: 12,
				viewportRows: 1,
			})
			expect(result.pushed).toHaveLength(1)
			expect(result.pushed[0]).toEqual({ id: 'wide', gridY: 3 })
			// gridX/gridWidth are NOT in the push payload — only gridY moves.
			// (Caller merges by id, leaving the other fields untouched.)
		})

		it('treats the auto-positioned slot as a failure when it lands below viewportRows', () => {
			// Fill rows 0..3 entirely so the next empty slot is at y=4.
			const placements = []
			for (let y = 0; y < 4; y++) {
				placements.push({ id: `row-${y}`, gridX: 0, gridY: y, gridWidth: 12, gridHeight: 1 })
			}
			const result = placeNewWidget({ w: 4, h: 4 }, placements, {
				gridColumns: 12,
				viewportRows: 4, // y=4 is "off-screen"
			})
			// Expect fallback: top-left + every overlapper pushed to gridY = 4.
			expect(result).toMatchObject({ x: 0, y: 0, w: 4, h: 4 })
			const pushed = result.pushed
			expect(pushed.map(p => p.id).sort()).toEqual(['row-0', 'row-1', 'row-2', 'row-3'])
			pushed.forEach(p => expect(p.gridY).toBe(4))
		})

		it('returns the autoPosition slot when it lands within the viewport (no fallback)', () => {
			// Single 4×2 widget at top-left; plenty of room at y=0, x=4.
			const placements = [
				{ id: 'a', gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 2 },
			]
			const result = placeNewWidget({ w: 4, h: 2 }, placements, {
				gridColumns: 12,
				viewportRows: 8,
			})
			expect(result.y).toBeLessThan(8)
			expect(result.pushed).toEqual([])
		})

		it('handles an empty grid by returning (0, 0)', () => {
			const result = placeNewWidget({ w: 4, h: 4 }, [], { gridColumns: 12 })
			expect(result).toMatchObject({ x: 0, y: 0, w: 4, h: 4, pushed: [] })
		})

		it('respects the gridColumns option for narrower dashboards', () => {
			// 8-column grid, single widget on the left half.
			const placements = [
				{ id: 'a', gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 4 },
			]
			const result = placeNewWidget({ w: 4, h: 4 }, placements, { gridColumns: 8 })
			// First empty row-major slot is x=4 (the right half of an 8-col grid).
			expect(result).toMatchObject({ x: 4, y: 0 })
		})

		it('does not mutate the input placements array', () => {
			const placements = [
				{ id: 'a', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 4 },
			]
			const snapshot = JSON.stringify(placements)
			placeNewWidget({ w: 4, h: 4 }, placements, { gridColumns: 12, viewportRows: 4 })
			expect(JSON.stringify(placements)).toBe(snapshot)
		})
	})

	describe('REQ-GRID-014 architectural enforcement (grep guard)', () => {
		// Recursively walk a directory and return absolute paths of all
		// `.js` and `.vue` files. Skips `node_modules` and `__tests__`
		// (the test file itself documents the rule and references the
		// forbidden pattern in comments).
		function walkSrc(dir, acc = []) {
			for (const entry of readdirSync(dir)) {
				const full = join(dir, entry)
				const st = statSync(full)
				if (st.isDirectory()) {
					if (entry === 'node_modules') continue
					walkSrc(full, acc)
				} else if (st.isFile() && (entry.endsWith('.js') || entry.endsWith('.vue'))) {
					acc.push(full)
				}
			}
			return acc
		}

		it('only `useGridManager.js` (and its test) reference `grid.addWidget(`', () => {
			// Pattern: literal `grid.addWidget(`. Captures the canonical
			// GridStack API call across `.js` and `.vue` files. Variants
			// like `gridInstance.addWidget(` would slip past — that's
			// intentional, the rule polices the canonical name and code
			// review handles aliases.
			const PATTERN = /\bgrid\.addWidget\s*\(/
			const srcDir = join(__dirname, '..', '..')
			const files = walkSrc(srcDir)
			const offenders = []
			for (const file of files) {
				const rel = relative(srcDir, file).replace(/\\/g, '/')
				// Skip the helper file itself and this test file.
				if (rel === 'composables/useGridManager.js') continue
				if (rel === 'composables/__tests__/useGridManager.spec.js') continue
				const text = readFileSync(file, 'utf8')
				if (PATTERN.test(text)) {
					offenders.push(rel)
				}
			}
			expect(offenders).toEqual([])
		})
	})
})
