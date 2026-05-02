/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `TextDisplayForm.vue` covering REQ-TXT-004:
 * validation returns `[t('Text is required')]` on empty/whitespace text and
 * an empty array otherwise; the form pre-fills every one of its five
 * controls from `editingWidget.content`; and `update:content` is emitted
 * reactively whenever a field changes.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import TextDisplayForm from '../TextDisplayForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('TextDisplayForm', () => {
	it('REQ-TXT-004: validate() returns one error when text is empty', () => {
		const wrapper = mount(TextDisplayForm, {
			propsData: { value: { text: '' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual(['Text is required'])
	})

	it('REQ-TXT-004: validate() returns one error when text is whitespace-only', () => {
		const wrapper = mount(TextDisplayForm, {
			propsData: { value: { text: '   \n ' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual(['Text is required'])
	})

	it('REQ-TXT-004: validate() returns empty array when text is non-empty', () => {
		const wrapper = mount(TextDisplayForm, {
			propsData: { value: { text: 'hello' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-TXT-004: pre-fills all five controls from editingWidget.content', () => {
		const editingWidget = {
			content: {
				text: 'Hi',
				fontSize: '20px',
				color: '#00ff00',
				backgroundColor: '#000000',
				textAlign: 'right',
			},
		}
		const wrapper = mount(TextDisplayForm, {
			propsData: { editingWidget },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.text).toBe('Hi')
		expect(wrapper.vm.fontSize).toBe('20px')
		expect(wrapper.vm.color).toBe('#00ff00')
		expect(wrapper.vm.backgroundColor).toBe('#000000')
		expect(wrapper.vm.textAlign).toBe('right')
	})

	it('REQ-TXT-004: emits update:content reactively when a field changes', () => {
		const wrapper = mount(TextDisplayForm, {
			propsData: { value: { text: '' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		wrapper.vm.updateField('text', 'Hello')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({ text: 'Hello' })
		// Also confirm validate flips to valid
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-TXT-004: textarea reflects bound value and emits on input', async () => {
		const wrapper = mount(TextDisplayForm, {
			propsData: { value: { text: 'X' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		const textarea = wrapper.find('textarea')
		expect(textarea.exists()).toBe(true)
		expect(textarea.element.value).toBe('X')
		await textarea.setValue('Y')
		const emitted = wrapper.emitted('update:content')
		expect(emitted[emitted.length - 1][0]).toMatchObject({ text: 'Y' })
	})
})
