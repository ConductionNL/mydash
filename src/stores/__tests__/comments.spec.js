/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for the dashboard-comments Pinia store
 * (REQ-CMNT-001..005). Mocks the api module so the store exercises its
 * cache + tree-merge logic without hitting the network.
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
		listDashboardComments: vi.fn(),
		createDashboardComment: vi.fn(),
		updateDashboardComment: vi.fn(),
		deleteDashboardComment: vi.fn(),
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

describe('useCommentsStore — load + cache', () => {
	it('loadComments stores the {enabled, comments} envelope keyed by uuid', async () => {
		const { useCommentsStore } = await import('../comments.js')
		mockApi.listDashboardComments.mockResolvedValueOnce({
			data: { enabled: true, comments: [{ id: 1, parentId: null, message: 'hi', replies: [] }] },
		})
		const store = useCommentsStore()
		await store.loadComments('dash-001')
		expect(store.threadFor('dash-001').enabled).toBe(true)
		expect(store.threadFor('dash-001').comments).toHaveLength(1)
		expect(store.isLoaded('dash-001')).toBe(true)
		expect(store.isLoaded('dash-002')).toBe(false)
	})

	it('threadFor returns the empty default for unknown dashboards', async () => {
		const { useCommentsStore } = await import('../comments.js')
		const store = useCommentsStore()
		expect(store.threadFor('never-loaded')).toEqual({ enabled: true, comments: [] })
	})
})

describe('useCommentsStore — create', () => {
	it('prepends new top-level comments to the thread', async () => {
		const { useCommentsStore } = await import('../comments.js')
		mockApi.listDashboardComments.mockResolvedValueOnce({
			data: { enabled: true, comments: [{ id: 1, parentId: null, message: 'old', replies: [] }] },
		})
		mockApi.createDashboardComment.mockResolvedValueOnce({
			data: { id: 2, parentId: null, message: 'new' },
		})
		const store = useCommentsStore()
		await store.loadComments('dash-001')
		await store.createComment('dash-001', { message: 'new' })
		const list = store.threadFor('dash-001').comments
		expect(list[0].id).toBe(2)
		expect(list[1].id).toBe(1)
	})

	it('attaches a reply under the matching parent comment', async () => {
		const { useCommentsStore } = await import('../comments.js')
		mockApi.listDashboardComments.mockResolvedValueOnce({
			data: { enabled: true, comments: [{ id: 1, parentId: null, message: 'top', replies: [] }] },
		})
		mockApi.createDashboardComment.mockResolvedValueOnce({
			data: { id: 2, parentId: 1, message: 'reply' },
		})
		const store = useCommentsStore()
		await store.loadComments('dash-001')
		await store.createComment('dash-001', { message: 'reply', parentId: 1 })
		const top = store.threadFor('dash-001').comments[0]
		expect(top.replies).toHaveLength(1)
		expect(top.replies[0].id).toBe(2)
	})
})

describe('useCommentsStore — update + delete', () => {
	it('updateComment merges fields onto the matching entry', async () => {
		const { useCommentsStore } = await import('../comments.js')
		mockApi.listDashboardComments.mockResolvedValueOnce({
			data: {
				enabled: true,
				comments: [
					{ id: 1, parentId: null, message: 'old', replies: [{ id: 11, parentId: 1, message: 'r' }] },
				],
			},
		})
		mockApi.updateDashboardComment.mockResolvedValueOnce({
			data: { id: 11, parentId: 1, message: 'edited', wasEdited: true },
		})
		const store = useCommentsStore()
		await store.loadComments('dash-001')
		await store.updateComment('dash-001', 11, { message: 'edited' })
		const reply = store.threadFor('dash-001').comments[0].replies[0]
		expect(reply.message).toBe('edited')
		expect(reply.wasEdited).toBe(true)
	})

	it('deleteComment removes the entry and any replies for top-level deletes', async () => {
		const { useCommentsStore } = await import('../comments.js')
		mockApi.listDashboardComments.mockResolvedValueOnce({
			data: {
				enabled: true,
				comments: [
					{ id: 1, parentId: null, message: 'top', replies: [{ id: 11, parentId: 1, message: 'r' }] },
					{ id: 2, parentId: null, message: 'other', replies: [] },
				],
			},
		})
		mockApi.deleteDashboardComment.mockResolvedValueOnce({ status: 204 })
		const store = useCommentsStore()
		await store.loadComments('dash-001')
		await store.deleteComment('dash-001', 1)
		const list = store.threadFor('dash-001').comments
		expect(list).toHaveLength(1)
		expect(list[0].id).toBe(2)
	})
})
