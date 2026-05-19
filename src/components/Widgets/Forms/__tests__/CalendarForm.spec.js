/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `CalendarForm.vue` covering REQ-CAL-002:
 * - validation requires at least one calendar source,
 * - external URLs must be HTTPS,
 * - assembled content matches the expected shape,
 * - editing widget pre-fills all five controls.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import CalendarForm from '../CalendarForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('CalendarForm', () => {
	it('REQ-CAL-002: validate() reports missing sources when both arrays empty', () => {
		const wrapper = mount(CalendarForm, {
			propsData: { value: { internalCalendars: [], externalIcsUrls: [] } },
		})
		const errors = wrapper.vm.validate()
		expect(errors).toContain('At least one calendar source is required')
	})

	it('REQ-CAL-002: validate() rejects non-HTTPS external URLs', () => {
		const wrapper = mount(CalendarForm, {
			propsData: { value: { internalCalendars: [], externalIcsUrls: ['http://x.test/cal.ics'] } },
		})
		const errors = wrapper.vm.validate()
		expect(errors).toContain('External ICS URLs must start with https://')
	})

	it('REQ-CAL-002: validate() returns empty when at least one HTTPS URL is set', () => {
		const wrapper = mount(CalendarForm, {
			propsData: { value: { externalIcsUrls: ['https://x.test/cal.ics'] } },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-CAL-002: assembledContent has the canonical shape', () => {
		const wrapper = mount(CalendarForm, {
			propsData: { value: { externalIcsUrls: ['https://x.test/cal.ics'], viewMode: 'week', daysAhead: 30, colorByCalendar: false } },
		})
		expect(wrapper.vm.assembledContent).toMatchObject({
			internalCalendars: [],
			externalIcsUrls: ['https://x.test/cal.ics'],
			viewMode: 'week',
			daysAhead: 30,
			colorByCalendar: false,
		})
	})

	it('REQ-CAL-002: pre-fills controls from editingWidget.content', () => {
		const editingWidget = {
			content: {
				internalCalendars: ['principals/users/alice/personal'],
				externalIcsUrls: ['https://x.test/cal.ics'],
				viewMode: 'month',
				daysAhead: 7,
				colorByCalendar: false,
			},
		}
		const wrapper = mount(CalendarForm, {
			propsData: { editingWidget },
		})
		expect(wrapper.vm.internalCalendars).toEqual(['principals/users/alice/personal'])
		expect(wrapper.vm.externalIcsUrls).toEqual(['https://x.test/cal.ics'])
		expect(wrapper.vm.viewMode).toBe('month')
		expect(wrapper.vm.daysAhead).toBe(7)
		expect(wrapper.vm.colorByCalendar).toBe(false)
	})

	it('emits update:content when a multi-line field is edited', () => {
		const wrapper = mount(CalendarForm, {
			propsData: { value: {} },
		})
		wrapper.vm.updateMulti('externalIcsUrls', 'https://a.test/x.ics\nhttps://b.test/y.ics')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		const last = emitted[emitted.length - 1][0]
		expect(last.externalIcsUrls).toEqual(['https://a.test/x.ics', 'https://b.test/y.ics'])
	})
})
