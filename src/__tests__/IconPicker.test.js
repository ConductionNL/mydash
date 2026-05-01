/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/* eslint-disable n/no-unpublished-import */
import { describe, it, expect, beforeAll, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
/* eslint-enable n/no-unpublished-import */

import IconPicker from '../components/Dashboard/IconPicker.vue'

beforeAll(() => {
	// Stub the Nextcloud `t` global with an identity function so components
	// render during tests without depending on @nextcloud/l10n.
	if (typeof globalThis.t !== 'function') {
		globalThis.t = (_app, key) => key
	}
})

afterEach(() => {
	vi.clearAllMocks()
})

describe('IconPicker — Select built-in icon (REQ-ICON-008)', () => {
	it('renders select with registry options from Object.keys(DASHBOARD_ICONS)', () => {
		const wrapper = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})
		const select = wrapper.find('.icon-picker__select')
		expect(select.exists()).toBe(true)
		const options = select.findAll('option')
		// First option is the disabled placeholder
		expect(options.length).toBeGreaterThan(1)
		// Find an option with text 'Star'
		const starOption = options.wrappers.find(opt => opt.text() === 'Star')
		expect(starOption).toBeDefined()
		expect(starOption.element.value).toBe('Star')
	})

	it('emits input event when select changes', async () => {
		const wrapper = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})
		const select = wrapper.find('.icon-picker__select')
		// Change to Home
		await select.setValue('Home')
		expect(wrapper.emitted('input')).toBeTruthy()
		expect(wrapper.emitted('input')[0]).toEqual(['Home'])
	})

	it('renders 24×24 preview via IconRenderer', () => {
		const wrapper = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})
		const preview = wrapper.findComponent({ name: 'IconRenderer' })
		expect(preview.exists()).toBe(true)
		expect(preview.props('name')).toBe('Star')
		expect(preview.props('size')).toBe(24)
	})
})

describe('IconPicker — Upload custom URL (REQ-ICON-008)', () => {
	it('renders file input with accept="image/*"', () => {
		const wrapper = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})
		const fileInput = wrapper.find('.icon-picker__file-input')
		expect(fileInput.exists()).toBe(true)
		expect(fileInput.element.accept).toBe('image/*')
	})

	it('uploads file and emits URL on success', async () => {
		global.fetch = vi.fn(() =>
			Promise.resolve({
				ok: true,
				json: () => Promise.resolve({ url: '/apps/mydash/resource/abc.png' }),
			}),
		)

		const wrapper = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})

		const fileInput = wrapper.find('.icon-picker__file-input')
		const file = new File(['image'], 'test.png', { type: 'image/png' })

		// Manually trigger file select
		Object.defineProperty(fileInput.element, 'files', {
			value: [file],
			writable: false,
		})
		await fileInput.trigger('change')
		// Wait for async operations to complete
		await new Promise(resolve => setTimeout(resolve, 50))

		expect(wrapper.emitted('input')).toBeTruthy()
		expect(wrapper.emitted('input')[0]).toEqual(['/apps/mydash/resource/abc.png'])
	})

	it('clears error on successful upload', async () => {
		global.fetch = vi.fn()
			.mockImplementationOnce(() =>
				Promise.resolve({
					ok: false,
					status: 500,
				}),
			)
			.mockImplementationOnce(() =>
				Promise.resolve({
					ok: true,
					json: () => Promise.resolve({ url: '/apps/mydash/resource/abc.png' }),
				}),
			)

		const wrapper = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})

		const fileInput = wrapper.find('.icon-picker__file-input')
		const file1 = new File(['image'], 'test.png', { type: 'image/png' })

		// First upload fails
		Object.defineProperty(fileInput.element, 'files', {
			value: [file1],
			writable: false,
		})
		await fileInput.trigger('change')
		// Wait for async operations to complete
		await new Promise(resolve => setTimeout(resolve, 50))
		expect(wrapper.find('.icon-picker__error').exists()).toBe(true)

		// Create a new wrapper instance for the second upload to avoid redefining files
		const wrapper2 = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})

		const fileInput2 = wrapper2.find('.icon-picker__file-input')
		const file2 = new File(['image'], 'test2.png', { type: 'image/png' })
		Object.defineProperty(fileInput2.element, 'files', {
			value: [file2],
			writable: false,
		})
		await fileInput2.trigger('change')
		// Wait for async operations to complete
		await new Promise(resolve => setTimeout(resolve, 50))
		expect(wrapper2.find('.icon-picker__error').exists()).toBe(false)
	})
})

