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
 *
 * These constants are the single source of truth referenced by
 * `DashboardGrid.vue` and `css/mydash.css`; flipping any value here will
 * fail this spec and force an explicit downstream update.
 */

import { describe, it, expect } from 'vitest'
import {
	BREAKPOINTS,
	CELL_HEIGHT,
	CELL_HEIGHT_CSS_VAR,
	COLUMN_LAYOUT,
	DEFAULT_COLUMNS,
	GRID_MARGIN,
	getColumnOpts,
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
})
