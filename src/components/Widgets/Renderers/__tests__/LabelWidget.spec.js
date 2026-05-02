/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `LabelWidget.vue` covering REQ-LBL-001..004 and
 * REQ-LBL-006. Verifies plain-text-only rendering (no HTML injection),
 * default style application, long-word wrapping safety net, and the
 * empty-content placeholder.
 *
 * The tests assume Vitest + @vue/test-utils@1 (Vue 2 compatibility
 * release). When Vitest is wired up at the repo level (currently a cohort-
 * wide infrastructure follow-up), this file runs with `npm test`.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import LabelWidget from '../LabelWidget.vue'

// Stub the global `t()` translation helper that Nextcloud injects at
// runtime. Tests need a deterministic identity-style return so assertions
// can compare against the bare key.
beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('LabelWidget', () => {
	it('REQ-LBL-001: renders HTML in text as literal characters (no <b> in DOM)', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: 'Sales <b>Q4</b>' } },
		})
		expect(wrapper.text()).toBe('Sales <b>Q4</b>')
		expect(wrapper.find('b').exists()).toBe(false)
	})

	it('REQ-LBL-001: renders <script> in text as literal characters', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: '<script>alert(1)</script>' } },
		})
		expect(wrapper.text()).toBe('<script>alert(1)</script>')
		expect(wrapper.find('script').exists()).toBe(false)
	})

	it('REQ-LBL-002: applies default styles when only text is provided', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: 'Hi' } },
		})
		const span = wrapper.find('.label-widget__text')
		const style = span.attributes('style') || ''
		expect(style).toContain('font-size: 16px')
		expect(style).toContain('font-weight: bold')
		expect(style).toContain('text-align: center')
		expect(style).toContain('color: var(--color-main-text)')
	})

	it('REQ-LBL-002: overrides default values while keeping untouched defaults', () => {
		const wrapper = mount(LabelWidget, {
			propsData: {
				content: {
					text: 'X',
					fontSize: '32px',
					fontWeight: 'normal',
					textAlign: 'left',
				},
			},
		})
		const span = wrapper.find('.label-widget__text')
		const style = span.attributes('style') || ''
		expect(style).toContain('font-size: 32px')
		expect(style).toContain('font-weight: normal')
		expect(style).toContain('text-align: left')
		// untouched defaults
		expect(style).toContain('color: var(--color-main-text)')
		const wrapperStyle = wrapper.find('.label-widget').attributes('style') || ''
		expect(wrapperStyle).toContain('background-color: transparent')
	})

	it('REQ-LBL-003: span carries overflow-wrap break-word so long words wrap', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: 'Pneumonoultramicroscopicsilicovolcanoconiosis' } },
		})
		const span = wrapper.find('.label-widget__text')
		const style = span.attributes('style') || ''
		expect(style).toContain('overflow-wrap: break-word')
	})

	it('REQ-LBL-004: empty text shows translated `Label` fallback', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: '' } },
		})
		expect(wrapper.text()).toBe('Label')
	})

	it('REQ-LBL-004: whitespace-only text shows translated `Label` fallback', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: '   ' } },
		})
		expect(wrapper.text()).toBe('Label')
	})

	it('REQ-LBL-006: wrapper fills cell with padding and centred flexbox', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: 'Centred' } },
		})
		const w = wrapper.find('.label-widget')
		const style = w.attributes('style') || ''
		expect(style).toContain('width: 100%')
		expect(style).toContain('height: 100%')
		expect(style).toContain('padding: 12px')
		expect(style).toContain('display: flex')
		expect(style).toContain('align-items: center')
		expect(style).toContain('justify-content: center')
	})
})
