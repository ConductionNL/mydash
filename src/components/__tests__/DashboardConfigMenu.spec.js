/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `DashboardConfigMenu.vue` after the
 * `runtime-shell-trim` + wave3.2 cleanup changes. The action menu must:
 *  - keep "Add custom widget…" (REQ-WDG-014, unified-add-widget-flow),
 *  - keep "Save dashboard" / "Edit dashboard" + "Dashboard configuration…",
 *  - drop the inline list of dashboards (sidebar owns navigation),
 *  - drop the legacy "Add tile…" and "Add widget…" entries (already
 *    consolidated by `unified-add-widget-flow`),
 *  - drop the "Powered by Sendent / Conduction" footer (moved to the
 *    sidebar by `dashboard-switcher-extensions`),
 *  - drop "Create dashboard…" — the left sidebar's "+" affordance is the
 *    only entry point now (wave3.2),
 *  - drop "Documentation" — the sidebar footer hosts the only link now
 *    (wave3.2).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import DashboardConfigMenu from '../DashboardConfigMenu.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

/**
 * Mount helper — stubs the @nextcloud/vue Action* primitives down to
 * predictable host elements so we can introspect labels / hrefs without
 * pulling in the full design system. Each stub renders both its `icon`
 * slot and default slot so the visible text is still in the DOM.
 *
 * @param {object} options mount overrides
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountMenu(options = {}) {
	const inject = {
		allowUserDashboards: true,
		...(options.inject || {}),
	}
	return mount(DashboardConfigMenu, {
		propsData: {
			activeDashboardId: 'd1',
			isEditMode: false,
			canEdit: true,
			isActiveOwner: true,
			...(options.propsData || {}),
		},
		provide: inject,
		stubs: {
			NcActions: {
				name: 'NcActions',
				template: '<div class="nc-actions-stub"><slot name="icon" /><slot /></div>',
			},
			NcActionButton: {
				name: 'NcActionButton',
				template: '<button class="nc-action-button-stub" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
			},
			NcActionLink: {
				name: 'NcActionLink',
				props: ['href'],
				template: '<a class="nc-action-link-stub" :href="href"><slot name="icon" /><slot /></a>',
			},
			NcActionSeparator: {
				name: 'NcActionSeparator',
				template: '<hr class="nc-action-separator-stub" />',
			},
			NcActionCaption: {
				name: 'NcActionCaption',
				props: ['name'],
				template: '<div class="nc-action-caption-stub">{{ name }}</div>',
			},
			Cog: true,
			Plus: true,
			Pencil: true,
			ContentSave: true,
			Tune: true,
			ShapePolygonPlus: true,
			BookOpenVariantOutline: true,
		},
	})
}

describe('DashboardConfigMenu', () => {
	it('keeps "Add custom widget…" when canEdit and registry has types', () => {
		const wrapper = mountMenu()
		const buttons = wrapper.findAll('.nc-action-button-stub')
		const labels = buttons.wrappers.map(w => w.text())
		expect(labels).toContain('Add custom widget…')
	})

	it('keeps "Edit dashboard" / "Save dashboard" toggle when canEdit', async () => {
		const wrapper = mountMenu({ propsData: { isEditMode: false } })
		expect(wrapper.findAll('.nc-action-button-stub').wrappers.map(w => w.text())).toContain('Edit dashboard')

		const wrapperEditing = mountMenu({ propsData: { isEditMode: true } })
		expect(wrapperEditing.findAll('.nc-action-button-stub').wrappers.map(w => w.text())).toContain('Save dashboard')
	})

	it('keeps "Dashboard configuration…" for the active owner', () => {
		const wrapper = mountMenu()
		const labels = wrapper.findAll('.nc-action-button-stub').wrappers.map(w => w.text())
		expect(labels).toContain('Dashboard configuration…')
	})

	it('wave3.2: does NOT render "Documentation" link (sidebar footer hosts it)', () => {
		const wrapper = mountMenu()
		const links = wrapper.findAll('.nc-action-link-stub').wrappers.map(w => w.text())
		expect(links).not.toContain('Documentation')
	})

	it('runtime-shell-trim: does NOT render the inline dashboards list', () => {
		const wrapper = mountMenu()
		// The previous implementation rendered an `NcActionCaption` with
		// the literal string "Dashboards" + an `NcActionButton` per row.
		// Both surfaces must be gone (sidebar owns navigation).
		const captions = wrapper.findAll('.nc-action-caption-stub').wrappers.map(w => w.text())
		expect(captions).not.toContain('Dashboards')
		expect(wrapper.emitted('switch-dashboard')).toBeUndefined()
	})

	it('runtime-shell-trim: does NOT render legacy "Add tile…" / "Add widget…" entries', () => {
		const wrapper = mountMenu()
		const labels = wrapper.findAll('.nc-action-button-stub').wrappers.map(w => w.text())
		expect(labels).not.toContain('Add tile…')
		expect(labels).not.toContain('Add widget…')
	})

	it('runtime-shell-trim: does NOT render the Powered by Sendent / Conduction footer', () => {
		const wrapper = mountMenu()
		const captions = wrapper.findAll('.nc-action-caption-stub').wrappers.map(w => w.text())
		expect(captions).not.toContain('Powered by')
		const links = wrapper.findAll('.nc-action-link-stub').wrappers.map(w => w.attributes('href'))
		expect(links).not.toContain('https://sendent.com')
		expect(links).not.toContain('https://conduction.nl')
	})

	it('wave3.2: never renders "Create dashboard…" (sidebar "+" is the only entry point)', () => {
		const wrapperOn = mountMenu({ inject: { allowUserDashboards: true } })
		const wrapperOff = mountMenu({ inject: { allowUserDashboards: false } })
		for (const w of [wrapperOn, wrapperOff]) {
			const labels = w.findAll('.nc-action-button-stub').wrappers.map(b => b.text())
			expect(labels).not.toContain('Create dashboard…')
		}
	})
})
