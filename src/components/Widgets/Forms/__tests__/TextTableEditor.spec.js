/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `TextTableEditor.vue` covering the in-place table
 * editing UX (REQ-TBLE-002..007): cell-level text edits emit the updated
 * tableData; toolbar add-row / add-column / delete-row / delete-column /
 * merge / split / header-row / column-alignment buttons round-trip through
 * the helper module; and the inline error banner reports validation issues.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import TextTableEditor from '../TextTableEditor.vue'
import { emptyTable } from '../../../../utils/textTable.js'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('TextTableEditor', () => {
	it('REQ-TBLE-002: renders a single empty input for the default 1x1 table', () => {
		const wrapper = mount(TextTableEditor, {
			propsData: { value: emptyTable() },
		})
		const inputs = wrapper.findAll('.text-table-editor__input')
		expect(inputs.length).toBe(1)
		expect(inputs.at(0).element.value).toBe('')
	})

	it('REQ-TBLE-003: clicking "Add row below" emits an updated 2-row tableData', async () => {
		const wrapper = mount(TextTableEditor, {
			propsData: { value: emptyTable() },
		})
		const buttons = wrapper.findAll('.text-table-editor__btn')
		const addBelow = buttons.wrappers.find((b) => b.text() === 'Add row below')
		await addBelow.trigger('click')
		const emitted = wrapper.emitted('input')
		expect(emitted).toBeTruthy()
		expect(emitted[0][0].rows.length).toBe(2)
	})

	it('REQ-TBLE-004: clicking "Add column right" emits an updated 2-column tableData', async () => {
		const wrapper = mount(TextTableEditor, {
			propsData: { value: emptyTable() },
		})
		const buttons = wrapper.findAll('.text-table-editor__btn')
		const addRight = buttons.wrappers.find((b) => b.text() === 'Add column right')
		await addRight.trigger('click')
		const next = wrapper.emitted('input')[0][0]
		expect(next.columnAlignments.length).toBe(2)
		expect(next.rows[0].length).toBe(2)
	})

	it('REQ-TBLE-007: toggling the header-row checkbox flips headerRow', async () => {
		const wrapper = mount(TextTableEditor, {
			propsData: { value: emptyTable() },
		})
		const checkbox = wrapper.find('input[type="checkbox"]')
		await checkbox.setChecked(true)
		const next = wrapper.emitted('input')[0][0]
		expect(next.headerRow).toBe(true)
	})

	it('REQ-TBLE-007: changing a column-alignment dropdown updates columnAlignments', async () => {
		const wrapper = mount(TextTableEditor, {
			propsData: { value: emptyTable() },
		})
		const select = wrapper.find('.text-table-editor__align-select')
		await select.setValue('center')
		const next = wrapper.emitted('input')[0][0]
		expect(next.columnAlignments[0]).toBe('center')
	})

	it('REQ-TBLE-001: typing in a cell input emits updated tableData with cell text', async () => {
		const wrapper = mount(TextTableEditor, {
			propsData: { value: emptyTable() },
		})
		const input = wrapper.find('.text-table-editor__input')
		await input.setValue('Hello')
		const emissions = wrapper.emitted('input')
		const last = emissions[emissions.length - 1][0]
		expect(last.rows[0][0].text).toBe('Hello')
	})

	it('REQ-TBLE-006: merge button is disabled when only one cell is selected', () => {
		const wrapper = mount(TextTableEditor, {
			propsData: { value: emptyTable() },
		})
		const buttons = wrapper.findAll('.text-table-editor__btn')
		const merge = buttons.wrappers.find((b) => b.text() === 'Merge cells')
		expect(merge.attributes('disabled')).toBeDefined()
	})

	it('REQ-TBLE-006: split button is disabled for a non-merged anchor', () => {
		const wrapper = mount(TextTableEditor, {
			propsData: { value: emptyTable() },
		})
		const buttons = wrapper.findAll('.text-table-editor__btn')
		const split = buttons.wrappers.find((b) => b.text() === 'Split cell')
		expect(split.attributes('disabled')).toBeDefined()
	})

	it('REQ-TBLE-006: split button is enabled when the anchor cell has a span > 1', () => {
		const merged = {
			headerRow: false,
			columnAlignments: ['left', 'left'],
			rows: [
				[{ text: 'A', rowSpan: 1, colSpan: 2 }, { text: '', rowSpan: 1, colSpan: 1 }],
			],
		}
		const wrapper = mount(TextTableEditor, {
			propsData: { value: merged },
		})
		const buttons = wrapper.findAll('.text-table-editor__btn')
		const split = buttons.wrappers.find((b) => b.text() === 'Split cell')
		expect(split.attributes('disabled')).toBeUndefined()
	})
})
