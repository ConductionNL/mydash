/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `LinkButtonForm.vue` covering REQ-LBN-006
 * (button-mode validation, placeholder swap, edit-mode pre-fill of all
 * six controls) and REQ-LBLM-006/007/009 (list-mode toggle, list editor
 * add/remove/reorder, list-mode validation, legacy → list seeding).
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

	it('REQ-LBLM-001/009: legacy placement (no displayMode) defaults to button mode', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { label: 'X', url: 'https://e' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.displayMode).toBe('button')
		expect(wrapper.vm.isListMode).toBe(false)
	})

	it('REQ-LBLM-006: updateDisplayMode("list") on a legacy placement seeds the first link from legacy fields', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { label: 'Docs', url: 'https://docs.example', icon: 'book', actionType: 'external' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.links.length).toBe(0)
		wrapper.vm.updateDisplayMode('list')
		expect(wrapper.vm.displayMode).toBe('list')
		expect(wrapper.vm.links.length).toBe(1)
		expect(wrapper.vm.links[0]).toMatchObject({
			label: 'Docs',
			url: 'https://docs.example',
			icon: 'book',
			actionType: 'external',
		})
	})

	it('REQ-LBLM-006: addLink/removeLink/reorder mutate the links array', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: {
				value: {
					displayMode: 'list',
					links: [
						{ label: 'A', url: 'https://a', actionType: 'external' },
						{ label: 'B', url: 'https://b', actionType: 'external' },
					],
				},
			},
			stubs: { NcTextField: true, NcSelect: true },
		})

		expect(wrapper.vm.links.length).toBe(2)
		wrapper.vm.addLink()
		expect(wrapper.vm.links.length).toBe(3)
		wrapper.vm.updateLinkField(2, 'label', 'C')
		wrapper.vm.updateLinkField(2, 'url', 'https://c')
		expect(wrapper.vm.links[2].label).toBe('C')

		wrapper.vm.moveLinkUp(2)
		expect(wrapper.vm.links.map((link) => link.label)).toEqual(['A', 'C', 'B'])

		wrapper.vm.removeLink(0)
		expect(wrapper.vm.links.map((link) => link.label)).toEqual(['C', 'B'])
	})

	it('REQ-LBLM-006: addLink is capped at 20 entries', () => {
		const initialLinks = []
		for (let i = 0; i < 20; i += 1) {
			initialLinks.push({ label: `L${i}`, url: `https://${i}`, actionType: 'external' })
		}
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { displayMode: 'list', links: initialLinks } },
			stubs: { NcTextField: true, NcSelect: true },
		})

		expect(wrapper.vm.links.length).toBe(20)
		wrapper.vm.addLink()
		expect(wrapper.vm.links.length).toBe(20)
	})

	it('REQ-LBLM-007: validate() in list mode fails when links array is empty', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { displayMode: 'list', links: [] } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		const errors = wrapper.vm.validate()
		expect(errors).toContain('At least one link is required for list mode')
	})

	it('REQ-LBLM-007: validate() in list mode fails when any link is missing label or url', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: {
				value: {
					displayMode: 'list',
					links: [
						{ label: 'A', url: 'https://a', actionType: 'external' },
						{ label: '', url: 'https://b', actionType: 'external' },
					],
				},
			},
			stubs: { NcTextField: true, NcSelect: true },
		})
		const errors = wrapper.vm.validate()
		expect(errors).toContain('Each link requires a label and a URL')
	})

	it('REQ-LBLM-007: validate() in list mode passes when all links are valid', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: {
				value: {
					displayMode: 'list',
					links: [
						{ label: 'A', url: 'https://a', actionType: 'external' },
						{ label: 'B', url: 'https://b', actionType: 'external' },
					],
				},
			},
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-LBLM-009: assembledContent always includes the new fields with safe defaults', () => {
		const wrapper = mount(LinkButtonForm, {
			propsData: { value: { label: 'X', url: 'y' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		const content = wrapper.vm.assembledContent
		expect(content.displayMode).toBe('button')
		expect(content.listOrientation).toBe('vertical')
		expect(content.listItemGap).toBe('normal')
		expect(content.links).toEqual([])
	})
})
