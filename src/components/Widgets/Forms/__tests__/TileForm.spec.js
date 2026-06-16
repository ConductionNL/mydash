/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `TileForm.vue` covering REQ-WDG-022: validation
 * catches missing title + linkValue, controls pre-fill from
 * `editingWidget.content`, and IconPicker integration emits
 * `update:content` with the resolved iconType.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TileForm from '../TileForm.vue'

vi.mock('../../../../constants/dashboardIcons.js', () => ({
	isCustomIconUrl: (value) => typeof value === 'string'
		&& (value.startsWith('http') || value.startsWith('/')),
	DASHBOARD_ICONS: { Star: {} },
}))

vi.mock('../../../../services/resourceService.js', () => ({
	uploadDataUrl: vi.fn(),
	ResourceUploadError: class {},
}))

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

const STUBS = {
	NcTextField: true,
	NcSelect: true,
	IconPicker: true,
}

describe('TileForm', () => {
	it('REQ-WDG-022: validate() returns errors for missing title', () => {
		const wrapper = mount(TileForm, {
			propsData: { value: { title: '', linkValue: '/apps/files' } },
			stubs: STUBS,
		})
		const errors = wrapper.vm.validate()
		expect(errors).toContain('Tile title is required')
	})

	it('REQ-WDG-022: validate() returns errors for missing linkValue', () => {
		const wrapper = mount(TileForm, {
			propsData: { value: { title: 'Files', linkValue: '' } },
			stubs: STUBS,
		})
		const errors = wrapper.vm.validate()
		expect(errors).toContain('Tile link target is required')
	})

	it('REQ-WDG-022: validate() returns empty array when title + linkValue are present', () => {
		const wrapper = mount(TileForm, {
			propsData: { value: { title: 'Files', linkValue: '/apps/files' } },
			stubs: STUBS,
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-WDG-022: pre-fills all seven controls from editingWidget.content', () => {
		const editingWidget = {
			content: {
				title: 'Mail',
				icon: 'icon-mail',
				iconType: 'class',
				backgroundColor: '#cc0000',
				textColor: '#ffffff',
				linkType: 'url',
				linkValue: 'mailto:hi@example.com',
			},
		}
		const wrapper = mount(TileForm, {
			propsData: { editingWidget },
			stubs: STUBS,
		})
		expect(wrapper.vm.title).toBe('Mail')
		expect(wrapper.vm.icon).toBe('icon-mail')
		expect(wrapper.vm.iconType).toBe('class')
		expect(wrapper.vm.backgroundColor).toBe('#cc0000')
		expect(wrapper.vm.textColor).toBe('#ffffff')
		expect(wrapper.vm.linkType).toBe('url')
		expect(wrapper.vm.linkValue).toBe('mailto:hi@example.com')
	})

	it('REQ-WDG-022: emits update:content with the assembled payload when a field changes', () => {
		const wrapper = mount(TileForm, {
			propsData: { value: { title: 'A' } },
			stubs: STUBS,
		})
		wrapper.vm.updateField('title', 'B')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({ title: 'B' })
	})

	it('REQ-WDG-022: IconPicker URL output sets iconType to url', () => {
		const wrapper = mount(TileForm, {
			propsData: { value: { title: 'X' } },
			stubs: STUBS,
		})
		wrapper.vm.onIconChange('https://example.com/icon.png')
		expect(wrapper.vm.icon).toBe('https://example.com/icon.png')
		expect(wrapper.vm.iconType).toBe('url')
		const emitted = wrapper.emitted('update:content')
		expect(emitted[emitted.length - 1][0]).toMatchObject({
			icon: 'https://example.com/icon.png',
			iconType: 'url',
		})
	})

	it('REQ-WDG-022: IconPicker registry-key output sets iconType to class', () => {
		const wrapper = mount(TileForm, {
			propsData: { value: { title: 'X' } },
			stubs: STUBS,
		})
		wrapper.vm.onIconChange('Star')
		expect(wrapper.vm.icon).toBe('Star')
		expect(wrapper.vm.iconType).toBe('class')
	})
})
