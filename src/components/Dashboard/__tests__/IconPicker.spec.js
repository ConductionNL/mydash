/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit test for `IconPicker.vue` covering REQ-ICON-008..009 — the
 * dual-input picker that surfaces a built-in `<select>` and an
 * `<input type="file">` simultaneously, both writing to the same v-model
 * value, with a 24×24 live preview through `IconRenderer` and previous-
 * value preservation on upload error.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import IconPicker from '../IconPicker.vue'

// `vi.mock` calls are hoisted by Vitest above the imports, so the
// stubs are in place before `axios` and `IconPicker.vue` (which pulls
// in `resourceService.js` → `axios`) are resolved.
vi.mock('@nextcloud/axios', () => ({
	default: { post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

/**
 * Stub the FileReader so `readAsDataURL` synchronously fires `onload`
 * with a fake data URL — the real DOM FileReader works in jsdom but
 * its async timing is irritating to await reliably.
 *
 * @param {string} dataUrl The data URL to feed to `onload`.
 */
function stubFileReader(dataUrl) {
	class FakeFileReader {

		constructor() {
			this.result = null
		}

		readAsDataURL() {
			this.result = dataUrl
			// Resolve on a microtask so test code can await.
			Promise.resolve().then(() => {
				if (this.onload) {
					this.onload({ target: { result: dataUrl } })
				}
			})
		}

	}
	globalThis.FileReader = FakeFileReader
}

/**
 * Wait for the upload pipeline (FileReader → uploadDataUrl → emit).
 *
 * @return {Promise<void>}
 */
async function flushAsync() {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
	}
}

beforeEach(() => {
	axios.post.mockReset()
})

afterEach(() => {
	vi.clearAllMocks()
})

describe('IconPicker — registry select (REQ-ICON-008)', () => {
	it('renders one <option> per DASHBOARD_ICONS entry plus a placeholder', () => {
		const wrapper = mount(IconPicker, {
			propsData: { value: 'Star' },
		})
		const select = wrapper.find('.icon-picker__select')
		expect(select.exists()).toBe(true)
		const options = select.findAll('option')
		expect(options.length).toBeGreaterThan(15)
		const star = options.wrappers.find(o => o.text() === 'Star')
		expect(star).toBeDefined()
		expect(star.element.value).toBe('Star')
	})

	it('emits input with the selected registry key on change', async () => {
		const wrapper = mount(IconPicker, {
			propsData: { value: 'Star' },
		})
		await wrapper.find('.icon-picker__select').setValue('Home')
		expect(wrapper.emitted('input')).toBeTruthy()
		expect(wrapper.emitted('input')[0]).toEqual(['Home'])
	})

	it('renders the 24×24 preview through IconRenderer', () => {
		const wrapper = mount(IconPicker, {
			propsData: { value: 'Star' },
		})
		const preview = wrapper.findComponent({ name: 'IconRenderer' })
		expect(preview.exists()).toBe(true)
		expect(preview.props('name')).toBe('Star')
		expect(preview.props('size')).toBe(24)
	})
})

describe('IconPicker — upload custom URL (REQ-ICON-008)', () => {
	it('renders a file input that accepts images', () => {
		const wrapper = mount(IconPicker, {
			propsData: { value: 'Star' },
		})
		const fileInput = wrapper.find('.icon-picker__file-input')
		expect(fileInput.exists()).toBe(true)
		expect(fileInput.attributes('accept')).toBe('image/*')
	})

	it('uploads the file and emits the returned URL on success', async () => {
		axios.post.mockResolvedValueOnce({
			status: 200,
			data: {
				status: 'success',
				url: '/apps/mydash/resource/abc.png',
				name: 'abc.png',
				size: 12,
			},
		})
		stubFileReader('data:image/png;base64,AAAA')

		const wrapper = mount(IconPicker, {
			propsData: { value: 'Star' },
		})

		const fileInput = wrapper.find('.icon-picker__file-input')
		const file = new File(['image'], 'test.png', { type: 'image/png' })
		Object.defineProperty(fileInput.element, 'files', {
			value: [file],
			writable: false,
		})
		await fileInput.trigger('change')
		await flushAsync()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/mydash/api/resources',
			{ base64: 'data:image/png;base64,AAAA' },
		)
		expect(wrapper.emitted('input')).toBeTruthy()
		expect(wrapper.emitted('input')[0]).toEqual(['/apps/mydash/resource/abc.png'])
	})
})

describe('IconPicker — upload error handling (REQ-ICON-008)', () => {
	it('shows an inline error message and preserves the previous value', async () => {
		axios.post.mockRejectedValueOnce({
			response: {
				status: 413,
				data: {
					status: 'error',
					error: 'file_too_large',
					message: 'File is too large',
				},
			},
		})
		stubFileReader('data:image/png;base64,AAAA')

		const wrapper = mount(IconPicker, {
			propsData: { value: 'Star' },
		})

		const fileInput = wrapper.find('.icon-picker__file-input')
		const file = new File(['image'], 'big.png', { type: 'image/png' })
		Object.defineProperty(fileInput.element, 'files', {
			value: [file],
			writable: false,
		})
		await fileInput.trigger('change')
		await flushAsync()

		// No emit means parent v-model retains the previous value.
		expect(wrapper.emitted('input')).toBeFalsy()

		const error = wrapper.find('.icon-picker__error')
		expect(error.exists()).toBe(true)
		expect(error.text()).toBe('File is too large')

		// The preview is still bound to the (unchanged) parent value.
		expect(wrapper.findComponent({ name: 'IconRenderer' }).props('name')).toBe('Star')
	})

	it('falls back to a generic message when the wrapper has no display text', async () => {
		// Reject with no .response.data — the wrapper synthesises a
		// `network_error` ResourceUploadError whose message is empty;
		// the picker MUST still surface the i18n fallback.
		axios.post.mockRejectedValueOnce({})
		stubFileReader('data:image/png;base64,AAAA')

		const wrapper = mount(IconPicker, {
			propsData: { value: 'Star' },
		})

		const fileInput = wrapper.find('.icon-picker__file-input')
		const file = new File(['image'], 'x.png', { type: 'image/png' })
		Object.defineProperty(fileInput.element, 'files', {
			value: [file],
			writable: false,
		})
		await fileInput.trigger('change')
		await flushAsync()

		// The wrapper builds a 'Network error' message; the picker
		// uses the wrapper's message verbatim when present, falling
		// back to 'Failed to upload icon' otherwise.
		const text = wrapper.find('.icon-picker__error').text()
		expect(text.length).toBeGreaterThan(0)
	})
})

describe('IconPicker — mode switching (REQ-ICON-008)', () => {
	it('switches the preview from svg to img after a successful upload', async () => {
		axios.post.mockResolvedValueOnce({
			status: 200,
			data: {
				status: 'success',
				url: '/apps/mydash/resource/abc.png',
				name: 'abc.png',
				size: 12,
			},
		})
		stubFileReader('data:image/png;base64,AAAA')

		const wrapper = mount(IconPicker, {
			propsData: { value: 'Star' },
		})
		// Initial: built-in icon → svg present, no img.
		expect(wrapper.find('svg').exists()).toBe(true)

		const fileInput = wrapper.find('.icon-picker__file-input')
		const file = new File(['image'], 'test.png', { type: 'image/png' })
		Object.defineProperty(fileInput.element, 'files', {
			value: [file],
			writable: false,
		})
		await fileInput.trigger('change')
		await flushAsync()

		// Parent would normally update v-model from the emit; emulate
		// that by setting the new value through propsData and re-rendering.
		await wrapper.setProps({ value: '/apps/mydash/resource/abc.png' })
		expect(wrapper.find('img').exists()).toBe(true)
	})

	it('switches back from a custom URL to a built-in registry name', async () => {
		const wrapper = mount(IconPicker, {
			propsData: { value: '/apps/mydash/resource/abc.png' },
		})
		// Initial: URL → img.
		expect(wrapper.find('img').exists()).toBe(true)

		await wrapper.find('.icon-picker__select').setValue('Home')
		expect(wrapper.emitted('input')[0]).toEqual(['Home'])

		await wrapper.setProps({ value: 'Home' })
		expect(wrapper.find('svg').exists()).toBe(true)
		expect(wrapper.find('img').exists()).toBe(false)
	})
})
