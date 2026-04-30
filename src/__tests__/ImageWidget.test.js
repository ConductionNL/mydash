/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, it, expect, beforeAll, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import ImageWidget from '../components/Widgets/Renderers/ImageWidget.vue'
import ImageForm from '../components/Widgets/Forms/ImageForm.vue'

beforeAll(() => {
	// Stub the Nextcloud `t` global with an identity function so components
	// render during tests without depending on @nextcloud/l10n.
	if (typeof globalThis.t !== 'function') {
		globalThis.t = (_app, key) => key
	}
})

describe('ImageWidget — object-fit binding (REQ-IMG-001)', () => {
	it('applies fit prop value to inline object-fit style', () => {
		const wrapper = mount(ImageWidget, {
			propsData: {
				content: { url: 'https://example.com/test.png', fit: 'contain' },
				url: 'https://example.com/test.png',
				fit: 'contain',
			},
		})
		const img = wrapper.find('.image-widget__img')
		expect(img.exists()).toBe(true)
		// Vue v-bind:style on CSS custom properties renders as inline style;
		// object-fit: contain should be present.
		const style = wrapper.element.getAttribute('style') || ''
		expect(style).toContain('contain')
	})

	it('defaults to cover when fit is missing', () => {
		const wrapper = mount(ImageWidget, {
			propsData: {
				content: { url: 'https://example.com/test.png' },
				url: 'https://example.com/test.png',
			},
		})
		const img = wrapper.find('.image-widget__img')
		expect(img.exists()).toBe(true)
		const style = wrapper.element.getAttribute('style') || ''
		expect(style).toContain('cover')
	})

	it('accepts all valid fit values', () => {
		const validValues = ['cover', 'contain', 'fill', 'none']
		validValues.forEach((fitValue) => {
			const wrapper = mount(ImageWidget, {
				propsData: {
					content: { url: 'https://example.com/test.png', fit: fitValue },
					url: 'https://example.com/test.png',
					fit: fitValue,
				},
			})
			const style = wrapper.element.getAttribute('style') || ''
			expect(style).toContain(fitValue)
		})
	})

	it('falls back to cover for invalid fit value', () => {
		const wrapper = mount(ImageWidget, {
			propsData: {
				content: { url: 'https://example.com/test.png', fit: 'invalid' },
				url: 'https://example.com/test.png',
				fit: 'invalid',
			},
		})
		const style = wrapper.element.getAttribute('style') || ''
		expect(style).toContain('cover')
	})
})

describe('ImageWidget — empty-URL placeholder (REQ-IMG-002)', () => {
	it('renders placeholder when url is empty', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '' }, url: '' },
		})
		expect(wrapper.find('.image-widget__placeholder').exists()).toBe(true)
		expect(wrapper.find('.image-widget__img').exists()).toBe(false)
		expect(wrapper.text()).toContain('No image')
	})

	it('renders placeholder when url is null', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: null }, url: null },
		})
		expect(wrapper.find('.image-widget__placeholder').exists()).toBe(true)
		expect(wrapper.find('.image-widget__img').exists()).toBe(false)
	})

	it('renders placeholder with proper styling', () => {
		const wrapper = mount(ImageWidget, {
			propsData: { content: { url: '' }, url: '' },
		})
		const placeholder = wrapper.find('.image-widget__placeholder')
		expect(placeholder.exists()).toBe(true)
		// Placeholder should have the CSS class with color: var(--color-text-maxcontrast)
		expect(placeholder.classes()).toContain('image-widget__placeholder')
	})
})

describe('ImageWidget — click-through link (REQ-IMG-003)', () => {
	it('sets cursor to pointer when link is non-empty', () => {
		const wrapper = mount(ImageWidget, {
			propsData: {
				content: { url: 'https://example.com/test.png', link: 'https://example.com' },
				url: 'https://example.com/test.png',
				link: 'https://example.com',
			},
		})
		const style = wrapper.element.getAttribute('style') || ''
		expect(style).toContain('pointer')
	})

	it('sets cursor to default when link is empty', () => {
		const wrapper = mount(ImageWidget, {
			propsData: {
				content: { url: 'https://example.com/test.png', link: '' },
				url: 'https://example.com/test.png',
				link: '',
			},
		})
		const style = wrapper.element.getAttribute('style') || ''
		expect(style).toContain('default')
	})

	it('opens link in new tab when clicked with link set', () => {
		const openMock = vi.fn()
		global.window.open = openMock
		const wrapper = mount(ImageWidget, {
			propsData: {
				content: { url: 'https://example.com/test.png', link: 'https://example.com' },
				url: 'https://example.com/test.png',
				link: 'https://example.com',
			},
		})
		wrapper.find('.image-widget').trigger('click')
		expect(openMock).toHaveBeenCalledWith(
			'https://example.com',
			'_blank',
			'noopener,noreferrer',
		)
	})

	it('does not open link when link is empty', () => {
		const openMock = vi.fn()
		global.window.open = openMock
		const wrapper = mount(ImageWidget, {
			propsData: {
				content: { url: 'https://example.com/test.png', link: '' },
				url: 'https://example.com/test.png',
				link: '',
			},
		})
		wrapper.find('.image-widget').trigger('click')
		expect(openMock).not.toHaveBeenCalled()
	})
})

