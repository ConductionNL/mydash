/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `LinksForm.vue` covering REQ-LNKS-002 (config
 * shape with defaults), REQ-LNKS-007 (URL sanitisation), and REQ-LNKS-008
 * (add / delete / reorder for sections and links).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import LinksForm from '../LinksForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

function mountForm(content = null) {
	return mount(LinksForm, {
		propsData: {
			editingWidget: content ? { content } : null,
		},
	})
}

describe('LinksForm — defaults (REQ-LNKS-002)', () => {
	it('falls back to default columns/layout/iconSize when content is empty', () => {
		const wrapper = mountForm({})
		expect(wrapper.vm.columns).toBe(3)
		expect(wrapper.vm.linkLayout).toBe('card')
		expect(wrapper.vm.iconSize).toBe('medium')
		expect(wrapper.vm.openInNewTab).toBe(true)
		expect(wrapper.vm.showSectionTitles).toBe(true)
		expect(wrapper.vm.showLinkDescriptions).toBe(true)
	})

	it('pre-fills from editingWidget.content', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: 'https://e.com', icon: 'X', description: 'D' }] }],
			columns: 5,
			linkLayout: 'inline',
			iconSize: 'large',
			openInNewTab: false,
			showSectionTitles: false,
			showLinkDescriptions: false,
		})
		expect(wrapper.vm.columns).toBe(5)
		expect(wrapper.vm.linkLayout).toBe('inline')
		expect(wrapper.vm.iconSize).toBe('large')
		expect(wrapper.vm.openInNewTab).toBe(false)
		expect(wrapper.vm.showSectionTitles).toBe(false)
		expect(wrapper.vm.showLinkDescriptions).toBe(false)
		expect(wrapper.vm.sections).toHaveLength(1)
		expect(wrapper.vm.sections[0].links[0].url).toBe('https://e.com')
	})

	it('clamps columns into the 1..6 range', () => {
		const wrapper = mountForm({ columns: 99 })
		expect(wrapper.vm.columns).toBe(6)
		const wrapper2 = mountForm({ columns: 0 })
		expect(wrapper2.vm.columns).toBe(1)
	})
})

describe('LinksForm — section + link mutators (REQ-LNKS-008)', () => {
	it('addSection appends an empty section and emits update:content', () => {
		const wrapper = mountForm({})
		wrapper.vm.addSection()
		expect(wrapper.vm.sections).toHaveLength(1)
		expect(wrapper.vm.sections[0].title).toBe('')
		expect(wrapper.vm.sections[0].links).toHaveLength(1)
		expect(wrapper.emitted('update:content')).toBeTruthy()
	})

	it('deleteSection removes the section at the given index', () => {
		const wrapper = mountForm({
			sections: [
				{ title: 'A', links: [{ label: 'L', url: 'https://a.com' }] },
				{ title: 'B', links: [{ label: 'L', url: 'https://b.com' }] },
			],
		})
		wrapper.vm.deleteSection(0)
		expect(wrapper.vm.sections).toHaveLength(1)
		expect(wrapper.vm.sections[0].title).toBe('B')
	})

	it('moveSection reorders by the given delta', () => {
		const wrapper = mountForm({
			sections: [
				{ title: 'A', links: [] },
				{ title: 'B', links: [] },
				{ title: 'C', links: [] },
			],
		})
		wrapper.vm.moveSection(0, 2)
		expect(wrapper.vm.sections.map((s) => s.title)).toEqual(['B', 'C', 'A'])
	})

	it('moveSection ignores out-of-range targets', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [] }, { title: 'B', links: [] }],
		})
		wrapper.vm.moveSection(0, -1)
		expect(wrapper.vm.sections.map((s) => s.title)).toEqual(['A', 'B'])
	})

	it('addLink appends an empty link to the given section', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: 'https://e.com' }] }],
		})
		wrapper.vm.addLink(0)
		expect(wrapper.vm.sections[0].links).toHaveLength(2)
		expect(wrapper.vm.sections[0].links[1].url).toBe('')
	})

	it('deleteLink removes the link at the given indices', () => {
		const wrapper = mountForm({
			sections: [{
				title: 'A',
				links: [
					{ label: 'A', url: 'https://a.com' },
					{ label: 'B', url: 'https://b.com' },
				],
			}],
		})
		wrapper.vm.deleteLink(0, 0)
		expect(wrapper.vm.sections[0].links).toHaveLength(1)
		expect(wrapper.vm.sections[0].links[0].label).toBe('B')
	})

	it('moveLink reorders within a single section without affecting siblings', () => {
		const wrapper = mountForm({
			sections: [
				{
					title: 'A',
					links: [
						{ label: 'A1', url: 'https://a1.com' },
						{ label: 'A2', url: 'https://a2.com' },
					],
				},
				{ title: 'B', links: [{ label: 'B1', url: 'https://b1.com' }] },
			],
		})
		wrapper.vm.moveLink(0, 0, 1)
		expect(wrapper.vm.sections[0].links.map((l) => l.label)).toEqual(['A2', 'A1'])
		expect(wrapper.vm.sections[1].links.map((l) => l.label)).toEqual(['B1'])
	})

	it('updateLink mutates the requested field and emits update:content', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: '', url: '' }] }],
		})
		wrapper.vm.updateLink(0, 0, 'url', 'https://new.com')
		expect(wrapper.vm.sections[0].links[0].url).toBe('https://new.com')
		expect(wrapper.emitted('update:content')).toBeTruthy()
	})
})

