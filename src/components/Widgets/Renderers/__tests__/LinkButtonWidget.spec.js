/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `LinkButtonWidget.vue` covering REQ-LBN-001
 * (three click branches, edit-mode suppression, disabled-while-in-flight),
 * REQ-LBN-002 (icon resolution), REQ-LBN-005 (internal action lookup),
 * and REQ-LBN-007 (theme-defaulted styling).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import LinkButtonWidget from '../LinkButtonWidget.vue'
import { __resetInternalActionsForTest, useInternalActions } from '../../../../composables/useInternalActions.js'

// Mock the @nextcloud/* runtime helpers — they touch globals (window,
// fetch, OC) that aren't worth setting up in jsdom for these unit tests.
vi.mock('@nextcloud/axios', () => ({
	default: { post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
}))

beforeEach(() => {
	globalThis.t = (_app, key) => key
	__resetInternalActionsForTest()
	vi.clearAllMocks()
})

afterEach(() => {
	__resetInternalActionsForTest()
})

describe('LinkButtonWidget', () => {
	it('REQ-LBN-001: external action opens URL in new tab', () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { actionType: 'external', url: 'https://example.com', label: 'Docs' },
			},
		})

		wrapper.find('button.link-button-widget__button').trigger('click')

		expect(openSpy).toHaveBeenCalledWith('https://example.com', '_blank', 'noopener,noreferrer')
		openSpy.mockRestore()
	})

	it('REQ-LBN-001: edit-mode click is fully suppressed', () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { actionType: 'external', url: 'https://example.com', label: 'Docs' },
				isAdmin: true,
				canEdit: true,
			},
		})

		wrapper.find('button.link-button-widget__button').trigger('click')

		expect(openSpy).not.toHaveBeenCalled()
		expect(wrapper.find('.link-button-widget__modal-backdrop').exists()).toBe(false)
		openSpy.mockRestore()
	})

	it('REQ-LBN-001: internal action invokes registered function exactly once', () => {
		const fn = vi.fn()
		useInternalActions().register('open-talk', fn)

		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { actionType: 'internal', url: 'open-talk', label: 'Talk' },
			},
		})

		wrapper.find('button.link-button-widget__button').trigger('click')

		expect(fn).toHaveBeenCalledTimes(1)
	})

	it('REQ-LBN-005: internal action with unknown id warns but does not throw', () => {
		const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => null)
		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { actionType: 'internal', url: 'does-not-exist', label: 'X' },
			},
		})

		expect(() => wrapper.find('button.link-button-widget__button').trigger('click')).not.toThrow()
		expect(warnSpy).toHaveBeenCalledWith('Unknown internal action: does-not-exist')
		warnSpy.mockRestore()
	})

	it('REQ-LBN-001 + REQ-LBN-003: createFile click opens the inline modal with prefilled name', async () => {
		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { actionType: 'createFile', url: 'docx', label: 'New report' },
			},
		})

		await wrapper.find('button.link-button-widget__button').trigger('click')

		expect(wrapper.find('.link-button-widget__modal-backdrop').exists()).toBe(true)
		const input = wrapper.find('.link-button-widget__modal-input').element
		expect(input.value).toMatch(/^document_\d+$/)
		expect(wrapper.find('.link-button-widget__modal-extension').text()).toBe('.docx')
	})

	it('REQ-LBN-003: empty filename disables Create button', async () => {
		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { actionType: 'createFile', url: 'docx', label: 'X' },
			},
		})

		await wrapper.find('button.link-button-widget__button').trigger('click')
		const input = wrapper.find('.link-button-widget__modal-input')
		await input.setValue('')

		const createBtn = wrapper.find('.link-button-widget__modal-create').element
		expect(createBtn.disabled).toBe(true)
	})

	it('REQ-LBN-003: createFile POSTs and opens result in new tab on 200', async () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		axios.post.mockResolvedValue({
			data: { status: 'success', fileId: 42, url: 'https://nc/index.php/apps/files/?openfile=42' },
		})

		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { actionType: 'createFile', url: 'docx', label: 'X' },
			},
		})

		await wrapper.find('button.link-button-widget__button').trigger('click')
		const input = wrapper.find('.link-button-widget__modal-input')
		await input.setValue('Q4-report')

		await wrapper.find('.link-button-widget__modal-create').trigger('click')
		// Wait for the async axios chain.
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/files/create'),
			{ filename: 'Q4-report.docx', dir: '/', content: '' },
		)
		expect(openSpy).toHaveBeenCalledWith('https://nc/index.php/apps/files/?openfile=42', '_blank')
		openSpy.mockRestore()
	})

	it('REQ-LBN-003: server error surfaces a translated toast', async () => {
		axios.post.mockRejectedValue(new Error('boom'))

		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { actionType: 'createFile', url: 'docx', label: 'X' },
			},
		})

		await wrapper.find('button.link-button-widget__button').trigger('click')
		await wrapper.find('.link-button-widget__modal-input').setValue('foo')
		await wrapper.find('.link-button-widget__modal-create').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(showError).toHaveBeenCalledWith('Failed to create document')
	})

	it('REQ-LBN-001: button is disabled while a createFile action is in flight', async () => {
		// A pending promise that never resolves — the button must stay disabled.
		let resolveLater = () => null
		axios.post.mockImplementation(() => new Promise((resolve) => {
			resolveLater = resolve
		}))

		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { actionType: 'createFile', url: 'docx', label: 'X' },
			},
		})

		await wrapper.find('button.link-button-widget__button').trigger('click')
		await wrapper.find('.link-button-widget__modal-input').setValue('foo')
		await wrapper.find('.link-button-widget__modal-create').trigger('click')
		// Allow dynamic imports + setState to flush.
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		const mainBtn = wrapper.find('button.link-button-widget__button').element
		expect(mainBtn.disabled).toBe(true)

		// Cleanup so the promise resolves and Vitest is not haunted by it.
		resolveLater({ data: { status: 'error' } })
		await new Promise((resolve) => setTimeout(resolve, 0))
	})

	it('REQ-LBN-002: custom URL icon renders <img> 48px', () => {
		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { icon: '/apps/mydash/resource/x.png', label: 'Open', url: 'https://e', actionType: 'external' },
			},
			stubs: { IconRenderer: true },
		})

		const img = wrapper.find('.link-button-widget__icon img')
		expect(img.exists()).toBe(true)
		expect(img.attributes('src')).toBe('/apps/mydash/resource/x.png')
		expect(img.attributes('width')).toBe('48')
		expect(img.attributes('height')).toBe('48')
	})

	it('REQ-LBN-002: empty icon renders no <img> or icon component', () => {
		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { icon: '', label: 'Click me', url: 'https://e', actionType: 'external' },
			},
			stubs: { IconRenderer: true },
		})

		expect(wrapper.find('.link-button-widget__icon').exists()).toBe(false)
		expect(wrapper.find('.link-button-widget__icon img').exists()).toBe(false)
	})

	it('REQ-LBN-007: empty colour fields default to theme primary CSS vars', () => {
		const wrapper = mount(LinkButtonWidget, {
			propsData: {
				content: { label: 'X', url: 'y', actionType: 'external', backgroundColor: '', textColor: '' },
			},
			stubs: { IconRenderer: true },
		})

		const style = wrapper.find('button.link-button-widget__button').attributes('style') || ''
		expect(style).toContain('background-color: var(--color-primary)')
		expect(style).toContain('color: var(--color-primary-text)')
	})
})
