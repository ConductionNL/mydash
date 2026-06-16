/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `LinksWidget.vue` covering REQ-LNKS-003 (empty
 * sections hidden), REQ-LNKS-004 (icon resolution), REQ-LNKS-005 (three
 * layout modes), REQ-LNKS-006 (column count + clamp), REQ-LNKS-009 (empty
 * state), and REQ-LNKS-010 (rel attributes / edit-mode suppression).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import LinksWidget from '../LinksWidget.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

const IconRendererStub = {
	name: 'IconRenderer',
	props: ['name', 'size'],
	template: '<span class="icon-stub" :data-name="name" :data-size="size" />',
}

function mountWidget(content = {}, propOverrides = {}) {
	return mount(LinksWidget, {
		propsData: {
			content,
			...propOverrides,
		},
		stubs: {
			IconRenderer: IconRendererStub,
		},
	})
}

describe('LinksWidget — empty state (REQ-LNKS-009)', () => {
	it('renders the empty-state message when sections is missing', () => {
		const wrapper = mountWidget({})
		expect(wrapper.find('.links-widget__empty').exists()).toBe(true)
		expect(wrapper.text()).toContain('No links yet')
	})

	it('renders the empty-state message when all sections have zero links', () => {
		const wrapper = mountWidget({
			sections: [{ title: 'Tools', links: [] }],
		})
		expect(wrapper.find('.links-widget__empty').exists()).toBe(true)
		expect(wrapper.find('.links-widget__section').exists()).toBe(false)
	})
})

describe('LinksWidget — empty sections hidden (REQ-LNKS-003)', () => {
	it('renders only sections that have at least one link', () => {
		const wrapper = mountWidget({
			sections: [
				{ title: 'Empty', links: [] },
				{ title: 'Filled', links: [{ label: 'Docs', url: 'https://example.com' }] },
			],
		})
		const sections = wrapper.findAll('.links-widget__section')
		expect(sections).toHaveLength(1)
		expect(sections.at(0).text()).toContain('Filled')
	})
})

describe('LinksWidget — three layout modes (REQ-LNKS-005)', () => {
	const sections = [
		{
			title: 'Tools',
			links: [{ label: 'Docs', url: 'https://example.com', description: 'Read the docs', icon: '' }],
		},
	]

	it('card layout shows label + description + card class', () => {
		const wrapper = mountWidget({ sections, linkLayout: 'card' })
		expect(wrapper.classes()).toContain('links-widget--card')
		expect(wrapper.find('.links-widget__link--card').exists()).toBe(true)
		expect(wrapper.find('.links-widget__description').exists()).toBe(true)
		expect(wrapper.find('.links-widget__description').text()).toBe('Read the docs')
	})

	it('inline layout shows label but suppresses description', () => {
		const wrapper = mountWidget({ sections, linkLayout: 'inline' })
		expect(wrapper.classes()).toContain('links-widget--inline')
		expect(wrapper.find('.links-widget__label').exists()).toBe(true)
		expect(wrapper.find('.links-widget__description').exists()).toBe(false)
	})

	it('icon-only layout suppresses label and description, sets title attr', () => {
		const wrapper = mountWidget({ sections, linkLayout: 'icon-only' })
		expect(wrapper.classes()).toContain('links-widget--icon-only')
		expect(wrapper.find('.links-widget__label').exists()).toBe(false)
		expect(wrapper.find('.links-widget__description').exists()).toBe(false)
		expect(wrapper.find('a').attributes('title')).toBe('Docs')
	})

	it('card layout hides description when showLinkDescriptions is false', () => {
		const wrapper = mountWidget({
			sections,
			linkLayout: 'card',
			showLinkDescriptions: false,
		})
		expect(wrapper.find('.links-widget__description').exists()).toBe(false)
	})
})

describe('LinksWidget — icon resolution (REQ-LNKS-004)', () => {
	it('renders <img> when icon is a URL', () => {
		const wrapper = mountWidget({
			sections: [{
				title: 'X',
				links: [{ label: 'L', url: 'https://e.com', icon: 'https://cdn.example/logo.png' }],
			}],
		})
		const img = wrapper.find('.links-widget__icon-wrap img')
		expect(img.exists()).toBe(true)
		expect(img.attributes('src')).toBe('https://cdn.example/logo.png')
	})

	it('renders IconRenderer (registry name) when icon is a bare word', () => {
		const wrapper = mountWidget({
			sections: [{
				title: 'X',
				links: [{ label: 'L', url: 'https://e.com', icon: 'Star' }],
			}],
		})
		const stub = wrapper.find('.icon-stub')
		expect(stub.exists()).toBe(true)
		expect(stub.attributes('data-name')).toBe('Star')
	})

	it('renders IconRenderer (default fallback) when icon is empty', () => {
		const wrapper = mountWidget({
			sections: [{
				title: 'X',
				links: [{ label: 'L', url: 'https://e.com', icon: '' }],
			}],
		})
		const stub = wrapper.find('.icon-stub')
		expect(stub.exists()).toBe(true)
		expect(stub.attributes('data-name')).toBe('')
	})

	it('icon size maps to pixel dimensions', () => {
		const wrapper = mountWidget({
			sections: [{
				title: 'X',
				links: [{ label: 'L', url: 'https://e.com', icon: 'Star' }],
			}],
			iconSize: 'large',
		})
		expect(wrapper.find('.icon-stub').attributes('data-size')).toBe('64')
	})
})