describe('LinksForm — assembledContent', () => {
	it('returns a faithful copy of the form state', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: 'https://e.com' }] }],
			columns: 4,
		})
		const payload = wrapper.vm.assembledContent
		expect(payload.columns).toBe(4)
		expect(payload.sections[0].title).toBe('A')
		expect(payload.sections[0].links[0].url).toBe('https://e.com')
	})
})

describe('LinksForm — URL sanitisation (REQ-LNKS-007)', () => {
	it('rejects empty URL', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: '' }] }],
		})
		const errors = wrapper.vm.validate()
		expect(errors.some((e) => e.includes('Link URL is required'))).toBe(true)
	})

	it('rejects javascript: URLs', () => {
		const wrapper = mountForm({
			// eslint-disable-next-line no-script-url
			sections: [{ title: 'A', links: [{ label: 'L', url: 'javascript:alert(1)' }] }],
		})
		const errors = wrapper.vm.validate()
		expect(errors.some((e) => e.includes('Invalid URL'))).toBe(true)
	})

	it('rejects data: URLs', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: 'data:text/html,<h1>x</h1>' }] }],
		})
		const errors = wrapper.vm.validate()
		expect(errors.some((e) => e.includes('Invalid URL'))).toBe(true)
	})

	it('rejects file:// URLs', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: 'file:///etc/passwd' }] }],
		})
		const errors = wrapper.vm.validate()
		expect(errors.some((e) => e.includes('Invalid URL'))).toBe(true)
	})

	it('rejects relative URLs that traverse parent directories', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: '/apps/../etc' }] }],
		})
		const errors = wrapper.vm.validate()
		expect(errors.some((e) => e.includes('Invalid URL'))).toBe(true)
	})

	it('accepts valid HTTPS URLs', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: 'https://example.com/page' }] }],
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('accepts valid HTTP URLs', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: 'http://example.com' }] }],
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('accepts root-relative URLs without traversal', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: 'L', url: '/apps/files/list' }] }],
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('rejects empty link label', () => {
		const wrapper = mountForm({
			sections: [{ title: 'A', links: [{ label: '', url: 'https://example.com' }] }],
		})
		const errors = wrapper.vm.validate()
		expect(errors.some((e) => e.includes('Link label is required'))).toBe(true)
	})

	it('returns no errors when there are no sections (empty config)', () => {
		const wrapper = mountForm({})
		expect(wrapper.vm.validate()).toEqual([])
	})
})
