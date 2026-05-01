/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/* eslint-disable n/no-unpublished-import */
import { describe, it, expect, beforeAll, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
/* eslint-enable n/no-unpublished-import */

import LinkButtonWidget from '../components/Widgets/Renderers/LinkButtonWidget.vue'

beforeAll(() => {
	if (typeof globalThis.t !== 'function') {
		globalThis.t = (_app, key) => key
	}
})

afterEach(() => {
	vi.restoreAllMocks()
})

// ─── Stubs ────────────────────────────────────────────────────────────────────

// Stub IconRenderer so tests don't need dashboardIcons
const IconRendererStub = {
	name: 'IconRenderer',
	props: ['name', 'size'],
	template: '<span class="icon-stub">{{ name }}</span>',
}

function makeWidget(overrides = {}) {
	return {
		content: {
			label: 'Click me',
			url: 'https://example.com',
			actionType: 'external',
			icon: '',
			backgroundColor: '',
			textColor: '',
			...overrides,
		},
	}
}

function mountWidget(contentOverrides = {}, propOverrides = {}) {
	return mount(LinkButtonWidget, {
		propsData: {
			widget: makeWidget(contentOverrides),
			...propOverrides,
		},
		stubs: {
			IconRenderer: IconRendererStub,
		},
		attachTo: document.body,
	})
}

// ─── Three click branches ─────────────────────────────────────────────────────

describe('LinkButtonWidget — external click branch (REQ-LBN-001)', () => {
	it('calls window.open with noopener,noreferrer on external click', async () => {
		const openMock = vi.fn()
		global.window.open = openMock

		const wrapper = mountWidget({ actionType: 'external', url: 'https://example.com' })
		wrapper.vm.handleClick()
		await wrapper.vm.$nextTick()

		expect(openMock).toHaveBeenCalledWith(
			'https://example.com',
			'_blank',
			'noopener,noreferrer',
		)

		wrapper.destroy()
	})
})

describe('LinkButtonWidget — internal click branch (REQ-LBN-001)', () => {
	it('invokes the registered action on internal click', async () => {
		const { useInternalActions } = await import('../composables/useInternalActions.js')
		const fn = vi.fn()
		useInternalActions().register('test-action-2', fn)

		const wrapper = mountWidget({ actionType: 'internal', url: 'test-action-2' })
		wrapper.vm.handleClick()
		await wrapper.vm.$nextTick()

		expect(fn).toHaveBeenCalledOnce()
		wrapper.destroy()
	})

	it('warns but does not throw when action ID is unknown', async () => {
		const warnMock = vi.spyOn(console, 'warn').mockImplementation(() => {})

		const wrapper = mountWidget({ actionType: 'internal', url: 'does-not-exist-xyz' })
		expect(() => {
			wrapper.vm.handleClick()
		}).not.toThrow()

		expect(warnMock).toHaveBeenCalledWith(
			expect.stringContaining('does-not-exist-xyz'),
		)

		wrapper.destroy()
	})
})

describe('LinkButtonWidget — createFile click branch (REQ-LBN-001, REQ-LBN-003)', () => {
	it('opens the filename modal on createFile click', async () => {
		const wrapper = mountWidget({ actionType: 'createFile', url: 'docx' })

		expect(wrapper.find('.link-button-widget__modal-backdrop').exists()).toBe(false)

		wrapper.vm.handleClick()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.showDocModal).toBe(true)
		expect(wrapper.find('.link-button-widget__modal-backdrop').exists()).toBe(true)

		wrapper.destroy()
	})

	it('posts to /api/files/create and opens result URL on submit', async () => {
		const openMock = vi.fn()
		global.window.open = openMock

		global.fetch = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				status: 'success',
				fileId: 42,
				url: 'https://nc/index.php/apps/files/?openfile=42',
			}),
		})

		const wrapper = mountWidget({ actionType: 'createFile', url: 'docx' })

		// Open modal via direct method
		wrapper.vm.handleClick()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showDocModal).toBe(true)

		// Set a name and submit
		wrapper.vm.docName = 'Q4-report'
		await wrapper.vm.submitCreate()
		await wrapper.vm.$nextTick()

		expect(global.fetch).toHaveBeenCalledWith(
			expect.stringContaining('files/create'),
			expect.objectContaining({ method: 'POST' }),
		)
		expect(openMock).toHaveBeenCalledWith(
			'https://nc/index.php/apps/files/?openfile=42',
			'_blank',
		)
		expect(wrapper.vm.showDocModal).toBe(false)

		wrapper.destroy()
	})

	it('shows error when POST fails', async () => {
		global.fetch = vi.fn().mockResolvedValue({
			ok: false,
			json: async () => ({ status: 'error', error: 'invalid_filename', message: 'bad' }),
		})

		const wrapper = mountWidget({ actionType: 'createFile', url: 'docx' })

		wrapper.vm.showDocModal = true
		wrapper.vm.docName = 'bad'
		await wrapper.vm.submitCreate()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.createError).toBe(true)
		wrapper.destroy()
	})

	it('does not submit when filename is empty', async () => {
		global.fetch = vi.fn()

		const wrapper = mountWidget({ actionType: 'createFile', url: 'docx' })

		wrapper.vm.showDocModal = true
		wrapper.vm.docName = ''
		await wrapper.vm.submitCreate()

		expect(global.fetch).not.toHaveBeenCalled()
		wrapper.destroy()
	})

	it('Create button is disabled when filename is empty', async () => {
		const wrapper = mountWidget({ actionType: 'createFile', url: 'docx' })

		wrapper.vm.showDocModal = true
		wrapper.vm.docName = ''
		await wrapper.vm.$nextTick()

		const createBtn = wrapper.find('.link-button-widget__modal-create')
		expect(createBtn.exists()).toBe(true)
		expect(createBtn.element.disabled).toBe(true)

		wrapper.destroy()
	})
})

