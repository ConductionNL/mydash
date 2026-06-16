/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `VideoForm.vue` covering REQ-VID-002, REQ-VID-005,
 * REQ-VID-008, REQ-VID-011:
 *   - validate() requires URL + recognised source.
 *   - URL input drives sourceType detection on change.
 *   - assembledContent emits the canonical embed URL for hosted platforms.
 *   - autoplay coerces muted=true even if the user toggled muted off first.
 *   - editingWidget pre-fills every form field.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import VideoForm from '../VideoForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('VideoForm', () => {
	it('REQ-VID-011: validate() errors when URL is empty', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: { videoUrl: '' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toEqual(['Video URL is required'])
	})

	it('REQ-VID-011: validate() errors on unrecognised URL', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: { videoUrl: 'https://example.com/foo' } },
			stubs: { NcTextField: true },
		})
		// Force the form to recompute by replacing the URL via the input handler.
		wrapper.vm.onUrlInput('https://example.com/foo')
		expect(wrapper.vm.validate()).toEqual(['Invalid video URL or domain not allowed.'])
	})

	it('REQ-VID-011: validate() returns [] for a recognised YouTube URL', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true },
		})
		wrapper.vm.onUrlInput('https://www.youtube.com/watch?v=ABC123')
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-VID-011: nc-file URL without fileId fails validation', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true },
		})
		wrapper.vm.onUrlInput('/apps/files/?fileid=12345')
		expect(wrapper.vm.validate()).toContain('Nextcloud file ID is required')
	})

	it('REQ-VID-011: nc-file with fileId set passes validation', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true },
		})
		wrapper.vm.onUrlInput('/apps/files/?fileid=12345')
		wrapper.vm.onFileIdInput('12345')
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-VID-005: URL input updates sourceType to youtube', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true },
		})
		wrapper.vm.onUrlInput('https://www.youtube.com/watch?v=ABC123')
		expect(wrapper.vm.sourceType).toBe('youtube')
	})

	it('REQ-VID-005: URL input updates sourceType to vimeo', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true },
		})
		wrapper.vm.onUrlInput('https://vimeo.com/12345')
		expect(wrapper.vm.sourceType).toBe('vimeo')
	})

	it('REQ-VID-005: URL input updates sourceType to peertube', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true },
		})
		wrapper.vm.onUrlInput('https://peertube.example.com/w/abc123')
		expect(wrapper.vm.sourceType).toBe('peertube')
	})

	it('REQ-VID-005: assembledContent emits canonical embed URL for YouTube', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true },
		})
		wrapper.vm.onUrlInput('https://youtu.be/ABC123')
		expect(wrapper.vm.assembledContent.videoUrl).toBe('https://www.youtube.com/embed/ABC123')
		expect(wrapper.vm.assembledContent.sourceType).toBe('youtube')
	})

	it('REQ-VID-005: assembledContent emits canonical Vimeo player URL', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true },
		})
		wrapper.vm.onUrlInput('https://vimeo.com/12345')
		expect(wrapper.vm.assembledContent.videoUrl).toBe('https://player.vimeo.com/video/12345')
	})

	it('REQ-VID-008: autoplay toggle forces muted=true', async () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: { muted: false } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.muted).toBe(false)
		wrapper.vm.onAutoplayToggle(true)
		expect(wrapper.vm.autoplay).toBe(true)
		expect(wrapper.vm.muted).toBe(true)
		expect(wrapper.vm.assembledContent.muted).toBe(true)
	})

	it('REQ-VID-008: assembledContent.muted is true whenever autoplay is true', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: { autoplay: true, muted: false } },
			stubs: { NcTextField: true },
		})
		// Even though stored muted=false, the assembled content coerces it.
		expect(wrapper.vm.assembledContent.muted).toBe(true)
	})

	it('REQ-VID-002: editingWidget pre-fills every field', () => {
		const wrapper = mount(VideoForm, {
			propsData: {
				editingWidget: {
					content: {
						sourceType: 'youtube',
						videoUrl: 'https://www.youtube.com/embed/XYZ',
						autoplay: true,
						muted: true,
						loop: true,
						controls: false,
						aspectRatio: '4:3',
						posterUrl: 'https://example.com/poster.jpg',
					},
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.videoUrl).toBe('https://www.youtube.com/embed/XYZ')
		expect(wrapper.vm.sourceType).toBe('youtube')
		expect(wrapper.vm.autoplay).toBe(true)
		expect(wrapper.vm.loop).toBe(true)
		expect(wrapper.vm.controls).toBe(false)
		expect(wrapper.vm.aspectRatio).toBe('4:3')
		expect(wrapper.vm.posterUrl).toBe('https://example.com/poster.jpg')
	})

	it('REQ-VID-002: editingWidget with nc-file pre-fills fileId', () => {
		const wrapper = mount(VideoForm, {
			propsData: {
				editingWidget: {
					content: {
						sourceType: 'nc-file',
						videoUrl: '/apps/files/?fileid=99',
						fileId: 99,
					},
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.fileId).toBe(99)
		expect(wrapper.vm.sourceType).toBe('nc-file')
	})

	it('REQ-VID-005: defaults assembleContent emits null sourceType when no URL', () => {
		const wrapper = mount(VideoForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.assembledContent.sourceType).toBeNull()
		expect(wrapper.vm.assembledContent.videoUrl).toBe('')
	})
})
