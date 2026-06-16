/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `HeaderWidget.vue` covering REQ-HDR-002
 * (placement config), REQ-HDR-003 (image source precedence), REQ-HDR-004
 * (overlay modes), REQ-HDR-007 (image-load failure fallback), REQ-HDR-008
 * (text rendering and styling), REQ-HDR-009 (height presets), and
 * REQ-HDR-010 (CTA rendering and external/internal target handling).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import HeaderWidget from '../HeaderWidget.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('HeaderWidget', () => {
	it('REQ-HDR-008: renders title as <h2> and subtitle as <p>', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: { title: 'Welcome', subtitle: 'Explore our dashboard' },
			},
		})

		const h2 = wrapper.find('h2.header-widget__title')
		const p = wrapper.find('p.header-widget__subtitle')
		expect(h2.exists()).toBe(true)
		expect(h2.text()).toBe('Welcome')
		expect(p.exists()).toBe(true)
		expect(p.text()).toBe('Explore our dashboard')
	})

	it('REQ-HDR-008: omits subtitle <p> when subtitle is empty', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: { content: { title: 'Hello' } },
		})

		expect(wrapper.find('p.header-widget__subtitle').exists()).toBe(false)
	})

	it('REQ-HDR-009: height=small renders 120px wrapper', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: { content: { title: 'X', height: 'small' } },
		})

		const style = wrapper.find('.header-widget').attributes('style') || ''
		expect(style).toContain('height: 120px')
	})

	it('REQ-HDR-009: default height is medium (200px)', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: { content: { title: 'X' } },
		})

		const style = wrapper.find('.header-widget').attributes('style') || ''
		expect(style).toContain('height: 200px')
	})

	it('REQ-HDR-009: height=large renders 320px wrapper', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: { content: { title: 'X', height: 'large' } },
		})

		const style = wrapper.find('.header-widget').attributes('style') || ''
		expect(style).toContain('height: 320px')
	})

	it('REQ-HDR-009: height=xlarge renders 480px wrapper', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: { content: { title: 'X', height: 'xlarge' } },
		})

		const style = wrapper.find('.header-widget').attributes('style') || ''
		expect(style).toContain('height: 480px')
	})

	it('REQ-HDR-009: unknown height collapses to medium default', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: { content: { title: 'X', height: 'jumbo' } },
		})

		const style = wrapper.find('.header-widget').attributes('style') || ''
		expect(style).toContain('height: 200px')
	})

	it('REQ-HDR-003: backgroundImageFileId takes precedence over backgroundImageUrl', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: {
					title: 'X',
					backgroundImageFileId: 42,
					backgroundImageUrl: 'https://example.com/banner.jpg',
				},
			},
		})

		const style = wrapper.find('.header-widget').attributes('style') || ''
		expect(style).toContain('fileId=42')
		expect(style).not.toContain('example.com/banner.jpg')
	})

	it('REQ-HDR-003: backgroundImageUrl is used when no file ID is set', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: {
					title: 'X',
					backgroundImageUrl: 'https://example.com/banner.jpg',
				},
			},
		})

		const style = wrapper.find('.header-widget').attributes('style') || ''
		expect(style).toContain('example.com/banner.jpg')
	})

	it('REQ-HDR-003: non-http URLs are ignored (no background-image)', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: {
					title: 'X',
					backgroundImageUrl: 'javascript:alert(1)',
				},
			},
		})

		const style = wrapper.find('.header-widget').attributes('style') || ''
		expect(style).not.toContain('background-image')
	})

	it('REQ-HDR-007: image load failure falls back to backgroundColor', async () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: {
					title: 'X',
					backgroundImageUrl: 'https://example.com/missing.jpg',
					backgroundColor: '#cc0000',
				},
			},
		})

		// Probe image fires error.
		const probe = wrapper.find('img.header-widget__probe')
		expect(probe.exists()).toBe(true)
		await probe.trigger('error')

		const style = wrapper.find('.header-widget').attributes('style') || ''
		expect(style).not.toContain('background-image')
		expect(style).toContain('background-color: rgb(204, 0, 0)')
	})

	it('REQ-HDR-004: overlayMode=none renders no overlay div', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: { title: 'X', overlayMode: 'none' },
			},
		})

		expect(wrapper.find('.header-widget__overlay').exists()).toBe(false)
	})

	it('REQ-HDR-004: overlayMode=tint renders overlay with opacity', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: {
					title: 'X',
					overlayMode: 'tint',
					overlayColor: '#000000',
					overlayOpacity: 0.4,
				},
			},
		})

		const overlay = wrapper.find('.header-widget__overlay')
		expect(overlay.exists()).toBe(true)
		const style = overlay.attributes('style') || ''
		expect(style).toContain('background-color: rgb(0, 0, 0)')
		expect(style).toContain('opacity: 0.4')
	})

	it('REQ-HDR-004: overlayMode=gradient-bottom uses linear-gradient', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: {
					title: 'X',
					overlayMode: 'gradient-bottom',
					overlayColor: '#000000',
				},
			},
		})

		const overlay = wrapper.find('.header-widget__overlay')
		expect(overlay.exists()).toBe(true)
		const style = overlay.attributes('style') || ''
		expect(style).toContain('linear-gradient')
	})

	it('REQ-HDR-010: CTA renders as <a> with primary class and external target', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: {
					title: 'X',
					cta: { label: 'Sign up', url: 'https://example.com/signup', style: 'primary' },
				},
			},
		})

		const cta = wrapper.find('a.header-widget__cta')
		expect(cta.exists()).toBe(true)
		expect(cta.text()).toBe('Sign up')
		expect(cta.attributes('href')).toBe('https://example.com/signup')
		expect(cta.attributes('target')).toBe('_blank')
		expect(cta.attributes('rel')).toBe('noopener noreferrer')
		expect(cta.classes()).toContain('header-widget__cta--primary')
	})

	it('REQ-HDR-010: internal CTA URL stays in same tab (no target/rel)', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: {
					title: 'X',
					cta: { label: 'Settings', url: '/settings', style: 'secondary' },
				},
			},
		})

		const cta = wrapper.find('a.header-widget__cta')
		expect(cta.exists()).toBe(true)
		expect(cta.attributes('target')).toBeUndefined()
		expect(cta.attributes('rel')).toBeUndefined()
		expect(cta.classes()).toContain('header-widget__cta--secondary')
	})

	it('REQ-HDR-010: missing CTA label or URL hides the button entirely', () => {
		const wrapperNoLabel = mount(HeaderWidget, {
			propsData: {
				content: { title: 'X', cta: { label: '', url: 'https://e.com', style: 'primary' } },
			},
		})
		expect(wrapperNoLabel.find('a.header-widget__cta').exists()).toBe(false)

		const wrapperNoUrl = mount(HeaderWidget, {
			propsData: {
				content: { title: 'X', cta: { label: 'Go', url: '', style: 'primary' } },
			},
		})
		expect(wrapperNoUrl.find('a.header-widget__cta').exists()).toBe(false)

		const wrapperNullCta = mount(HeaderWidget, {
			propsData: { content: { title: 'X', cta: null } },
		})
		expect(wrapperNullCta.find('a.header-widget__cta').exists()).toBe(false)
	})

	it('REQ-HDR-008: textColor explicit override wins over auto-contrast', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: {
					title: 'Hello',
					textColor: '#ff00ff',
					backgroundColor: '#ffffff',
				},
			},
		})

		const titleStyle = wrapper.find('h2.header-widget__title').attributes('style') || ''
		expect(titleStyle).toMatch(/color:\s*(rgb\(255,\s*0,\s*255\)|#ff00ff)/i)
	})

	it('REQ-HDR-008: auto-contrast picks dark text on light background', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: { title: 'Hello', backgroundColor: '#ffffff' },
			},
		})

		const titleStyle = wrapper.find('h2.header-widget__title').attributes('style') || ''
		expect(titleStyle).toMatch(/color:\s*(rgb\(0,\s*0,\s*0\)|#000000)/i)
	})

	it('REQ-HDR-008: auto-contrast picks light text on dark background', () => {
		const wrapper = mount(HeaderWidget, {
			propsData: {
				content: { title: 'Hello', backgroundColor: '#000000' },
			},
		})

		const titleStyle = wrapper.find('h2.header-widget__title').attributes('style') || ''
		expect(titleStyle).toMatch(/color:\s*(rgb\(255,\s*255,\s*255\)|#ffffff)/i)
	})
})
