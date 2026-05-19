/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `DividerWidget.vue` covering REQ-DIV-003
 * (line render + accessibility), REQ-DIV-004 (whitespace render +
 * accessibility), REQ-DIV-005 (heading-break render + accessibility),
 * REQ-DIV-007 (theme-aware default colour, explicit override), and
 * REQ-DIV-009 (no API calls — implicitly proven by mounting the
 * component without any axios/fetch stubs).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import DividerWidget from '../DividerWidget.vue'

beforeEach(() => {
	globalThis.t = (_app, key, vars) => {
		if (vars && typeof key === 'string') {
			return key.replace(/\{(\w+)\}/g, (_, name) =>
				Object.prototype.hasOwnProperty.call(vars, name) ? vars[name] : `{${name}}`,
			)
		}
		return key
	}
})

describe('DividerWidget', () => {
	it('REQ-DIV-003: renders a horizontal line by default with theme border colour', () => {
		const wrapper = mount(DividerWidget, { propsData: { content: { style: 'line' } } })
		const line = wrapper.find('.divider-widget__line')
		expect(line.exists()).toBe(true)
		const style = line.attributes('style') || ''
		expect(style).toContain('border-bottom-width: 1px')
		expect(style).toContain('border-bottom-style: solid')
		expect(style).toContain('border-bottom-color: var(--color-border)')
		expect(line.attributes('role')).toBe('separator')
	})

	it('REQ-DIV-003 / REQ-DIV-007: explicit lineColor overrides the theme variable', () => {
		const wrapper = mount(DividerWidget, {
			propsData: {
				content: { style: 'line', lineColor: '#ff0000', lineThickness: 3, lineStyle: 'solid' },
			},
		})
		const line = wrapper.find('.divider-widget__line')
		const style = line.attributes('style') || ''
		expect(style).toContain('border-bottom-width: 3px')
		expect(style).toContain('border-bottom-color: rgb(255, 0, 0)')
		expect(style).not.toContain('var(--color-border)')
	})

	it('REQ-DIV-003: dashed and dotted line styles are honoured', () => {
		const dashed = mount(DividerWidget, {
			propsData: { content: { style: 'line', lineStyle: 'dashed', lineThickness: 2 } },
		})
		expect(dashed.find('.divider-widget__line').attributes('style')).toContain('border-bottom-style: dashed')

		const dotted = mount(DividerWidget, {
			propsData: { content: { style: 'line', lineStyle: 'dotted', lineThickness: 2 } },
		})
		expect(dotted.find('.divider-widget__line').attributes('style')).toContain('border-bottom-style: dotted')
	})

	it('REQ-DIV-003: clamps lineThickness into the 1..8 range', () => {
		const tooBig = mount(DividerWidget, {
			propsData: { content: { style: 'line', lineThickness: 999 } },
		})
		expect(tooBig.find('.divider-widget__line').attributes('style')).toContain('border-bottom-width: 8px')

		const tooSmall = mount(DividerWidget, {
			propsData: { content: { style: 'line', lineThickness: 0 } },
		})
		expect(tooSmall.find('.divider-widget__line').attributes('style')).toContain('border-bottom-width: 1px')
	})

	it('REQ-DIV-004: renders a transparent whitespace block with default medium height', () => {
		const wrapper = mount(DividerWidget, {
			propsData: { content: { style: 'whitespace' } },
		})
		const ws = wrapper.find('.divider-widget__whitespace')
		expect(ws.exists()).toBe(true)
		const style = ws.attributes('style') || ''
		expect(style).toContain('height: 32px')
		expect(style).toContain('background-color: transparent')
		expect(ws.attributes('role')).toBe('separator')
	})

	it('REQ-DIV-004: maps whitespaceSize tokens to 16/32/64/128 px', () => {
		const cases = { small: '16px', medium: '32px', large: '64px', xlarge: '128px' }
		for (const [size, px] of Object.entries(cases)) {
			const wrapper = mount(DividerWidget, {
				propsData: { content: { style: 'whitespace', whitespaceSize: size } },
			})
			const style = wrapper.find('.divider-widget__whitespace').attributes('style') || ''
			expect(style).toContain(`height: ${px}`)
		}
	})

	it('REQ-DIV-005: renders heading-break with semantic h3 between two lines', () => {
		const wrapper = mount(DividerWidget, {
			propsData: { content: { style: 'heading-break', headingText: 'Key Section' } },
		})
		const wrap = wrapper.find('.divider-widget__heading-break')
		expect(wrap.exists()).toBe(true)
		expect(wrap.attributes('role')).toBe('separator')
		expect(wrap.attributes('aria-label')).toBe('Key Section divider')
		const heading = wrapper.find('h3.divider-widget__heading-text')
		expect(heading.exists()).toBe(true)
		expect(heading.text()).toBe('Key Section')
		const lines = wrapper.findAll('hr.divider-widget__heading-line')
		expect(lines.length).toBe(2)
	})

	it('REQ-DIV-005 / REQ-DIV-007: heading-break lines respect explicit lineColor override', () => {
		const wrapper = mount(DividerWidget, {
			propsData: {
				content: { style: 'heading-break', headingText: 'Important', lineColor: '#00ff00' },
			},
		})
		const lines = wrapper.findAll('hr.divider-widget__heading-line')
		for (let i = 0; i < lines.length; i++) {
			const style = lines.at(i).attributes('style') || ''
			expect(style).toContain('border-top-color: rgb(0, 255, 0)')
		}
	})

	it('REQ-DIV-005: heading-break falls back to translated "Section" when text is empty', () => {
		const wrapper = mount(DividerWidget, {
			propsData: { content: { style: 'heading-break', headingText: '' } },
		})
		expect(wrapper.find('h3.divider-widget__heading-text').text()).toBe('Section')
	})

	it('unknown style falls back to line', () => {
		const wrapper = mount(DividerWidget, {
			propsData: { content: { style: 'totally-fake' } },
		})
		expect(wrapper.find('.divider-widget__line').exists()).toBe(true)
	})
})
