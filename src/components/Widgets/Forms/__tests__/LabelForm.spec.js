/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `LabelForm.vue` covering REQ-LBL-005: validation
 * returns an error array on empty text and an empty array on non-empty text,
 * and the form pre-fills every one of the six controls from
 * `editingWidget.content` when opened in edit mode.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import LabelForm from '../LabelForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('LabelForm', () => {
	it('REQ-LBL-005: validate() returns one error when text is empty', () => {
		const wrapper = mount(LabelForm, {
			propsData: { value: { text: '' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		const errors = wrapper.vm.validate()
		expect(errors).toEqual(['Label text is required'])
	})

	it('REQ-LBL-005: validate() returns one error when text is whitespace only', () => {
		const wrapper = mount(LabelForm, {
			propsData: { value: { text: '   ' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual(['Label text is required'])
	})

	it('REQ-LBL-005: validate() returns empty array when text is non-empty', () => {
		const wrapper = mount(LabelForm, {
			propsData: { value: { text: 'Header' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-LBL-005: pre-fills all six controls from editingWidget.content', () => {
		const editingWidget = {
			content: {
				text: 'Hi',
				fontSize: '20px',
				color: '#ff0000',
				backgroundColor: '#ffffff',
				fontWeight: '700',
				textAlign: 'right',
			},
		}
		const wrapper = mount(LabelForm, {
			propsData: { editingWidget },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.text).toBe('Hi')
		expect(wrapper.vm.fontSize).toBe('20px')
		expect(wrapper.vm.color).toBe('#ff0000')
		expect(wrapper.vm.backgroundColor).toBe('#ffffff')
		expect(wrapper.vm.fontWeight).toBe('700')
		expect(wrapper.vm.textAlign).toBe('right')
	})

	it('emits update:content with the assembled payload when a field changes', () => {
		const wrapper = mount(LabelForm, {
			propsData: { value: { text: 'A' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		wrapper.vm.updateField('text', 'B')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({ text: 'B' })
	})
})
