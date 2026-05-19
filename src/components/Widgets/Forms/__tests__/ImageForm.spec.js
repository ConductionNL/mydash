/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `ImageForm.vue` covering REQ-IMG-005 and the
 * `image-widget-media-picker` extension (REQ-IMP-001..008):
 *   - validate() returns `[t('mydash', 'Image URL is required')]` when
 *     `url` is empty/whitespace and the active source is url/upload.
 *   - validate() returns `[t('mydash', 'Please pick a file from
 *     Files')]` when sourceType=files and no file has been picked.
 *   - Successful upload populates `form.url` from the response.
 *   - Failed upload surfaces the inline error and leaves `form.url`
 *     untouched.
 *   - Switching source types preserves previously entered values
 *     (REQ-IMP-007).
 *   - File picker invocation pre-fills url/fileId/filePath from the
 *     resolved Nextcloud node (REQ-IMP-002, REQ-IMP-003, REQ-IMP-004).
 *   - File picker cancellation (FilePickerClosed) keeps prior selection
 *     and does NOT surface an error (REQ-IMP-002 task 4.5).
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

// Lazy-imported `@nextcloud/dialogs` — the form pulls it via dynamic
// import to keep the picker bundle out of the modal's first paint. We
// stub the chain `getFilePickerBuilder().setMultiSelect().setMimeType
// Filter().allowDirectories().build().pickNodes()` and let each test
// drive the `pickNodes` resolution.
const pickNodesMock = vi.fn()
vi.mock('@nextcloud/dialogs', () => {
	const builder = {
		setMultiSelect: vi.fn().mockReturnThis(),
		setMimeTypeFilter: vi.fn().mockReturnThis(),
		allowDirectories: vi.fn().mockReturnThis(),
		build: vi.fn(() => ({ pickNodes: pickNodesMock })),
	}
	class FilePickerClosed extends Error {

		constructor(msg) {
			super(msg)
			this.name = 'FilePickerClosed'
		}

	}
	return {
		getFilePickerBuilder: vi.fn(() => builder),
		FilePickerClosed,
	}
})

