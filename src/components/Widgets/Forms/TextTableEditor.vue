<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="text-table-editor">
		<div class="text-table-editor__toolbar">
			<label class="text-table-editor__inline">
				<input
					type="checkbox"
					:checked="value.headerRow"
					@change="onHeaderRowToggle($event.target.checked)">
				{{ t('mydash', 'Header row') }}
			</label>

			<button
				type="button"
				class="text-table-editor__btn"
				@click="onAddRow('above')">
				{{ t('mydash', 'Add row above') }}
			</button>
			<button
				type="button"
				class="text-table-editor__btn"
				@click="onAddRow('below')">
				{{ t('mydash', 'Add row below') }}
			</button>
			<button
				type="button"
				class="text-table-editor__btn"
				@click="onAddColumn('left')">
				{{ t('mydash', 'Add column left') }}
			</button>
			<button
				type="button"
				class="text-table-editor__btn"
				@click="onAddColumn('right')">
				{{ t('mydash', 'Add column right') }}
			</button>
			<button
				type="button"
				class="text-table-editor__btn"
				:disabled="!hasSelection"
				@click="onDeleteRow">
				{{ t('mydash', 'Delete row') }}
			</button>
			<button
				type="button"
				class="text-table-editor__btn"
				:disabled="!hasSelection"
				@click="onDeleteColumn">
				{{ t('mydash', 'Delete column') }}
			</button>
			<button
				type="button"
				class="text-table-editor__btn"
				:disabled="!canMerge"
				@click="onMergeCells">
				{{ t('mydash', 'Merge cells') }}
			</button>
			<button
				type="button"
				class="text-table-editor__btn"
				:disabled="!canSplit"
				@click="onSplitCell">
				{{ t('mydash', 'Split cell') }}
			</button>
		</div>

		<div class="text-table-editor__alignments">
			<span class="text-table-editor__hint">{{ t('mydash', 'Column alignment') }}:</span>
			<select
				v-for="(_, cIdx) in value.columnAlignments"
				:key="`align-${cIdx}`"
				class="text-table-editor__align-select"
				:value="value.columnAlignments[cIdx]"
				:aria-label="t('mydash', 'Column alignment')"
				@change="onAlignmentChange(cIdx, $event.target.value)">
				<option value="left">
					{{ t('mydash', 'Left') }}
				</option>
				<option value="center">
					{{ t('mydash', 'Center') }}
				</option>
				<option value="right">
					{{ t('mydash', 'Right') }}
				</option>
			</select>
		</div>

		<div v-if="errorMessage" class="text-table-editor__error">
			{{ errorMessage }}
		</div>

		<table class="text-table-editor__grid">
			<tbody>
				<tr v-for="(row, rIdx) in value.rows" :key="`r-${rIdx}`">
					<template v-for="(cell, cIdx) in row">
						<td
							v-show="!isPlaceholder(rIdx, cIdx)"
							:key="`c-${rIdx}-${cIdx}`"
							:rowspan="cell.rowSpan || 1"
							:colspan="cell.colSpan || 1"
							:class="cellClass(rIdx, cIdx)"
							:style="cellStyle(cIdx)"
							@click="selectCell(rIdx, cIdx, $event)">
							<input
								type="text"
								class="text-table-editor__input"
								:value="cell.text"
								:placeholder="cellPlaceholder(rIdx)"
								:aria-label="cellLabel(rIdx, cIdx)"
								@input="onCellInput(rIdx, cIdx, $event.target.value)"
								@focus="selectCell(rIdx, cIdx, $event)">
						</td>
					</template>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
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
} from '../../../utils/textTable.js'

