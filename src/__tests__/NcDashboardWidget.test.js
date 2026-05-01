/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/* eslint-disable n/no-unpublished-import */
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
/* eslint-enable n/no-unpublished-import */

// Vitest hoists vi.mock() calls above all imports at runtime, so declaring
// them here is equivalent to placing them before any import statement.
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path) => `http://localhost${path}`),
}))

vi.mock('../services/widgetBridge.js', () => ({
	widgetBridge: {
		hasWidgetCallback: vi.fn(() => false),
		mountWidget: vi.fn(),
		pollForCallback: vi.fn(() => Promise.resolve(false)),
	},
}))

// eslint-disable-next-line import/first
import NcDashboardWidget from '../components/Widgets/Renderers/NcDashboardWidget.vue'
// eslint-disable-next-line import/first
import axios from '@nextcloud/axios'
// eslint-disable-next-line import/first
import { widgetBridge } from '../services/widgetBridge.js'

// ─── Global stubs ──────────────────────────────────────────────────────────

beforeAll(() => {
	if (typeof globalThis.t !== 'function') {
		globalThis.t = (_app, key) => key
	}
})

// ─── Helpers ───────────────────────────────────────────────────────────────

function makeWidget(content = {}) {
	return {
		content: {
			widgetId: 'test_widget',
			displayMode: 'vertical',
			...content,
		},
	}
}

function mountWidget(propsData = {}) {
	return mount(NcDashboardWidget, {
		propsData: {
			widget: makeWidget(),
			...propsData,
		},
	})
}

// ─── Tests ─────────────────────────────────────────────────────────────────

describe('NcDashboardWidget — array normalisation (defensive, PHP objects)', () => {
	beforeEach(() => {
		// PHP may serialise a sequential array as a JSON object with numeric keys
		window.__initialState = {
			widgets: { 0: { id: 'test_widget', title: 'Test Widget' }, 1: { id: 'other', title: 'Other' } },
		}
		widgetBridge.hasWidgetCallback.mockReturnValue(false)
		widgetBridge.pollForCallback.mockResolvedValue(false)
		axios.get.mockResolvedValue({ data: { items: { test_widget: [] } } })
	})

	afterEach(() => {
		delete window.__initialState
		vi.clearAllMocks()
	})

	it('normalises PHP-serialised object (numeric keys) to an array', () => {
		const wrapper = mountWidget()
		// availableWidgets computed must be an array despite object input
		expect(Array.isArray(wrapper.vm.availableWidgets)).toBe(true)
		expect(wrapper.vm.availableWidgets.length).toBe(2)
	})

	it('keeps a real JS array as-is', () => {
		window.__initialState = {
			widgets: [{ id: 'a', title: 'A' }, { id: 'b', title: 'B' }],
		}
		const wrapper = mountWidget()
		expect(Array.isArray(wrapper.vm.availableWidgets)).toBe(true)
		expect(wrapper.vm.availableWidgets.length).toBe(2)
	})
})

describe('NcDashboardWidget — empty-list state (REQ-WDG-021)', () => {
	beforeEach(() => {
		window.__initialState = { widgets: [{ id: 'test_widget', title: 'Test Widget', iconUrl: '' }] }
		widgetBridge.hasWidgetCallback.mockReturnValue(false)
		widgetBridge.pollForCallback.mockResolvedValue(false)
	})

	afterEach(() => {
		delete window.__initialState
		vi.clearAllMocks()
	})

	it('shows "No items available" when API returns an empty array', async () => {
		axios.get.mockResolvedValue({ data: { items: { test_widget: [] } } })
		const wrapper = mountWidget()

		await axios.get.mock.results[0].value
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('No items available')
		expect(wrapper.find('a').exists()).toBe(false)
	})

	it('shows "No items available" when API response is malformed', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = mountWidget()

		await axios.get.mock.results[0].value
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('No items available')
	})

	it('does not throw on malformed API response', () => {
		axios.get.mockResolvedValue({ data: null })
		expect(() => mountWidget()).not.toThrow()
	})
})