beforeEach(() => {
	globalThis.t = (_app, key) => key
	uploadDataUrl.mockReset()
	readFileAsDataUrl.mockReset()
	readFileAsDataUrl.mockResolvedValue('data:image/png;base64,AAA')
	pickNodesMock.mockReset()
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
			propsData: { value: { url: '', sourceType: 'upload' } },
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
			propsData: { value: { url: '/keep.png', sourceType: 'upload' } },
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
			sourceType: 'url',
		})
	})

	it('REQ-IMP-001: defaults sourceType to "url" for new placements', () => {
		const wrapper = mount(ImageForm, {
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.sourceType).toBe('url')
	})

	it('REQ-IMP-001: pre-fills sourceType, fileId, filePath from editingWidget', () => {
		const wrapper = mount(ImageForm, {
			propsData: {
				editingWidget: {
					content: {
						url: '/index.php/core/preview?fileId=42&x=512&y=512&a=true',
						sourceType: 'files',
						fileId: 42,
						filePath: '/Photos/sunset.png',
					},
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.sourceType).toBe('files')
		expect(wrapper.vm.fileId).toBe(42)
		expect(wrapper.vm.filePath).toBe('/Photos/sunset.png')
	})

	it('REQ-IMP-001: invalid sourceType in input falls back to default', () => {
		const wrapper = mount(ImageForm, {
			propsData: {
				value: {
					url: '',
					sourceType: 'clipboard',
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.sourceType).toBe('url')
	})

	it('REQ-IMP-002: file picker pre-fills url, fileId, filePath from picked node', async () => {
		pickNodesMock.mockResolvedValueOnce([
			{ fileid: 12345, path: '/Photos/sunset.png' },
		])
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '', sourceType: 'files' } },
			stubs: { NcTextField: true },
		})
		await wrapper.vm.onPickFromFiles()
		expect(wrapper.vm.fileId).toBe(12345)
		expect(wrapper.vm.filePath).toBe('/Photos/sunset.png')
		expect(wrapper.vm.url).toContain('/index.php/core/preview?fileId=12345')
		expect(wrapper.vm.pickError).toBe('')
	})

	it('REQ-IMP-002: file picker is invoked with image MIME type filter and single-select', async () => {
		pickNodesMock.mockResolvedValueOnce([
			{ fileid: 1, path: '/x.png' },
		])
		const dialogs = await import('@nextcloud/dialogs')
		const builder = dialogs.getFilePickerBuilder('')
		builder.setMultiSelect.mockClear()
		builder.setMimeTypeFilter.mockClear()
		builder.allowDirectories.mockClear()
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '', sourceType: 'files' } },
			stubs: { NcTextField: true },
		})
		await wrapper.vm.onPickFromFiles()
		expect(builder.setMultiSelect).toHaveBeenCalledWith(false)
		const mimeArg = builder.setMimeTypeFilter.mock.calls[0][0]
		expect(mimeArg).toEqual([
			'image/png',
			'image/jpeg',
			'image/gif',
			'image/webp',
			'image/svg+xml',
		])
		expect(builder.allowDirectories).toHaveBeenCalledWith(false)
	})

	it('REQ-IMP-002 task 4.5: cancelled picker (FilePickerClosed) keeps prior selection silently', async () => {
		const dialogs = await import('@nextcloud/dialogs')
		pickNodesMock.mockRejectedValueOnce(new dialogs.FilePickerClosed('user closed'))
		const wrapper = mount(ImageForm, {
			propsData: {
				value: {
					url: '/index.php/core/preview?fileId=99',
					sourceType: 'files',
					fileId: 99,
					filePath: '/Old/photo.png',
				},
			},
			stubs: { NcTextField: true },
		})
		await wrapper.vm.onPickFromFiles()
		expect(wrapper.vm.fileId).toBe(99)
		expect(wrapper.vm.filePath).toBe('/Old/photo.png')
		expect(wrapper.vm.pickError).toBe('')
	})

	it('REQ-IMP-002 task 4.5: picker failure (non-cancel) surfaces inline error', async () => {
		pickNodesMock.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '', sourceType: 'files' } },
			stubs: { NcTextField: true },
		})
		await wrapper.vm.onPickFromFiles()
		expect(wrapper.vm.pickError).toBe('File picker failed to open')
	})

	it('REQ-IMP-006: validate() errors when sourceType=files and no file picked', () => {
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '', sourceType: 'files' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toEqual(['Please pick a file from Files'])
	})

	it('REQ-IMP-006: validate() returns [] when sourceType=files and filePath set', () => {
		const wrapper = mount(ImageForm, {
			propsData: {
				value: {
					url: '/index.php/core/preview?fileId=42',
					sourceType: 'files',
					fileId: 42,
					filePath: '/Photos/sunset.png',
				},
			},
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-IMP-007: switching source types preserves previously entered values', async () => {
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: 'https://example.com/x.png' } },
			stubs: { NcTextField: true },
		})
		expect(wrapper.vm.sourceType).toBe('url')
		expect(wrapper.vm.url).toBe('https://example.com/x.png')

		wrapper.vm.sourceType = 'upload'
		wrapper.vm.onSourceChange()
		expect(wrapper.vm.url).toBe('https://example.com/x.png')

		wrapper.vm.sourceType = 'files'
		wrapper.vm.onSourceChange()
		expect(wrapper.vm.url).toBe('https://example.com/x.png')
		expect(wrapper.vm.filePath).toBe('')

		wrapper.vm.sourceType = 'url'
		wrapper.vm.onSourceChange()
		expect(wrapper.vm.url).toBe('https://example.com/x.png')
	})

	it('REQ-IMP-007: emits update:content on source-type change so parent re-syncs', async () => {
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: '' } },
			stubs: { NcTextField: true },
		})
		wrapper.vm.sourceType = 'files'
		wrapper.vm.onSourceChange()
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		const last = emitted[emitted.length - 1][0]
		expect(last.sourceType).toBe('files')
	})

	it('REQ-IMP-001: assembledContent always includes sourceType, fileId, filePath', () => {
		const wrapper = mount(ImageForm, {
			propsData: { value: { url: 'https://example.com/x.png' } },
			stubs: { NcTextField: true },
		})
		const content = wrapper.vm.assembledContent
		expect(content).toMatchObject({
			url: 'https://example.com/x.png',
			alt: '',
			link: '',
			fit: 'cover',
			sourceType: 'url',
			fileId: null,
			filePath: '',
		})
	})
})
