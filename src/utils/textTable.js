/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the text-widget table mode (REQ-TBLE-001..011).
 *
 * The table data lives inside `widget.content.tableData` with the shape
 *
 *   {
 *     headerRow: boolean,
 *     columnAlignments: ('left'|'center'|'right')[],
 *     rows: Array<Array<{text: string, rowSpan: number, colSpan: number}>>
 *   }
 *
 * To keep the editor and renderer simple, every cell that is "covered" by
 * another cell's `rowSpan` / `colSpan` stays in the matrix as a placeholder
 * with `text:''`. `isPlaceholderCell` tells callers which cells to hide;
 * mutation helpers (`addRow`, `addColumn`, `mergeCells`, `splitCell`) keep
 * the matrix rectangular.
 */

/** Default cell — used by every helper that creates fresh cells. */
const emptyCell = () => ({ text: '', rowSpan: 1, colSpan: 1 })

/**
 * The minimal valid table — a 1×1 grid with one empty left-aligned cell. Used
 * by the form whenever a user toggles `tableMode = false → true` and there is
 * no existing tableData to load (REQ-TBLE-001 / REQ-TBLE-002).
 *
 * @return {object} a fresh empty tableData object
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function emptyTable() {
	return {
		headerRow: false,
		columnAlignments: ['left'],
		rows: [[emptyCell()]],
	}
}

/**
 * Whether the given cell coordinate is covered by another cell's `rowSpan`
 * or `colSpan` and should be hidden in the rendered output.
 *
 * @param {Array<Array<object>>} rows the table rows matrix
 * @param {number} rIdx target row index
 * @param {number} cIdx target column index
 * @return {boolean} true when this cell is hidden by another cell's span
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function isPlaceholderCell(rows, rIdx, cIdx) {
	if (!Array.isArray(rows)) {
		return false
	}
	for (let r = 0; r <= rIdx; r++) {
		const row = rows[r]
		if (!Array.isArray(row)) {
			continue
		}
		for (let c = 0; c <= cIdx; c++) {
			const cell = row[c]
			if (!cell) {
				continue
			}
			if (r === rIdx && c === cIdx) {
				continue
			}
			const rowSpan = cell.rowSpan || 1
			const colSpan = cell.colSpan || 1
			if (rowSpan === 1 && colSpan === 1) {
				continue
			}
			if (
				r <= rIdx
				&& r + rowSpan - 1 >= rIdx
				&& c <= cIdx
				&& c + colSpan - 1 >= cIdx
			) {
				return true
			}
		}
	}
	return false
}

/**
 * Insert a new empty row above or below the given row index.
 *
 * @param {object} tableData the current tableData object (mutated copy returned)
 * @param {number} rIdx anchor row index
 * @param {'above'|'below'} position where to insert relative to the anchor
 * @return {object} new tableData with the row inserted
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function addRow(tableData, rIdx, position) {
	const next = cloneTable(tableData)
	const insertAt = position === 'above' ? rIdx : rIdx + 1
	const colCount = next.columnAlignments.length
	const newRow = []
	for (let i = 0; i < colCount; i++) {
		newRow.push(emptyCell())
	}
	next.rows.splice(insertAt, 0, newRow)
	return next
}

/**
 * Insert a new empty column to the left or right of the given column index.
 * Adds a default `'left'` alignment for the new column.
 *
 * @param {object} tableData the current tableData object
 * @param {number} cIdx anchor column index
 * @param {'left'|'right'} position where to insert relative to the anchor
 * @return {object} new tableData with the column inserted
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function addColumn(tableData, cIdx, position) {
	const next = cloneTable(tableData)
	const insertAt = position === 'left' ? cIdx : cIdx + 1
	next.columnAlignments.splice(insertAt, 0, 'left')
	for (const row of next.rows) {
		row.splice(insertAt, 0, emptyCell())
	}
	return next
}

/**
 * Remove the row at the given index. Refuses to remove the last remaining
 * row so the table never becomes empty.
 *
 * @param {object} tableData the current tableData
 * @param {number} rIdx row index to delete
 * @return {object} new tableData with the row removed (or unchanged if last)
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function deleteRow(tableData, rIdx) {
	const next = cloneTable(tableData)
	if (next.rows.length <= 1) {
		return next
	}
	next.rows.splice(rIdx, 1)
	return next
}

/**
 * Remove the column at the given index from every row and from
 * `columnAlignments`. Refuses to remove the last remaining column.
 *
 * @param {object} tableData the current tableData
 * @param {number} cIdx column index to delete
 * @return {object} new tableData with the column removed (or unchanged)
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function deleteColumn(tableData, cIdx) {
	const next = cloneTable(tableData)
	if (next.columnAlignments.length <= 1) {
		return next
	}
	next.columnAlignments.splice(cIdx, 1)
	for (const row of next.rows) {
		row.splice(cIdx, 1)
	}
	return next
}

/**
 * Merge two cell coordinates (the rectangular span between them) into a
 * single cell anchored at the upper-left corner. Cells inside the merged
 * region are kept as placeholders to maintain matrix rectangularity but
 * their `text` is cleared and their span set to 1×1 (REQ-TBLE-006).
 *
 * @param {object} tableData the current tableData
 * @param {{rIdx: number, cIdx: number}} from corner A
 * @param {{rIdx: number, cIdx: number}} to corner B
 * @return {object} new tableData with the cells merged
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function mergeCells(tableData, from, to) {
	const next = cloneTable(tableData)
	const r0 = Math.min(from.rIdx, to.rIdx)
	const r1 = Math.max(from.rIdx, to.rIdx)
	const c0 = Math.min(from.cIdx, to.cIdx)
	const c1 = Math.max(from.cIdx, to.cIdx)
	const anchor = next.rows[r0][c0]
	anchor.rowSpan = (r1 - r0) + 1
	anchor.colSpan = (c1 - c0) + 1
	for (let r = r0; r <= r1; r++) {
		for (let c = c0; c <= c1; c++) {
			if (r === r0 && c === c0) {
				continue
			}
			next.rows[r][c] = { text: '', rowSpan: 1, colSpan: 1 }
		}
	}
	return next
}

/**
 * Reset a previously-merged anchor cell back to a 1×1 cell. No-op if the
 * cell is already 1×1 (idempotent — REQ-TBLE-006 split scenario).
 *
 * @param {object} tableData the current tableData
 * @param {number} rIdx anchor row index
 * @param {number} cIdx anchor column index
 * @return {object} new tableData with the cell split
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function splitCell(tableData, rIdx, cIdx) {
	const next = cloneTable(tableData)
	const cell = next.rows[rIdx][cIdx]
	if (!cell) {
		return next
	}
	cell.rowSpan = 1
	cell.colSpan = 1
	return next
}

/**
 * Toggle (or explicitly set) the headerRow flag.
 *
 * @param {object} tableData the current tableData
 * @param {boolean} value the new headerRow value
 * @return {object} new tableData with the flag set
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function setHeaderRow(tableData, value) {
	const next = cloneTable(tableData)
	next.headerRow = value === true
	return next
}

/**
 * Set the alignment for a specific column. Falls back to `'left'` when an
 * unknown alignment is supplied.
 *
 * @param {object} tableData the current tableData
 * @param {number} cIdx column index
 * @param {string} alignment one of 'left' | 'center' | 'right'
 * @return {object} new tableData with the alignment applied
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function setColumnAlignment(tableData, cIdx, alignment) {
	const next = cloneTable(tableData)
	const safe = ['left', 'center', 'right'].includes(alignment) ? alignment : 'left'
	next.columnAlignments[cIdx] = safe
	return next
}

/**
 * Update the text of a single cell.
 *
 * @param {object} tableData the current tableData
 * @param {number} rIdx row index
 * @param {number} cIdx column index
 * @param {string} text new cell text
 * @return {object} new tableData with the cell text updated
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function setCellText(tableData, rIdx, cIdx, text) {
	const next = cloneTable(tableData)
	if (next.rows[rIdx] && next.rows[rIdx][cIdx]) {
		next.rows[rIdx][cIdx].text = typeof text === 'string' ? text : ''
	}
	return next
}

/**
 * Validate the table grid for save: rectangular rows and in-bounds spans.
 * Returns an array of translated error messages (empty array on success).
 *
 * REQ-TBLE-008: must reject ragged rows, span overflow, and column-alignment
 * length mismatches before persisting to `styleConfig`.
 *
 * @param {object} tableData the tableData to validate
 * @return {string[]} array of error messages (empty if valid)
 */
