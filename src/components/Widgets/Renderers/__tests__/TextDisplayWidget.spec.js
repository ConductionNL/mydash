/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `TextDisplayWidget.vue` covering REQ-TXT-001..005.
 * Verifies DOMPurify-backed sanitisation (script/handler/javascript-URL
 * stripped while safe formatting preserved), default-style application with
 * theme-aware fallbacks, the empty-content placeholder, and the wrapper-fills-
 * cell layout contract.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import TextDisplayWidget from '../TextDisplayWidget.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('TextDisplayWidget', () => {
	describe('REQ-TXT-001: HTML sanitisation via DOMPurify', () => {
		it('preserves common formatting tags (<b>, <i>, <a>, <br>, <p>, <ul>, <li>)', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						text: 'Hello <b>world</b> <i>x</i> <a href="https://example.com">link</a><br><p>p</p><ul><li>li</li></ul>',
					},
				},
			})
			const html = wrapper.find('.text-display-widget__content').html()
			expect(html).toContain('<b>world</b>')
			expect(html).toContain('<i>x</i>')
			expect(html).toContain('<a href="https://example.com">link</a>')
			expect(html).toContain('<br>')
			expect(html).toContain('<p>p</p>')
			expect(html).toContain('<ul>')
			expect(html).toContain('<li>li</li>')
		})

		it('strips <script> tags entirely', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'Click <script>alert(1)</script> me' } },
			})
			expect(wrapper.find('script').exists()).toBe(false)
			expect(wrapper.find('.text-display-widget__content').html()).not.toContain('<script')
		})

		it('strips on* event handler attributes', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '<a href="x" onclick="alert(1)">x</a>' } },
			})
			const a = wrapper.find('.text-display-widget__content a')
			expect(a.exists()).toBe(true)
			expect(a.attributes('onclick')).toBeUndefined()
			// href should be preserved
			expect(a.attributes('href')).toBe('x')
		})

		it('strips javascript: URLs from href', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '<a href="javascript:alert(1)">x</a>' } },
			})
			const a = wrapper.find('.text-display-widget__content a')
			// DOMPurify removes the offending href entirely; the <a> may or
			// may not survive — the contract only requires no javascript: href.
			if (a.exists()) {
				const href = a.attributes('href') || ''
				expect(href.toLowerCase().startsWith('javascript:')).toBe(false)
			}
		})

		it('strips <style> and <link> tags so dashboards layout is not hijacked', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '<style>body{display:none}</style><link rel="stylesheet" href="x">hello' } },
			})
			const html = wrapper.find('.text-display-widget__content').html()
			expect(html).not.toContain('<style')
			expect(html).not.toContain('<link')
			expect(wrapper.text()).toContain('hello')
		})
	})

	describe('REQ-TXT-002: style application with theme-aware fallbacks', () => {
		it('applies provided custom values', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						text: 'X',
						fontSize: '24px',
						color: '#ff0000',
					},
				},
			})
			const inner = wrapper.find('.text-display-widget__content')
			const innerStyle = inner.attributes('style') || ''
			expect(innerStyle).toContain('font-size: 24px')
			expect(innerStyle).toContain('color: rgb(255, 0, 0)')
			expect(innerStyle).toContain('text-align: left')
			const outer = wrapper.find('.text-display-widget')
			const outerStyle = outer.attributes('style') || ''
			expect(outerStyle).toContain('background-color: transparent')
		})

		it('falls back to theme variable for color when empty', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'X', color: '' } },
			})
			const innerStyle = wrapper.find('.text-display-widget__content').attributes('style') || ''
			expect(innerStyle).toContain('color: var(--color-main-text)')
		})

		it('accepts free-form font-size like 1.2em verbatim', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'X', fontSize: '1.2em' } },
			})
			const innerStyle = wrapper.find('.text-display-widget__content').attributes('style') || ''
			expect(innerStyle).toContain('font-size: 1.2em')
		})

		it('falls back to all defaults when only text is provided', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'X' } },
			})
			const innerStyle = wrapper.find('.text-display-widget__content').attributes('style') || ''
			expect(innerStyle).toContain('font-size: 14px')
			expect(innerStyle).toContain('text-align: left')
			expect(innerStyle).toContain('color: var(--color-main-text)')
			const outerStyle = wrapper.find('.text-display-widget').attributes('style') || ''
			expect(outerStyle).toContain('background-color: transparent')
		})
	})

	describe('REQ-TXT-003: empty-content placeholder', () => {
		it('shows translated `No text content` placeholder when text is empty', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '' } },
			})
			expect(wrapper.find('.text-display-widget__content').exists()).toBe(false)
			const placeholder = wrapper.find('.text-display-widget__placeholder')
			expect(placeholder.exists()).toBe(true)
			expect(placeholder.text()).toBe('No text content')
		})

		it('treats whitespace-only text as empty', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '   \n  ' } },
			})
			const placeholder = wrapper.find('.text-display-widget__placeholder')
			expect(placeholder.exists()).toBe(true)
			expect(placeholder.text()).toBe('No text content')
		})

		it('placeholder is italic and uses var(--color-text-maxcontrast)', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '' } },
			})
			const style = wrapper.find('.text-display-widget__placeholder').attributes('style') || ''
			expect(style).toContain('font-style: italic')
			expect(style).toContain('color: var(--color-text-maxcontrast)')
		})
	})

	describe('REQ-TXT-005: layout fills cell with padded scrollable content', () => {
		it('wrapper occupies full cell with padding 16px and overflow auto', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'X' } },
			})
			const style = wrapper.find('.text-display-widget').attributes('style') || ''
			expect(style).toContain('width: 100%')
			expect(style).toContain('height: 100%')
			expect(style).toContain('padding: 16px')
			expect(style).toContain('overflow: auto')
			expect(style).toContain('display: flex')
			expect(style).toContain('align-items: center')
			expect(style).toContain('justify-content: center')
		})
	})
})
