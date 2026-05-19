/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `HeaderForm.vue` covering REQ-HDR-002 (placement
 * config), REQ-HDR-005 (image URL allow-list / scheme), REQ-HDR-010
 * (CTA validity), and the standard pre-fill / emit contract used by the
 * AddWidgetModal sub-form pattern.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import HeaderForm from '../HeaderForm.vue'
import {
	uploadDataUrl,
	readFileAsDataUrl,
	ResourceUploadError,
} from '../../../../services/resourceService.js'

vi.mock('../../../../services/resourceService.js', () => {
	return {
		uploadDataUrl: vi.fn(),
		readFileAsDataUrl: vi.fn(async () => 'data:image/png;base64,AAA'),
		ResourceUploadError: class ResourceUploadError extends Error {

			constructor(code, message) {
				super(message)
				this.code = code
			}

		},
	}
})

beforeEach(() => {
	globalThis.t = (_app, key) => key
	uploadDataUrl.mockReset()
	readFileAsDataUrl.mockReset()
	readFileAsDataUrl.mockResolvedValue('data:image/png;base64,AAA')
})

describe('HeaderForm', () => {
	it('REQ-HDR-002: validate() errors when title is empty', () => {
		const wrapper = mount(HeaderForm, {
			propsData: { value: { title: '' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toContain('Title is required')
	})

	it('REQ-HDR-002: validate() returns empty array when title is non-empty and no CTA', () => {
		const wrapper = mount(HeaderForm, {
			propsData: { value: { title: 'Welcome' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-HDR-005: validate() rejects non-http background image URL', () => {
		const wrapper = mount(HeaderForm, {
			propsData: {
				value: {
					title: 'X',
					backgroundImageUrl: 'javascript:alert(1)',
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toContain('Background image URL must be HTTP or HTTPS')
	})

	it('REQ-HDR-005: validate() accepts https background image URL', () => {
		const wrapper = mount(HeaderForm, {
			propsData: {
				value: {
					title: 'X',
					backgroundImageUrl: 'https://example.com/banner.jpg',
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-HDR-010: validate() flags partial CTA (label without URL)', () => {
		const wrapper = mount(HeaderForm, {
			propsData: {
				value: {
					title: 'X',
					cta: { label: 'Go', url: '', style: 'primary' },
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toContain('Call-to-Action requires both label and URL')
	})

	it('REQ-HDR-010: validate() accepts complete CTA', () => {
		const wrapper = mount(HeaderForm, {
			propsData: {
				value: {
					title: 'X',
					cta: { label: 'Go', url: 'https://example.com', style: 'primary' },
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-HDR-002: pre-fills every control from editingWidget.content', () => {
		const editingWidget = {
			content: {
				title: 'My banner',
				subtitle: 'Welcome aboard',
				backgroundImageUrl: 'https://example.com/banner.jpg',
				backgroundImageFileId: 42,
				backgroundColor: '#112233',
				overlayMode: 'tint',
				overlayColor: '#000000',
				overlayOpacity: 0.6,
				textColor: '#ffffff',
				textAlign: 'left',
				verticalAlign: 'top',
				height: 'large',
				cta: { label: 'Sign up', url: 'https://example.com/signup', style: 'secondary' },
			},
		}

		const wrapper = mount(HeaderForm, {
			propsData: { editingWidget },
			stubs: { NcTextField: true },
		})

		expect(wrapper.vm.title).toBe('My banner')
		expect(wrapper.vm.subtitle).toBe('Welcome aboard')
		expect(wrapper.vm.backgroundImageUrl).toBe('https://example.com/banner.jpg')
		expect(wrapper.vm.backgroundImageFileId).toBe(42)
		expect(wrapper.vm.backgroundColor).toBe('#112233')
		expect(wrapper.vm.overlayMode).toBe('tint')
		expect(wrapper.vm.overlayColor).toBe('#000000')
		expect(wrapper.vm.overlayOpacity).toBe(0.6)
		expect(wrapper.vm.textColor).toBe('#ffffff')
		expect(wrapper.vm.textAlign).toBe('left')
		expect(wrapper.vm.verticalAlign).toBe('top')
		expect(wrapper.vm.height).toBe('large')
		expect(wrapper.vm.ctaLabel).toBe('Sign up')
		expect(wrapper.vm.ctaUrl).toBe('https://example.com/signup')
		expect(wrapper.vm.ctaStyle).toBe('secondary')
	})

	it('emits update:content with assembled payload on field change', () => {
		const wrapper = mount(HeaderForm, {
			propsData: { value: { title: 'X' } },
			stubs: { NcTextField: true },
		})
		wrapper.vm.updateField('title', 'Y')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({ title: 'Y' })
	})

	it('REQ-HDR-002: assembled cta is null when both label and url are empty', () => {
		const wrapper = mount(HeaderForm, {
			propsData: { value: { title: 'X' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.assembledContent.cta).toBeNull()
	})

	it('REQ-HDR-002: invalid overlayMode collapses to default `none`', () => {
		const wrapper = mount(HeaderForm, {
			propsData: { value: { title: 'X', overlayMode: 'spinning-rainbow' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.overlayMode).toBe('none')
	})

	it('REQ-HDR-002: invalid height collapses to default `medium`', () => {
		const wrapper = mount(HeaderForm, {
			propsData: { value: { title: 'X', height: 'jumbo' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.height).toBe('medium')
	})

	it('successful upload sets backgroundImageUrl from response', async () => {
		uploadDataUrl.mockResolvedValue({ url: '/apps/mydash/resource/abc.png' })
		const wrapper = mount(HeaderForm, {
			propsData: { value: { title: 'X' } },
			stubs: { NcTextField: true },
		})

		const file = new File(['hi'], 'banner.png', { type: 'image/png' })
		await wrapper.vm.onFileSelected({ target: { files: [file], value: 'banner.png' } })

		expect(wrapper.vm.backgroundImageUrl).toBe('/apps/mydash/resource/abc.png')
		expect(wrapper.vm.uploadError).toBe('')
	})

	it('failed upload surfaces inline error and leaves backgroundImageUrl untouched', async () => {
		uploadDataUrl.mockRejectedValue(new ResourceUploadError('boom', 'nope'))
		const wrapper = mount(HeaderForm, {
			propsData: { value: { title: 'X', backgroundImageUrl: 'https://e.com/keep.jpg' } },
			stubs: { NcTextField: true },
		})

		const file = new File(['hi'], 'banner.png', { type: 'image/png' })
		await wrapper.vm.onFileSelected({ target: { files: [file], value: 'banner.png' } })

		expect(wrapper.vm.uploadError).toBe('Failed to upload image')
		expect(wrapper.vm.backgroundImageUrl).toBe('https://e.com/keep.jpg')
	})
})
