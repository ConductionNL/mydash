/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `NcDashboardForm.vue` covering REQ-WDG-018:
 * widgets-catalog-driven picker (now a CSS-grid of icon cards via
 * `NcWidgetGridPicker`), validation rejecting an empty pick, and pre-filling
 * both controls from `editingWidget.content` when opened in edit mode.
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import NcDashboardForm from '../NcDashboardForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('NcDashboardForm', () => {
	it('REQ-WDG-018: lists all discovered widgets in the picker', () => {
		const widgets = [
			{ id: 'weather_status', title: 'Weather' },
			{ id: 'recommendations', title: 'Recommended' },
		]
		const wrapper = mount(NcDashboardForm, {
			provide: { widgets },
		})
		const cards = wrapper.findAll('[role="radio"]').wrappers.map((c) => c.attributes('aria-label'))
		expect(cards).toContain('Weather')
		expect(cards).toContain('Recommended')
	})

	it('REQ-WDG-018: does NOT render a <select> for the widget picker', () => {
		const wrapper = mount(NcDashboardForm, {
			provide: { widgets: [{ id: 'weather_status', title: 'Weather' }] },
		})
		// Display-mode select is still allowed; just assert no widget options.
		const widgetOptions = wrapper.findAll('option').wrappers
			.map((o) => o.attributes('value'))
			.filter((v) => v === 'weather_status')
		expect(widgetOptions.length).toBe(0)
	})

	it('REQ-WDG-018: validate() returns one error when widgetId is empty', () => {
		const wrapper = mount(NcDashboardForm, {
			provide: { widgets: [{ id: 'a', title: 'A' }] },
		})
		const errors = wrapper.vm.validate()
		expect(errors.length).toBe(1)
	})

	it('REQ-WDG-018: validate() returns empty array when widgetId is set', () => {
		const wrapper = mount(NcDashboardForm, {
			propsData: { value: { widgetId: 'notes', displayMode: 'vertical' } },
			provide: { widgets: [{ id: 'notes', title: 'Notes' }] },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-WDG-018: pre-fills both controls from editingWidget.content', () => {
		const editingWidget = {
			content: { widgetId: 'recommendations', displayMode: 'horizontal' },
		}
		const wrapper = mount(NcDashboardForm, {
			propsData: { editingWidget },
			provide: { widgets: [{ id: 'recommendations', title: 'Rec' }] },
		})
		expect(wrapper.vm.widgetId).toBe('recommendations')
		expect(wrapper.vm.displayMode).toBe('horizontal')
	})

	it('emits update:content when the user picks a widget', async () => {
		const wrapper = mount(NcDashboardForm, {
			provide: { widgets: [{ id: 'notes', title: 'Notes' }] },
		})
		wrapper.vm.widgetId = 'notes'
		wrapper.vm.emitContent()
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({ widgetId: 'notes' })
	})

	it('tolerates an object-with-numeric-keys catalog (PHP serialisation quirk)', () => {
		const wrapper = mount(NcDashboardForm, {
			provide: { widgets: { 0: { id: 'a', title: 'A' }, 1: { id: 'b', title: 'B' } } },
		})
		expect(wrapper.vm.widgetOptions.map((w) => w.id)).toEqual(['a', 'b'])
	})

	it('emits update:content with the widgetId chosen via the grid picker', async () => {
		const wrapper = mount(NcDashboardForm, {
			provide: { widgets: [{ id: 'notes', title: 'Notes' }, { id: 'weather_status', title: 'Weather' }] },
		})
		const cards = wrapper.findAll('[role="radio"]')
		await cards.at(1).trigger('click')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({ widgetId: 'weather_status' })
	})
})
