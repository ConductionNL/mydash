/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `FilesWidget.vue` covering REQ-FLS-003
 * (folder listing fetch + render), REQ-FLS-004 (no_access state),
 * REQ-FLS-005 (breadcrumb + folder navigation), REQ-FLS-006
 * (deep-link to Files app), REQ-FLS-008 (delete-button gating),
 * REQ-FLS-009 (folder_not_found state), and REQ-FLS-011 (in-widget
 * client-side search).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import FilesWidget from '../FilesWidget.vue'

// Vue 2's @vue/test-utils does not export `flushPromises`. The
// `setImmediate` shim below drains the microtask queue (which covers
// both the dynamic `import()` calls inside the widget AND the
// chained `.then(...)` handlers waiting on the mocked axios call).
async function flushPromises() {
	await new Promise((resolve) => setTimeout(resolve, 0))
	await new Promise((resolve) => setTimeout(resolve, 0))
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		delete: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => Object.entries(params).reduce(
		(acc, [key, value]) => acc.replace(`{${key}}`, value),
		path,
	),
}))

const SAMPLE_ITEMS = [
	{
		fileId: 1,
		name: 'budget.pdf',
		path: 'budget.pdf',
		mimeType: 'application/pdf',
		size: 12345,
		modifiedAt: '2026-01-01T12:00:00Z',
		isFolder: false,
		thumbnailUrl: null,
		canEdit: true,
		canDelete: true,
	},
	{
		fileId: 2,
		name: 'images',
		path: 'images',
		mimeType: 'httpd/unix-directory',
		size: 0,
		modifiedAt: '2026-01-02T12:00:00Z',
		isFolder: true,
		thumbnailUrl: null,
		canEdit: true,
		canDelete: false,
	},
]

beforeEach(() => {
	globalThis.t = (_app, key, vars) => {
		if (!vars) {
			return key
		}
		return Object.entries(vars).reduce(
			(acc, [varName, value]) => acc.replace(`{${varName}}`, value),
			key,
		)
	}
	vi.clearAllMocks()
})

afterEach(() => {
	vi.clearAllMocks()
})