describe('ImageWidget — broken-image fallback (REQ-IMG-004)', () => {
	it('swaps to placeholder on @error event', () => {
		const wrapper = mount(ImageWidget, {
			propsData: {
				content: { url: 'https://example.com/missing.png' },
				url: 'https://example.com/missing.png',
			},
		})
		// Initially shows image
		expect(wrapper.find('.image-widget__img').exists()).toBe(true)
		expect(wrapper.find('.image-widget__placeholder').exists()).toBe(false)

		// Trigger error event
		wrapper.find('.image-widget__img').trigger('error')

		// Now shows placeholder with error text
		wrapper.vm.$nextTick(() => {
			expect(wrapper.find('.image-widget__img').exists()).toBe(false)
			expect(wrapper.find('.image-widget__placeholder').exists()).toBe(true)
			expect(wrapper.text()).toContain('Image failed to load')
		})
	})

	it('does not raise exceptions on error', () => {
		const wrapper = mount(ImageWidget, {
			propsData: {
				content: { url: 'https://example.com/missing.png' },
				url: 'https://example.com/missing.png',
			},
		})
		// Error event should not throw
		expect(() => {
			wrapper.find('.image-widget__img').trigger('error')
		}).not.toThrow()
	})
})

describe('ImageForm — URL validation (REQ-IMG-005)', () => {
	it('rejects empty URL', () => {
		const wrapper = mount(ImageForm, {
			propsData: { editingWidget: null },
		})
		const errors = wrapper.vm.validate()
		expect(errors.length).toBe(1)
		expect(errors[0]).toContain('Image URL is required')
	})

	it('rejects whitespace-only URL', () => {
		const wrapper = mount(ImageForm, {
			propsData: { editingWidget: null },
		})
		wrapper.vm.form.url = '   \n  '
		const errors = wrapper.vm.validate()
		expect(errors.length).toBe(1)
	})

	it('accepts non-empty URL', () => {
		const wrapper = mount(ImageForm, {
			propsData: { editingWidget: null },
		})
		wrapper.vm.form.url = 'https://example.com/image.png'
		const errors = wrapper.vm.validate()
		expect(errors.length).toBe(0)
	})
})

describe('ImageForm — pre-fill from editingWidget', () => {
	it('pre-fills form fields from editingWidget.content', () => {
		const editingWidget = {
			content: {
				url: 'https://example.com/existing.png',
				alt: 'Existing alt',
				link: 'https://example.com',
				fit: 'contain',
			},
		}
		const wrapper = mount(ImageForm, {
			propsData: { editingWidget },
		})
		expect(wrapper.vm.form.url).toBe('https://example.com/existing.png')
		expect(wrapper.vm.form.alt).toBe('Existing alt')
		expect(wrapper.vm.form.link).toBe('https://example.com')
		expect(wrapper.vm.form.fit).toBe('contain')
	})

	it('defaults to empty values when editingWidget is null', () => {
		const wrapper = mount(ImageForm, {
			propsData: { editingWidget: null },
		})
		expect(wrapper.vm.form.url).toBe('')
		expect(wrapper.vm.form.alt).toBe('')
		expect(wrapper.vm.form.link).toBe('')
		expect(wrapper.vm.form.fit).toBe('cover')
	})
})

describe('ImageForm — preview rendering', () => {
	it('renders live preview when URL is non-empty', () => {
		const wrapper = mount(ImageForm, {
			propsData: { editingWidget: null },
		})
		wrapper.vm.form.url = 'https://example.com/image.png'
		wrapper.vm.$nextTick(() => {
			expect(wrapper.find('.image-form__preview').exists()).toBe(true)
		})
	})

	it('does not render preview when URL is empty', () => {
		const wrapper = mount(ImageForm, {
			propsData: { editingWidget: null },
		})
		expect(wrapper.find('.image-form__preview').exists()).toBe(false)
	})
})

describe('ImageForm — fit select options', () => {
	it('contains all four fit options', () => {
		const wrapper = mount(ImageForm, {
			propsData: { editingWidget: null },
		})
		const options = wrapper.findAll('option')
		const values = options.wrappers.map((opt) => opt.element.value)
		expect(values).toContain('cover')
		expect(values).toContain('contain')
		expect(values).toContain('fill')
		expect(values).toContain('none')
	})

	it('defaults fit to cover for new placements', () => {
		const wrapper = mount(ImageForm, {
			propsData: { editingWidget: null },
		})
		expect(wrapper.vm.form.fit).toBe('cover')
	})
})
