/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `MenuWidget.vue` covering REQ-MENU-001..011 —
 * the three render styles, active-item detection, external/internal
 * URL handling, the empty state, and the shared menu-active helper.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MenuWidget from '../MenuWidget.vue'
import { isActiveItem, computeActivePath, isExternalUrl } from '../../../../utils/menuActive.js'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

const stubChildren = {
	stubs: {
		MenuTreeNode: true,
		MenuItemIcon: true,
		IconRenderer: true,
	},
}

function makeContent(overrides = {}) {
	return {
		items: [],
		style: 'dropdown',
		orientation: 'horizontal',
		showIcons: true,
		expandedByDefault: false,
		activeItemHighlight: 'underline',
		...overrides,
	}
}

describe('menuActive helpers', () => {
	it('REQ-MENU-008: external URLs are detected by http/https prefix', () => {
		expect(isExternalUrl('https://example.com')).toBe(true)
		expect(isExternalUrl('http://example.com')).toBe(true)
		expect(isExternalUrl('/users')).toBe(false)
		expect(isExternalUrl('')).toBe(false)
	})

	it('REQ-MENU-006: isActiveItem matches internal route exactly', () => {
		expect(isActiveItem('/reports', { pathname: '/reports' })).toBe(true)
	})

	it('REQ-MENU-006: isActiveItem matches as a path prefix', () => {
		expect(isActiveItem('/users', { pathname: '/users/alice' })).toBe(true)
		expect(isActiveItem('/users', { pathname: '/usersalice' })).toBe(false)
	})

	it('REQ-MENU-006: external URLs do NOT auto-highlight', () => {
		expect(isActiveItem('https://example.com', { pathname: '/example.com' })).toBe(false)
	})

	it('REQ-MENU-006: ancestors of an active leaf are flagged "in-path"', () => {
		const items = [
			{
				label: 'Users',
				url: '/users',
				children: [
					{ label: 'Alice', url: '/users/alice' },
				],
			},
		]
		const { path, leafKey } = computeActivePath({
			items,
			currentLocation: { pathname: '/users/alice/profile' },
		})
		expect(leafKey).toBe('0.0')
		expect(path['0.0']).toBe('active')
		expect(path['0']).toBe('in-path')
	})
})