describe('FilesWidget', () => {
	it('REQ-FLS-003: fetches contents on mount and renders rows', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: {
				placement: { id: 7 },
				content: { folderPath: '/Documents' },
			},
		})
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		const rows = wrapper.findAll('.files-widget__row')
		expect(rows.length).toBe(2)
		expect(wrapper.text()).toContain('budget.pdf')
		expect(wrapper.text()).toContain('images')
	})

	it('REQ-FLS-009: shows folder_not_found state on HTTP 404', async () => {
		axios.get.mockRejectedValue({
			response: { status: 404, data: { error: 'folder_not_found' } },
		})

		const wrapper = mount(FilesWidget, {
			propsData: { placement: { id: 7 }, content: { folderPath: '/x' } },
		})
		await flushPromises()

		expect(wrapper.find('.files-widget__state--not-found').exists()).toBe(true)
		expect(wrapper.text()).toContain('Folder no longer exists.')
	})

	it('REQ-FLS-004: shows no_access state on HTTP 403', async () => {
		axios.get.mockRejectedValue({
			response: { status: 403, data: { error: 'no_access' } },
		})

		const wrapper = mount(FilesWidget, {
			propsData: { placement: { id: 7 }, content: { folderPath: '/x' } },
		})
		await flushPromises()

		expect(wrapper.find('.files-widget__state--no-access').exists()).toBe(true)
		expect(wrapper.text()).toContain("You don't have access to this folder.")
	})

	it('REQ-FLS-006: clicking a file opens the Files app deep link in a new tab', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)

		const wrapper = mount(FilesWidget, {
			propsData: { placement: { id: 7 }, content: { folderPath: '/x' } },
		})
		await flushPromises()

		await wrapper.findAll('.files-widget__row-name').at(0).trigger('click')
		expect(openSpy).toHaveBeenCalledWith('/apps/files/?fileid=1', '_blank', 'noopener,noreferrer')
		openSpy.mockRestore()
	})

	it('REQ-FLS-005: clicking a folder updates the breadcrumb and refetches contents', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: { placement: { id: 7 }, content: { folderPath: '/x' } },
		})
		await flushPromises()

		// Folder click — second item is the `images` folder.
		await wrapper.findAll('.files-widget__row-name').at(1).trigger('click')
		await flushPromises()

		expect(wrapper.vm.currentSubPath).toBe('/images')
		expect(axios.get).toHaveBeenCalledTimes(2)
		// Breadcrumb should now show the new segment.
		expect(wrapper.text()).toContain('images')
	})

	it('REQ-FLS-005: clicking a breadcrumb segment navigates back up', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: { placement: { id: 7 }, content: { folderPath: '/x' } },
		})
		await flushPromises()
		wrapper.vm.currentSubPath = '/a/b/c'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.pathSegments).toEqual(['a', 'b', 'c'])

		// Click on the root crumb to go back to the configured root.
		await wrapper.find('.files-widget__crumb--root').trigger('click')
		await flushPromises()
		expect(wrapper.vm.currentSubPath).toBe('/')
	})

	it('REQ-FLS-008: delete button only renders when allowDelete AND canDelete are true', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: {
				placement: { id: 7 },
				content: { folderPath: '/x', allowDelete: true },
			},
		})
		await flushPromises()

		const deleteButtons = wrapper.findAll('.files-widget__row-delete')
		// Only the first (file) item has canDelete=true; the folder
		// has canDelete=false so its row should not show the button.
		expect(deleteButtons.length).toBe(1)
	})

	it('REQ-FLS-008: delete button is hidden when allowDelete is false', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: {
				placement: { id: 7 },
				content: { folderPath: '/x', allowDelete: false },
			},
		})
		await flushPromises()

		expect(wrapper.findAll('.files-widget__row-delete').length).toBe(0)
	})

	it('REQ-FLS-008: confirm modal appears on delete click and DELETE is sent on confirm', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})
		axios.delete.mockResolvedValue({ data: { status: 'success', fileId: 1 } })

		const wrapper = mount(FilesWidget, {
			propsData: {
				placement: { id: 7 },
				content: { folderPath: '/x', allowDelete: true },
			},
		})
		await flushPromises()

		await wrapper.find('.files-widget__row-delete').trigger('click')
		expect(wrapper.find('.files-widget__modal').exists()).toBe(true)

		await wrapper.find('.files-widget__modal-confirm').trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith('/apps/mydash/api/widgets/files/7/files/1')
		// Optimistic remove — file should be gone from the listing.
		expect(wrapper.vm.items.find((item) => item.fileId === 1)).toBeUndefined()
	})

	it('REQ-FLS-011: client-side search filters items by substring (case-insensitive)', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: { placement: { id: 7 }, content: { folderPath: '/x' } },
		})
		await flushPromises()

		wrapper.vm.searchQuery = 'BUDGET'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.filteredItems.length).toBe(1)
		expect(wrapper.vm.filteredItems[0].name).toBe('budget.pdf')
	})

	it('REQ-FLS-011: empty folder shows empty-state copy', async () => {
		axios.get.mockResolvedValue({
			data: { items: [], nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: { placement: { id: 7 }, content: { folderPath: '/x' } },
		})
		await flushPromises()

		expect(wrapper.find('.files-widget__state--empty').exists()).toBe(true)
		expect(wrapper.text()).toContain('This folder is empty.')
	})

	it('REQ-FLS-011: empty search results show "No files matching" message', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: { placement: { id: 7 }, content: { folderPath: '/x' } },
		})
		await flushPromises()

		wrapper.vm.searchQuery = 'invoice'
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain("No files matching 'invoice'")
	})

	it('REQ-FLS-007: upload button hidden when allowUpload is false', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: {
				placement: { id: 7 },
				content: { folderPath: '/x', allowUpload: false },
			},
		})
		await flushPromises()

		expect(wrapper.find('.files-widget__upload').exists()).toBe(false)
	})

	it('REQ-FLS-007: upload button visible when allowUpload + viewer can write', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: {
				placement: { id: 7 },
				content: { folderPath: '/x', allowUpload: true },
			},
		})
		await flushPromises()

		expect(wrapper.find('.files-widget__upload').exists()).toBe(true)
	})

	it('REQ-FLS-005: navigating resets the search query', async () => {
		axios.get.mockResolvedValue({
			data: { items: SAMPLE_ITEMS, nextCursor: null },
		})

		const wrapper = mount(FilesWidget, {
			propsData: { placement: { id: 7 }, content: { folderPath: '/x' } },
		})
		await flushPromises()

		wrapper.vm.searchQuery = 'budget'
		wrapper.vm.navigateTo('/sub')
		await flushPromises()

		expect(wrapper.vm.searchQuery).toBe('')
	})
})
