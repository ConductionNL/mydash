/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `WorkspaceApp.vue`. Covers REQ-SHELL-001..007 with
 * special focus on:
 *  - REQ-SHELL-002 (canEdit gate) post `runtime-shell-trim`,
 *  - REQ-SHELL-004 (hamburger as `NcButton type="tertiary"` + active-
 *    dashboard label, NOT a select control),
 *  - REQ-SHELL-005 (empty-state branch on `allowUserDashboards`),
 *  - REQ-SHELL-007 (lifecycle cleanup of the `document.click` listener),
 *  - the `runtime-shell-trim` removals (no top toolbar, no Save Layout
 *    button, no standalone "Active dashboard" select).
 *
 * The embedded Views.vue child is stubbed because it depends on Pinia
 * stores + GridStack — neither is in scope for runtime-shell unit tests.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Vue from 'vue'
import { PiniaVuePlugin, createPinia } from 'pinia'

import WorkspaceApp from '../WorkspaceApp.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

Vue.use(PiniaVuePlugin)

/**
 * Mount helper supplying the inject defaults that mirror the typed
 * initial-state contract (REQ-INIT-002). Each call accepts an `inject`
 * override so individual tests target a single key.
 *
 * @param {object} options mount overrides
 * @param {object} [options.inject] inject overrides
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountShell(options = {}) {
	const inject = {
		isAdmin: false,
		dashboardSource: 'group',
		activeDashboardId: '',
		allowUserDashboards: false,
		layout: [],
		userDashboards: [],
		groupDashboards: [],
		...(options.inject || {}),
	}
	return mount(WorkspaceApp, {
		pinia: createPinia(),
		provide: inject,
		stubs: {
			Views: {
				name: 'Views',
				template: '<div class="views-stub" />',
				methods: {
					openCustomWidgetModal: vi.fn(),
				},
			},
			SidebarBackdrop: true,
			DashboardSwitcherSidebar: true,
			// Stub `NcButton` to a transparent `<button>` so the test
			// can drive `.workspace-shell__hamburger` clicks without
			// mounting the full Nextcloud Vue button (which pulls
			// design-system tokens we don't load in unit tests). The
			// rendered class is preserved so the assertion that the
			// hamburger is an NcButton-bound element still passes.
			NcButton: {
				name: 'NcButton',
				props: ['type'],
				template: '<button :class="$attrs.class" :data-nc-button-type="type" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
				inheritAttrs: false,
			},
			MenuIcon: true,
		},
	})
}

describe('WorkspaceApp', () => {
	it('REQ-SHELL-002: canEdit true for admin on group dashboard', () => {
		const wrapper = mountShell({
			inject: { isAdmin: true, dashboardSource: 'group', activeDashboardId: 'd1' },
		})
		expect(wrapper.vm.canEdit).toBe(true)
	})

	it('REQ-SHELL-002: canEdit true for owner of personal dashboard', () => {
		const wrapper = mountShell({
			inject: { isAdmin: false, dashboardSource: 'user', activeDashboardId: 'd1' },
		})
		expect(wrapper.vm.canEdit).toBe(true)
	})

	it('REQ-SHELL-002: canEdit false for non-admin viewing group-shared dashboard', () => {
		const wrapper = mountShell({
			inject: { isAdmin: false, dashboardSource: 'group', activeDashboardId: 'd1' },
		})
		expect(wrapper.vm.canEdit).toBe(false)
	})

	it('runtime-shell-trim: no top toolbar is rendered for any role', () => {
		const adminWrapper = mountShell({
			inject: { isAdmin: true, dashboardSource: 'group', activeDashboardId: 'd1' },
		})
		expect(adminWrapper.find('.workspace-shell__toolbar').exists()).toBe(false)

		const userWrapper = mountShell({
			inject: { isAdmin: false, dashboardSource: 'user', activeDashboardId: 'd1' },
		})
		expect(userWrapper.find('.workspace-shell__toolbar').exists()).toBe(false)
	})

	it('runtime-shell-trim: no Save Layout button or in-flight saving spinner present', () => {
		const wrapper = mountShell({
			inject: { isAdmin: true, dashboardSource: 'user', activeDashboardId: 'd1' },
		})
		expect(wrapper.find('.workspace-shell__save-button').exists()).toBe(false)
		expect(wrapper.find('[data-test="add-widget-toolbar-button"]').exists()).toBe(false)
		// `saving` reactive state is also gone — defending against accidental re-introduction.
		expect(wrapper.vm.saving).toBeUndefined()
	})

	it('REQ-SHELL-004: title strip is hidden when sidebar is closed', () => {
		const wrapper = mountShell({ inject: { activeDashboardId: 'd1' } })
		// Default state: sidebar closed → strip should NOT render.
		expect(wrapper.vm.sidebarOpen).toBe(false)
		expect(wrapper.find('.workspace-shell__strip').exists()).toBe(false)
		expect(wrapper.find('.workspace-shell__hamburger').exists()).toBe(false)
		expect(wrapper.find('.workspace-shell__title').exists()).toBe(false)
	})

	it('REQ-SHELL-004: title strip with hamburger appears when sidebar is open', async () => {
		const wrapper = mountShell({ inject: { activeDashboardId: 'd1' } })
		// Open the sidebar via the exposed setter on the component.
		wrapper.vm.sidebarOpen = true
		await wrapper.vm.$nextTick()
		const strip = wrapper.find('.workspace-shell__strip')
		expect(strip.exists()).toBe(true)
		const hamburger = wrapper.find('.workspace-shell__hamburger')
		expect(hamburger.exists()).toBe(true)
		// Stubbed NcButton mirrors the `type` prop onto a data attribute
		// so we can assert on the variant the shell selected.
		expect(hamburger.attributes('data-nc-button-type')).toBe('tertiary')
		// Clicking the in-strip hamburger closes the sidebar (it's the only
		// affordance available while the strip is mounted).
		await hamburger.trigger('click')
		expect(wrapper.vm.sidebarOpen).toBe(false)
	})

	it('REQ-SHELL-004: title strip shows dashboard name as a heading, NOT as a select', async () => {
		const wrapper = mountShell({
			inject: {
				activeDashboardId: 'd1',
				userDashboards: [{ id: 'd1', name: 'Marketing Overview' }],
			},
		})
		// Title only renders when the sidebar is open.
		wrapper.vm.sidebarOpen = true
		await wrapper.vm.$nextTick()
		const title = wrapper.find('.workspace-shell__title')
		expect(title.exists()).toBe(true)
		expect(title.element.tagName).toBe('H1')
		expect(title.text()).toBe('Marketing Overview')
		// Defensive: no <select> / activeDashboard-bound switcher control
		// should leak into the title strip — the sidebar owns navigation
		// (REQ-SWITCH-002).
		expect(wrapper.find('.workspace-shell__strip select').exists()).toBe(false)
	})

	it('REQ-SHELL-005: empty state with allowUserDashboards renders Create CTA', () => {
		const wrapper = mountShell({
			inject: { activeDashboardId: '', allowUserDashboards: true },
		})
		const cta = wrapper.find('.workspace-shell__empty-cta')
		expect(cta.exists()).toBe(true)
		expect(cta.text()).toBe('Create your first dashboard')
	})

	it('REQ-SHELL-005: empty state without allowUserDashboards hides Create CTA', () => {
		const wrapper = mountShell({
			inject: { activeDashboardId: '', allowUserDashboards: false },
		})
		expect(wrapper.find('.workspace-shell__empty-cta').exists()).toBe(false)
		// Hint text falls through to the "contact administrator" branch.
		expect(wrapper.find('.workspace-shell__empty-hint').text())
			.toBe('Contact your administrator')
	})

	it('REQ-SHELL-007: registers document click listener on mount, removes on unmount', async () => {
		const addSpy = vi.spyOn(document, 'addEventListener')
		const removeSpy = vi.spyOn(document, 'removeEventListener')
		const wrapper = mountShell({ inject: { activeDashboardId: 'd1' } })
		await wrapper.vm.$nextTick()
		const addedHandler = addSpy.mock.calls.find(c => c[0] === 'click')?.[1]
		expect(typeof addedHandler).toBe('function')

		wrapper.destroy()
		const removedHandler = removeSpy.mock.calls.find(c => c[0] === 'click')?.[1]
		expect(removedHandler).toBe(addedHandler)
		addSpy.mockRestore()
		removeSpy.mockRestore()
	})
})
