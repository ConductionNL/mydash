/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `DashboardSwitcherSidebar.vue` (capability
 * `dashboard-switcher`). Covers REQ-SWITCH-001..004, 006..009:
 *  - three-section visibility matrix (group / default / personal)
 *  - empty sections do NOT render their heading or container
 *  - emit order: `update:open(false)` MUST precede `switch(id, source)`
 *  - `source` discriminator matches the section the row was rendered in
 *  - `delete-dashboard` does not also emit `switch` or `update:open`
 *  - REQ-SWITCH-008: dedicated Add-Dashboard card gated on
 *    `allowUserDashboards`, emits `update:open(false)` then
 *    `create-dashboard()`
 *  - REQ-SWITCH-009: persistent footer with brand attribution and
 *    Documentation link, pinned via `position: sticky`
 *  - `.active` class follows `activeDashboardId` reactively
 *  - icon rendering goes through the shared IconRenderer (REQ-SWITCH-007)
 *
 * IconRenderer + NcButton + SidebarFooter are stubbed so the test focuses
 * on switcher semantics (icon-discriminator coverage lives in the
 * dashboard-icons spec; brand-link coverage lives in SidebarFooter.spec.js).
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

const ncButtonStub = {
	name: 'NcButton',
	props: ['type', 'wide', 'ariaLabel'],
	template: `
		<button
			type="button"
			class="nc-button-stub"
			:data-action="$attrs['data-action']"
			:data-button-type="type"
			@click="$emit('click', $event)">
			<slot name="icon" />
			<slot />
		</button>
	`,
}

