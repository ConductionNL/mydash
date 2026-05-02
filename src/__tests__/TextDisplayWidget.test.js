/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'

import TextDisplayWidget from '../components/Widgets/Renderers/TextDisplayWidget.vue'
import TextDisplayForm from '../components/Widgets/Forms/TextDisplayForm.vue'

beforeAll(() => {
	// Stub the Nextcloud `t` global with an identity function so component
	// renders during tests without depending on @nextcloud/l10n.
	if (typeof globalThis.t !== 'function') {
		globalThis.t = (_app, key) => key
	}
})

describe('TextDisplayWidget — sanitisation (REQ-TXT-001)', () => {
	it('strips <script> tags from sanitised output', () => {
		const wrapper = mount(TextDisplayWidget, {
			propsData: { content: { text: 'Hello <script>alert(1)</script> world' } },
		})
		const html = wrapper.html()
		expect(html).not.toContain('<script')
		expect(html).not.toContain('alert(1)')
		expect(wrapper.text()).toContain('Hello')
		expect(wrapper.text()).toContain('world')
	})

	it('strips on* event-handler attributes from sanitised output', () => {
		const wrapper = mount(TextDisplayWidget, {
			propsData: { content: { text: '<a href="https://example.com" onclick="alert(1)">link</a>' } },
		})
		const anchor = wrapper.find('a')
		expect(anchor.exists()).toBe(true)
		expect(anchor.attributes('onclick')).toBeUndefined()
		expect(anchor.attributes('href')).toBe('https://example.com')
	})

	it('preserves safe formatting tags <b> and <a href>', () => {
		const wrapper = mount(TextDisplayWidget, {
			propsData: { content: { text: 'Hello <b>world</b> <a href="https://example.com">x</a>' } },
		})
		const html = wrapper.html()
		expect(html).toContain('<b>world</b>')
		expect(wrapper.find('a').attributes('href')).toBe('https://example.com')
	})

	it('strips javascript: URLs', () => {
		const wrapper = mount(TextDisplayWidget, {
			propsData: { content: { text: '<a href="javascript:alert(1)">x</a>' } },
		})
		const anchor = wrapper.find('a')
		// DOMPurify strips the href entirely when it is javascript: — anchor
		// itself remains but loses the dangerous attribute.
		const href = anchor.exists() ? anchor.attributes('href') : undefined
		expect(href === undefined || !href.startsWith('javascript:')).toBe(true)
	})
})

describe('TextDisplayWidget — empty placeholder (REQ-TXT-003)', () => {
	it('shows the localised placeholder when text is empty', () => {
		const wrapper = mount(TextDisplayWidget, {
			propsData: { content: { text: '' } },
		})
		expect(wrapper.text()).toContain('No text content')
		expect(wrapper.find('.text-display-widget__placeholder').exists()).toBe(true)
		expect(wrapper.find('.text-display-widget__content').exists()).toBe(false)
	})

	it('treats whitespace-only content as empty', () => {
		const wrapper = mount(TextDisplayWidget, {
			propsData: { content: { text: '   \n  ' } },
		})
		expect(wrapper.find('.text-display-widget__placeholder').exists()).toBe(true)
	})
})

describe('TextDisplayWidget — inline style fallbacks (REQ-TXT-002)', () => {
	it('applies provided values verbatim and resolves missing fields to theme defaults', () => {
		const wrapper = mount(TextDisplayWidget, {
			propsData: { content: { text: 'X', fontSize: '24px', color: '#ff0000' } },
		})
		const style = wrapper.element.getAttribute('style') || ''
		expect(style).toContain('font-size: 24px')
		expect(style).toContain('color: rgb(255, 0, 0)')
		expect(style).toContain('background-color: transparent')
		expect(style).toContain('text-align: left')
	})

	it('falls back to theme variables when colour fields are empty', () => {
		const wrapper = mount(TextDisplayWidget, {
			propsData: { content: { text: 'X' } },
		})
		const style = wrapper.element.getAttribute('style') || ''
		expect(style).toContain('font-size: 14px')
		expect(style).toContain('var(--color-main-text)')
		expect(style).toContain('background-color: transparent')
	})
})

describe('TextDisplayForm — validation (REQ-TXT-004)', () => {
	it('validate() returns an error array when text is empty', () => {
		const wrapper = mount(TextDisplayForm, {
			propsData: { editingWidget: { content: { text: '' } } },
		})
		const errors = wrapper.vm.validate()
		expect(errors).toEqual(['Text is required'])
	})

	it('validate() returns [] when text is populated', () => {
		const wrapper = mount(TextDisplayForm, {
			propsData: { editingWidget: { content: { text: 'Hello' } } },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('pre-fills all five fields from editingWidget.content on mount', () => {
		const wrapper = mount(TextDisplayForm, {
			propsData: {
				editingWidget: {
					content: {
						text: 'Hi',
						fontSize: '20px',
						color: '#00ff00',
						backgroundColor: '#000000',
						textAlign: 'center',
					},
				},
			},
		})
		expect(wrapper.vm.form.text).toBe('Hi')
		expect(wrapper.vm.form.fontSize).toBe('20px')
		expect(wrapper.vm.form.color).toBe('#00ff00')
		expect(wrapper.vm.form.backgroundColor).toBe('#000000')
		expect(wrapper.vm.form.textAlign).toBe('center')
	})

	it('emits update:content when the user types in the textarea', async () => {
		const wrapper = mount(TextDisplayForm, {
			propsData: { editingWidget: { content: { text: '' } } },
		})
		const textarea = wrapper.find('textarea')
		await textarea.setValue('Hello')
		const events = wrapper.emitted('update:content')
		expect(events).toBeTruthy()
		expect(events[events.length - 1][0].text).toBe('Hello')
		expect(wrapper.vm.validate()).toEqual([])
	})
})
