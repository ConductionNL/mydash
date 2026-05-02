/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `loadInitialState`. Covers REQ-INIT-002, REQ-INIT-003,
 * REQ-INIT-004, REQ-INIT-005:
 *  - reader fills defaults for keys the server omitted (no `undefined`)
 *  - reader logs a console warning on schema-version mismatch
 *  - provide/inject pipe-through works for every workspace key
 *  - cloning an injected value into a local ref does not leak to siblings
 *
 * `@nextcloud/initial-state` is mocked at module-resolution time so each
 * test controls exactly which keys "the server pushed".
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Vue from 'vue'

let pushedState = {}

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app, key, fallback) => {
		if (Object.prototype.hasOwnProperty.call(pushedState, key)) {
			return pushedState[key]
		}
		return fallback
	},
}))

beforeEach(() => {
	pushedState = {}
	vi.resetModules()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('loadInitialState', () => {
	it('fills defaults for every workspace key the server omitted', async () => {
		// Server pushes only the schema version — every other key is missing.
		pushedState = { _schemaVersion: 1 }

		const { loadInitialState } = await import('../loadInitialState.js')
		const state = loadInitialState('workspace')

		expect(state.widgets).toEqual([])
		expect(state.layout).toEqual([])
		expect(state.primaryGroup).toBe('default')
		expect(state.primaryGroupName).toBe('')
		expect(state.isAdmin).toBe(false)
		expect(state.activeDashboardId).toBe('')
		expect(state.dashboardSource).toBe('group')
		expect(state.groupDashboards).toEqual([])
		expect(state.userDashboards).toEqual([])
		expect(state.allowUserDashboards).toBe(false)

		for (const value of Object.values(state)) {
			expect(value).not.toBeUndefined()
		}
	})

	it('returns the server-pushed values when present', async () => {
		pushedState = {
			_schemaVersion: 1,
			widgets: [{ id: 'w1', title: 'Calendar' }],
			layout: [{ widgetId: 'w1', x: 0, y: 0 }],
			primaryGroup: 'engineering',
			primaryGroupName: 'Engineering',
			isAdmin: true,
			activeDashboardId: 'dash-uuid-1',
			dashboardSource: 'user',
			groupDashboards: [{ id: 'gd-1', name: 'Team', icon: '' }],
			userDashboards: [{ id: 'ud-1', name: 'Mine', icon: '' }],
			allowUserDashboards: true,
		}

		const { loadInitialState } = await import('../loadInitialState.js')
		const state = loadInitialState('workspace')

		expect(state.primaryGroup).toBe('engineering')
		expect(state.isAdmin).toBe(true)
		expect(state.activeDashboardId).toBe('dash-uuid-1')
		expect(state.widgets).toHaveLength(1)
		expect(state.userDashboards[0].id).toBe('ud-1')
	})

	it('logs a console warning on schema-version mismatch', async () => {
		pushedState = { _schemaVersion: 99 }
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

		const { loadInitialState } = await import('../loadInitialState.js')
		loadInitialState('workspace')

		expect(warn).toHaveBeenCalled()
		const message = warn.mock.calls[0][0]
		expect(message).toMatch(/MyDash initial-state schema mismatch/)
		expect(message).toMatch(/server v99/)
		expect(message).toMatch(/client v2/)
	})

	it('does not warn when the schema versions match', async () => {
		pushedState = { _schemaVersion: 2 }
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

		const { loadInitialState } = await import('../loadInitialState.js')
		loadInitialState('workspace')

		expect(warn).not.toHaveBeenCalled()
	})

	it('throws on an unknown page identifier', async () => {
		const { loadInitialState } = await import('../loadInitialState.js')
		expect(() => loadInitialState('not-a-page')).toThrow(/unknown page/)
	})

	it('exposes every key via provide/inject for descendant components (REQ-INIT-004)', async () => {
		pushedState = {
			_schemaVersion: 1,
			widgets: [{ id: 'w1' }],
			layout: [{ widgetId: 'w1' }],
			primaryGroup: 'g',
			primaryGroupName: 'G',
			isAdmin: true,
			activeDashboardId: 'a-1',
			dashboardSource: 'group',
			groupDashboards: [],
			userDashboards: [],
			allowUserDashboards: true,
		}

		const { loadInitialState } = await import('../loadInitialState.js')
		const state = loadInitialState('workspace')

		const Child = {
			name: 'Child',
			inject: [
				'widgets',
				'layout',
				'primaryGroup',
				'primaryGroupName',
				'isAdmin',
				'activeDashboardId',
				'dashboardSource',
				'groupDashboards',
				'userDashboards',
				'allowUserDashboards',
			],
			template: '<div />',
		}

		const Parent = {
			name: 'Parent',
			provide() {
				return { ...state }
			},
			components: { Child },
			template: '<Child />',
		}

		const wrapper = mount(Parent)
		const child = wrapper.findComponent(Child).vm

		expect(child.widgets).toEqual([{ id: 'w1' }])
		expect(child.isAdmin).toBe(true)
		expect(child.primaryGroup).toBe('g')
		expect(child.activeDashboardId).toBe('a-1')
		expect(child.dashboardSource).toBe('group')
		expect(child.allowUserDashboards).toBe(true)
	})

	it('mutating a cloned ref does not leak to a sibling (REQ-INIT-005)', async () => {
		pushedState = {
			_schemaVersion: 1,
			widgets: [],
			layout: [{ widgetId: 'w1', x: 0 }],
			primaryGroup: 'g',
			primaryGroupName: 'G',
			isAdmin: false,
			activeDashboardId: '',
			dashboardSource: 'group',
			groupDashboards: [],
			userDashboards: [],
			allowUserDashboards: false,
		}

		const { loadInitialState } = await import('../loadInitialState.js')
		const state = loadInitialState('workspace')

		const Mutator = {
			name: 'Mutator',
			inject: ['layout'],
			data() {
				return { localLayout: [...this.layout] }
			},
			methods: {
				bump() {
					this.localLayout.push({ widgetId: 'w2', x: 99 })
				},
			},
			template: '<div />',
		}

		const Sibling = {
			name: 'Sibling',
			inject: ['layout'],
			template: '<div />',
		}

		const Parent = {
			name: 'Parent',
			provide() {
				return { ...state }
			},
			components: { Mutator, Sibling },
			template: '<div><Mutator /><Sibling /></div>',
		}

		const wrapper = mount(Parent)
		const mutator = wrapper.findComponent(Mutator).vm
		const sibling = wrapper.findComponent(Sibling).vm

		mutator.bump()
		await Vue.nextTick()

		expect(mutator.localLayout).toHaveLength(2)
		expect(sibling.layout).toHaveLength(1)
		expect(sibling.layout[0].widgetId).toBe('w1')
	})

	it('fills defaults for every admin key the server omitted', async () => {
		pushedState = { _schemaVersion: 2 }

		const { loadInitialState } = await import('../loadInitialState.js')
		const state = loadInitialState('admin')

		expect(state.allGroups).toEqual([])
		expect(state.configuredGroups).toEqual([])
		expect(state.widgets).toEqual([])
		expect(state.allowUserDashboards).toBe(false)
		// REQ-LBN-004: createFile extension allow-list defaults to the
		// curated list when the admin has not customised it.
		expect(state.linkCreateFileExtensions).toEqual(['txt', 'md', 'docx', 'xlsx', 'csv', 'odt'])
		for (const value of Object.values(state)) {
			expect(value).not.toBeUndefined()
		}
	})

	it('exports INITIAL_STATE_SCHEMA_VERSION matching the PHP constant (2)', async () => {
		const mod = await import('../loadInitialState.js')
		expect(mod.INITIAL_STATE_SCHEMA_VERSION).toBe(2)
	})
})