const sidebarFooterStub = {
	name: 'SidebarFooter',
	template: '<footer class="sidebar-footer-stub" data-testid="sidebar-footer-stub" />',
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
			NcButton: ncButtonStub,
			SidebarFooter: sidebarFooterStub,
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
			// REQ-SWITCH-008: the dashboards list is empty; the Add-Dashboard
			// card lives BELOW the list (still inside the section), not inside
			// the <ul>.
			expect(userSection.findAll('.dashboard-switcher-sidebar__item').length).toBe(0)
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

	describe('wave3.3: per-row delete affordance is REMOVED', () => {
		it('user rows no longer render an inline `__delete` button', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow, userRow2],
			})
			expect(wrapper.findAll('.dashboard-switcher-sidebar__delete').length).toBe(0)
		})

		it('group / default rows still have NO delete button', () => {
			const wrapper = mountSidebar({
				groupDashboards: [groupRow, defaultRow],
				userDashboards: [],
				allowUserDashboards: false,
			})
			expect(wrapper.findAll('.dashboard-switcher-sidebar__delete').length).toBe(0)
		})
	})

	describe('REQ-SWITCH-008 dedicated Add-Dashboard card button', () => {
		it('renders the Add-Dashboard card when allowUserDashboards: true', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow],
				allowUserDashboards: true,
			})
			const card = wrapper.find('.dashboard-switcher-sidebar__add-dashboard-card')
			expect(card.exists()).toBe(true)
			// The card lives in the personal section but BELOW the <ul>
			expect(wrapper.find('[data-section="user"] .dashboard-switcher-sidebar__add-dashboard-card').exists())
				.toBe(true)
			// NcButton stub carries the type="outline" the spec requires
			const button = card.find('[data-action="create"]')
			expect(button.exists()).toBe(true)
			expect(button.attributes('data-button-type')).toBe('outline')
			// Localised label
			expect(button.text()).toContain('Add dashboard')
		})

		it('does NOT render the Add-Dashboard card when allowUserDashboards: false', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow],
				allowUserDashboards: false,
			})
			expect(wrapper.find('[data-action="create"]').exists()).toBe(false)
			expect(wrapper.find('.dashboard-switcher-sidebar__add-dashboard-card').exists()).toBe(false)
		})

		it('emits update:open(false) THEN create-dashboard on Add-Dashboard card click', async () => {
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

	describe('REQ-SWITCH-009 persistent footer mounting', () => {
		it('renders the SidebarFooter inside the sidebar root', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRow],
				allowUserDashboards: true,
			})
			const footer = wrapper.find('[data-testid="sidebar-footer-stub"]')
			expect(footer.exists()).toBe(true)
			expect(footer.classes()).toContain('dashboard-switcher-sidebar__footer')
		})

		it('mounts the footer outside the scroll container (sibling of __body)', () => {
			const wrapper = mountSidebar({ userDashboards: [userRow] })
			const aside = wrapper.find('aside')
			const directChildren = Array.from(aside.element.children)
			const footerEl = wrapper.find('[data-testid="sidebar-footer-stub"]').element
			// Footer must be a direct child of the sidebar root so its
			// `position: sticky; bottom: 0` pins to the sidebar viewport,
			// not to the inside of the scrolling __body container.
			expect(directChildren).toContain(footerEl)
		})

		it('keeps the footer present when the personal list grows long (sticky semantics)', () => {
			// Spec scenario: 30+ dashboards in the personal section MUST
			// still render the footer (it does not scroll out of view because
			// it's a sibling of the scroll container, not a child).
			const many = Array.from({ length: 32 }, (_v, i) => ({
				id: `p${i}`,
				name: `Dashboard ${i}`,
				icon: null,
			}))
			const wrapper = mountSidebar({
				userDashboards: many,
				allowUserDashboards: true,
			})
			expect(wrapper.find('[data-testid="sidebar-footer-stub"]').exists()).toBe(true)
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

	describe('default-dashboard star marker', () => {
		const userRowWithUuid = { id: 'p1', uuid: 'pin-uuid', name: 'My Notes', icon: null, source: 'user' }
		const userRowOtherUuid = { id: 'p2', uuid: 'other-uuid', name: 'Side Project', icon: null, source: 'user' }
		const groupRowWithUuid = { id: 'g1', uuid: 'group-uuid', name: 'Team Board', icon: null, source: 'group' }

		it('renders a star marker only on the row whose uuid matches defaultUuid', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRowWithUuid, userRowOtherUuid],
				defaultUuid: 'pin-uuid',
			})

			const markers = wrapper.findAll('.dashboard-switcher-sidebar__default-marker')
			expect(markers.length).toBe(1)

			// The star sits in the row for the pinned dashboard.
			const pinnedRow = wrapper.findAll('.dashboard-switcher-sidebar__item').wrappers.find(
				(li) => li.text().includes('My Notes'),
			)
			expect(pinnedRow.find('.dashboard-switcher-sidebar__default-marker').exists()).toBe(true)
		})

		it('star marker carries a tooltip via the title attribute', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRowWithUuid],
				defaultUuid: 'pin-uuid',
			})

			const marker = wrapper.find('.dashboard-switcher-sidebar__default-marker')
			expect(marker.exists()).toBe(true)
			expect(marker.attributes('title')).toMatch(/default dashboard/i)
			expect(marker.attributes('aria-label')).toMatch(/default dashboard/i)
		})

		it('does not render any star marker when defaultUuid is empty', () => {
			const wrapper = mountSidebar({
				userDashboards: [userRowWithUuid, userRowOtherUuid],
				defaultUuid: '',
			})

			expect(wrapper.findAll('.dashboard-switcher-sidebar__default-marker').length).toBe(0)
		})

		it('marks a group-section row when the user has pinned a group dashboard', () => {
			const wrapper = mountSidebar({
				groupName: 'Engineering',
				groupDashboards: [groupRowWithUuid],
				userDashboards: [userRowOtherUuid],
				defaultUuid: 'group-uuid',
			})

			const markers = wrapper.findAll('.dashboard-switcher-sidebar__default-marker')
			expect(markers.length).toBe(1)

			const pinnedRow = wrapper.findAll('.dashboard-switcher-sidebar__item').wrappers.find(
				(li) => li.text().includes('Team Board'),
			)
			expect(pinnedRow.find('.dashboard-switcher-sidebar__default-marker').exists()).toBe(true)
		})

		describe('group-default fallback (no personal pin)', () => {
			it('falls back to the default-group row carrying isDefault=1 when no pin is set', () => {
				const groupDefault = { id: 'g3', uuid: 'group-default-uuid', name: 'Group Default', icon: null, source: 'default', isDefault: 1 }
				const otherGroup = { id: 'g4', uuid: 'other-default-uuid', name: 'Other Default', icon: null, source: 'default', isDefault: 0 }

				const wrapper = mountSidebar({
					groupDashboards: [otherGroup, groupDefault],
					userDashboards: [userRowOtherUuid],
					defaultUuid: '',
				})

				const markers = wrapper.findAll('.dashboard-switcher-sidebar__default-marker')
				expect(markers.length).toBe(1)
				const starred = wrapper.findAll('.dashboard-switcher-sidebar__item').wrappers.find(
					(li) => li.find('.dashboard-switcher-sidebar__default-marker').exists(),
				)
				expect(starred.text()).toContain('Group Default')
			})

			it('prefers a primary-group isDefault=1 row over a default-group isDefault=1 row (resolver step 2 wins)', () => {
				const primaryDefault = { id: 'g5', uuid: 'primary-default', name: 'Primary Default', icon: null, source: 'group', isDefault: 1 }
				const fallbackDefault = { id: 'g6', uuid: 'fallback-default', name: 'Fallback Default', icon: null, source: 'default', isDefault: 1 }

				const wrapper = mountSidebar({
					groupName: 'Engineering',
					groupDashboards: [fallbackDefault, primaryDefault],
					userDashboards: [],
					defaultUuid: '',
				})

				const starred = wrapper.findAll('.dashboard-switcher-sidebar__item').wrappers.find(
					(li) => li.find('.dashboard-switcher-sidebar__default-marker').exists(),
				)
				expect(starred.text()).toContain('Primary Default')
			})

			it('falls back to the first primary-group row when no isDefault=1 flag exists anywhere', () => {
				const firstPrimary = { id: 'g7', uuid: 'first-primary', name: 'First Primary', icon: null, source: 'group', isDefault: 0 }
				const secondPrimary = { id: 'g8', uuid: 'second-primary', name: 'Second Primary', icon: null, source: 'group', isDefault: 0 }

				const wrapper = mountSidebar({
					groupName: 'Engineering',
					groupDashboards: [firstPrimary, secondPrimary],
					userDashboards: [],
					defaultUuid: '',
				})

				const starred = wrapper.findAll('.dashboard-switcher-sidebar__item').wrappers.find(
					(li) => li.find('.dashboard-switcher-sidebar__default-marker').exists(),
				)
				expect(starred.text()).toContain('First Primary')
			})

			it('does NOT star a personal dashboard via fallback when no group rows exist', () => {
				const wrapper = mountSidebar({
					groupDashboards: [],
					userDashboards: [userRowWithUuid, userRowOtherUuid],
					defaultUuid: '',
				})

				expect(wrapper.findAll('.dashboard-switcher-sidebar__default-marker').length).toBe(0)
			})

			it('still prefers the explicit pin over any group fallback', () => {
				const groupDefault = { id: 'g9', uuid: 'group-default-uuid', name: 'Group Default', icon: null, source: 'default', isDefault: 1 }

				const wrapper = mountSidebar({
					groupDashboards: [groupDefault],
					userDashboards: [userRowWithUuid],
					defaultUuid: 'pin-uuid',
				})

				const markers = wrapper.findAll('.dashboard-switcher-sidebar__default-marker')
				expect(markers.length).toBe(1)
				const starred = wrapper.findAll('.dashboard-switcher-sidebar__item').wrappers.find(
					(li) => li.find('.dashboard-switcher-sidebar__default-marker').exists(),
				)
				expect(starred.text()).toContain('My Notes')
			})
		})
	})
})
