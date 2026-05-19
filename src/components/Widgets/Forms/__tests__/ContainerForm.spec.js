/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `ContainerForm.vue` covering REQ-CONT-007 (three
 * fields exposed: backgroundColor / padding / title; no children-management
 * UI inside the form) plus the `validate()` always-empty contract.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import ContainerForm from '../ContainerForm.vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label', 'placeholder'],
		template: '<input :value="value" :data-label="label" :data-placeholder="placeholder" @input="$emit(\'update:value\', $event.target.value)">',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['value', 'options', 'inputLabel'],
		template: '<select :value="value" :data-input-label="inputLabel" @change="$emit(\'input\', $event.target.value)"><option v-for="opt in options" :key="opt.value || opt" :value="opt.value || opt">{{ opt.label || opt }}</option></select>',
	},
}))

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('ContainerForm — REQ-CONT-007 three fields', () => {
	it('mounts with the three field components (backgroundColor / padding / title)', () => {
		const wrapper = mount(ContainerForm)
		// backgroundColor — native <input type="color"> (always present
		// regardless of which Nc* stub or real component is loaded)
		expect(wrapper.find('input[type="color"]').exists()).toBe(true)
		// padding + title controls live in stubbed/mounted Nc components;
		// assert via the form's reactive data shape (REQ-CONT-007 fields).
		expect(wrapper.vm.padding).toBeDefined()
		expect(typeof wrapper.vm.title).toBe('string')
	})

	it('does NOT render a placement/children manager inside the form (REQ-CONT-007)', () => {
		const wrapper = mount(ContainerForm)
		const html = wrapper.html().toLowerCase()
		// The form deliberately omits any "manage children", "child
		// placements" or "placement list" UI — children are added via
		// the inner-grid's own affordances when the container is in
		// edit mode.
		expect(html).not.toContain('manage children')
		expect(html).not.toContain('child placements')
		expect(html).not.toContain('placement list')
	})

	it('seeds defaults: backgroundColor=transparent, padding=medium, title=""', () => {
		const wrapper = mount(ContainerForm)
		expect(wrapper.vm.backgroundColor).toBe('transparent')
		expect(wrapper.vm.padding).toBe('medium')
		expect(wrapper.vm.title).toBe('')
	})

	it('pre-fills from editingWidget.content when provided', () => {
		const wrapper = mount(ContainerForm, {
			propsData: {
				editingWidget: {
					content: {
						backgroundColor: '#abcdef',
						padding: 'large',
						title: 'My section',
						placements: [{ id: 9, type: 'label' }],
					},
				},
			},
		})
		expect(wrapper.vm.backgroundColor).toBe('#abcdef')
		expect(wrapper.vm.padding).toBe('large')
		expect(wrapper.vm.title).toBe('My section')
		// placements pre-served (so save round-trip doesn't drop kids)
		expect(wrapper.vm.placements).toHaveLength(1)
	})

	it('emits update:content when backgroundColor changes', async () => {
		const wrapper = mount(ContainerForm)
		wrapper.vm.updateField('backgroundColor', '#ff0000')
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[0][0].backgroundColor).toBe('#ff0000')
	})
})

describe('ContainerForm — REQ-CONT-007 validate()', () => {
	it('validate() returns an empty array (every field is optional)', () => {
		const wrapper = mount(ContainerForm)
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('validate() stays empty even when title is empty', () => {
		const wrapper = mount(ContainerForm, {
			propsData: { value: { title: '', padding: 'medium', backgroundColor: '' } },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})
})
