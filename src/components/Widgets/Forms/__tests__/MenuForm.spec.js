/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `MenuForm.vue` covering REQ-MENU-009 — config
 * dropdowns/toggles, recursive item editor, depth-cap validation, and
 * pre-fill from `editingWidget.content`.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import MenuForm from '../MenuForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

const stubChildren = {
	stubs: { NcSelect: true, MenuItemEditor: true },
}

describe('MenuForm', () => {
	it('REQ-MENU-009: pre-fills every config field from editingWidget.content', () => {
		const editingWidget = {
			content: {
				items: [{ label: 'X', url: '/x', icon: 'Star', children: [] }],
				style: 'tree',
				orientation: 'vertical',
				showIcons: false,
				expandedByDefault: true,
				activeItemHighlight: 'background',
			},
		}
		const wrapper = mount(MenuForm, {
			propsData: { editingWidget },
			...stubChildren,
		})
		expect(wrapper.vm.style).toBe('tree')
		expect(wrapper.vm.orientation).toBe('vertical')
		expect(wrapper.vm.showIcons).toBe(false)
		expect(wrapper.vm.expandedByDefault).toBe(true)
		expect(wrapper.vm.activeItemHighlight).toBe('background')
		expect(wrapper.vm.items).toEqual([
			{ label: 'X', url: '/x', icon: 'Star', children: [] },
		])
	})

	it('REQ-MENU-002: validate() rejects items nested deeper than 3 levels', () => {
		const wrapper = mount(MenuForm, {
			propsData: {
				value: {
					items: [
						{
							label: 'L1',
							children: [
								{
									label: 'L2',
									children: [
										{
											label: 'L3',
											children: [
												{ label: 'L4' },
											],
										},
									],
								},
							],
						},
					],
					style: 'dropdown',
				},
			},
			...stubChildren,
		})
		const errors = wrapper.vm.validate()
		expect(errors).toContain('Menu items can nest at most 3 levels deep')
	})

	it('REQ-MENU-002: validate() returns empty for legal 3-level trees', () => {
		const wrapper = mount(MenuForm, {
			propsData: {
				value: {
					items: [
						{
							label: 'L1',
							children: [
								{
									label: 'L2',
									children: [
										{ label: 'L3' },
									],
								},
							],
						},
					],
					style: 'dropdown',
				},
			},
			...stubChildren,
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-MENU-009: emits update:content when a field changes', () => {
		const wrapper = mount(MenuForm, {
			propsData: { value: { items: [], style: 'dropdown' } },
			...stubChildren,
		})
		wrapper.vm.updateField('style', 'megamenu')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0].style).toBe('megamenu')
	})

	it('REQ-MENU-009: onAddTop appends a blank item and emits', () => {
		const wrapper = mount(MenuForm, {
			propsData: { value: { items: [], style: 'dropdown' } },
			...stubChildren,
		})
		wrapper.vm.onAddTop()
		expect(wrapper.vm.items.length).toBe(1)
		expect(wrapper.vm.items[0]).toEqual({ label: '', url: '', icon: '', children: [] })
		expect(wrapper.emitted('update:content')).toBeTruthy()
	})

	it('REQ-MENU-009: onAddChild on a depth-1 item creates a child slot', () => {
		const wrapper = mount(MenuForm, {
			propsData: {
				value: {
					items: [{ label: 'A', url: '', icon: '', children: [] }],
					style: 'dropdown',
				},
			},
			...stubChildren,
		})
		wrapper.vm.onAddChild({ path: [0] })
		expect(wrapper.vm.items[0].children.length).toBe(1)
	})

	it('REQ-MENU-009: onUpdateItem updates the deep-nested label', () => {
		const wrapper = mount(MenuForm, {
			propsData: {
				value: {
					items: [{
						label: 'A',
						url: '',
						icon: '',
						children: [
							{ label: 'A1', url: '', icon: '', children: [] },
						],
					}],
					style: 'dropdown',
				},
			},
			...stubChildren,
		})
		wrapper.vm.onUpdateItem({ path: [0, 0], item: { label: 'A1-new' } })
		expect(wrapper.vm.items[0].children[0].label).toBe('A1-new')
	})

	it('REQ-MENU-009: onRemoveItem removes the addressed row', () => {
		const wrapper = mount(MenuForm, {
			propsData: {
				value: {
					items: [
						{ label: 'A', url: '', icon: '', children: [] },
						{ label: 'B', url: '', icon: '', children: [] },
					],
					style: 'dropdown',
				},
			},
			...stubChildren,
		})
		wrapper.vm.onRemoveItem({ path: [0] })
		expect(wrapper.vm.items.length).toBe(1)
		expect(wrapper.vm.items[0].label).toBe('B')
	})
})
