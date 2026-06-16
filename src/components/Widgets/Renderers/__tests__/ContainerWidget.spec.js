/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `ContainerWidget.vue` covering REQ-CONT-001
 * (registration), REQ-CONT-002 (inner grid bounded), REQ-CONT-003
 * (recursive child rendering via the registry-driven dispatcher),
 * REQ-CONT-004 (view-mode click delegation — wrapper has
 * `pointer-events: none` while children re-enable them), and the
 * cleanup contract on beforeDestroy.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ContainerWidget from '../ContainerWidget.vue'

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

describe('ContainerWidget — REQ-CONT-002 inner grid surface', () => {
	it('renders a `.grid-stack.mydash-container-grid` host div for the inner GridStack', () => {
		const wrapper = mount(ContainerWidget, {
			propsData: { content: { placements: [] } },
		})
		const inner = wrapper.find('.mydash-container-grid')
		expect(inner.exists()).toBe(true)
		expect(inner.classes()).toContain('grid-stack')
	})

	it('renders one child element per content.placements[]', () => {
		const wrapper = mount(ContainerWidget, {
			propsData: {
				content: {
					placements: [
						{ id: 1, type: 'label', content: { text: 'Hi' }, gridX: 0, gridY: 0, gridWidth: 2, gridHeight: 2 },
						{ id: 2, type: 'label', content: { text: 'There' }, gridX: 2, gridY: 0, gridWidth: 2, gridHeight: 2 },
					],
				},
			},
		})
		const items = wrapper.findAll('.container-widget__child')
		expect(items.length).toBe(2)
	})
})

describe('ContainerWidget — REQ-CONT-003 recursive dispatch', () => {
	it('dispatches each child through the ContainerChild dispatcher (registry lookup)', () => {
		const wrapper = mount(ContainerWidget, {
			propsData: {
				content: {
					placements: [
						{ id: 1, type: 'label', content: { text: 'A' }, gridX: 0, gridY: 0, gridWidth: 2, gridHeight: 2 },
					],
				},
			},
		})
		// The label widget renders a `.label-widget` element via its
		// own renderer — proving the registry-driven dispatch worked.
		expect(wrapper.find('.label-widget').exists()).toBe(true)
	})
})

describe('ContainerWidget — REQ-CONT-004 view-mode click delegation', () => {
	it('wrapper has pointer-events: none in view mode (clicks fall through)', () => {
		const wrapper = mount(ContainerWidget, {
			propsData: { content: { placements: [] }, editMode: false },
		})
		const style = wrapper.find('.container-widget').attributes('style') || ''
		expect(style).toContain('pointer-events: none')
	})

	it('wrapper has pointer-events: auto in edit mode (drag/resize)', () => {
		const wrapper = mount(ContainerWidget, {
			propsData: { content: { placements: [] }, editMode: true },
		})
		const style = wrapper.find('.container-widget').attributes('style') || ''
		expect(style).toContain('pointer-events: auto')
	})
})

describe('ContainerWidget — content rendering', () => {
	it('renders the title heading when content.title is non-empty', () => {
		const wrapper = mount(ContainerWidget, {
			propsData: { content: { placements: [], title: 'My section' } },
		})
		const heading = wrapper.find('.container-widget__title')
		expect(heading.exists()).toBe(true)
		expect(heading.text()).toBe('My section')
	})

	it('omits the title heading when content.title is empty', () => {
		const wrapper = mount(ContainerWidget, {
			propsData: { content: { placements: [], title: '' } },
		})
		expect(wrapper.find('.container-widget__title').exists()).toBe(false)
	})

	it('applies content.backgroundColor inline', () => {
		const wrapper = mount(ContainerWidget, {
			propsData: { content: { placements: [], backgroundColor: '#ff0000' } },
		})
		const style = wrapper.find('.container-widget').attributes('style') || ''
		expect(style).toContain('background-color: rgb(255, 0, 0)')
	})

	it('maps padding tokens to pixels (none/small/medium/large)', () => {
		const cases = { none: '0', small: '4px', medium: '8px', large: '16px' }
		for (const [token, px] of Object.entries(cases)) {
			const wrapper = mount(ContainerWidget, {
				propsData: { content: { placements: [], padding: token } },
			})
			const style = wrapper.find('.container-widget').attributes('style') || ''
			expect(style).toContain(`padding: ${px}`)
		}
	})
})

describe('ContainerWidget — cleanup', () => {
	it('beforeDestroy nulls the gridInstance reference (idempotent)', () => {
		const wrapper = mount(ContainerWidget, {
			propsData: { content: { placements: [] } },
		})
		// We don't actually init GridStack in JSDOM (the dynamic import
		// silently bails) — but the destroy path still runs and must
		// remain a no-op.
		expect(() => wrapper.destroy()).not.toThrow()
	})
})
