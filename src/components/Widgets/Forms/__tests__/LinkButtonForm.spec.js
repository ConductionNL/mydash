/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `LinkButtonForm.vue` covering REQ-LBN-006:
 * validation requires both `label` and `url` non-empty, the placeholder
 * swaps with `actionType`, and the form pre-fills every one of the six
 * controls from `editingWidget.content` when opened in edit mode.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import LinkButtonForm from '../LinkButtonForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('LinkButtonForm', () => {
	it('REQ-LBN-006: validate() returns one error when label is empty', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { label: '', url: 'x' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toContain('Label is required')
	})

	it('REQ-LBN-006: validate() returns one error when url is empty', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { label: 'X', url: '' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toContain('URL is required')
	})

	it('REQ-LBN-006: validate() returns empty array when both label and url are non-empty', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { label: 'X', url: 'https://example.com' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-LBN-006: pre-fills all six controls from editingWidget.content', () => {
		const editingWidget = {
			content: {
				label: 'My Tile',
				url: 'docx',
				icon: '/apps/mydash/resource/x.png',
				actionType: 'createFile',
				backgroundColor: '#112233',
				textColor: '#ffffff',
			},
		}
		const wrapper = mount(LinkButtonForm, {
			propsData: { editingWidget },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.label).toBe('My Tile')
		expect(wrapper.vm.url).toBe('docx')
		expect(wrapper.vm.icon).toBe('/apps/mydash/resource/x.png')
		expect(wrapper.vm.actionType).toBe('createFile')
		expect(wrapper.vm.backgroundColor).toBe('#112233')
		expect(wrapper.vm.textColor).toBe('#ffffff')
	})

	it('REQ-LBN-006: urlPlaceholder swaps with actionType', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { label: 'X', url: '', actionType: 'external' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.urlPlaceholder).toBe('https://...')

		wrapper.vm.actionType = 'createFile'
		expect(wrapper.vm.urlPlaceholder).toBe('docx')

		wrapper.vm.actionType = 'internal'
		expect(wrapper.vm.urlPlaceholder).toBe('action-id')

		wrapper.vm.actionType = 'external'
		expect(wrapper.vm.urlPlaceholder).toBe('https://...')
	})

	it('emits update:content with the assembled payload when a field changes', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { label: 'X', url: 'y' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		wrapper.vm.updateField('label', 'Y')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({ label: 'Y' })
	})
})
