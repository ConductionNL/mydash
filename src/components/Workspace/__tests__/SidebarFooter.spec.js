/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `SidebarFooter.vue` (capability
 * `dashboard-switcher`). Covers REQ-SWITCH-009:
 *
 *  - Sendent + Conduction logos are wrapped in `<a target="_blank"
 *    rel="noopener noreferrer">` (security gate; never omit the rel)
 *  - Documentation link points at the same URL the gear-menu Documentation
 *    entry used before runtime-shell-trim (https://mydash.conduction.nl)
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SidebarFooter, { DOCS_URL } from '../SidebarFooter.vue'

vi.mock('@nextcloud/router', () => ({
	generateFilePath: (app, folder, file) => `/apps/${app}/${folder}/${file}`,
}))

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

function mountFooter() {
	return mount(SidebarFooter, {
		stubs: {
			BookOpenVariantOutline: { template: '<span class="book-icon-stub" />' },
		},
	})
}

describe('SidebarFooter', () => {
	describe('Brand attribution', () => {
		it('renders the Sendent logo wrapped in a target=_blank, rel=noopener noreferrer link', () => {
			const wrapper = mountFooter()
			const links = wrapper.findAll('a.dashboard-switcher-sidebar-footer__brand-link')
			expect(links.length).toBe(2)

			const sendent = links.at(0)
			expect(sendent.attributes('href')).toBe('https://sendent.com')
			expect(sendent.attributes('target')).toBe('_blank')
			expect(sendent.attributes('rel')).toBe('noopener noreferrer')
			expect(sendent.attributes('aria-label')).toBe('Sendent')
			const img = sendent.find('img')
			expect(img.attributes('alt')).toBe('Sendent')
			expect(img.attributes('src')).toBe('/apps/mydash/img/sendent-logo.png')
		})

		it('renders the Conduction logo wrapped in a target=_blank, rel=noopener noreferrer link', () => {
			const wrapper = mountFooter()
			const links = wrapper.findAll('a.dashboard-switcher-sidebar-footer__brand-link')
			const conduction = links.at(1)
			expect(conduction.attributes('href')).toBe('https://conduction.nl')
			expect(conduction.attributes('target')).toBe('_blank')
			expect(conduction.attributes('rel')).toBe('noopener noreferrer')
			expect(conduction.attributes('aria-label')).toBe('Conduction')
			const img = conduction.find('img')
			expect(img.attributes('alt')).toBe('Conduction')
			expect(img.attributes('src')).toBe('/apps/mydash/img/conduction-logo.png')
		})

		it('neither brand link omits rel="noopener noreferrer" (security gate)', () => {
			const wrapper = mountFooter()
			const links = wrapper.findAll('a.dashboard-switcher-sidebar-footer__brand-link')
			links.wrappers.forEach((link) => {
				expect(link.attributes('rel')).toBe('noopener noreferrer')
				expect(link.attributes('target')).toBe('_blank')
			})
		})

		it('renders the localised "Powered by" caption', () => {
			const wrapper = mountFooter()
			expect(wrapper.find('.dashboard-switcher-sidebar-footer__brand-caption').text())
				.toBe('Powered by')
		})
	})

	describe('Documentation link', () => {
		it('renders the documentation link with the same URL the gear-menu used', () => {
			const wrapper = mountFooter()
			const link = wrapper.find('a.dashboard-switcher-sidebar-footer__doc-link')
			expect(link.exists()).toBe(true)
			expect(link.attributes('href')).toBe(DOCS_URL)
			expect(link.attributes('href')).toBe('https://mydash.conduction.nl')
			expect(link.attributes('target')).toBe('_blank')
			expect(link.attributes('rel')).toBe('noopener noreferrer')
		})

		it('renders the localised "Documentation" label next to the icon', () => {
			const wrapper = mountFooter()
			const link = wrapper.find('a.dashboard-switcher-sidebar-footer__doc-link')
			expect(link.find('.dashboard-switcher-sidebar-footer__doc-label').text())
				.toBe('Documentation')
			expect(link.find('.book-icon-stub').exists()).toBe(true)
		})
	})
})
