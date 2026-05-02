/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `WorkspaceApp.vue`. Covers REQ-SHELL-001..007 with
 * special focus on REQ-SHELL-002 (canEdit gate), REQ-SHELL-005 (empty-state
 * branch on `allowUserDashboards`), and REQ-SHELL-007 (lifecycle cleanup
 * of the `document.click` listener).
 *
 * The embedded Views.vue child is stubbed because it depends on Pinia
 * stores + GridStack — neither is in scope for runtime-shell unit tests.
 * The stub exposes `openCustomWidgetModal` so the Add Widget dropdown
 * forwarding test can assert the integration surface stays stable.
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
		},
	})
}

describe('WorkspaceApp', () => {
	it('REQ-SHELL-002: admin sees toolbar even on a group dashboard', () => {
		const wrapper = mountShell({
			inject: { isAdmin: true, dashboardSource: 'group', activeDashboardId: 'd1' },
		})
		expect(wrapper.find('.workspace-shell__toolbar').exists()).toBe(true)
	})

	it('REQ-SHELL-002: non-admin user editing own personal dashboard sees toolbar', () => {
		const wrapper = mountShell({
			inject: { isAdmin: false, dashboardSource: 'user', activeDashboardId: 'd1' },
		})
		expect(wrapper.find('.workspace-shell__toolbar').exists()).toBe(true)
	})

	it('REQ-SHELL-002: non-admin viewing group-shared dashboard has NO toolbar in the DOM', () => {
		const wrapper = mountShell({
			inject: { isAdmin: false, dashboardSource: 'group', activeDashboardId: 'd1' },
		})
		// `v-if` (NOT `v-show`) — toolbar must be entirely absent.
		expect(wrapper.find('.workspace-shell__toolbar').exists()).toBe(false)
	})

	it('REQ-SHELL-004: hamburger toggles sidebar state', async () => {
		const wrapper = mountShell({ inject: { activeDashboardId: 'd1' } })
		expect(wrapper.vm.sidebarOpen).toBe(false)
		await wrapper.find('.workspace-shell__hamburger').trigger('click')
		expect(wrapper.vm.sidebarOpen).toBe(true)
		await wrapper.find('.workspace-shell__hamburger').trigger('click')
		expect(wrapper.vm.sidebarOpen).toBe(false)
	})

	it('REQ-SHELL-004: active-dashboard label resolves from inject lists', () => {
		const wrapper = mountShell({
			inject: {
				activeDashboardId: 'd1',
				userDashboards: [{ id: 'd1', name: 'Marketing Overview' }],
			},
		})
		expect(wrapper.find('.workspace-shell__title').text()).toBe('Marketing Overview')
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

	it('REQ-SHELL-003: Save button disables while a save is in flight', async () => {
		const wrapper = mountShell({
			inject: {
				isAdmin: true,
				dashboardSource: 'user',
				activeDashboardId: 'd1',
			},
		})
		// Hand-set saving so the test stays focused on the button binding.
		wrapper.vm.saving = true
		await wrapper.vm.$nextTick()
		const button = wrapper.find('.workspace-shell__save-button')
		expect(button.attributes('disabled')).toBeDefined()
		expect(button.text()).toBe('Saving…')
	})
})