/**
 * TextTableEditor — in-place visual table editor used by `TextDisplayForm`
 * when `tableMode === true` (REQ-TBLE-002..007).
 *
 * Cells are edited directly in the grid (one `<input type="text">` per cell);
 * row/column/merge operations live in a toolbar above the grid. The editor
 * is "controlled": the parent owns the `tableData` state via `v-model` and
 * receives a fresh, validated copy on every change.
 *
 * Selection is single-cell by default; Shift+Click extends the selection to
 * a rectangular region so the user can merge cells. The Merge / Split / Delete
 * buttons enable / disable themselves based on the current selection.
 */
export default {
	name: 'TextTableEditor',

	props: {
		/** The current tableData object — `v-model` value. */
		value: {
			type: Object,
			default: () => emptyTable(),
		},
	},

	emits: ['input'],

	data() {
		return {
			anchor: { rIdx: 0, cIdx: 0 },
			extent: { rIdx: 0, cIdx: 0 },
			errorMessage: '',
		}
	},

	computed: {
		hasSelection() {
			return this.value.rows.length > 0 && this.value.columnAlignments.length > 0
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		canMerge() {
			if (!this.hasSelection) {
				return false
			}
			return this.anchor.rIdx !== this.extent.rIdx
				|| this.anchor.cIdx !== this.extent.cIdx
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		canSplit() {
			if (!this.hasSelection) {
				return false
			}
			const cell = this.value.rows[this.anchor.rIdx]?.[this.anchor.cIdx]
			return cell && ((cell.rowSpan || 1) > 1 || (cell.colSpan || 1) > 1)
		},
	},

	methods: {
		/**
		 * Translate the change-helper output into a `v-model` update.
		 * Re-runs validation so the user sees errors as they edit.
		 *
		 * @param {object} next the next tableData value
		 */
		/** @spec openspec/specs/text-display-widget/spec.md */
		emitUpdate(next) {
			const errors = validateTable(next)
			this.errorMessage = errors.length > 0 ? errors[0] : ''
			this.$emit('input', next)
		},

		/**
		 * Selection handling — single-click sets a new anchor; Shift+Click
		 * extends the selection from the current anchor for merge ops.
		 *
		 * @param {number} rIdx the clicked row
		 * @param {number} cIdx the clicked column
		 * @param {Event} event the originating click/focus event
		 */
		/** @spec openspec/specs/text-display-widget/spec.md */
		selectCell(rIdx, cIdx, event) {
			if (event && event.shiftKey) {
				this.extent = { rIdx, cIdx }
			} else {
				this.anchor = { rIdx, cIdx }
				this.extent = { rIdx, cIdx }
			}
		},

		isPlaceholder(rIdx, cIdx) {
			return isPlaceholderCell(this.value.rows, rIdx, cIdx)
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		cellClass(rIdx, cIdx) {
			const isAnchor = rIdx === this.anchor.rIdx && cIdx === this.anchor.cIdx
			const inSelection = this.cellInSelection(rIdx, cIdx)
			return {
				'text-table-editor__cell': true,
				'text-table-editor__cell--header': this.value.headerRow && rIdx === 0,
				'text-table-editor__cell--anchor': isAnchor,
				'text-table-editor__cell--selected': inSelection && !isAnchor,
			}
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		cellInSelection(rIdx, cIdx) {
			const r0 = Math.min(this.anchor.rIdx, this.extent.rIdx)
			const r1 = Math.max(this.anchor.rIdx, this.extent.rIdx)
			const c0 = Math.min(this.anchor.cIdx, this.extent.cIdx)
			const c1 = Math.max(this.anchor.cIdx, this.extent.cIdx)
			return rIdx >= r0 && rIdx <= r1 && cIdx >= c0 && cIdx <= c1
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		cellStyle(cIdx) {
			return {
				'text-align': this.value.columnAlignments[cIdx] || 'left',
			}
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		cellPlaceholder(rIdx) {
			return this.value.headerRow && rIdx === 0
				? t('mydash', 'Header')
				: t('mydash', 'Cell')
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		cellLabel(rIdx, cIdx) {
			return t('mydash', 'Row {row}, column {col}', { row: rIdx + 1, col: cIdx + 1 })
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		onCellInput(rIdx, cIdx, text) {
			this.emitUpdate(setCellText(this.value, rIdx, cIdx, text))
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		onHeaderRowToggle(checked) {
			this.emitUpdate(setHeaderRow(this.value, checked === true))
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		onAlignmentChange(cIdx, alignment) {
			this.emitUpdate(setColumnAlignment(this.value, cIdx, alignment))
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		onAddRow(position) {
			this.emitUpdate(addRow(this.value, this.anchor.rIdx, position))
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		onAddColumn(position) {
			this.emitUpdate(addColumn(this.value, this.anchor.cIdx, position))
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		onDeleteRow() {
			const rIdx = this.anchor.rIdx
			const row = this.value.rows[rIdx] || []
			const hasText = row.some((cell) => typeof cell?.text === 'string' && cell.text.trim() !== '')
			if (hasText) {
				const proceed = typeof window !== 'undefined' && typeof window.confirm === 'function'
					? window.confirm(t('mydash', 'This row contains text. Delete?'))
					: true
				if (!proceed) {
					return
				}
			}
			this.emitUpdate(deleteRow(this.value, rIdx))
			this.anchor = { rIdx: Math.max(0, rIdx - 1), cIdx: this.anchor.cIdx }
			this.extent = { ...this.anchor }
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		onDeleteColumn() {
			const cIdx = this.anchor.cIdx
			const hasText = this.value.rows.some(
				(row) => row[cIdx] && typeof row[cIdx].text === 'string' && row[cIdx].text.trim() !== '',
			)
			if (hasText) {
				const proceed = typeof window !== 'undefined' && typeof window.confirm === 'function'
					? window.confirm(t('mydash', 'This column contains text. Delete?'))
					: true
				if (!proceed) {
					return
				}
			}
			this.emitUpdate(deleteColumn(this.value, cIdx))
			this.anchor = { rIdx: this.anchor.rIdx, cIdx: Math.max(0, cIdx - 1) }
			this.extent = { ...this.anchor }
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		onMergeCells() {
			this.emitUpdate(mergeCells(this.value, this.anchor, this.extent))
			this.extent = { ...this.anchor }
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		onSplitCell() {
			this.emitUpdate(splitCell(this.value, this.anchor.rIdx, this.anchor.cIdx))
		},
	},
}
</script>

<style scoped>
.text-table-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.text-table-editor__toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	align-items: center;
}

.text-table-editor__alignments {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.text-table-editor__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.text-table-editor__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	font-size: 13px;
}

.text-table-editor__btn {
	padding: 4px 8px;
	font-size: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	cursor: pointer;
}

.text-table-editor__btn[disabled] {
	opacity: 0.5;
	cursor: not-allowed;
}

.text-table-editor__align-select {
	font-size: 12px;
	padding: 2px 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.text-table-editor__error {
	color: var(--color-error, #d32f2f);
	font-size: 12px;
	padding: 4px 8px;
	border: 1px solid var(--color-error, #d32f2f);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.text-table-editor__grid {
	border-collapse: collapse;
	width: 100%;
}

.text-table-editor__cell {
	border: 1px solid var(--color-border);
	padding: 0;
	vertical-align: top;
}

.text-table-editor__cell--header {
	background: var(--color-background-hover);
	font-weight: bold;
}

.text-table-editor__cell--anchor {
	outline: 2px solid var(--color-primary, #006aa3);
	outline-offset: -2px;
}

.text-table-editor__cell--selected {
	background: var(--color-primary-light, #e8f1f8);
}

.text-table-editor__input {
	width: 100%;
	padding: 6px 8px;
	border: none;
	background: transparent;
	color: inherit;
	font: inherit;
	text-align: inherit;
	box-sizing: border-box;
}

.text-table-editor__input:focus {
	outline: none;
}
</style>
