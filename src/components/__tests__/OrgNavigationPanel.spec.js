/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `OrgNavigationPanel.vue` and the recursive
 * `OrgNavigationItem.vue` (REQ-ONAV-005, REQ-ONAV-006, REQ-ONAV-008,
 * REQ-ONAV-009, REQ-ONAV-010).
 *
 * The orgNavigation store is mocked via Pinia's testing helper so we
 * can drive `tree`/`position`/`shouldRender` directly without going
 * through the API.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Vue from 'vue'
import { PiniaVuePlugin, createPinia, setActivePinia } from 'pinia'

import OrgNavigationPanel from '../OrgNavigationPanel.vue'
import OrgNavigationItem from '../OrgNavigationItem.vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

vi.mock('../../services/api.js', () => ({
	api: {
		getOrgNavigation: vi.fn().mockResolvedValue({ data: { tree: [], language: 'nl' } }),
		updateOrgNavigation: vi.fn(),
		getOrgNavigationPosition: vi.fn().mockResolvedValue({ data: { position: 'hidden' } }),
		updateOrgNavigationPosition: vi.fn(),
	},
}))

Vue.use(PiniaVuePlugin)

beforeEach(() => {
	globalThis.t = (_app, key) => key
	setActivePinia(createPinia())
})

describe('OrgNavigationPanel', () => {
	it('REQ-ONAV-008: panel does not render when shouldRender is false', () => {
		const wrapper = mount(OrgNavigationPanel, {
			pinia: createPinia(),
		})
		// Default state: empty tree + position 'hidden' -> nothing renders.
		expect(wrapper.find('.org-nav').exists()).toBe(false)
	})

	it('REQ-ONAV-005: panel renders the tree when shouldRender is true', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const { useOrgNavigationStore } = await import('../../stores/orgNavigation.js')
		const store = useOrgNavigationStore()
		store.tree = [
			{ id: 'a', label: 'Section A', url: null, icon: null, children: [] },
		]
		store.position = 'left'

		const wrapper = mount(OrgNavigationPanel, { pinia })
		expect(wrapper.find('.org-nav').exists()).toBe(true)
		expect(wrapper.text()).toContain('Section A')
	})
})

describe('OrgNavigationItem', () => {
	function mountItem(node, currentUrl = '') {
		return mount(OrgNavigationItem, {
			propsData: { node, level: 1, currentUrl },
		})
	}

	it('REQ-ONAV-005: link nodes render as <a> with href', () => {
		const wrapper = mountItem({
			id: 'x', label: 'Reports', url: '/reports', children: [],
		})
		const link = wrapper.find('a')
		expect(link.exists()).toBe(true)
		expect(link.attributes('href')).toBe('/reports')
	})

	it('REQ-ONAV-005: openInNewTab sets target="_blank"', () => {
		const wrapper = mountItem({
			id: 'x', label: 'Ext', url: 'https://example.com', openInNewTab: true, children: [],
		})
		expect(wrapper.find('a').attributes('target')).toBe('_blank')
	})

	it('REQ-ONAV-005: section nodes (no url) render as <button>', () => {
		const wrapper = mountItem({
			id: 'x', label: 'Section', url: null, children: [],
		})
		expect(wrapper.find('a').exists()).toBe(false)
		expect(wrapper.find('button').exists()).toBe(true)
	})

	it('REQ-ONAV-006: URL icon renders as <img>', () => {
		const wrapper = mountItem({
			id: 'x', label: 'I', url: '/x', icon: '/icons/foo.png', children: [],
		})
		const img = wrapper.find('.org-nav-item__icon')
		expect(img.element.tagName.toLowerCase()).toBe('img')
		expect(img.attributes('src')).toBe('/icons/foo.png')
	})

	it('REQ-ONAV-009: exact URL match marks node as active', () => {
		const wrapper = mountItem(
			{ id: 'x', label: 'Hub', url: '/apps/mydash/hub', children: [] },
			'/apps/mydash/hub',
		)
		expect(wrapper.classes('org-nav-item--active')).toBe(true)
	})

	it('REQ-ONAV-009: prefix URL match marks node as active', () => {
		const wrapper = mountItem(
			{ id: 'x', label: 'Hub', url: '/apps/mydash/hub', children: [] },
			'/apps/mydash/hub/details',
		)
		expect(wrapper.classes('org-nav-item--active')).toBe(true)
	})

	it('REQ-ONAV-009: non-matching URL does not mark node as active', () => {
		const wrapper = mountItem(
			{ id: 'x', label: 'Hub', url: '/apps/mydash/hub', children: [] },
			'/apps/mydash/hubris',
		)
		expect(wrapper.classes('org-nav-item--active')).toBe(false)
	})

	it('REQ-ONAV-009: parent auto-expands when a child is active', async () => {
		const wrapper = mountItem(
			{
				id: 'p',
				label: 'Parent',
				url: null,
				children: [
					{ id: 'c', label: 'Child', url: '/active/path', children: [] },
				],
			},
			'/active/path',
		)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.expanded).toBe(true)
	})
})
