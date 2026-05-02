/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `ImageForm.vue` covering REQ-IMG-005:
 *   - validate() returns `[t('mydash', 'Image URL is required')]` when
 *     `url` is empty/whitespace, otherwise an empty array.
 *   - Successful upload populates `form.url` from the response.
 *   - Failed upload surfaces the inline error and leaves `form.url`
 *     untouched.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ImageForm from '../ImageForm.vue'
import {
	uploadDataUrl,
	readFileAsDataUrl,
	ResourceUploadError,
} from '../../../../services/resourceService.js'

// vi.mock() is hoisted to the top of the file by Vitest's transform, so
// it runs BEFORE the imports above resolve — the imported symbols then
// reference the mocked module. This intentionally violates the visual
// "imports at top, mocks below" reading order; the mock is active even
// though it appears textually after the imports.
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

describe('ImageForm', () => {
	it('REQ-IMG-005: validate() errors when url is empty', () => {
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toEqual(['Image URL is required'])
	})

	it('REQ-IMG-005: validate() errors when url is whitespace-only', () => {
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '   ' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toEqual(['Image URL is required'])
	})

	it('REQ-IMG-005: validate() returns [] when url is non-empty', () => {
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: 'https://example.com/x.png' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-IMG-005: pre-fills url, alt, link, fit from editingWidget', () => {
		const wrapper = mount(ImageForm, {
			propsData: {
				editingWidget: {
					content: {
						url: '/img/a.png',
						alt: 'A',
						link: 'https://example.com',
						fit: 'contain',
					},
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.url).toBe('/img/a.png')
		expect(wrapper.vm.alt).toBe('A')
		expect(wrapper.vm.link).toBe('https://example.com')
		expect(wrapper.vm.fit).toBe('contain')
	})

	it('REQ-IMG-005: defaults fit to cover for new placements', () => {
		const wrapper = mount(ImageForm, {
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.fit).toBe('cover')
	})

	it('REQ-IMG-005: successful upload sets form.url from response', async () => {
		uploadDataUrl.mockResolvedValueOnce({
			url: '/apps/mydash/resource/abc.png',
			name: 'abc.png',
			size: 1024,
		})
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '' } },
			stubs: { NcTextField: true },
		})
		const file = new Blob(['x'], { type: 'image/png' })
		await wrapper.vm.onFileSelected({ target: { files: [file], value: '' } })
		expect(uploadDataUrl).toHaveBeenCalledWith('data:image/png;base64,AAA')
		expect(wrapper.vm.url).toBe('/apps/mydash/resource/abc.png')
		expect(wrapper.vm.uploadError).toBe('')
	})

	it('REQ-IMG-005: upload-error path surfaces inline error and leaves url unchanged', async () => {
		uploadDataUrl.mockRejectedValueOnce(new ResourceUploadError('forbidden', 'Forbidden'))
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '/keep.png' } },
			stubs: { NcTextField: true },
		})
		const file = new Blob(['x'], { type: 'image/png' })
		await wrapper.vm.onFileSelected({ target: { files: [file], value: '' } })
		expect(wrapper.vm.uploadError).toBe('Failed to upload image')
		expect(wrapper.vm.url).toBe('/keep.png')
	})

	it('REQ-IMG-005: emits update:content on field change', async () => {
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '' } },
			stubs: { NcTextField: true },
		})
		wrapper.vm.updateField('url', 'https://example.com/y.png')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		const last = emitted[emitted.length - 1][0]
		expect(last).toMatchObject({
			url: 'https://example.com/y.png',
			alt: '',
			link: '',
			fit: 'cover',
		})
	})
})