describe('IconPicker — Upload error handling (REQ-ICON-008)', () => {
	it('shows error message on upload failure', async () => {
		global.fetch = vi.fn(() =>
			Promise.resolve({
				ok: false,
				status: 500,
			}),
		)

		const wrapper = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})

		const fileInput = wrapper.find('.icon-picker__file-input')
		const file = new File(['image'], 'test.png', { type: 'image/png' })

		Object.defineProperty(fileInput.element, 'files', {
			value: [file],
			writable: false,
		})
		await fileInput.trigger('change')
		// Wait for async operations to complete
		await new Promise(resolve => setTimeout(resolve, 50))

		const errorMsg = wrapper.find('.icon-picker__error')
		expect(errorMsg.exists()).toBe(true)
		expect(errorMsg.text()).toBe('Failed to upload icon')
	})

	it('preserves previous value on upload error', async () => {
		global.fetch = vi.fn(() =>
			Promise.resolve({
				ok: false,
				status: 500,
			}),
		)

		const wrapper = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})

		const fileInput = wrapper.find('.icon-picker__file-input')
		const file = new File(['image'], 'test.png', { type: 'image/png' })

		Object.defineProperty(fileInput.element, 'files', {
			value: [file],
			writable: false,
		})
		await fileInput.trigger('change')
		// Wait for async operations to complete
		await new Promise(resolve => setTimeout(resolve, 50))

		// Should not have emitted input on failure
		expect(wrapper.emitted('input')).toBeFalsy()
		// Preview should still show Star
		expect(wrapper.findComponent({ name: 'IconRenderer' }).props('name')).toBe('Star')
	})
})

describe('IconPicker — Mode switching (REQ-ICON-008)', () => {
	it('switches from built-in to custom URL', async () => {
		global.fetch = vi.fn(() =>
			Promise.resolve({
				ok: true,
				json: () => Promise.resolve({ url: '/apps/mydash/resource/abc.png' }),
			}),
		)

		const wrapper = mount(IconPicker, {
			propsData: {
				value: 'Star',
			},
		})

		// Verify initial built-in render
		const preview = wrapper.findComponent({ name: 'IconRenderer' })
		expect(preview.props('name')).toBe('Star')

		// Upload a file
		const fileInput = wrapper.find('.icon-picker__file-input')
		const file = new File(['image'], 'test.png', { type: 'image/png' })

		Object.defineProperty(fileInput.element, 'files', {
			value: [file],
			writable: false,
		})
		await fileInput.trigger('change')
		// Wait for async operations to complete
		await new Promise(resolve => setTimeout(resolve, 50))

		// Verify preview now shows URL
		expect(wrapper.emitted('input')[0]).toEqual(['/apps/mydash/resource/abc.png'])
	})

	it('switches from custom URL back to built-in', async () => {
		const wrapper = mount(IconPicker, {
			propsData: {
				value: '/apps/mydash/resource/abc.png',
			},
		})

		// Verify initial URL render
		const preview = wrapper.findComponent({ name: 'IconRenderer' })
		expect(preview.props('name')).toBe('/apps/mydash/resource/abc.png')

		// Select a built-in icon
		const select = wrapper.find('.icon-picker__select')
		await select.setValue('Home')

		// Verify preview now shows built-in
		expect(wrapper.emitted('input')[0]).toEqual(['Home'])
	})
})
