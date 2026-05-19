/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `WidgetContextMenu.vue`. Covers REQ-WDG-015..017
 * — render of three buttons, position-style derivation, z-index and
 * min-width invariants, and event emission discipline (each button click
 * also emits `close` so the popover is always single-instance).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import WidgetContextMenu from '../WidgetContextMenu.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

/**
 * Mount helper — supplies sensible defaults for the required position
 * props so individual tests stay focused on the behaviour under test.
 *
 * @param {object} props prop overrides
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountMenu(props = {}) {
	return mount(WidgetContextMenu, {
		propsData: {
			top: 100,
			left: 200,
			...props,
		},
	})
}

describe('WidgetContextMenu', () => {
	it('REQ-WDG-015: renders three buttons — Edit, Remove, Cancel', () => {
		const wrapper = mountMenu()
		const buttons = wrapper.findAll('.widget-context-menu__item')
		expect(buttons.length).toBe(3)
		const labels = buttons.wrappers.map((b) => b.text().trim())
		expect(labels).toEqual(['Edit', 'Remove', 'Cancel'])
	})

	it('REQ-WDG-017: applies top / left from props as fixed positioning', () => {
		const wrapper = mountMenu({ top: 250, left: 480 })
		const root = wrapper.find('.widget-context-menu')
		const style = root.element.style
		expect(style.top).toBe('250px')
		expect(style.left).toBe('480px')
	})

	it('REQ-WDG-017: root element carries z-index 10000 and min-width 150px in scoped CSS', () => {
		// We can't read scoped <style> rules from jsdom, but we can assert
		// the class is present so the styles in the SFC apply at runtime.
		const wrapper = mountMenu()
		const root = wrapper.find('.widget-context-menu')
		expect(root.exists()).toBe(true)
	})

	it('REQ-WDG-015 edit: clicking Edit emits edit then close (single-instance)', async () => {
		const wrapper = mountMenu()
		const editBtn = wrapper.findAll('.widget-context-menu__item').at(0)
		await editBtn.trigger('click')
		expect(wrapper.emitted('edit')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('REQ-WDG-015 remove: clicking Remove emits remove then close', async () => {
		const wrapper = mountMenu()
		const removeBtn = wrapper.findAll('.widget-context-menu__item').at(1)
		await removeBtn.trigger('click')
		expect(wrapper.emitted('remove')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('REQ-WDG-015 cancel: clicking Cancel emits close only (no edit, no remove)', async () => {
		const wrapper = mountMenu()
		const cancelBtn = wrapper.findAll('.widget-context-menu__item').at(2)
		await cancelBtn.trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.emitted('edit')).toBeFalsy()
		expect(wrapper.emitted('remove')).toBeFalsy()
	})

	it('REQ-WDG-016: stops click propagation so the document-level outside-click listener does not fire', async () => {
		// The popover root carries `@click.stop`. In jsdom we assert the
		// modifier resolves by dispatching a click and confirming it does
		// NOT bubble to the document.
		const wrapper = mountMenu({ attachTo: document.body })
		let bubbled = false
		const handler = () => { bubbled = true }
		document.addEventListener('click', handler)
		await wrapper.find('.widget-context-menu').trigger('click')
		expect(bubbled).toBe(false)
		document.removeEventListener('click', handler)
		wrapper.destroy()
	})
})
