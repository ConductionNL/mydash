/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `SidebarBackdrop.vue`. The backdrop is a tiny
 * presentational component — no props, no state — so its only contract
 * is "emits a `click` event when clicked". The parent is responsible
 * for closing whichever sidebar is open.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SidebarBackdrop from '../SidebarBackdrop.vue'

describe('SidebarBackdrop', () => {
	it('renders a single .sidebar-backdrop element', () => {
		const wrapper = mount(SidebarBackdrop)
		expect(wrapper.find('.sidebar-backdrop').exists()).toBe(true)
	})

	it('emits "click" once per click', async () => {
		const wrapper = mount(SidebarBackdrop)
		await wrapper.find('.sidebar-backdrop').trigger('click')
		expect(wrapper.emitted('click')).toBeTruthy()
		expect(wrapper.emitted('click').length).toBe(1)
	})
})
