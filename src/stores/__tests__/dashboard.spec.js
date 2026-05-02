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
		setGroupDashboardDefault: vi.fn(),
		setActiveDashboardPreference: vi.fn(),
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

describe('useDashboardStore — resolveActive (REQ-DASH-018)', () => {
	const make = (overrides = {}) => ({
		id: overrides.id ?? overrides.uuid,
		uuid: overrides.uuid,
		source: 'user',
		isDefault: 0,
		groupId: null,
		...overrides,
	})

	it('returns null when there are no dashboards', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		expect(store.resolveActive).toBeNull()
	})

	it('step 1: honours the currently-active dashboard if still visible', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.dashboards = [
			make({ uuid: 'a', source: 'user' }),
			make({ uuid: 'b', source: 'group', groupId: 'eng' }),
		]
		store.activeDashboard = { id: 'b' }
		store.primaryGroup = 'eng'
		expect(store.resolveActive.uuid).toBe('b')
	})

	it('step 2: primary-group default beats default-group default', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.primaryGroup = 'eng'
		store.dashboards = [
			make({ uuid: 'd', source: 'default', groupId: 'default', isDefault: 1 }),
			make({ uuid: 'g', source: 'group', groupId: 'eng', isDefault: 1 }),
		]
		expect(store.resolveActive.uuid).toBe('g')
	})

	it('step 3: falls through to default-group default when primary group has none', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.primaryGroup = 'support'
		store.dashboards = [
			make({ uuid: 'd', source: 'default', groupId: 'default', isDefault: 1 }),
		]
		expect(store.resolveActive.uuid).toBe('d')
	})

	it('step 4: first group-shared in primary group when no defaults exist', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.primaryGroup = 'eng'
		store.dashboards = [
			make({ uuid: 'g1', source: 'group', groupId: 'eng', isDefault: 0 }),
			make({ uuid: 'g2', source: 'group', groupId: 'eng', isDefault: 0 }),
		]
		expect(store.resolveActive.uuid).toBe('g1')
	})

	it('step 5: first default-group dashboard when nothing in primary group', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.primaryGroup = 'orphan'
		store.dashboards = [
			make({ uuid: 'd1', source: 'default', groupId: 'default', isDefault: 0 }),
			make({ uuid: 'd2', source: 'default', groupId: 'default', isDefault: 0 }),
		]
		expect(store.resolveActive.uuid).toBe('d1')
	})

	it('step 6: first personal dashboard when no group/default rows exist', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.primaryGroup = ''
		store.dashboards = [
			make({ uuid: 'mine', source: 'user' }),
		]
		expect(store.resolveActive.uuid).toBe('mine')
	})
})

describe('useDashboardStore — persistActivePreference (REQ-DASH-019)', () => {
	it('POSTs the uuid to /api/dashboards/active fire-and-forget', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		mockApi.setActiveDashboardPreference.mockResolvedValue({ data: { status: 'success' } })

		store.persistActivePreference('uuid-xyz')

		expect(mockApi.setActiveDashboardPreference).toHaveBeenCalledWith('uuid-xyz')
	})

	it('passes empty string when called with no uuid (clears the preference)', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		mockApi.setActiveDashboardPreference.mockResolvedValue({ data: { status: 'success' } })

		store.persistActivePreference('')

		expect(mockApi.setActiveDashboardPreference).toHaveBeenCalledWith('')
	})

	it('swallows network errors so a failed POST does not break the UI', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		mockApi.setActiveDashboardPreference.mockRejectedValue(new Error('boom'))

		// Returns void synchronously; rejection is caught internally.
		const ret = store.persistActivePreference('uuid-xyz')
		expect(ret).toBeUndefined()
		// Settle the rejected promise so the test doesn't leak an unhandled rejection.
		await new Promise(resolve => setTimeout(resolve, 0))
	})
})

describe('useDashboardStore — switchDashboard wires the active-pref POST', () => {
	it('calls setActiveDashboardPreference with the new uuid after a successful switch', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.dashboards = [{ id: 'd-1', uuid: 'uuid-1', source: 'user', isOwner: true }]

		mockApi.activateDashboard.mockResolvedValue({ data: { status: 'ok' } })
		mockApi.getDashboardById.mockResolvedValue({
			data: {
				dashboard: { id: 'd-1', uuid: 'uuid-1' },
				placements: [],
				permissionLevel: 'full',
				isOwner: true,
				sharedBy: null,
			},
		})
		mockApi.setActiveDashboardPreference.mockResolvedValue({ data: { status: 'success' } })

		await store.switchDashboard('d-1')

		expect(mockApi.setActiveDashboardPreference).toHaveBeenCalledWith('uuid-1')
	})
})

describe('useDashboardStore — setGroupDashboardDefault (REQ-DASH-015)', () => {
	const seed = () => ([
		{ id: 1, uuid: 'a', source: 'group', groupId: 'marketing', isDefault: 1 },
		{ id: 2, uuid: 'b', source: 'group', groupId: 'marketing', isDefault: 0 },
		{ id: 3, uuid: 'c', source: 'group', groupId: 'marketing', isDefault: 0 },
		// Different group — must NOT be touched.
		{ id: 4, uuid: 'x', source: 'group', groupId: 'sales', isDefault: 1 },
		// Personal — must NOT be touched.
		{ id: 5, uuid: 'p', source: 'user', groupId: null, isDefault: 0 },
	])

	it('optimistically flips target → 1 and every other row in the same group → 0 on success', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.dashboards = seed()
		mockApi.setGroupDashboardDefault.mockResolvedValue({ data: { status: 'ok' } })

		await store.setGroupDashboardDefault('marketing', 'c')

		const byUuid = Object.fromEntries(store.dashboards.map(d => [d.uuid, d]))
		expect(byUuid.a.isDefault).toBe(0)
		expect(byUuid.b.isDefault).toBe(0)
		expect(byUuid.c.isDefault).toBe(1)
		// Untouched scopes remain stable.
		expect(byUuid.x.isDefault).toBe(1)
		expect(byUuid.p.isDefault).toBe(0)
		expect(mockApi.setGroupDashboardDefault).toHaveBeenCalledWith('marketing', 'c')
	})

	it('rolls back the snapshot on a 4xx/5xx and re-throws', async () => {
		const { useDashboardStore } = await import('../dashboard.js')
		const store = useDashboardStore()
		store.dashboards = seed()
		mockApi.setGroupDashboardDefault.mockRejectedValue(new Error('403'))

		await expect(store.setGroupDashboardDefault('marketing', 'c')).rejects.toThrow('403')

		const byUuid = Object.fromEntries(store.dashboards.map(d => [d.uuid, d]))
		// Snapshot restored.
		expect(byUuid.a.isDefault).toBe(1)
		expect(byUuid.b.isDefault).toBe(0)
		expect(byUuid.c.isDefault).toBe(0)
		// Other group untouched.
		expect(byUuid.x.isDefault).toBe(1)
	})
})