// ─── Admin-mode suppression ───────────────────────────────────────────────────

describe('LinkButtonWidget — admin-mode suppression (REQ-LBN-001)', () => {
	it('suppresses external click when isAdmin is true', async () => {
		const openMock = vi.fn()
		global.window.open = openMock

		const wrapper = mountWidget(
			{ actionType: 'external', url: 'https://example.com' },
			{ isAdmin: true },
		)

		wrapper.vm.handleClick()
		expect(openMock).not.toHaveBeenCalled()

		wrapper.destroy()
	})

	it('suppresses createFile modal when isAdmin is true', async () => {
		const wrapper = mountWidget(
			{ actionType: 'createFile', url: 'docx' },
			{ isAdmin: true },
		)

		wrapper.vm.handleClick()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.showDocModal).toBe(false)
		wrapper.destroy()
	})
})

// ─── Disabled while executing ─────────────────────────────────────────────────

describe('LinkButtonWidget — disabled while executing (REQ-LBN-001)', () => {
	it('button carries disabled attribute while isExecuting is true', async () => {
		const wrapper = mountWidget()
		wrapper.vm.isExecuting = true
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.link-button-widget__btn').element.disabled).toBe(true)
		wrapper.destroy()
	})

	it('button carries disabled attribute while creatingDoc is true', async () => {
		const wrapper = mountWidget()
		wrapper.vm.creatingDoc = true
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.link-button-widget__btn').element.disabled).toBe(true)
		wrapper.destroy()
	})
})

// ─── Default colour fallback ──────────────────────────────────────────────────

describe('LinkButtonWidget — default colours (REQ-LBN-007)', () => {
	it('uses var(--color-primary) as default background', () => {
		const wrapper = mountWidget({ backgroundColor: '', textColor: '' })
		const style = wrapper.vm.buttonStyle
		expect(style.backgroundColor).toBe('var(--color-primary)')
		wrapper.destroy()
	})

	it('uses var(--color-primary-text) as default text colour', () => {
		const wrapper = mountWidget({ backgroundColor: '', textColor: '' })
		const style = wrapper.vm.buttonStyle
		expect(style.color).toBe('var(--color-primary-text)')
		wrapper.destroy()
	})

	it('respects custom colours when provided', () => {
		const wrapper = mountWidget({ backgroundColor: '#ff0000', textColor: '#ffffff' })
		const style = wrapper.vm.buttonStyle
		expect(style.backgroundColor).toBe('#ff0000')
		expect(style.color).toBe('#ffffff')
		wrapper.destroy()
	})
})

// ─── Icon resolution (REQ-LBN-002) ───────────────────────────────────────────

describe('LinkButtonWidget — icon rendering (REQ-LBN-002)', () => {
	it('renders IconRenderer when icon is non-empty', () => {
		const wrapper = mountWidget({ icon: 'Star' })
		expect(wrapper.find('.icon-stub').exists()).toBe(true)
		wrapper.destroy()
	})

	it('does not render IconRenderer when icon is empty', () => {
		const wrapper = mountWidget({ icon: '' })
		expect(wrapper.find('.icon-stub').exists()).toBe(false)
		wrapper.destroy()
	})
})