describe('MenuWidget', () => {
	it('REQ-MENU-011: renders the empty-state placeholder when items is empty', () => {
		const wrapper = mount(MenuWidget, {
			propsData: { content: makeContent({ items: [] }) },
			...stubChildren,
		})
		expect(wrapper.find('.menu-widget__empty').exists()).toBe(true)
		expect(wrapper.text()).toContain('No menu items yet')
	})

	it('REQ-MENU-011: hides placeholder when items are present', () => {
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({ items: [{ label: 'Item', url: '/x' }] }),
			},
			...stubChildren,
		})
		expect(wrapper.find('.menu-widget__empty').exists()).toBe(false)
	})

	it('REQ-MENU-011: edit-mode click on placeholder emits edit-request', async () => {
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({ items: [] }),
				isAdmin: true,
				canEdit: true,
			},
			...stubChildren,
		})
		await wrapper.find('.menu-widget__empty').trigger('click')
		expect(wrapper.emitted('edit-request')).toBeTruthy()
	})

	it('REQ-MENU-003: dropdown opens on top-level click and shows children', async () => {
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({
					style: 'dropdown',
					items: [
						{ label: 'Menu', children: [{ label: 'Item 1', url: '/p1' }] },
					],
				}),
			},
			...stubChildren,
		})
		await wrapper.find('.menu-widget__bar-button').trigger('click')
		expect(wrapper.find('.menu-widget__dropdown').exists()).toBe(true)
		expect(wrapper.text()).toContain('Item 1')
	})

	it('REQ-MENU-003: Esc closes the open dropdown', async () => {
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({
					items: [{ label: 'Menu', children: [{ label: 'Item', url: '/p' }] }],
				}),
			},
			...stubChildren,
		})
		await wrapper.find('.menu-widget__bar-button').trigger('click')
		expect(wrapper.find('.menu-widget__dropdown').exists()).toBe(true)
		await wrapper.find('.menu-widget').trigger('keydown.esc')
		expect(wrapper.find('.menu-widget__dropdown').exists()).toBe(false)
	})

	it('REQ-MENU-004: megamenu panel opens on top-level click', async () => {
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({
					style: 'megamenu',
					items: [{
						label: 'Products',
						children: [
							{ label: 'Widget', url: '/widgets' },
							{ label: 'Gadget', url: '/gadgets' },
						],
					}],
				}),
			},
			...stubChildren,
		})
		await wrapper.find('.menu-widget__bar-button').trigger('click')
		expect(wrapper.find('.menu-widget__mega-panel').exists()).toBe(true)
		expect(wrapper.text()).toContain('Widget')
		expect(wrapper.text()).toContain('Gadget')
	})

	it('REQ-MENU-004: megamenu panel switches when another top-level is clicked', async () => {
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({
					style: 'megamenu',
					items: [
						{ label: 'Products', children: [{ label: 'Widget', url: '/widgets' }] },
						{ label: 'Services', children: [{ label: 'Consulting', url: '/consulting' }] },
					],
				}),
			},
			...stubChildren,
		})
		const buttons = wrapper.findAll('.menu-widget__bar-button')
		await buttons.at(0).trigger('click')
		expect(wrapper.text()).toContain('Widget')
		await buttons.at(1).trigger('click')
		expect(wrapper.text()).not.toContain('Widget')
		expect(wrapper.text()).toContain('Consulting')
	})

	it('REQ-MENU-005: tree style mounts a MenuTreeNode per top-level item', () => {
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({
					style: 'tree',
					items: [
						{ label: 'A', children: [{ label: 'A1' }] },
						{ label: 'B' },
					],
				}),
			},
			...stubChildren,
		})
		const nodes = wrapper.findAllComponents({ name: 'MenuTreeNode' })
		expect(nodes.length).toBe(2)
	})

	it('REQ-MENU-008: external URL navigation calls window.open with rel attrs', async () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({
					items: [{ label: 'GitHub', url: 'https://github.com' }],
				}),
			},
			...stubChildren,
		})
		await wrapper.find('.menu-widget__bar-button').trigger('click')
		expect(openSpy).toHaveBeenCalledWith('https://github.com', '_blank', 'noopener,noreferrer')
		openSpy.mockRestore()
	})

	it('REQ-MENU-008: internal URL navigation uses router.push when available', async () => {
		const push = vi.fn()
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({
					items: [{ label: 'Dashboard', url: '/dashboard' }],
				}),
			},
			mocks: { $router: { push } },
			...stubChildren,
		})
		await wrapper.find('.menu-widget__bar-button').trigger('click')
		expect(push).toHaveBeenCalledWith('/dashboard')
	})

	it('REQ-MENU-006: active item gets the --active class', async () => {
		const originalLocation = window.location
		Object.defineProperty(window, 'location', {
			configurable: true,
			value: { pathname: '/reports', host: 'localhost' },
		})
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({
					items: [{ label: 'Reports', url: '/reports' }],
				}),
			},
			...stubChildren,
		})
		const btn = wrapper.find('.menu-widget__bar-button')
		expect(btn.classes()).toContain('menu-widget__item--active')
		Object.defineProperty(window, 'location', {
			configurable: true,
			value: originalLocation,
		})
	})

	it('REQ-MENU-002: defaults route invalid style to dropdown', () => {
		const wrapper = mount(MenuWidget, {
			propsData: {
				content: makeContent({ style: 'something-else', items: [{ label: 'X' }] }),
			},
			...stubChildren,
		})
		expect(wrapper.classes()).toContain('menu-widget--style-dropdown')
	})
})
