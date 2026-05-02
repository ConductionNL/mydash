/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `DashboardSwitcherSidebar.vue` (capability
 * `dashboard-switcher`). Covers REQ-SWITCH-001..007:
 *  - three-section visibility matrix (group / default / personal)
 *  - empty sections do NOT render their heading or container
 *  - emit order: `update:open(false)` MUST precede `switch(id, source)`
 *  - `source` discriminator matches the section the row was rendered in
 *  - `delete-dashboard` does not also emit `switch` or `update:open`
 *  - `+ New Dashboard` row is gated on `allowUserDashboards`
 *  - `.active` class follows `activeDashboardId` reactively
 *  - icon rendering goes through the shared IconRenderer (REQ-SWITCH-007)
 *
 * IconRenderer is stubbed so the test focuses on switcher semantics
 * (icon-discriminator coverage lives in the dashboard-icons spec).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import DashboardSwitcherSidebar from '../DashboardSwitcherSidebar.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

const iconRendererStub = {
	name: 'IconRenderer',
	props: ['name', 'size'],
	template: '<span class="icon-renderer-stub" :data-name="name" />',
}

function mountSidebar(props = {}) {
	return mount(DashboardSwitcherSidebar, {
		propsData: {
			isOpen: true,
			groupName: null,
			groupDashboards: [],
			userDashboards: [],
			activeDashboardId: null,
			allowUserDashboards: false,
			...props,
		},
		stubs: {
			IconRenderer: iconRendererStub,
		},
	})
}

const groupRow = { id: 'g1', name: 'Team Board', icon: 'Star', source: 'group' }
const groupRow2 = { id: 'g2', name: 'Other Team', icon: null, source: 'group' }
const defaultRow = { id: 'd1', name: 'Org Default', icon: 'Home', source: 'default' }
const userRow = { id: 'p1', name: 'My Notes', icon: null, source: 'user' }
const userRow2 = { id: 'p2', name: 'Side Project', icon: 'Heart', source: 'user' }

