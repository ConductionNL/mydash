/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for the Pinia dashboard store. Covers the new
 * source-aware getters added by the multi-scope-dashboards change:
 *  - `userDashboards` filters to `source === 'user'` (REQ-DASH-013)
 *  - `groupSharedDashboards` filters to `source === 'group'` (REQ-DASH-014)
 *  - `defaultGroupDashboards` filters to `source === 'default'` (REQ-DASH-012)
 *
 * The api module is mocked so `loadDashboards` exercises the
 * `/api/dashboards/visible` happy path and the legacy fallback path.
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
		getDashboards: vi.fn(),
		getVisibleDashboards: vi.fn(),
		getActiveDashboard: vi.fn(),
		createDashboard: vi.fn(),
		updateDashboard: vi.fn(),
		deleteDashboard: vi.fn(),
		activateDashboard: vi.fn(),
		getDashboardById: vi.fn(),
		addWidget: vi.fn(),
		addTile: vi.fn(),
		updateWidgetPlacement: vi.fn(),
		removeWidget: vi.fn(),
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

describe('useDashboardStore — source-aware getters', () => {
	it('userDashboards returns only `source === "user"` rows', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.dashboards = [
			{ id: 1, uuid: 'a', source: 'user', name: 'Mine' },
			{ id: 2, uuid: 'b', source: 'group', name: 'Marketing' },
			{ id: 3, uuid: 'c', source: 'default', name: 'Welcome' },
		]
		expect(store.userDashboards).toHaveLength(1)
		expect(store.userDashboards[0].uuid).toBe('a')
	})

	it('groupSharedDashboards returns only `source === "group"` rows', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.dashboards = [
			{ id: 1, uuid: 'a', source: 'user' },
			{ id: 2, uuid: 'b', source: 'group' },
			{ id: 3, uuid: 'c', source: 'group' },
			{ id: 4, uuid: 'd', source: 'default' },
		]
		expect(store.groupSharedDashboards).toHaveLength(2)
		expect(store.groupSharedDashboards.map(d => d.uuid)).toEqual(['b', 'c'])
	})

	it('defaultGroupDashboards returns only `source === "default"` rows', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.dashboards = [
			{ id: 1, uuid: 'a', source: 'user' },
			{ id: 2, uuid: 'b', source: 'group' },
			{ id: 3, uuid: 'c', source: 'default' },
		]
		expect(store.defaultGroupDashboards).toHaveLength(1)
		expect(store.defaultGroupDashboards[0].uuid).toBe('c')
	})

	it('all source-aware getters return empty array for an empty store', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		expect(store.userDashboards).toEqual([])
		expect(store.groupSharedDashboards).toEqual([])
		expect(store.defaultGroupDashboards).toEqual([])
	})
})

describe('useDashboardStore — loadDashboards source plumbing', () => {
	it('calls /api/dashboards/visible and stores the source-tagged payload', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()

		mockApi.getVisibleDashboards.mockResolvedValue({
			data: [
				{ id: 1, uuid: 'a', source: 'user', name: 'Mine' },
				{ id: 2, uuid: 'b', source: 'group', name: 'Marketing' },
				{ id: 3, uuid: 'c', source: 'default', name: 'Welcome' },
			],
		})
		mockApi.getActiveDashboard.mockResolvedValue({ data: null })

		await store.loadDashboards()

		expect(mockApi.getVisibleDashboards).toHaveBeenCalledTimes(1)
		expect(store.dashboards).toHaveLength(3)
		expect(store.dashboards[0].source).toBe('user')
		expect(store.dashboards[1].source).toBe('group')
		expect(store.dashboards[2].source).toBe('default')
	})

	it('falls back to /api/dashboards and tags rows as `source: "user"`', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()

		mockApi.getVisibleDashboards.mockRejectedValue(new Error('404'))
		mockApi.getDashboards.mockResolvedValue({
			data: [
				{ id: 1, uuid: 'a', name: 'Mine' },
				{ id: 2, uuid: 'b', name: 'Other' },
			],
		})
		mockApi.getActiveDashboard.mockResolvedValue({ data: null })

		await store.loadDashboards()

		expect(mockApi.getVisibleDashboards).toHaveBeenCalled()
		expect(mockApi.getDashboards).toHaveBeenCalled()
		expect(store.dashboards).toHaveLength(2)
		// Legacy payloads receive `source: 'user'` so getters still work.
		expect(store.dashboards.every(d => d.source === 'user')).toBe(true)
	})
})
