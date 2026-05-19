/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `NcDashboardWidget.vue` covering REQ-WDG-018,
 * REQ-WDG-019, REQ-WDG-020 and REQ-WDG-021. Verifies the two-mode mounting
 * behaviour (native callback present at mount, native callback registers
 * mid-poll, full API fallback when no callback ever appears, malformed API
 * response, and defensive normalisation of an object-with-numeric-keys
 * widgets catalog).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { widgetBridge } from '../../../../services/widgetBridge.js'
import { api } from '../../../../services/api.js'
import NcDashboardWidget, { normaliseWidgetCatalog, extractItems } from '../NcDashboardWidget.vue'

vi.mock('../../../../services/api.js', () => ({
	api: {
		getWidgetItems: vi.fn(),
	},
}))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	widgetBridge.widgetCallbacks.clear()
	api.getWidgetItems.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
	widgetBridge.widgetCallbacks.clear()
})

describe('NcDashboardWidget', () => {
	it('REQ-WDG-019: mounts natively when callback is already registered (no API call)', async () => {
		const callback = vi.fn()
		widgetBridge.widgetCallbacks.set('notes', callback)

		mount(NcDashboardWidget, {
			propsData: { content: { widgetId: 'notes', displayMode: 'vertical' } },
			provide: { widgets: [{ id: 'notes', title: 'Notes', iconUrl: '' }] },
		})

		await flush()
		await flush()

		expect(api.getWidgetItems).not.toHaveBeenCalled()
		expect(callback).toHaveBeenCalledTimes(1)
	})

	it('REQ-WDG-019: switches from API mode to native when poll wins mid-flight', async () => {
		// First fetch resolves with empty data; we are checking the mode
		// switch, not the items.
		api.getWidgetItems.mockResolvedValue({ data: { notes: { items: [] } } })

		vi.useFakeTimers()
		const wrapper = mount(NcDashboardWidget, {
			propsData: { content: { widgetId: 'notes', displayMode: 'vertical' } },
			provide: { widgets: [{ id: 'notes', title: 'Notes' }] },
		})

		// Initial mode is `api` because the callback isn't registered.
		expect(wrapper.vm.mode).toBe('api')

		// Register the callback after one poll tick so the next tick detects it.
		const cb = vi.fn()
		await vi.advanceTimersByTimeAsync(200)
		widgetBridge.widgetCallbacks.set('notes', cb)
		await vi.advanceTimersByTimeAsync(200)

		expect(wrapper.vm.mode).toBe('native')
	})

	it('REQ-WDG-021: handles malformed API responses without throwing', async () => {
		api.getWidgetItems.mockResolvedValue({ data: 'not-an-object' })

		const wrapper = mount(NcDashboardWidget, {
			propsData: { content: { widgetId: 'weather_status', displayMode: 'vertical' } },
			provide: { widgets: [{ id: 'weather_status', title: 'Weather' }] },
		})

		await flush()
		await flush()

		expect(wrapper.vm.items).toEqual([])
		expect(wrapper.text()).toContain('No items available')
	})

	it('tasks.md §2: normaliseWidgetCatalog tolerates object-with-numeric-keys input', () => {
		const input = { 0: { id: 'a' }, 1: { id: 'b' } }
		const result = normaliseWidgetCatalog(input)
		expect(result).toEqual([{ id: 'a' }, { id: 'b' }])
	})

	it('tasks.md §2: normaliseWidgetCatalog passes arrays through unchanged', () => {
		const input = [{ id: 'a' }, { id: 'b' }]
		expect(normaliseWidgetCatalog(input)).toBe(input)
	})

	it('tasks.md §2: normaliseWidgetCatalog returns [] for null/undefined', () => {
		expect(normaliseWidgetCatalog(null)).toEqual([])
		expect(normaliseWidgetCatalog(undefined)).toEqual([])
	})

	it('REQ-WDG-021: extractItems pulls from the wrapped {items} envelope', () => {
		const data = { items: [{ title: 'a' }, { title: 'b' }] }
		expect(extractItems(data)).toEqual([{ title: 'a' }, { title: 'b' }])
	})

	it('REQ-WDG-021: extractItems collapses null payload to []', () => {
		expect(extractItems(null)).toEqual([])
	})
})