describe('NcDashboardWidget — mode switching: poll wins over API (REQ-WDG-019)', () => {
	beforeEach(() => {
		window.__initialState = { widgets: [{ id: 'notes', title: 'Notes', iconUrl: '' }] }
	})

	afterEach(() => {
		delete window.__initialState
		vi.clearAllMocks()
	})

	it('switches to native-callback mode when poll resolves true', async () => {
		// API will settle but poll also resolves true — native wins
		axios.get.mockResolvedValue({ data: { items: { notes: [{ title: 'Note 1', link: '#' }] } } })
		widgetBridge.hasWidgetCallback.mockReturnValue(false)
		widgetBridge.pollForCallback.mockResolvedValue(true)
		widgetBridge.mountWidget.mockImplementation(() => {})

		const wrapper = mount(NcDashboardWidget, {
			propsData: { widget: makeWidget({ widgetId: 'notes' }) },
		})

		// Await the poll promise then flush the Vue queue
		await widgetBridge.pollForCallback.mock.results[0].value
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.usesRegisteredCallback).toBe(true)
		expect(widgetBridge.mountWidget).toHaveBeenCalledWith(
			'notes',
			expect.any(Object),
			expect.objectContaining({ widget: expect.any(Object) }),
		)
	})

	it('stays in API fallback mode when poll resolves false', async () => {
		axios.get.mockResolvedValue({ data: { items: { notes: [{ title: 'Note A', link: '#' }] } } })
		widgetBridge.hasWidgetCallback.mockReturnValue(false)
		widgetBridge.pollForCallback.mockResolvedValue(false)

		const wrapper = mount(NcDashboardWidget, {
			propsData: { widget: makeWidget({ widgetId: 'notes' }) },
		})

		await axios.get.mock.results[0].value
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.usesRegisteredCallback).toBe(false)
		expect(widgetBridge.mountWidget).not.toHaveBeenCalled()
	})
})

describe('NcDashboardWidget — native fast-path when callback already registered', () => {
	afterEach(() => {
		delete window.__initialState
		vi.clearAllMocks()
	})

	it('mounts native immediately and does NOT issue API request', async () => {
		window.__initialState = { widgets: [{ id: 'notes', title: 'Notes', iconUrl: '' }] }
		widgetBridge.hasWidgetCallback.mockReturnValue(true)
		widgetBridge.mountWidget.mockImplementation(() => {})

		const wrapper = mount(NcDashboardWidget, {
			propsData: { widget: makeWidget({ widgetId: 'notes' }) },
		})

		await wrapper.vm.$nextTick()

		expect(wrapper.vm.usesRegisteredCallback).toBe(true)
		expect(axios.get).not.toHaveBeenCalled()
		expect(widgetBridge.pollForCallback).not.toHaveBeenCalled()
	})
})

describe('NcDashboardWidget — display modes (REQ-WDG-020)', () => {
	beforeEach(() => {
		window.__initialState = { widgets: [] }
		widgetBridge.hasWidgetCallback.mockReturnValue(false)
		widgetBridge.pollForCallback.mockResolvedValue(false)
		axios.get.mockResolvedValue({
			data: {
				items: {
					test_widget: [
						{ title: 'Item 1', subtitle: 'Sub 1', link: '#', iconUrl: '' },
						{ title: 'Item 2', subtitle: 'Sub 2', link: '#', iconUrl: '' },
					],
				},
			},
		})
	})

	afterEach(() => {
		delete window.__initialState
		vi.clearAllMocks()
	})

	it('renders vertical list class in vertical mode', async () => {
		const wrapper = mountWidget({ widget: makeWidget({ displayMode: 'vertical' }) })
		await axios.get.mock.results[0].value
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.nc-dashboard-widget__list--vertical').exists()).toBe(true)
		expect(wrapper.find('.nc-dashboard-widget__list--horizontal').exists()).toBe(false)
	})

	it('renders horizontal cards class in horizontal mode', async () => {
		const wrapper = mountWidget({ widget: makeWidget({ displayMode: 'horizontal' }) })
		await axios.get.mock.results[0].value
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.nc-dashboard-widget__list--horizontal').exists()).toBe(true)
		expect(wrapper.find('.nc-dashboard-widget__list--vertical').exists()).toBe(false)
	})
})
