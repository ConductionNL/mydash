/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `QuicklinksForm.vue` covering REQ-QLNK-002
 * (per-placement config shape preserved across save), REQ-QLNK-008
 * (URL validation rejects empty, javascript:, data:; sanitisation
 * happens before assemble), the bulk-add CSV parser, and
 * pre-fill-from-editingWidget behaviour.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import QuicklinksForm from '../QuicklinksForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

const mountForm = (initial = {}) => mount(QuicklinksForm, {
	propsData: { value: initial },
	stubs: { NcSelect: true },
})

describe('QuicklinksForm', () => {
	it('REQ-QLNK-002: defaults are applied when no content provided', () => {
		const wrapper = mountForm()
		expect(wrapper.vm.iconSize).toBe('medium')
		expect(wrapper.vm.iconShape).toBe('rounded')
		expect(wrapper.vm.showLabels).toBe(true)
		expect(wrapper.vm.labelPosition).toBe('below')
		expect(wrapper.vm.columns).toBe('auto')
		expect(wrapper.vm.tileBackgroundStyle).toBe('transparent')
		expect(wrapper.vm.hoverEffect).toBe('lift')
		expect(wrapper.vm.links).toEqual([])
	})

	it('REQ-QLNK-002: pre-fills all fields from editingWidget.content', () => {
		const editingWidget = {
			content: {
				links: [
					{ label: 'A', url: 'https://a', icon: 'ChartBar', color: '#112233' },
				],
				iconSize: 'large',
				iconShape: 'circle',
				showLabels: false,
				labelPosition: 'overlay',
				columns: 6,
				tileBackgroundStyle: 'solid',
				hoverEffect: 'fade',
			},
		}
		const wrapper = mount(QuicklinksForm, {
			propsData: { editingWidget },
			stubs: { NcSelect: true },
		})
		expect(wrapper.vm.iconSize).toBe('large')
		expect(wrapper.vm.iconShape).toBe('circle')
		expect(wrapper.vm.showLabels).toBe(false)
		expect(wrapper.vm.labelPosition).toBe('overlay')
		expect(wrapper.vm.columns).toBe(6)
		expect(wrapper.vm.tileBackgroundStyle).toBe('solid')
		expect(wrapper.vm.hoverEffect).toBe('fade')
		expect(wrapper.vm.links.length).toBe(1)
		expect(wrapper.vm.links[0].label).toBe('A')
	})

	it('REQ-QLNK-008: validate() returns error when any link URL is empty', () => {
		const wrapper = mountForm({
			links: [{ label: 'A', url: '', icon: '' }],
		})
		const errors = wrapper.vm.validate()
		expect(errors.length).toBeGreaterThan(0)
		expect(errors[0]).toBe('Invalid URL in one or more links')
	})

	it('REQ-QLNK-008: validate() returns error when any link URL has javascript: scheme', () => {
		const wrapper = mountForm({
			links: [{ label: 'A', url: 'javascript:alert(1)', icon: '' }],
		})
		expect(wrapper.vm.validate().length).toBeGreaterThan(0)
	})

	it('REQ-QLNK-008: validate() returns error when any link URL has data: scheme', () => {
		const wrapper = mountForm({
			links: [{ label: 'A', url: 'data:text/html,abc', icon: '' }],
		})
		expect(wrapper.vm.validate().length).toBeGreaterThan(0)
	})

	it('REQ-QLNK-008: validate() returns empty array when all URLs are valid', () => {
		const wrapper = mountForm({
			links: [
				{ label: 'A', url: 'https://a.example', icon: '' },
				{ label: 'B', url: '/apps/files/', icon: '' },
			],
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-QLNK-008: assembledContent strips javascript: URLs via sanitiseUrl', () => {
		const wrapper = mountForm({
			links: [{ label: 'A', url: 'javascript:alert(1)', icon: '' }],
		})
		const out = wrapper.vm.assembledContent
		expect(out.links[0].url).toBe('')
	})

	it('REQ-QLNK-002: addLink() appends an empty link row', () => {
		const wrapper = mountForm({ links: [] })
		const before = wrapper.vm.links.length
		wrapper.vm.addLink()
		expect(wrapper.vm.links.length).toBe(before + 1)
	})

	it('REQ-QLNK-002: removeLink(idx) removes the row', () => {
		const wrapper = mountForm({
			links: [
				{ label: 'A', url: 'https://a', icon: '' },
				{ label: 'B', url: 'https://b', icon: '' },
			],
		})
		wrapper.vm.removeLink(0)
		expect(wrapper.vm.links.length).toBe(1)
		expect(wrapper.vm.links[0].label).toBe('B')
	})

	it('REQ-QLNK-002: bulk-add parses CSV and appends new links', () => {
		const wrapper = mountForm({ links: [] })
		wrapper.vm.csvDraft = 'Docs,https://docs.example.com\nFiles,/apps/files/'
		wrapper.vm.applyBulkAdd()
		expect(wrapper.vm.links.length).toBe(2)
		expect(wrapper.vm.links[0]).toMatchObject({ label: 'Docs', url: 'https://docs.example.com' })
		expect(wrapper.vm.links[1]).toMatchObject({ label: 'Files', url: '/apps/files/' })
		expect(wrapper.vm.csvDraft).toBe('')
	})

	it('REQ-QLNK-002: bulk-add ignores empty lines and malformed rows', () => {
		const wrapper = mountForm({ links: [] })
		wrapper.vm.csvDraft = '\nDocs,https://docs\nMissingComma\n,onlyurl\nlabelonly,'
		wrapper.vm.applyBulkAdd()
		expect(wrapper.vm.links.length).toBe(1)
		expect(wrapper.vm.links[0].label).toBe('Docs')
	})

	it('REQ-QLNK-002: showColorColumn is true only when tile background is solid', () => {
		const wrapper = mountForm({})
		expect(wrapper.vm.showColorColumn).toBe(false)
		wrapper.vm.tileBackgroundStyle = 'solid'
		expect(wrapper.vm.showColorColumn).toBe(true)
	})
})
