/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `DividerForm.vue` covering REQ-DIV-002: the form
 * exposes the per-style config fields, validates `headingText` only when
 * style is `heading-break`, pre-fills from `editingWidget.content` in
 * edit mode, and emits the assembled content payload on field changes.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import DividerForm from '../DividerForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('DividerForm', () => {
	it('REQ-DIV-002: validate() returns no errors for default line style', () => {
		const wrapper = mount(DividerForm, {
			propsData: { value: { style: 'line' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-DIV-002: validate() returns no errors for whitespace style', () => {
		const wrapper = mount(DividerForm, {
			propsData: { value: { style: 'whitespace', whitespaceSize: 'small' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-DIV-002: validate() requires headingText when style is heading-break', () => {
		const wrapper = mount(DividerForm, {
			propsData: { value: { style: 'heading-break', headingText: '' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual(['Heading text is required'])
	})

	it('REQ-DIV-002: whitespace-only headingText is rejected', () => {
		const wrapper = mount(DividerForm, {
			propsData: { value: { style: 'heading-break', headingText: '   ' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual(['Heading text is required'])
	})

	it('REQ-DIV-002: validate() passes once headingText is non-empty', () => {
		const wrapper = mount(DividerForm, {
			propsData: { value: { style: 'heading-break', headingText: 'Hello' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-DIV-002: pre-fills all six controls from editingWidget.content', () => {
		const editingWidget = {
			content: {
				style: 'heading-break',
				lineColor: '#abcdef',
				lineThickness: 4,
				lineStyle: 'dashed',
				whitespaceSize: 'large',
				headingText: 'My section',
			},
		}
		const wrapper = mount(DividerForm, {
			propsData: { editingWidget },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.style).toBe('heading-break')
		expect(wrapper.vm.lineColor).toBe('#abcdef')
		expect(wrapper.vm.lineThickness).toBe(4)
		expect(wrapper.vm.lineStyle).toBe('dashed')
		expect(wrapper.vm.whitespaceSize).toBe('large')
		expect(wrapper.vm.headingText).toBe('My section')
	})

	it('REQ-DIV-002: emits update:content with the assembled payload when a field changes', () => {
		const wrapper = mount(DividerForm, {
			propsData: { value: { style: 'line' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		wrapper.vm.updateField('lineStyle', 'dashed')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({
			style: 'line',
			lineStyle: 'dashed',
		})
	})

	it('REQ-DIV-002: updateThickness() clamps the value into 1..8 before emitting', () => {
		const wrapper = mount(DividerForm, {
			propsData: { value: { style: 'line' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		wrapper.vm.updateThickness('99')
		expect(wrapper.vm.lineThickness).toBe(8)
		wrapper.vm.updateThickness('0')
		expect(wrapper.vm.lineThickness).toBe(1)
		wrapper.vm.updateThickness('not-a-number')
		expect(wrapper.vm.lineThickness).toBe(1)
		wrapper.vm.updateThickness('3')
		expect(wrapper.vm.lineThickness).toBe(3)
	})
})
