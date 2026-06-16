/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `PeopleForm.vue` covering REQ-PPL-002 (per-placement
 * configuration shape, `birthdayWindowDays` 0..30 validation, group-filter
 * round-trip via `filters` array).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import PeopleForm from '../PeopleForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key, params) => {
		if (params) {
			return Object.entries(params).reduce(
				(acc, [k, v]) => acc.replace(`{${k}}`, String(v)),
				key,
			)
		}
		return key
	}
})

describe('PeopleForm', () => {
	it('REQ-PPL-002: defaults align with the spec when value is empty', () => {
		const wrapper = mount(PeopleForm, {
			propsData: { value: {} },
			stubs: { NcSelect: true },
		})
		expect(wrapper.vm.layout).toBe('grid')
		expect(wrapper.vm.sortBy).toBe('displayName')
		expect(wrapper.vm.excludeDisabled).toBe(true)
		expect(wrapper.vm.showBirthdays).toBe(true)
		expect(wrapper.vm.birthdayWindowDays).toBe(7)
		expect(wrapper.vm.columns).toBe(3)
	})

	it('REQ-PPL-002: pre-fills from editingWidget.content', () => {
		const wrapper = mount(PeopleForm, {
			propsData: {
				editingWidget: {
					content: {
						layout: 'card',
						sortBy: 'group',
						excludeDisabled: false,
						showBirthdays: false,
						birthdayWindowDays: 14,
						columns: 4,
						filters: [
							{ fieldName: 'group', operator: 'in', values: ['management', 'product'] },
						],
					},
				},
			},
			stubs: { NcSelect: true },
		})
		expect(wrapper.vm.layout).toBe('card')
		expect(wrapper.vm.sortBy).toBe('group')
		expect(wrapper.vm.excludeDisabled).toBe(false)
		expect(wrapper.vm.showBirthdays).toBe(false)
		expect(wrapper.vm.birthdayWindowDays).toBe(14)
		expect(wrapper.vm.columns).toBe(4)
		expect(wrapper.vm.groupFilterValues).toEqual(['management', 'product'])
	})

	it('REQ-PPL-006: group-filter round-trips into the canonical filters array', () => {
		const wrapper = mount(PeopleForm, {
			propsData: { value: {} },
			stubs: { NcSelect: true },
		})
		wrapper.vm.updateGroupFilter('management, product, ')
		expect(wrapper.vm.assembledFilters).toEqual([
			{ fieldName: 'group', operator: 'in', values: ['management', 'product'] },
		])
		expect(wrapper.emitted('update:content')).toBeTruthy()
	})

	it('REQ-PPL-002: empty group filter produces empty filters array', () => {
		const wrapper = mount(PeopleForm, {
			propsData: { value: {} },
			stubs: { NcSelect: true },
		})
		wrapper.vm.updateGroupFilter('   ')
		expect(wrapper.vm.assembledFilters).toEqual([])
	})

	it('REQ-PPL-002: validate() rejects birthdayWindowDays > 30', () => {
		const wrapper = mount(PeopleForm, {
			propsData: { value: { birthdayWindowDays: 50 } },
			stubs: { NcSelect: true },
		})
		expect(wrapper.vm.validate()).toContain('Birthday window must be between 0 and 30')
	})

	it('REQ-PPL-002: validate() accepts in-range values', () => {
		const wrapper = mount(PeopleForm, {
			propsData: { value: { birthdayWindowDays: 0 } },
			stubs: { NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-PPL-002: updateBirthdayWindow records inline error for out-of-range input', () => {
		const wrapper = mount(PeopleForm, {
			propsData: { value: {} },
			stubs: { NcSelect: true },
		})
		wrapper.vm.updateBirthdayWindow('99')
		expect(wrapper.vm.birthdayWindowError).toBe('Must be between 0 and 30')
	})

	it('REQ-PPL-002: updateBirthdayWindow clears error and clamps to integer', () => {
		const wrapper = mount(PeopleForm, {
			propsData: { value: {} },
			stubs: { NcSelect: true },
		})
		wrapper.vm.updateBirthdayWindow('5.7')
		expect(wrapper.vm.birthdayWindowError).toBe('')
		expect(wrapper.vm.birthdayWindowDays).toBe(5)
	})
})
