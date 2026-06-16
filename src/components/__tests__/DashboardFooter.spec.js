/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `DashboardFooter.vue` (REQ-FTR-004, REQ-FTR-006,
 * REQ-FTR-007). The component is a pure renderer of a server-resolved
 * payload — every conditional branch (HTML mode, structured mode,
 * locale-variant pick, hidden-when-null) is exercised here.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import DashboardFooter from '../DashboardFooter.vue'

describe('DashboardFooter', () => {
	it('renders nothing when footer prop is null (REQ-FTR-001 disabled)', () => {
		const wrapper = mount(DashboardFooter, { propsData: { footer: null } })
		expect(wrapper.find('.mydash-footer').exists()).toBe(false)
	})

	it('renders HTML mode (REQ-FTR-004)', () => {
		const wrapper = mount(DashboardFooter, {
			propsData: {
				footer: {
					mode: 'global',
					html: '<p>Copyright 2026</p>',
					config: null,
					backgroundColor: null,
					textColor: null,
				},
			},
		})
		const html = wrapper.find('.mydash-footer__html')
		expect(html.exists()).toBe(true)
		expect(html.html()).toContain('Copyright 2026')
	})

	it('renders structured columns layout (REQ-FTR-004)', () => {
		const wrapper = mount(DashboardFooter, {
			propsData: {
				footer: {
					mode: 'global',
					html: null,
					config: {
						organisation: 'ACME',
						address: 'Main 1\n1234 AB Amsterdam',
						links: [{ label: 'Privacy', url: 'https://e.com/p' }],
						legal: 'All rights reserved',
						layoutMode: 'columns',
					},
					backgroundColor: null,
					textColor: null,
				},
			},
		})
		expect(wrapper.find('.mydash-footer__config--columns').exists()).toBe(true)
		expect(wrapper.text()).toContain('ACME')
		expect(wrapper.text()).toContain('1234 AB Amsterdam')
		expect(wrapper.text()).toContain('Privacy')
	})

	it('renders structured inline layout (REQ-FTR-004)', () => {
		const wrapper = mount(DashboardFooter, {
			propsData: {
				footer: {
					mode: 'global',
					html: null,
					config: { organisation: 'ACME', layoutMode: 'inline' },
					backgroundColor: null,
					textColor: null,
				},
			},
		})
		expect(wrapper.find('.mydash-footer__config--inline').exists()).toBe(true)
	})

	it('picks locale variant when html is a map (REQ-FTR-007)', () => {
		const wrapper = mount(DashboardFooter, {
			propsData: {
				footer: {
					mode: 'global',
					html: { en: '<p>Welcome</p>', nl: '<p>Welkom</p>' },
					config: null,
					backgroundColor: null,
					textColor: null,
				},
				locale: 'nl',
			},
		})
		expect(wrapper.html()).toContain('Welkom')
		expect(wrapper.html()).not.toContain('Welcome')
	})

	it('falls back to first key when locale missing (REQ-FTR-007)', () => {
		const wrapper = mount(DashboardFooter, {
			propsData: {
				footer: {
					mode: 'global',
					html: { fr: '<p>Bonjour</p>', nl: '<p>Welkom</p>' },
					config: null,
					backgroundColor: null,
					textColor: null,
				},
				locale: 'ja',
			},
		})
		expect(wrapper.html()).toContain('Bonjour')
	})

	it('applies admin colour overrides as inline style (REQ-FTR-009)', () => {
		const wrapper = mount(DashboardFooter, {
			propsData: {
				footer: {
					mode: 'global',
					html: '<p>X</p>',
					config: null,
					backgroundColor: '#1a1a1a',
					textColor: '#ffffff',
				},
			},
		})
		const style = wrapper.find('.mydash-footer').attributes('style') || ''
		expect(style).toContain('rgb(26, 26, 26)')
		expect(style).toContain('rgb(255, 255, 255)')
	})

	it('renders nothing when both html and config are empty (REQ-FTR-001)', () => {
		const wrapper = mount(DashboardFooter, {
			propsData: {
				footer: {
					mode: 'global',
					html: '',
					config: null,
					backgroundColor: null,
					textColor: null,
				},
			},
		})
		expect(wrapper.find('.mydash-footer').exists()).toBe(false)
	})
})
