/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for the orgNavigation Pinia store
 * (REQ-ONAV-002, REQ-ONAV-004, REQ-ONAV-008).
 *
 * The api module is mocked at the import boundary so no HTTP traffic
 * is generated. Each test calls a single store action and asserts
 * the resulting state mutation.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

vi.mock('../../services/api.js', () => ({
	api: {
		getOrgNavigation: vi.fn(),
		updateOrgNavigation: vi.fn(),
		getOrgNavigationPosition: vi.fn(),
		updateOrgNavigationPosition: vi.fn(),
	},
}))

let mockApi

beforeEach(async () => {
	setActivePinia(createPinia())
	const mod = await import('../../services/api.js')
	mockApi = mod.api
	for (const fn of Object.values(mockApi)) {
		if (typeof fn?.mockReset === 'function') {
			fn.mockReset()
		}
	}
})

describe('useOrgNavigationStore', () => {
	it('REQ-ONAV-002: fetchTree stores the filtered tree returned by the API', async () => {
		const { useOrgNavigationStore } = await import('../orgNavigation.js')
		mockApi.getOrgNavigation.mockResolvedValue({
			data: { tree: [{ id: 'a', label: 'A' }], language: 'nl' },
		})

		const store = useOrgNavigationStore()
		await store.fetchTree('nl')

		expect(mockApi.getOrgNavigation).toHaveBeenCalledWith('nl')
		expect(store.tree).toEqual([{ id: 'a', label: 'A' }])
		expect(store.language).toBe('nl')
		expect(store.error).toBeNull()
	})

	it('REQ-ONAV-008: isEmpty is true when the tree is empty', async () => {
		const { useOrgNavigationStore } = await import('../orgNavigation.js')
		const store = useOrgNavigationStore()
		expect(store.isEmpty).toBe(true)
		store.tree = [{ id: 'x' }]
		expect(store.isEmpty).toBe(false)
	})

	it('REQ-ONAV-008: shouldRender is false when position is hidden', async () => {
		const { useOrgNavigationStore } = await import('../orgNavigation.js')
		const store = useOrgNavigationStore()
		store.tree = [{ id: 'x' }]
		store.position = 'hidden'
		expect(store.shouldRender).toBe(false)
		store.position = 'left'
		expect(store.shouldRender).toBe(true)
	})

	it('REQ-ONAV-008: shouldRender is false when tree is empty even with position=left', async () => {
		const { useOrgNavigationStore } = await import('../orgNavigation.js')
		const store = useOrgNavigationStore()
		store.tree = []
		store.position = 'left'
		expect(store.shouldRender).toBe(false)
	})

	it('REQ-ONAV-003: updateTree returns true on success and updates state', async () => {
		const { useOrgNavigationStore } = await import('../orgNavigation.js')
		mockApi.updateOrgNavigation.mockResolvedValue({
			data: { tree: [{ id: 'b', label: 'B' }], language: 'en' },
		})

		const store = useOrgNavigationStore()
		const ok = await store.updateTree([{ id: 'b', label: 'B' }], 'en')

		expect(ok).toBe(true)
		expect(mockApi.updateOrgNavigation).toHaveBeenCalledWith([{ id: 'b', label: 'B' }], 'en')
		expect(store.tree).toEqual([{ id: 'b', label: 'B' }])
		expect(store.language).toBe('en')
	})

	it('REQ-ONAV-003: updateTree surfaces backend error on failure', async () => {
		const { useOrgNavigationStore } = await import('../orgNavigation.js')
		const error = new Error('boom')
		error.response = { data: { error: 'Tree depth cannot exceed 3 levels' } }
		mockApi.updateOrgNavigation.mockRejectedValue(error)

		const store = useOrgNavigationStore()
		const ok = await store.updateTree([], 'nl')

		expect(ok).toBe(false)
		expect(store.error).toBe('Tree depth cannot exceed 3 levels')
	})

	it('REQ-ONAV-004: fetchPosition stores the value when valid', async () => {
		const { useOrgNavigationStore } = await import('../orgNavigation.js')
		mockApi.getOrgNavigationPosition.mockResolvedValue({ data: { position: 'left' } })

		const store = useOrgNavigationStore()
		await store.fetchPosition()

		expect(store.position).toBe('left')
	})

	it('REQ-ONAV-004: updatePosition rejects unknown values and does not call the API', async () => {
		const { useOrgNavigationStore } = await import('../orgNavigation.js')
		const store = useOrgNavigationStore()

		const ok = await store.updatePosition('sideways')

		expect(ok).toBe(false)
		expect(mockApi.updateOrgNavigationPosition).not.toHaveBeenCalled()
		expect(store.error).toBe('Unsupported position')
	})

	it('REQ-ONAV-004: updatePosition persists accepted values', async () => {
		const { useOrgNavigationStore } = await import('../orgNavigation.js')
		mockApi.updateOrgNavigationPosition.mockResolvedValue({ data: { position: 'top' } })

		const store = useOrgNavigationStore()
		const ok = await store.updatePosition('top')

		expect(ok).toBe(true)
		expect(store.position).toBe('top')
	})
})
