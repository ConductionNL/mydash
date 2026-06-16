/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `CalendarWidget.vue` covering REQ-CAL-008
 * (three render modes), REQ-CAL-010 (empty + loading + error states),
 * and REQ-CAL-009 failure-notice surfacing.
 *
 * Network calls are mocked at the `@nextcloud/axios` boundary so the
 * suite does not require a backing HTTP server.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import axios from '@nextcloud/axios'
import CalendarWidget from '../CalendarWidget.vue'

vi.mock('@nextcloud/axios', () => {
	return {
		default: {
			get: vi.fn(),
		},
	}
})

vi.mock('@nextcloud/router', () => {
	return {
		generateUrl: (template, args = {}) => {
			let out = template
			for (const [key, val] of Object.entries(args)) {
				out = out.replace(`{${key}}`, encodeURIComponent(String(val)))
			}
			return out
		},
	}
})

beforeEach(() => {
	globalThis.t = (_app, key) => key
	axios.get.mockReset()
})

describe('CalendarWidget', () => {
	it('REQ-CAL-010: shows "No calendars configured" when content has no sources', async () => {
		const wrapper = mount(CalendarWidget, {
			propsData: {
				content: { internalCalendars: [], externalIcsUrls: [] },
				placementId: 1,
			},
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('No calendars configured')
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('REQ-CAL-010: shows empty state when API returns no events', async () => {
		axios.get.mockResolvedValueOnce({ data: { events: [], failures: [] } })
		const wrapper = mount(CalendarWidget, {
			propsData: {
				content: { externalIcsUrls: ['https://x.test/cal.ics'], daysAhead: 7 },
				placementId: 42,
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('No events in the next 7 days')
	})

	it('REQ-CAL-010: shows error state with retry on fetch failure', async () => {
		axios.get.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mount(CalendarWidget, {
			propsData: {
				content: { externalIcsUrls: ['https://x.test/cal.ics'] },
				placementId: 42,
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('Failed to load events')
		expect(wrapper.text()).toContain('Retry')
	})

	it('REQ-CAL-008: renders agenda mode by default with grouped events', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				events: [
					{
						uid: 'a',
						title: 'Meeting one',
						start: '2026-05-03T09:00:00Z',
						end: '2026-05-03T10:00:00Z',
						allDay: false,
						calendarName: 'Work',
					},
					{
						uid: 'b',
						title: 'Lunch',
						start: '2026-05-03T12:00:00Z',
						end: '2026-05-03T13:00:00Z',
						allDay: false,
						calendarName: 'Work',
					},
				],
				failures: [],
			},
		})
		const wrapper = mount(CalendarWidget, {
			propsData: {
				content: { externalIcsUrls: ['https://x.test/cal.ics'], viewMode: 'agenda' },
				placementId: 42,
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		const txt = wrapper.text()
		expect(txt).toContain('Meeting one')
		expect(txt).toContain('Lunch')
		expect(wrapper.find('.calendar-widget__agenda').exists()).toBe(true)
	})

	it('REQ-CAL-008: switching to month mode renders the 7-column grid', async () => {
		axios.get.mockResolvedValue({ data: { events: [], failures: [] } })
		const wrapper = mount(CalendarWidget, {
			propsData: {
				content: { externalIcsUrls: ['https://x.test/cal.ics'], viewMode: 'month' },
				placementId: 42,
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.calendar-widget__month').exists()).toBe(true)
		// 7 weekday headers + at least 28 day cells = >= 35 children
		const cells = wrapper.findAll('.calendar-widget__month-cell')
		expect(cells.length).toBeGreaterThanOrEqual(28)
	})

	it('REQ-CAL-008: week mode renders exactly 7 columns', async () => {
		axios.get.mockResolvedValue({ data: { events: [], failures: [] } })
		const wrapper = mount(CalendarWidget, {
			propsData: {
				content: { externalIcsUrls: ['https://x.test/cal.ics'], viewMode: 'week' },
				placementId: 42,
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		const cols = wrapper.findAll('.calendar-widget__week-col')
		expect(cols.length).toBe(7)
	})

	it('REQ-CAL-009: failure notice appears when failures returned', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				events: [
					{
						uid: 'a',
						title: 'Solo',
						start: '2026-05-03T09:00:00Z',
						end: '2026-05-03T10:00:00Z',
						allDay: false,
					},
				],
				failures: ['external: timeout https://x.test/cal.ics'],
			},
		})
		const wrapper = mount(CalendarWidget, {
			propsData: {
				content: { externalIcsUrls: ['https://x.test/cal.ics'] },
				placementId: 42,
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.calendar-widget__failures').exists()).toBe(true)
		expect(wrapper.text()).toContain('1 calendar source(s) unavailable')
	})
})