describe('LinksWidget — column count (REQ-LNKS-006)', () => {
	it('exposes the column count as a CSS variable on the root', () => {
		const wrapper = mountWidget({ columns: 4 })
		const style = wrapper.attributes('style') || ''
		expect(style).toContain('--links-widget-cols: 4')
	})

	it('clamps columns above 6 down to 6', () => {
		const wrapper = mountWidget({ columns: 99 })
		expect(wrapper.attributes('style')).toContain('--links-widget-cols: 6')
	})

	it('clamps columns below 1 up to 1', () => {
		const wrapper = mountWidget({ columns: 0 })
		expect(wrapper.attributes('style')).toContain('--links-widget-cols: 1')
	})

	it('defaults to 3 columns when value is missing or invalid', () => {
		const wrapper = mountWidget({})
		expect(wrapper.attributes('style')).toContain('--links-widget-cols: 3')
	})
})

describe('LinksWidget — section titles (REQ-LNKS-002)', () => {
	const sections = [{ title: 'Tools', links: [{ label: 'L', url: 'https://e.com' }] }]

	it('renders the section title heading by default', () => {
		const wrapper = mountWidget({ sections })
		expect(wrapper.find('.links-widget__section-title').exists()).toBe(true)
		expect(wrapper.find('.links-widget__section-title').text()).toBe('Tools')
	})

	it('hides the section title heading when showSectionTitles is false', () => {
		const wrapper = mountWidget({ sections, showSectionTitles: false })
		expect(wrapper.find('.links-widget__section-title').exists()).toBe(false)
	})
})

describe('LinksWidget — rel + target attributes (REQ-LNKS-010)', () => {
	it('external URLs receive rel="noopener noreferrer" with target=_blank', () => {
		const wrapper = mountWidget({
			sections: [{ title: 'X', links: [{ label: 'L', url: 'https://example.com' }] }],
		})
		const a = wrapper.find('a.links-widget__link')
		expect(a.attributes('target')).toBe('_blank')
		expect(a.attributes('rel')).toBe('noopener noreferrer')
	})

	it('relative URLs omit rel even when openInNewTab is true', () => {
		const wrapper = mountWidget({
			sections: [{ title: 'X', links: [{ label: 'L', url: '/apps/files' }] }],
		})
		const a = wrapper.find('a.links-widget__link')
		expect(a.attributes('target')).toBe('_blank')
		expect(a.attributes('rel')).toBeUndefined()
	})

	it('omits rel when openInNewTab is false', () => {
		const wrapper = mountWidget({
			sections: [{ title: 'X', links: [{ label: 'L', url: 'https://example.com' }] }],
			openInNewTab: false,
		})
		const a = wrapper.find('a.links-widget__link')
		expect(a.attributes('target')).toBe('_self')
		expect(a.attributes('rel')).toBeUndefined()
	})

	it('suppresses navigation in edit mode (preventDefault)', () => {
		const wrapper = mountWidget(
			{ sections: [{ title: 'X', links: [{ label: 'L', url: 'https://example.com' }] }] },
			{ isAdmin: true, canEdit: true },
		)
		const a = wrapper.find('a.links-widget__link')
		const event = { preventDefault: () => { event.prevented = true } }
		wrapper.vm.onLinkClick(event, 'https://example.com')
		expect(event.prevented).toBe(true)
		// Verify edit-mode flag exposed for assertion completeness.
		expect(wrapper.vm.isInEditMode).toBe(true)
		// Anchor still renders so layout doesn't reflow on edit.
		expect(a.exists()).toBe(true)
	})

	it('sanitises javascript: URLs to # so the anchor is inert', () => {
		const wrapper = mountWidget({
			// eslint-disable-next-line no-script-url
			sections: [{ title: 'X', links: [{ label: 'L', url: 'javascript:alert(1)' }] }],
		})
		const a = wrapper.find('a.links-widget__link')
		expect(a.attributes('href')).toBe('#')
	})
})