describe('DashboardSwitcherSidebar', () => {
	describe('REQ-SWITCH-001 three-section navigation', () => {
		it('renders all three sections in order when populated', () => {
			const wrapper = mountSidebar({
				groupName: 'Engineering',
				groupDashboards: [groupRow, groupRow2, defaultRow],
				userDashboards: [userRow],
			})
			const sections = wrapper.findAll('.dashboard-switcher-sidebar__section')
			expect(sections.length).toBe(3)
			expect(sections.at(0).attributes('data-section')).toBe('group')
			expect(sections.at(1).attributes('data-section')).toBe('default')
			expect(sections.at(2).attributes('data-section')).toBe('user')
			// Two dividers (between 1↔2 and 2↔3)
			expect(wrapper.findAll('.dashboard-switcher-sidebar__divider').length).toBe(2)
		})

		it('uses groupName when supplied, falls back to "Dashboards" otherwise', () => {
			const named = mountSidebar({
				groupName: 'Engineering',
				groupDashboards: [groupRow],
			})
			expect(named.find('[data-section="group"] .dashboard-switcher-sidebar__heading').text())
				.toBe('Engineering')

			const unnamed = mountSidebar({
				groupDashboards: [groupRow],
			})
			expect(unnamed.find('[data-section="group"] .dashboard-switcher-sidebar__heading').text())
				.toBe('Dashboards')
		})

		it('renders only the personal section when group lists are empty', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow],
			})
			const sections = wrapper.findAll('.dashboard-switcher-sidebar__section')
			expect(sections.length).toBe(1)
			expect(sections.at(0).attributes('data-section')).toBe('user')
			expect(wrapper.findAll('.dashboard-switcher-sidebar__divider').length).toBe(0)
		})

		it('renders the personal section heading when allowUserDashboards even with empty list', () => {
			const wrapper = mountSidebar({
				userDashboards: [],
				allowUserDashboards: true,
			})
			const userSection = wrapper.find('[data-section="user"]')
			expect(userSection.exists()).toBe(true)
			expect(userSection.find('.dashboard-switcher-sidebar__heading').text())
				.toBe('My Dashboards')
			// Only the create row is present
			expect(userSection.findAll('.dashboard-switcher-sidebar__item').length).toBe(1)
			expect(userSection.find('[data-action="create"]').exists()).toBe(true)
		})

		it('omits empty sections entirely (no orphan heading)', () => {
			const wrapper = mountSidebar({
				groupDashboards: [defaultRow],
				userDashboards: [],
				allowUserDashboards: false,
			})
			const sections = wrapper.findAll('.dashboard-switcher-sidebar__section')
			expect(sections.length).toBe(1)
			expect(sections.at(0).attributes('data-section')).toBe('default')
			// No divider — only one section
			expect(wrapper.findAll('.dashboard-switcher-sidebar__divider').length).toBe(0)
		})

		it('renders one divider when group + user are present but default is empty', () => {
			const wrapper = mountSidebar({
				groupDashboards: [groupRow],
				userDashboards: [userRow],
			})
			expect(wrapper.findAll('.dashboard-switcher-sidebar__divider').length).toBe(1)
		})
	})

	describe('REQ-SWITCH-002 switch click semantics', () => {
		it('emits update:open(false) BEFORE switch(id, source) on row click', async () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow],
			})
			const row = wrapper.find('.dashboard-switcher-sidebar__item[data-source="user"]')
			await row.trigger('click')

			const updateOpen = wrapper.emitted('update:open')
			const switchEv = wrapper.emitted('switch')
			expect(updateOpen).toBeTruthy()
			expect(switchEv).toBeTruthy()
			expect(updateOpen[0]).toEqual([false])
			expect(switchEv[0]).toEqual(['p1', 'user'])

			// Order: update:open recorded first.
			const allEmits = Object.entries(wrapper.emitted())
				.flatMap(([name, evs]) => evs.map((_args, i) => ({ name, i })))
				// Stable order is preserved by @vue/test-utils via insertion;
				// double-check the names appear in the expected sequence.
			const names = allEmits.map(e => e.name)
			expect(names.indexOf('update:open')).toBeLessThan(names.indexOf('switch'))
		})

		it('emits source "group" for primary-group rows', async () => {
			const wrapper = mountSidebar({
				groupDashboards: [groupRow],
			})
			await wrapper.find('.dashboard-switcher-sidebar__item[data-source="group"]').trigger('click')
			expect(wrapper.emitted('switch')[0]).toEqual(['g1', 'group'])
		})

		it('emits source "default" for default-group rows (not "group")', async () => {
			const wrapper = mountSidebar({
				groupDashboards: [defaultRow],
			})
			await wrapper.find('.dashboard-switcher-sidebar__item[data-source="default"]').trigger('click')
			const ev = wrapper.emitted('switch')[0]
			expect(ev).toEqual(['d1', 'default'])
			// Defensive: never the group fallback.
			expect(ev[1]).not.toBe('group')
		})

		it('emits source "user" for personal rows', async () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow],
			})
			await wrapper.find('.dashboard-switcher-sidebar__item[data-source="user"]').trigger('click')
			expect(wrapper.emitted('switch')[0]).toEqual(['p1', 'user'])
		})
	})

	describe('REQ-SWITCH-003 active item highlight', () => {
		it('marks exactly the row matching activeDashboardId as .active', () => {
			const wrapper = mountSidebar({
				groupDashboards: [groupRow, defaultRow],
				userDashboards: [userRow],
				activeDashboardId: 'd1',
			})
			const active = wrapper.findAll('.dashboard-switcher-sidebar__item.active')
			expect(active.length).toBe(1)
			expect(active.at(0).attributes('data-source')).toBe('default')
		})

		it('updates reactively when activeDashboardId prop changes', async () => {
			const wrapper = mountSidebar({
				groupDashboards: [groupRow],
				userDashboards: [userRow],
				activeDashboardId: 'g1',
			})
			expect(wrapper.findAll('.dashboard-switcher-sidebar__item.active').length).toBe(1)
			expect(wrapper.find('.dashboard-switcher-sidebar__item.active').attributes('data-source')).toBe('group')

			await wrapper.setProps({ activeDashboardId: 'p1' })
			const active = wrapper.findAll('.dashboard-switcher-sidebar__item.active')
			expect(active.length).toBe(1)
			expect(active.at(0).attributes('data-source')).toBe('user')
		})
	})

	describe('REQ-SWITCH-004 personal-row delete affordance', () => {
		it('emits delete-dashboard(id) on delete button click', async () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow, userRow2],
			})
			const deleteBtn = wrapper.findAll('.dashboard-switcher-sidebar__delete').at(0)
			await deleteBtn.trigger('click')
			expect(wrapper.emitted('delete-dashboard')).toBeTruthy()
			expect(wrapper.emitted('delete-dashboard')[0]).toEqual(['p1'])
		})

		it('delete click does not also emit switch or update:open (@click.stop)', async () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow],
			})
			const deleteBtn = wrapper.find('.dashboard-switcher-sidebar__delete')
			await deleteBtn.trigger('click')
			expect(wrapper.emitted('delete-dashboard')).toBeTruthy()
			expect(wrapper.emitted('switch')).toBeFalsy()
			expect(wrapper.emitted('update:open')).toBeFalsy()
		})

		it('group / default rows have NO delete button', () => {
			const wrapper = mountSidebar({
				groupDashboards: [groupRow, defaultRow],
				userDashboards: [],
				allowUserDashboards: false,
			})
			expect(wrapper.findAll('.dashboard-switcher-sidebar__delete').length).toBe(0)
		})
	})

	describe('REQ-SWITCH-005 create-dashboard affordance', () => {
		it('renders the +New Dashboard row when allowUserDashboards: true', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow],
				allowUserDashboards: true,
			})
			expect(wrapper.find('[data-action="create"]').exists()).toBe(true)
		})

		it('does NOT render the +New Dashboard row when allowUserDashboards: false', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow],
				allowUserDashboards: false,
			})
			expect(wrapper.find('[data-action="create"]').exists()).toBe(false)
		})

		it('emits update:open(false) THEN create-dashboard on create row click', async () => {
			const wrapper = mountSidebar({
				userDashboards: [],
				allowUserDashboards: true,
			})
			await wrapper.find('[data-action="create"]').trigger('click')
			expect(wrapper.emitted('update:open')[0]).toEqual([false])
			expect(wrapper.emitted('create-dashboard')).toBeTruthy()
			expect(wrapper.emitted('create-dashboard')[0]).toEqual([])

			const allEmits = Object.entries(wrapper.emitted())
				.flatMap(([name, evs]) => evs.map(() => name))
			expect(allEmits.indexOf('update:open')).toBeLessThan(allEmits.indexOf('create-dashboard'))
		})
	})

	describe('REQ-SWITCH-006 slide-in animation', () => {
		it('omits the .open class when isOpen: false (off-screen via translateX(-100%))', () => {
			const wrapper = mountSidebar({ isOpen: false, userDashboards: [userRow] })
			expect(wrapper.find('aside').classes()).not.toContain('open')
			// Body should not be visible to screen readers either
			expect(wrapper.find('aside').attributes('aria-hidden')).toBe('true')
		})

		it('adds the .open class when isOpen: true', () => {
			const wrapper = mountSidebar({ isOpen: true, userDashboards: [userRow] })
			expect(wrapper.find('aside').classes()).toContain('open')
			expect(wrapper.find('aside').attributes('aria-hidden')).toBe('false')
		})
	})

	describe('REQ-SWITCH-007 icon rendering via shared renderer', () => {
		it('renders one IconRenderer per dashboard row', () => {
			const wrapper = mountSidebar({
				groupDashboards: [groupRow, defaultRow],
				userDashboards: [userRow],
			})
			// 3 dashboards → 3 IconRenderers (the +New Dashboard plus icon
			// is a different MDI component, not IconRenderer).
			const renderers = wrapper.findAllComponents(iconRendererStub)
			expect(renderers.length).toBe(3)
		})

		it('passes the icon name through unchanged (no inline URL branching)', () => {
			const wrapper = mountSidebar({
				userDashboards: [
					{ id: 'p1', name: 'Star', icon: 'Star' },
					{ id: 'p2', name: 'Custom', icon: '/apps/mydash/resource/x.png' },
					{ id: 'p3', name: 'Empty', icon: null },
				],
			})
			const stubs = wrapper.findAll('.icon-renderer-stub')
			expect(stubs.length).toBe(3)
			expect(stubs.at(0).attributes('data-name')).toBe('Star')
			expect(stubs.at(1).attributes('data-name')).toBe('/apps/mydash/resource/x.png')
			// null becomes the empty attribute
			expect(stubs.at(2).attributes('data-name')).toBeFalsy()
		})
	})

	describe('Close button + Esc', () => {
		it('emits update:open(false) on close button click', async () => {
			const wrapper = mountSidebar({ isOpen: true })
			await wrapper.find('.dashboard-switcher-sidebar__close').trigger('click')
			expect(wrapper.emitted('update:open')).toBeTruthy()
			expect(wrapper.emitted('update:open')[0]).toEqual([false])
		})

		it('emits update:open(false) on Esc keydown when open', async () => {
			const wrapper = mountSidebar({ isOpen: true })
			await wrapper.find('aside').trigger('keydown.esc')
			expect(wrapper.emitted('update:open')[0]).toEqual([false])
		})
	})
})
