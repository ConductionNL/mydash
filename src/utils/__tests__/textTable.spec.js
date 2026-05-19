/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `utils/textTable.js` covering REQ-TBLE-001..008:
 * the empty-table default, row/column add/delete, merge/split, header-row
 * and per-column alignment toggles, placeholder detection, and the save-
 * time validator (rectangular grid + in-bounds spans).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import {
	emptyTable,
	addRow,
	addColumn,
	deleteRow,
	deleteColumn,
	mergeCells,
	splitCell,
	setHeaderRow,
	setColumnAlignment,
	setCellText,
	isPlaceholderCell,
	validateTable,
} from '../textTable.js'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('textTable utilities', () => {
	describe('REQ-TBLE-001: emptyTable default', () => {
		it('returns a 1x1 table with a single empty left-aligned cell', () => {
			const t = emptyTable()
			expect(t.headerRow).toBe(false)
			expect(t.columnAlignments).toEqual(['left'])
			expect(t.rows).toHaveLength(1)
			expect(t.rows[0]).toHaveLength(1)
			expect(t.rows[0][0]).toEqual({ text: '', rowSpan: 1, colSpan: 1 })
		})

		it('returns independent objects on each call', () => {
			const a = emptyTable()
			const b = emptyTable()
			a.rows[0][0].text = 'mutated'
			expect(b.rows[0][0].text).toBe('')
		})
	})

	describe('REQ-TBLE-003: addRow', () => {
		it('inserts a new row below the anchor with matching column count', () => {
			const before = emptyTable()
			const after = addRow(addColumn(before, 0, 'right'), 0, 'below')
			expect(after.rows).toHaveLength(2)
			expect(after.rows[1]).toHaveLength(2)
			expect(after.rows[1].every((c) => c.text === '' && c.rowSpan === 1 && c.colSpan === 1)).toBe(true)
		})

		it('inserts a new row above the anchor', () => {
			const before = emptyTable()
			const widened = addColumn(before, 0, 'right')
			widened.rows[0][0].text = 'A'
			const after = addRow(widened, 0, 'above')
			expect(after.rows[0].every((c) => c.text === '')).toBe(true)
			expect(after.rows[1][0].text).toBe('A')
		})
	})

	describe('REQ-TBLE-004: addColumn', () => {
		it('inserts a new column to the right with default left alignment', () => {
			const before = emptyTable()
			const after = addColumn(before, 0, 'right')
			expect(after.columnAlignments).toEqual(['left', 'left'])
			expect(after.rows[0]).toHaveLength(2)
		})

		it('inserts a new column to the left', () => {
			const before = setCellText(emptyTable(), 0, 0, 'A')
			const after = addColumn(before, 0, 'left')
			expect(after.columnAlignments.length).toBe(2)
			expect(after.rows[0][0].text).toBe('')
			expect(after.rows[0][1].text).toBe('A')
		})
	})

	describe('REQ-TBLE-005: deleteRow / deleteColumn', () => {
		it('deletes the targeted row when more than one exists', () => {
			let t = emptyTable()
			t = addRow(t, 0, 'below')
			t = setCellText(t, 1, 0, 'B')
			t = deleteRow(t, 1)
			expect(t.rows).toHaveLength(1)
		})

		it('refuses to delete the last remaining row', () => {
			const t = deleteRow(emptyTable(), 0)
			expect(t.rows).toHaveLength(1)
		})

		it('deletes the targeted column from every row and from columnAlignments', () => {
			let t = addColumn(emptyTable(), 0, 'right')
			t = setCellText(t, 0, 1, 'B')
			t = deleteColumn(t, 1)
			expect(t.columnAlignments).toEqual(['left'])
			expect(t.rows[0]).toHaveLength(1)
		})

		it('refuses to delete the last remaining column', () => {
			const t = deleteColumn(emptyTable(), 0)
			expect(t.columnAlignments).toEqual(['left'])
			expect(t.rows[0]).toHaveLength(1)
		})
	})

	describe('REQ-TBLE-006: mergeCells / splitCell', () => {
		it('merges two horizontally-adjacent cells into a single colSpan=2 anchor', () => {
			let t = addColumn(emptyTable(), 0, 'right')
			t = setCellText(t, 0, 0, 'A')
			t = setCellText(t, 0, 1, 'B')
			t = mergeCells(t, { rIdx: 0, cIdx: 0 }, { rIdx: 0, cIdx: 1 })
			expect(t.rows[0][0].colSpan).toBe(2)
			expect(t.rows[0][0].rowSpan).toBe(1)
			expect(t.rows[0][1].text).toBe('')
		})

		it('merges two vertically-adjacent cells into a single rowSpan=2 anchor', () => {
			let t = addRow(emptyTable(), 0, 'below')
			t = mergeCells(t, { rIdx: 0, cIdx: 0 }, { rIdx: 1, cIdx: 0 })
			expect(t.rows[0][0].rowSpan).toBe(2)
			expect(t.rows[1][0].text).toBe('')
			expect(t.rows[1][0].rowSpan).toBe(1)
		})

		it('merges a 2x2 block into a single rowSpan=2 colSpan=2 anchor', () => {
			let t = addRow(addColumn(emptyTable(), 0, 'right'), 0, 'below')
			t = mergeCells(t, { rIdx: 0, cIdx: 0 }, { rIdx: 1, cIdx: 1 })
			expect(t.rows[0][0].rowSpan).toBe(2)
			expect(t.rows[0][0].colSpan).toBe(2)
			expect(t.rows[0][1]).toEqual({ text: '', rowSpan: 1, colSpan: 1 })
			expect(t.rows[1][1]).toEqual({ text: '', rowSpan: 1, colSpan: 1 })
		})

		it('splits a merged anchor back to 1x1', () => {
			let t = addColumn(emptyTable(), 0, 'right')
			t = mergeCells(t, { rIdx: 0, cIdx: 0 }, { rIdx: 0, cIdx: 1 })
			t = splitCell(t, 0, 0)
			expect(t.rows[0][0].rowSpan).toBe(1)
			expect(t.rows[0][0].colSpan).toBe(1)
		})

		it('split is idempotent on a non-merged cell', () => {
			const before = emptyTable()
			const after = splitCell(before, 0, 0)
			expect(after.rows[0][0]).toEqual({ text: '', rowSpan: 1, colSpan: 1 })
		})
	})

	describe('REQ-TBLE-007: header row + alignment', () => {
		it('toggles the headerRow flag', () => {
			const t = setHeaderRow(emptyTable(), true)
			expect(t.headerRow).toBe(true)
		})

		it('sets a per-column alignment', () => {
			const t = setColumnAlignment(emptyTable(), 0, 'center')
			expect(t.columnAlignments[0]).toBe('center')
		})

		it('falls back to left for unknown alignments', () => {
			const t = setColumnAlignment(emptyTable(), 0, 'wibble')
			expect(t.columnAlignments[0]).toBe('left')
		})
	})

	describe('REQ-TBLE-009: isPlaceholderCell detection', () => {
		it('returns false for a fresh 1x1 grid', () => {
			expect(isPlaceholderCell(emptyTable().rows, 0, 0)).toBe(false)
		})

		it('returns true for a cell covered by a colSpan=2 anchor', () => {
			let t = addColumn(emptyTable(), 0, 'right')
			t = mergeCells(t, { rIdx: 0, cIdx: 0 }, { rIdx: 0, cIdx: 1 })
			expect(isPlaceholderCell(t.rows, 0, 1)).toBe(true)
			expect(isPlaceholderCell(t.rows, 0, 0)).toBe(false)
		})

		it('returns true for a cell covered by a rowSpan=2 anchor', () => {
			let t = addRow(emptyTable(), 0, 'below')
			t = mergeCells(t, { rIdx: 0, cIdx: 0 }, { rIdx: 1, cIdx: 0 })
			expect(isPlaceholderCell(t.rows, 1, 0)).toBe(true)
		})
	})

	describe('REQ-TBLE-008: validateTable', () => {
		it('passes a rectangular 1x1 grid', () => {
			expect(validateTable(emptyTable())).toEqual([])
		})

		it('passes a rectangular 2x2 grid with no merges', () => {
			let t = addRow(addColumn(emptyTable(), 0, 'right'), 0, 'below')
			t = setCellText(t, 0, 0, 'A')
			expect(validateTable(t)).toEqual([])
		})

		it('rejects ragged rows', () => {
			const bad = {
				headerRow: false,
				columnAlignments: ['left', 'left'],
				rows: [
					[{ text: 'A', rowSpan: 1, colSpan: 1 }, { text: 'B', rowSpan: 1, colSpan: 1 }],
					[{ text: 'C', rowSpan: 1, colSpan: 1 }],
				],
			}
			expect(validateTable(bad)).toEqual(['Grid is not rectangular'])
		})

		it('rejects rowSpan that exceeds the grid', () => {
			const bad = {
				headerRow: false,
				columnAlignments: ['left'],
				rows: [
					[{ text: 'A', rowSpan: 1, colSpan: 1 }],
					[{ text: 'B', rowSpan: 5, colSpan: 1 }],
				],
			}
			expect(validateTable(bad)).toEqual(['Cell span exceeds grid bounds'])
		})

		it('rejects colSpan that exceeds the grid', () => {
			const bad = {
				headerRow: false,
				columnAlignments: ['left'],
				rows: [
					[{ text: 'A', rowSpan: 1, colSpan: 3 }],
				],
			}
			expect(validateTable(bad)).toEqual(['Cell span exceeds grid bounds'])
		})

		it('rejects empty tables', () => {
			expect(validateTable(null)).toEqual(['Table must have at least one row'])
			expect(validateTable({ rows: [], columnAlignments: ['left'] })).toEqual(['Table must have at least one row'])
		})
	})
})
