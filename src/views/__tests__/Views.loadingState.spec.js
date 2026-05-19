/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit test for the `Views.vue` initial-load shim.
 *
 * The default-active-dashboard endpoint resolves async, so during the
 * first render `activeDashboard` is null even though dashboards exist
 * server-side. Before the loading-state fix the empty-state ("No
 * dashboard yet" + Create CTA) flashed in that window. This spec
 * pins the corrected behaviour: while `loading` is true and
 * `activeDashboard` is null the loading shim renders and the empty
 * state stays out of the DOM.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		t: (_app, key) => key,
		translate: (_app, key) => key,
		translatePlural: (_app, sing, plur, n) => (n === 1 ? sing : plur),
	}
})
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn((_app, _key, fallback) => fallback),
}))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: [] })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		generateUrl: (path) => path,
	}
})

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

const childStubs = {
	DashboardGrid: { name: 'DashboardGrid', template: '<div class="stub-dashboard-grid" />' },
	DashboardSwitcherSidebar: { name: 'DashboardSwitcherSidebar', template: '<div />' },
	SidebarBackdrop: { name: 'SidebarBackdrop', template: '<div />' },
	WidgetPickerModal: { name: 'WidgetPickerModal', template: '<div />' },
	AddWidgetModal: { name: 'AddWidgetModal', template: '<div />' },
	WidgetStyleEditor: { name: 'WidgetStyleEditor', template: '<div />' },
	TileEditor: { name: 'TileEditor', template: '<div />' },
	WidgetContextMenu: { name: 'WidgetContextMenu', template: '<div />' },
	DashboardConfigModal: { name: 'DashboardConfigModal', template: '<div />' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="nc-loading-icon-stub" />' },
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: { name: String, description: String },
		template: '<div class="empty-content"><slot name="icon" /><slot /><slot name="action" /></div>',
	},
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	ViewDashboard: { name: 'ViewDashboard', template: '<span />' },
	MenuIcon: { name: 'MenuIcon', template: '<span />' },
	Close: { template: '<span />' },
	Cog: { template: '<span />' },
	Plus: { template: '<span />' },
	ContentSave: { template: '<span />' },
}

async function mountViews({ activeDashboard = null, loading = false } = {}) {
	setActivePinia(createPinia())

	const { default: Views } = await import('../Views.vue')
	const { useDashboardStore } = await import('../../stores/dashboard.js')
	const { useWidgetStore } = await import('../../stores/widgets.js')
	const { useTileStore } = await import('../../stores/tiles.js')

	const dashStore = useDashboardStore()
	dashStore.activeDashboard = activeDashboard
	dashStore.dashboards = activeDashboard ? [activeDashboard] : []
	dashStore.widgetPlacements = []
	dashStore.loading = loading
	dashStore.loadDashboards = vi.fn()
	dashStore.switchDashboard = vi.fn()
	dashStore.createDashboard = vi.fn()
	dashStore.updatePlacements = vi.fn()
	dashStore.addWidgetToDashboard = vi.fn()
	dashStore.addTileToDashboard = vi.fn()
	dashStore.removeWidgetFromDashboard = vi.fn()
	dashStore.updateWidgetPlacement = vi.fn()

	const widgetStore = useWidgetStore()
	widgetStore.availableWidgets = []
	widgetStore.loadAvailableWidgets = vi.fn()

	const tileStore = useTileStore()
	tileStore.tiles = []
	tileStore.loadTiles = vi.fn()
	tileStore.createTile = vi.fn()
	tileStore.updateTile = vi.fn()
	tileStore.deleteTile = vi.fn()

	return mount(Views, {
		provide: {
			isAdmin: false,
			dashboardSource: 'user',
			allowUserDashboards: true,
			primaryGroupName: '',
			groupDashboards: [],
			userDashboards: [],
		},
		stubs: childStubs,
		mocks: { t: (_app, key) => key },
	})
}

describe('Views — initial-load shim', () => {
	it('renders the loading shim, NOT the empty-state CTA, while loading=true and activeDashboard is null', async () => {
		const wrapper = await mountViews({ activeDashboard: null, loading: true })

		expect(wrapper.find('.mydash-loading').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		expect(wrapper.find('.mydash-empty').exists()).toBe(false)
	})

	it('renders the empty-state CTA once loading=false and activeDashboard is still null', async () => {
		const wrapper = await mountViews({ activeDashboard: null, loading: false })

		expect(wrapper.find('.mydash-loading').exists()).toBe(false)
		expect(wrapper.find('.mydash-empty').exists()).toBe(true)
	})
})
