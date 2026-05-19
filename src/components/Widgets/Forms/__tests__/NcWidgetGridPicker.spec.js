/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `NcWidgetGridPicker.vue` covering REQ-NCDP-PICKER:
 * grid renders one card per discovered widget, click selects, keyboard
 * navigation rotates focus + tabindex, empty state when no widgets, and
 * v-model binds to the widget id (string).
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import NcWidgetGridPicker from '../NcWidgetGridPicker.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('NcWidgetGridPicker', () => {
	const baseWidgets = [
		{ id: 'weather_status', title: 'Weather', iconUrl: '/apps/weather_status/img/app.svg' },
		{ id: 'recommendations', title: 'Recommended', iconUrl: '/apps/recommendations/img/app.svg' },
		{ id: 'notes', title: 'Notes', iconUrl: '/apps/notes/img/app.svg' },
		{ id: 'mail', title: 'Mail', iconUrl: '' },
	]

	it('renders one card per widget with role="radio" and the widget icon + title', () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: { widgets: baseWidgets, value: '' },
		})
		const cards = wrapper.findAll('[role="radio"]')
		expect(cards.length).toBe(4)
		// Icon images present for widgets with iconUrl
		const imgs = wrapper.findAll('img.nc-widget-grid-picker__icon')
		expect(imgs.length).toBe(3)
		// Title text present
		const titles = wrapper.findAll('.nc-widget-grid-picker__title').wrappers.map((t) => t.text())
		expect(titles).toEqual(['Weather', 'Recommended', 'Notes', 'Mail'])
	})

	it('container has role="radiogroup" with an aria-label', () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: { widgets: baseWidgets, value: '' },
		})
		const group = wrapper.find('[role="radiogroup"]')
		expect(group.exists()).toBe(true)
		expect(group.attributes('aria-label')).toBe('Pick a widget')
	})

	it('clicking a card emits input with that widget id (v-model contract)', async () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: { widgets: baseWidgets, value: '' },
		})
		const cards = wrapper.findAll('[role="radio"]')
		await cards.at(2).trigger('click')
		const emitted = wrapper.emitted('input')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toBe('notes')
	})

	it('selected card has the selected-state class and aria-checked="true"', () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: { widgets: baseWidgets, value: 'recommendations' },
		})
		const cards = wrapper.findAll('[role="radio"]')
		expect(cards.at(1).classes()).toContain('nc-widget-grid-picker__card--selected')
		expect(cards.at(1).attributes('aria-checked')).toBe('true')
		expect(cards.at(0).attributes('aria-checked')).toBe('false')
	})

	it('selected card has tabindex="0" and others have tabindex="-1"', () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: { widgets: baseWidgets, value: 'notes' },
		})
		const cards = wrapper.findAll('[role="radio"]')
		expect(cards.at(2).attributes('tabindex')).toBe('0')
		expect(cards.at(0).attributes('tabindex')).toBe('-1')
		expect(cards.at(1).attributes('tabindex')).toBe('-1')
		expect(cards.at(3).attributes('tabindex')).toBe('-1')
	})

	it('with no selection, the first card is the tabindex entry point', () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: { widgets: baseWidgets, value: '' },
		})
		const cards = wrapper.findAll('[role="radio"]')
		expect(cards.at(0).attributes('tabindex')).toBe('0')
		expect(cards.at(1).attributes('tabindex')).toBe('-1')
	})

	it('ArrowRight from card 0 moves focus to card 1', async () => {
		const wrapper = mount(NcWidgetGridPicker, {
			attachTo: document.body,
			propsData: { widgets: baseWidgets, value: '' },
		})
		const cards = wrapper.findAll('[role="radio"]')
		cards.at(0).element.focus()
		await cards.at(0).trigger('keydown', { key: 'ArrowRight' })
		await wrapper.vm.$nextTick()
		expect(document.activeElement).toBe(cards.at(1).element)
		wrapper.destroy()
	})

	it('ArrowLeft from card 0 wraps to the last card', async () => {
		const wrapper = mount(NcWidgetGridPicker, {
			attachTo: document.body,
			propsData: { widgets: baseWidgets, value: '' },
		})
		const cards = wrapper.findAll('[role="radio"]')
		cards.at(0).element.focus()
		await cards.at(0).trigger('keydown', { key: 'ArrowLeft' })
		await wrapper.vm.$nextTick()
		expect(document.activeElement).toBe(cards.at(3).element)
		wrapper.destroy()
	})

	it('Enter on a focused card selects it', async () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: { widgets: baseWidgets, value: '' },
		})
		const cards = wrapper.findAll('[role="radio"]')
		await cards.at(1).trigger('keydown', { key: 'Enter' })
		const emitted = wrapper.emitted('input')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toBe('recommendations')
	})

	it('Space on a focused card selects it', async () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: { widgets: baseWidgets, value: '' },
		})
		const cards = wrapper.findAll('[role="radio"]')
		await cards.at(2).trigger('keydown', { key: ' ' })
		const emitted = wrapper.emitted('input')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toBe('notes')
	})

	it('renders the localised empty-state message when no widgets are passed', () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: { widgets: [], value: '' },
		})
		expect(wrapper.find('[role="radiogroup"]').exists()).toBe(false)
		expect(wrapper.find('.nc-widget-grid-picker__empty').text()).toBe(
			'No Nextcloud widgets are installed',
		)
	})

	it('tolerates an object-with-numeric-keys catalog (PHP serialisation quirk)', () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: {
				widgets: { 0: { id: 'a', title: 'A' }, 1: { id: 'b', title: 'B' } },
				value: '',
			},
		})
		const cards = wrapper.findAll('[role="radio"]')
		expect(cards.length).toBe(2)
	})

	it('falls back to a placeholder when iconUrl is missing', () => {
		const wrapper = mount(NcWidgetGridPicker, {
			propsData: {
				widgets: [{ id: 'mail', title: 'Mail' }],
				value: '',
			},
		})
		expect(wrapper.find('img.nc-widget-grid-picker__icon').exists()).toBe(false)
		expect(wrapper.find('.nc-widget-grid-picker__icon--placeholder').exists()).toBe(true)
	})
})
