/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `useNestedGridManager.js` covering REQ-CONT-002
 * (inner-grid constants: 4 cols / 40px / 4px / disableOneColumnMode) and
 * REQ-CONT-005 (persistence callback fires when child placements change).
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import {
	NESTED_COLUMNS,
	NESTED_CELL_HEIGHT,
	NESTED_MARGIN,
	NESTED_DEFAULT_W,
	NESTED_DEFAULT_H,
	getNestedGridOptions,
	placeNewWidget,
	useNestedGridManager,
} from '../useNestedGridManager.js'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('useNestedGridManager — REQ-CONT-002 inner-grid constants', () => {
	it('NESTED_COLUMNS is 4 (vs 12 outer)', () => {
		expect(NESTED_COLUMNS).toBe(4)
	})

	it('NESTED_CELL_HEIGHT is 40 (vs 60 outer)', () => {
		expect(NESTED_CELL_HEIGHT).toBe(40)
	})

	it('NESTED_MARGIN is 4 (vs 8 outer)', () => {
		expect(NESTED_MARGIN).toBe(4)
	})

	it('getNestedGridOptions wires the four required GridStack options', () => {
		const opts = getNestedGridOptions()
		expect(opts.column).toBe(4)
		expect(opts.cellHeight).toBe(40)
		expect(opts.margin).toBe(4)
		expect(opts.acceptWidgets).toBe(true)
		expect(opts.disableOneColumnMode).toBe(true)
	})

	it('getNestedGridOptions returns a fresh copy each call', () => {
		const a = getNestedGridOptions()
		const b = getNestedGridOptions()
		expect(a).not.toBe(b)
		expect(a).toEqual(b)
	})
})

describe('useNestedGridManager — placeNewWidget', () => {
	it('REQ-CONT-002: respects the 4-column ceiling on the empty grid', () => {
		const result = placeNewWidget({ w: 2, h: 2 }, [])
		expect(result.x).toBe(0)
		expect(result.y).toBe(0)
		expect(result.w).toBe(2)
		expect(result.h).toBe(2)
	})

	it('REQ-CONT-002: defaults missing w/h to NESTED_DEFAULT_W/H', () => {
		const result = placeNewWidget({}, [])
		expect(result.w).toBe(NESTED_DEFAULT_W)
		expect(result.h).toBe(NESTED_DEFAULT_H)
	})

	it('REQ-CONT-002: never returns x + w > 4 (the 4-col ceiling)', () => {
		// Three slots of width 2 in a 4-col grid: first two stack
		// horizontally [0..1][2..3]; the third must wrap.
		const placements = [
			{ id: 1, gridX: 0, gridY: 0, gridWidth: 2, gridHeight: 2 },
			{ id: 2, gridX: 2, gridY: 0, gridWidth: 2, gridHeight: 2 },
		]
		const result = placeNewWidget({ w: 2, h: 2 }, placements)
		expect(result.x + result.w).toBeLessThanOrEqual(4)
	})
})

describe('useNestedGridManager — REQ-CONT-005 persistence callback', () => {
	it('persist() fires the callback with the new placements array', () => {
		const cb = vi.fn()
		const manager = useNestedGridManager({ persistPlacements: cb })
		manager.persist([{ id: 1, type: 'label' }])
		expect(cb).toHaveBeenCalledTimes(1)
		expect(cb).toHaveBeenCalledWith([{ id: 1, type: 'label' }])
	})

	it('persist() coerces non-array input to an empty array (defensive)', () => {
		const cb = vi.fn()
		const manager = useNestedGridManager({ persistPlacements: cb })
		manager.persist(null)
		expect(cb).toHaveBeenCalledWith([])
	})

	it('useNestedGridManager() returns the helper bag (getOptions/placeNewWidget/persist)', () => {
		const manager = useNestedGridManager()
		expect(typeof manager.getOptions).toBe('function')
		expect(typeof manager.placeNewWidget).toBe('function')
		expect(typeof manager.persist).toBe('function')
	})
})
