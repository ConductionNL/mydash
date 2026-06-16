/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit test for `IconRenderer.vue` covering REQ-ICON-007 — the
 * shared dual-mode renderer that branches between `<img>` (custom URL
 * icons) and `<component :is>` (built-in MDI registry icons) so
 * consumers don't have to duplicate the if/else.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import IconRenderer from '../IconRenderer.vue'

describe('IconRenderer (REQ-ICON-007)', () => {
	it('renders a built-in MDI icon (svg) for a registry name', () => {
		const wrapper = mount(IconRenderer, {
			propsData: { name: 'Star' },
		})
		expect(wrapper.find('svg').exists()).toBe(true)
		expect(wrapper.find('img').exists()).toBe(false)
	})

	it('renders an <img> for a custom URL input', () => {
		const wrapper = mount(IconRenderer, {
			propsData: { name: '/apps/mydash/resource/abc.png' },
		})
		const img = wrapper.find('img')
		expect(img.exists()).toBe(true)
		expect(img.attributes('src')).toBe('/apps/mydash/resource/abc.png')
		expect(wrapper.find('svg').exists()).toBe(false)
	})

	it('falls back to the default MDI icon for null name', () => {
		const wrapper = mount(IconRenderer, {
			propsData: { name: null },
		})
		expect(wrapper.find('svg').exists()).toBe(true)
		expect(wrapper.find('img').exists()).toBe(false)
	})

	it('propagates the alt prop to the rendered <img> for URL inputs', () => {
		const wrapper = mount(IconRenderer, {
			propsData: {
				name: '/apps/mydash/resource/abc.png',
				alt: 'Marketing',
			},
		})
		expect(wrapper.find('img').attributes('alt')).toBe('Marketing')
	})

	it('falls back to a non-empty default alt when none is supplied', () => {
		const wrapper = mount(IconRenderer, {
			propsData: { name: '/apps/mydash/resource/abc.png' },
		})
		const alt = wrapper.find('img').attributes('alt')
		expect(typeof alt).toBe('string')
		expect(alt.length).toBeGreaterThan(0)
	})

	it('applies the size prop as width/height on <img> for URLs', () => {
		const wrapper = mount(IconRenderer, {
			propsData: {
				name: '/apps/mydash/resource/abc.png',
				size: 32,
			},
		})
		const img = wrapper.find('img')
		expect(img.attributes('width')).toBe('32')
		expect(img.attributes('height')).toBe('32')
	})
})
