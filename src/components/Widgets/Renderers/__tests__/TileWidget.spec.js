/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `TileWidget.vue` covering REQ-WDG-022 (tile widget
 * type registered) and REQ-TILE-PLACEMENT (inline-content tile placements
 * promoted to canonical, with dual-shape rendering for legacy rows).
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TileWidget from '../TileWidget.vue'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('TileWidget', () => {
	it('REQ-WDG-022: renders title + icon + colour from new content shape', () => {
		const wrapper = mount(TileWidget, {
			propsData: {
				content: {
					title: 'Files',
					icon: 'icon-folder',
					iconType: 'class',
					linkType: 'app',
					linkValue: '/apps/files',
					backgroundColor: '#3b82f6',
					textColor: '#ffffff',
				},
			},
		})
		expect(wrapper.text()).toContain('Files')
		expect(wrapper.find('.tile-widget-renderer__icon-class').exists()).toBe(true)
		const root = wrapper.find('.tile-widget-renderer')
		const style = root.attributes('style') || ''
		expect(style).toContain('rgb(59, 130, 246)')
	})

	it('REQ-WDG-022: app linkType resolves through generateUrl on the anchor', () => {
		const wrapper = mount(TileWidget, {
			propsData: {
				content: {
					title: 'Files',
					linkType: 'app',
					linkValue: '/apps/files',
				},
			},
		})
		const anchor = wrapper.find('a.tile-widget-renderer__link')
		expect(anchor.attributes('href')).toBe('/index.php/apps/files')
		expect(anchor.attributes('target')).toBe('_self')
	})

	it('REQ-WDG-022: url linkType opens externally and prevents default navigation', async () => {
		const openSpy = vi.fn()
		const originalOpen = window.open
		window.open = openSpy

		try {
			const wrapper = mount(TileWidget, {
				propsData: {
					content: {
						title: 'Conduction',
						linkType: 'url',
						linkValue: 'https://conduction.nl',
					},
				},
			})
			const anchor = wrapper.find('a.tile-widget-renderer__link')
			expect(anchor.attributes('target')).toBe('_blank')
			expect(anchor.attributes('rel')).toBe('noopener noreferrer')
			await anchor.trigger('click')
			expect(openSpy).toHaveBeenCalledWith(
				'https://conduction.nl',
				'_blank',
				'noopener,noreferrer',
			)
		} finally {
			window.open = originalOpen
		}
	})

	it('REQ-WDG-022 / REQ-WDG-014: click is suppressed when in admin/edit mode', async () => {
		const openSpy = vi.fn()
		const originalOpen = window.open
		window.open = openSpy

		try {
			const wrapper = mount(TileWidget, {
				propsData: {
					content: {
						title: 'Test',
						linkType: 'url',
						linkValue: 'https://example.com',
					},
					isAdmin: true,
					canEdit: true,
				},
			})
			const anchor = wrapper.find('a.tile-widget-renderer__link')
			await anchor.trigger('click')
			expect(openSpy).not.toHaveBeenCalled()
		} finally {
			window.open = originalOpen
		}
	})

	it('REQ-TILE-PLACEMENT: renders correctly from legacy flat placement.tile* fields', () => {
		const wrapper = mount(TileWidget, {
			propsData: {
				content: {},
				placement: {
					tileTitle: 'Old Tile',
					tileIcon: 'icon-folder',
					tileIconType: 'class',
					tileLinkType: 'app',
					tileLinkValue: '/apps/files',
					tileBackgroundColor: '#ff0000',
					tileTextColor: '#000000',
				},
			},
		})
		expect(wrapper.text()).toContain('Old Tile')
		expect(wrapper.find('.tile-widget-renderer__icon-class').exists()).toBe(true)
		const anchor = wrapper.find('a.tile-widget-renderer__link')
		expect(anchor.attributes('href')).toBe('/index.php/apps/files')
	})

	it('REQ-TILE-PLACEMENT: legacy emoji icon renders without throwing', () => {
		const wrapper = mount(TileWidget, {
			propsData: {
				content: {},
				placement: {
					tileTitle: 'Emoji Tile',
					tileIcon: '📁',
					tileIconType: 'emoji',
					tileLinkType: 'url',
					tileLinkValue: 'https://example.com',
				},
			},
		})
		expect(wrapper.find('.tile-widget-renderer__emoji').text()).toBe('📁')
	})

	it('REQ-WDG-022: new content shape takes precedence over legacy fields when both exist', () => {
		const wrapper = mount(TileWidget, {
			propsData: {
				content: { title: 'New', linkType: 'app', linkValue: '/x' },
				placement: { tileTitle: 'Legacy' },
			},
		})
		expect(wrapper.text()).toContain('New')
		expect(wrapper.text()).not.toContain('Legacy')
	})
})
