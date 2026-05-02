/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `ImageWidget.vue` covering REQ-IMG-001..004:
 *   - object-fit applied from `fit` (cover, contain, fill, none) and the
 *     prop validator rejecting unknown values + falling back to `cover`.
 *   - Empty-URL placeholder rendering (camera icon + `No image`).
 *   - Click-through: non-empty `link` → `window.open(...)`; empty `link`
 *     → no-op + default cursor.
 *   - Broken-image fallback: `<img>` `error` event swaps in the
 *     placeholder + `Image failed to load`.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ImageWidget from '../ImageWidget.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('ImageWidget', () => {
	for (const fit of ['cover', 'contain', 'fill', 'none']) {
		it(`REQ-IMG-001: object-fit === ${fit} when content.fit is ${fit}`, () => {
			const wrapper = mount(ImageWidget, {
				propsData: { content: { url: '/x.png', fit } },
			})
			const img = wrapper.find('img.image-widget__img')
			expect(img.exists()).toBe(true)
			const style = img.attributes('style') || ''
			expect(style).toContain(`object-fit: ${fit}`)
			expect(style).toContain('width: 100%')
			expect(style).toContain('height: 100%')
		})
	}

	it('REQ-IMG-001: cell wrapper sets overflow: hidden', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '/x.png' } },
		})
		const wrap = wrapper.find('.image-widget')
		const style = wrap.attributes('style') || ''
		expect(style).toContain('overflow: hidden')
	})

	it('REQ-IMG-001: default fit is cover when no fit field is provided', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '/x.png' } },
		})
		const img = wrapper.find('img.image-widget__img')
		const style = img.attributes('style') || ''
		expect(style).toContain('object-fit: cover')
	})

	it('REQ-IMG-001: prop validator rejects unknown fit value', () => {
		const validator = ImageWidget.props.fit.validator
		expect(validator('cover')).toBe(true)
		expect(validator('contain')).toBe(true)
		expect(validator('fill')).toBe(true)
		expect(validator('none')).toBe(true)
		expect(validator('stretch')).toBe(false)
		expect(validator('garbage')).toBe(false)
		// undefined / null pass the validator (computed will fall back).
		expect(validator(undefined)).toBe(true)
		expect(validator(null)).toBe(true)
	})

	it('REQ-IMG-001: unknown fit value in content.fit falls back to cover', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '/x.png', fit: 'stretch' } },
		})
		const img = wrapper.find('img.image-widget__img')
		const style = img.attributes('style') || ''
		expect(style).toContain('object-fit: cover')
	})

	it('REQ-IMG-002: empty url shows placeholder, no <img>', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '' } },
		})
		expect(wrapper.find('img.image-widget__img').exists()).toBe(false)
		expect(wrapper.find('.image-widget__placeholder').exists()).toBe(true)
		expect(wrapper.text()).toContain('No image')
	})

	it('REQ-IMG-002: null url shows placeholder', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: null } },
		})
		expect(wrapper.find('img.image-widget__img').exists()).toBe(false)
		expect(wrapper.find('.image-widget__placeholder').exists()).toBe(true)
	})

	it('REQ-IMG-002: placeholder uses var(--color-text-maxcontrast)', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '' } },
		})
		const placeholder = wrapper.find('.image-widget__placeholder')
		const style = placeholder.attributes('style') || ''
		expect(style).toContain('color: var(--color-text-maxcontrast)')
	})

	it('REQ-IMG-003: cursor is pointer when link is non-empty', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '/x.png', link: 'https://example.com' } },
		})
		const wrap = wrapper.find('.image-widget')
		const style = wrap.attributes('style') || ''
		expect(style).toContain('cursor: pointer')
	})

	it('REQ-IMG-003: cursor is default when link is empty', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '/x.png', link: '' } },
		})
		const wrap = wrapper.find('.image-widget')
		const style = wrap.attributes('style') || ''
		expect(style).toContain('cursor: default')
		expect(style).not.toContain('cursor: pointer')
	})

	it('REQ-IMG-003: click opens link in new tab with noopener,noreferrer', async () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		try {
			const wrapper = mount(ImageWidget, {
				propsData: { content: { url: '/x.png', link: 'https://example.com' } },
			})
			await wrapper.find('.image-widget').trigger('click')
			expect(openSpy).toHaveBeenCalledWith(
				'https://example.com',
				'_blank',
				'noopener,noreferrer',
			)
		} finally {
			openSpy.mockRestore()
		}
	})

	it('REQ-IMG-003: click is a no-op when link is empty', async () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		try {
			const wrapper = mount(ImageWidget, {
				propsData: { content: { url: '/x.png', link: '' } },
			})
			await wrapper.find('.image-widget').trigger('click')
			expect(openSpy).not.toHaveBeenCalled()
		} finally {
			openSpy.mockRestore()
		}
	})

	it('REQ-IMG-004: <img> error event swaps to placeholder + Image failed to load', async () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '/missing.png' } },
		})
		expect(wrapper.find('img.image-widget__img').exists()).toBe(true)
		await wrapper.find('img.image-widget__img').trigger('error')
		expect(wrapper.find('img.image-widget__img').exists()).toBe(false)
		expect(wrapper.find('.image-widget__placeholder').exists()).toBe(true)
		expect(wrapper.text()).toContain('Image failed to load')
	})

	it('REQ-IMG-004: error handler swallows the event (no rethrow)', async () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '/missing.png' } },
		})
		// If the handler threw, trigger() would surface the error.
		await expect(
			wrapper.find('img.image-widget__img').trigger('error'),
		).resolves.toBeUndefined()
	})

	it('REQ-IMG-004: changing url re-arms <img> after a previous error', async () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '/missing.png' } },
		})
		await wrapper.find('img.image-widget__img').trigger('error')
		expect(wrapper.find('img.image-widget__img').exists()).toBe(false)
		await wrapper.setProps({ content: { url: '/working.png' } })
		expect(wrapper.find('img.image-widget__img').exists()).toBe(true)
	})
})