/** @spec openspec/specs/text-display-widget/spec.md */
export function validateTable(tableData) {
	const errors = []
	if (!tableData || !Array.isArray(tableData.rows) || tableData.rows.length === 0) {
		errors.push(t('mydash', 'Table must have at least one row'))
		return errors
	}
	const colCount = Array.isArray(tableData.columnAlignments)
		? tableData.columnAlignments.length
		: 0
	if (colCount === 0) {
		errors.push(t('mydash', 'Table must have at least one column'))
		return errors
	}
	for (let r = 0; r < tableData.rows.length; r++) {
		const row = tableData.rows[r]
		if (!Array.isArray(row) || row.length !== colCount) {
			errors.push(t('mydash', 'Grid is not rectangular'))
			return errors
		}
		for (let c = 0; c < row.length; c++) {
			const cell = row[c]
			const rowSpan = cell?.rowSpan || 1
			const colSpan = cell?.colSpan || 1
			if (r + rowSpan > tableData.rows.length || c + colSpan > colCount) {
				errors.push(t('mydash', 'Cell span exceeds grid bounds'))
				return errors
			}
		}
	}
	return errors
}

/**
 * Deep clone a tableData object so callers can mutate it without polluting
 * the caller's reference. Cheap because tableData is small JSON.
 *
 * @param {object} tableData the table to clone
 * @return {object} structurally-cloned copy
 */
function cloneTable(tableData) {
	if (!tableData) {
		return emptyTable()
	}
	return {
		headerRow: tableData.headerRow === true,
		columnAlignments: Array.isArray(tableData.columnAlignments)
			? [...tableData.columnAlignments]
			: ['left'],
		rows: Array.isArray(tableData.rows)
			? tableData.rows.map((row) => row.map((cell) => ({
				text: typeof cell?.text === 'string' ? cell.text : '',
				rowSpan: cell?.rowSpan || 1,
				colSpan: cell?.colSpan || 1,
			})))
			: [[emptyCell()]],
	}
}
