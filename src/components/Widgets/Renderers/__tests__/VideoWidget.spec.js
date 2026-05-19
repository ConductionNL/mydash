/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `VideoWidget.vue` covering REQ-VID-002, REQ-VID-007,
 * REQ-VID-008, REQ-VID-009, REQ-VID-011:
 *   - Empty state when no source/URL is configured.
 *   - Iframe rendering for YouTube, Vimeo, PeerTube with sandbox + canonical URL.
 *   - HTML5 video rendering for nc-file with poster, autoplay, muted, loop.
 *   - Autoplay coerces muted=true on iframe query params and on <video>.
 *   - Aspect ratio CSS applied to media wrapper.
 *   - Error state when content.error is set; on <video> error event.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import VideoWidget from '../VideoWidget.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('VideoWidget', () => {
	it('REQ-VID-011: empty source renders the placeholder + "No video URL configured"', () => {
		const wrapper = mount(VideoWidget, {
			propsData: { content: {} },
		})
		expect(wrapper.find('.video-widget__placeholder').exists()).toBe(true)
		expect(wrapper.text()).toContain('No video URL configured')
		expect(wrapper.find('iframe').exists()).toBe(false)
		expect(wrapper.find('video').exists()).toBe(false)
	})

	it('REQ-VID-011: invalid sourceType collapses to empty state', () => {
		const wrapper = mount(VideoWidget, {
			propsData: { content: { sourceType: 'tiktok', videoUrl: 'https://tiktok.com/x' } },
		})
		expect(wrapper.find('.video-widget__placeholder').exists()).toBe(true)
		expect(wrapper.text()).toContain('No video URL configured')
	})

	it('REQ-VID-007: YouTube renders iframe with canonical URL + sandbox', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'youtube',
					videoUrl: 'https://www.youtube.com/embed/ABC123',
					aspectRatio: '16:9',
				},
			},
		})
		const iframe = wrapper.find('iframe.video-widget__iframe')
		expect(iframe.exists()).toBe(true)
		expect(iframe.attributes('src')).toContain('https://www.youtube.com/embed/ABC123')
		expect(iframe.attributes('sandbox')).toContain('allow-scripts')
		expect(iframe.attributes('sandbox')).toContain('allow-same-origin')
		expect(iframe.attributes('sandbox')).not.toContain('allow-top-navigation')
		expect(iframe.attributes('sandbox')).not.toContain('allow-forms')
		// allowfullscreen is a boolean attribute — Vue renders it as the
		// empty-string presence form (`allowfullscreen=""`).
		expect(iframe.attributes('allowfullscreen')).toBeDefined()
	})

	it('REQ-VID-007: Vimeo renders iframe with player URL', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'vimeo',
					videoUrl: 'https://player.vimeo.com/video/12345',
				},
			},
		})
		const iframe = wrapper.find('iframe')
		expect(iframe.exists()).toBe(true)
		expect(iframe.attributes('src')).toContain('https://player.vimeo.com/video/12345')
	})

	it('REQ-VID-007: PeerTube renders iframe with embed URL', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'peertube',
					videoUrl: 'https://peertube.example.com/videos/embed/abc123',
				},
			},
		})
		const iframe = wrapper.find('iframe')
		expect(iframe.exists()).toBe(true)
		expect(iframe.attributes('src')).toContain('peertube.example.com/videos/embed/abc123')
	})

	it('REQ-VID-008: autoplay forces autoplay+mute query params on iframe', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'youtube',
					videoUrl: 'https://www.youtube.com/embed/ABC123',
					autoplay: true,
					muted: false,
				},
			},
		})
		const iframe = wrapper.find('iframe')
		const src = iframe.attributes('src')
		expect(src).toContain('autoplay=1')
		expect(src).toContain('mute=1')
	})

	it('REQ-VID-008: loop=true appends loop=1 to iframe URL', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'youtube',
					videoUrl: 'https://www.youtube.com/embed/ABC123',
					loop: true,
				},
			},
		})
		expect(wrapper.find('iframe').attributes('src')).toContain('loop=1')
	})

	it('REQ-VID-008: nc-file <video> with autoplay carries muted attribute', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'nc-file',
					fileId: 12345,
					fileStreamingUrl: '/index.php/apps/files/api/v1/files/12345/content',
					autoplay: true,
					muted: false,
				},
			},
		})
		const video = wrapper.find('video.video-widget__video')
		expect(video.exists()).toBe(true)
		// Vue 2 binds `muted` as a DOM property (not an enumerated HTML
		// attribute), so we assert the live DOM property — that is what
		// the browser autoplay policy actually checks.
		expect(video.attributes('autoplay')).toBeDefined()
		expect(video.element.muted).toBe(true)
	})

	it('REQ-VID-002: nc-file renders <video> with src + poster + loop', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'nc-file',
					fileId: 99,
					fileStreamingUrl: '/stream/99.mp4',
					posterUrl: 'https://example.com/poster.jpg',
					loop: true,
					controls: true,
				},
			},
		})
		const video = wrapper.find('video')
		expect(video.exists()).toBe(true)
		expect(video.attributes('src')).toBe('/stream/99.mp4')
		expect(video.attributes('poster')).toBe('https://example.com/poster.jpg')
		expect(video.attributes('loop')).toBeDefined()
		expect(video.attributes('controls')).toBeDefined()
	})

	it('REQ-VID-009: aspect ratio applied as CSS aspect-ratio property', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'youtube',
					videoUrl: 'https://www.youtube.com/embed/ABC123',
					aspectRatio: '4:3',
				},
			},
		})
		const media = wrapper.find('.video-widget__media')
		const style = media.attributes('style') || ''
		expect(style).toContain('aspect-ratio: 4 / 3')
	})

	it('REQ-VID-009: unknown aspect ratio falls back to 16:9', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'youtube',
					videoUrl: 'https://www.youtube.com/embed/ABC123',
					aspectRatio: 'banana',
				},
			},
		})
		const media = wrapper.find('.video-widget__media')
		const style = media.attributes('style') || ''
		expect(style).toContain('aspect-ratio: 16 / 9')
	})

	it('REQ-VID-011: content.error string triggers error placeholder with that message', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'youtube',
					videoUrl: 'https://www.youtube.com/embed/ABC123',
					error: 'Domain not allowed by administrator',
				},
			},
		})
		expect(wrapper.find('.video-widget__placeholder').exists()).toBe(true)
		expect(wrapper.text()).toContain('Domain not allowed by administrator')
		expect(wrapper.find('iframe').exists()).toBe(false)
	})

	it('REQ-VID-011: nc-file with fileId but no streaming URL shows access error', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'nc-file',
					fileId: 12345,
					fileStreamingUrl: '',
				},
			},
		})
		expect(wrapper.find('.video-widget__placeholder').exists()).toBe(true)
		expect(wrapper.text()).toContain('Video not accessible')
	})

	it('REQ-VID-011: <video> error event swaps to error placeholder', async () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'nc-file',
					fileId: 99,
					fileStreamingUrl: '/stream/99.mp4',
				},
			},
		})
		expect(wrapper.find('video').exists()).toBe(true)
		await wrapper.find('video').trigger('error')
		expect(wrapper.find('video').exists()).toBe(false)
		expect(wrapper.find('.video-widget__placeholder').exists()).toBe(true)
		expect(wrapper.text()).toContain('Video failed to load')
	})

	it('REQ-VID-011: error handler swallows the event', async () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'nc-file',
					fileId: 99,
					fileStreamingUrl: '/stream/99.mp4',
				},
			},
		})
		await expect(wrapper.find('video').trigger('error')).resolves.toBeUndefined()
	})

	it('REQ-VID-008: muted-only (no autoplay) appends mute=1 only', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'youtube',
					videoUrl: 'https://www.youtube.com/embed/ABC123',
					autoplay: false,
					muted: true,
				},
			},
		})
		const src = wrapper.find('iframe').attributes('src')
		expect(src).toContain('mute=1')
		expect(src).not.toContain('autoplay=1')
	})

	it('REQ-VID-008: explicit muted=false with autoplay=false strips mute query param', () => {
		const wrapper = mount(VideoWidget, {
			propsData: {
				content: {
					sourceType: 'youtube',
					videoUrl: 'https://www.youtube.com/embed/ABC123',
					autoplay: false,
					muted: false,
				},
			},
		})
		const src = wrapper.find('iframe').attributes('src')
		expect(src).toBe('https://www.youtube.com/embed/ABC123')
	})
})
