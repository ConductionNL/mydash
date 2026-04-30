/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/* eslint-disable n/no-unpublished-import */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DashboardSwitcherSidebar from '@/components/Workspace/DashboardSwitcherSidebar.vue'

// Mock the translate function
vi.mock('@nextcloud/l10n', () => ({
	translate: vi.fn((app, key) => key),
}))

describe('DashboardSwitcherSidebar', () => {
	describe('REQ-SWITCH-001: Three-section navigation', () => {
		it('renders only visible sections', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [],
					userDashboards: [{ id: 'u1', name: 'User Dash', icon: 'Star' }],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			// Should have only "My Dashboards" section
			expect(wrapper.find('.sidebar-section-heading').text()).toBe('My Dashboards')
			expect(wrapper.findAll('.sidebar-section-heading')).toHaveLength(1)
		})

		it('renders all three sections when populated', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupName: 'Engineering',
					groupDashboards: [
						{ id: 'g1', name: 'Group Dash', icon: 'Home', source: 'group' },
						{ id: 'd1', name: 'Default Dash', icon: 'Star', source: 'default' },
					],
					userDashboards: [{ id: 'u1', name: 'User Dash', icon: 'Heart' }],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const headings = wrapper.findAll('.sidebar-section-heading')
			expect(headings).toHaveLength(3)
			expect(headings.at(0).text()).toBe('Engineering')
			expect(headings.at(1).text()).toBe('Default')
			expect(headings.at(2).text()).toBe('My Dashboards')
		})

		it('shows My Dashboards when allowUserDashboards is true even with empty list', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [],
					userDashboards: [],
					allowUserDashboards: true,
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			expect(wrapper.find('.sidebar-section-heading').text()).toBe('My Dashboards')
		})

		it('does not render My Dashboards when empty and allowUserDashboards is false', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [],
					userDashboards: [],
					allowUserDashboards: false,
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			expect(wrapper.find('.sidebar-section-heading').exists()).toBe(false)
		})
	})

	describe('REQ-SWITCH-002: Click semantics', () => {
		it('emits update:open(false) BEFORE switch event', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [{ id: 'g1', name: 'Group Dash', icon: 'Home', source: 'group' }],
					userDashboards: [],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const emits = []
			wrapper.vm.$on('update:open', () => emits.push('update:open'))
			wrapper.vm.$on('switch', () => emits.push('switch'))

			const dashButton = wrapper.find('.sidebar-item--dashboard')
			await dashButton.trigger('click')

			expect(emits).toEqual(['update:open', 'switch'])
		})

		it('emits switch with correct source for primary group items', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [{ id: 'g1', name: 'Group Dash', icon: 'Home', source: 'group' }],
					userDashboards: [],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const dashButton = wrapper.find('.sidebar-item--dashboard')
			await dashButton.trigger('click')

			const switchEmits = wrapper.emitted('switch')
			expect(switchEmits).toHaveLength(1)
			expect(switchEmits[0]).toEqual(['g1', 'group'])
		})

		it('emits switch with source "default" for default group items', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [{ id: 'd1', name: 'Default Dash', icon: 'Star', source: 'default' }],
					userDashboards: [],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const dashButton = wrapper.find('.sidebar-item--dashboard')
			await dashButton.trigger('click')

			const switchEmits = wrapper.emitted('switch')
			expect(switchEmits[0]).toEqual(['d1', 'default'])
		})

		it('emits switch with source "user" for personal items', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [],
					userDashboards: [{ id: 'u1', name: 'User Dash', icon: 'Heart' }],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const dashButton = wrapper.find('.sidebar-item--dashboard')
			await dashButton.trigger('click')

			const switchEmits = wrapper.emitted('switch')
			expect(switchEmits[0]).toEqual(['u1', 'user'])
		})
	})

	describe('REQ-SWITCH-003: Active item highlight', () => {
		it('applies active class to the active dashboard', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [
						{ id: 'g1', name: 'Group Dash 1', icon: 'Home', source: 'group' },
						{ id: 'g2', name: 'Group Dash 2', icon: 'Star', source: 'group' },
					],
					userDashboards: [],
					activeDashboardId: 'g2',
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const items = wrapper.findAll('.sidebar-item--dashboard')
			expect(items.at(0).classes()).not.toContain('active')
			expect(items.at(1).classes()).toContain('active')
		})

		it('updates active class reactively when prop changes', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [{ id: 'g1', name: 'Group Dash', icon: 'Home', source: 'group' }],
					userDashboards: [{ id: 'u1', name: 'User Dash', icon: 'Heart' }],
					activeDashboardId: 'g1',
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			expect(wrapper.findAll('.sidebar-item.active')).toHaveLength(1)

			await wrapper.setProps({ activeDashboardId: 'u1' })

			const activeItems = wrapper.findAll('.sidebar-item.active')
			expect(activeItems).toHaveLength(1)
			expect(activeItems.at(0).text()).toContain('User Dash')
		})
	})

	describe('REQ-SWITCH-004: Personal delete affordance', () => {
		it('does not emit switch when delete button is clicked', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [],
					userDashboards: [{ id: 'u1', name: 'User Dash', icon: 'Heart' }],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const deleteButton = wrapper.find('.sidebar-item-delete')
			await deleteButton.trigger('click')

			expect(wrapper.emitted('switch')).toBeUndefined()
			expect(wrapper.emitted('update:open')).toBeUndefined()
		})

		it('emits delete-dashboard when delete button is clicked', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [],
					userDashboards: [{ id: 'u1', name: 'User Dash', icon: 'Heart' }],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const deleteButton = wrapper.find('.sidebar-item-delete')
			await deleteButton.trigger('click')

			const deleteEmits = wrapper.emitted('delete-dashboard')
			expect(deleteEmits).toHaveLength(1)
			expect(deleteEmits[0]).toEqual(['u1'])
		})

		it('shows delete button only for personal dashboards, not for group items', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [{ id: 'g1', name: 'Group Dash', icon: 'Home', source: 'group' }],
					userDashboards: [{ id: 'u1', name: 'User Dash', icon: 'Heart' }],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			expect(wrapper.findAll('.sidebar-item-delete')).toHaveLength(1)
		})
	})

	describe('REQ-SWITCH-005: Create-dashboard affordance', () => {
		it('renders + New Dashboard button when allowUserDashboards is true', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [],
					userDashboards: [],
					allowUserDashboards: true,
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			expect(wrapper.find('.sidebar-item--action').exists()).toBe(true)
		})

		it('does not render + New Dashboard button when allowUserDashboards is false', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [],
					userDashboards: [],
					allowUserDashboards: false,
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			expect(wrapper.find('.sidebar-item--action').exists()).toBe(false)
		})

		it('emits update:open(false) and create-dashboard in order', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [],
					userDashboards: [],
					allowUserDashboards: true,
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const emits = []
			wrapper.vm.$on('update:open', () => emits.push('update:open'))
			wrapper.vm.$on('create-dashboard', () => emits.push('create-dashboard'))

			const createButton = wrapper.find('.sidebar-item--action')
			await createButton.trigger('click')

			expect(emits).toEqual(['update:open', 'create-dashboard'])
		})
	})

	describe('REQ-SWITCH-006: Slide-in animation', () => {
		it('applies open class when isOpen is true', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					isOpen: false,
					groupDashboards: [],
					userDashboards: [],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			expect(wrapper.find('.dashboard-switcher-sidebar').classes()).not.toContain('open')

			await wrapper.setProps({ isOpen: true })

			expect(wrapper.find('.dashboard-switcher-sidebar').classes()).toContain('open')
		})
	})

	describe('REQ-SWITCH-007: Icon rendering', () => {
		it('renders icons via IconRenderer for all dashboard items', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [
						{ id: 'g1', name: 'Home', icon: 'Home', source: 'group' },
						{ id: 'g2', name: 'Chart', icon: 'ChartBar', source: 'group' },
					],
					userDashboards: [
						{ id: 'u1', name: 'Star', icon: 'Star' },
						{ id: 'u2', name: 'Default', icon: null },
					],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const iconRenderers = wrapper.findAllComponents({ name: 'IconRenderer' })
			expect(iconRenderers.length).toBeGreaterThan(0)
		})
	})

	describe('Accessibility', () => {
		it('responds to Esc key to close sidebar', async () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					isOpen: true,
					groupDashboards: [],
					userDashboards: [],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			await wrapper.find('.dashboard-switcher-sidebar').trigger('keydown.esc')

			const updateEmits = wrapper.emitted('update:open')
			expect(updateEmits).toHaveLength(1)
			expect(updateEmits[0]).toEqual([false])
		})

		it('has aria-labels on dashboard items', () => {
			const wrapper = mount(DashboardSwitcherSidebar, {
				propsData: {
					groupDashboards: [{ id: 'g1', name: 'Engineering Dashboard', icon: 'Home', source: 'group' }],
					userDashboards: [],
				},
				mocks: {
					t: (app, key) => key,
				},
			})

			const button = wrapper.find('.sidebar-item--dashboard')
			expect(button.attributes('aria-label')).toBe('Engineering Dashboard')
		})
	})
})
