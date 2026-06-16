/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `QuicklinksWidget.vue` covering REQ-QLNK-001
 * (registration shape via defaults), REQ-QLNK-002 (config round-trip),
 * REQ-QLNK-003 (icon resolution), REQ-QLNK-004 (sizes / shapes),
 * REQ-QLNK-005 (label position control), REQ-QLNK-006 (column layout),
 * REQ-QLNK-007 (hover effect classes), REQ-QLNK-009 (click navigation +
 * edit-mode suppression), REQ-QLNK-010 (accessibility), and
 * REQ-QLNK-011 (empty state).
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import QuicklinksWidget from '../QuicklinksWidget.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

const baseLink = (overrides = {}) => ({
	label: 'Docs',
	url: 'https://docs.example.com',
	icon: 'BookOpenVariant',
	...overrides,
})

const mountWidget = (content = {}, props = {}) => mount(QuicklinksWidget, {
	propsData: { content, ...props },
	stubs: { IconRenderer: true },
})

describe('QuicklinksWidget', () => {
	it('REQ-QLNK-011: empty state renders when links is empty or missing', () => {
		const wrapper = mountWidget({ links: [] })
		expect(wrapper.find('.quicklinks-widget__empty').exists()).toBe(true)
		expect(wrapper.text()).toContain('No quicklinks yet — click the gear icon to add some.')
	})

	it('REQ-QLNK-011: empty state click emits "edit" event', async () => {
		const wrapper = mountWidget({ links: [] })
		await wrapper.find('.quicklinks-widget__empty-button').trigger('click')
		expect(wrapper.emitted('edit')).toBeTruthy()
	})

	it('REQ-QLNK-004: icon size small maps to 32px', () => {
		const wrapper = mountWidget({ links: [baseLink()], iconSize: 'small' })
		const icon = wrapper.find('.quicklinks-widget__icon')
		expect(icon.attributes('style')).toContain('width: 32px')
		expect(icon.attributes('style')).toContain('height: 32px')
	})

	it('REQ-QLNK-004: icon size xlarge maps to 96px', () => {
		const wrapper = mountWidget({ links: [baseLink()], iconSize: 'xlarge' })
		const icon = wrapper.find('.quicklinks-widget__icon')
		expect(icon.attributes('style')).toContain('width: 96px')
		expect(icon.attributes('style')).toContain('height: 96px')
	})

	it('REQ-QLNK-004: shape circle maps to border-radius 50%', () => {
		const wrapper = mountWidget({ links: [baseLink()], iconShape: 'circle' })
		const icon = wrapper.find('.quicklinks-widget__icon')
		expect(icon.attributes('style')).toContain('border-radius: 50%')
	})

	it('REQ-QLNK-004: shape square maps to border-radius 0', () => {
		const wrapper = mountWidget({ links: [baseLink()], iconShape: 'square' })
		const icon = wrapper.find('.quicklinks-widget__icon')
		expect(icon.attributes('style')).toContain('border-radius: 0')
	})

	it('REQ-QLNK-005: showLabels false suppresses all labels', () => {
		const wrapper = mountWidget({ links: [baseLink()], showLabels: false })
		expect(wrapper.find('.quicklinks-widget__label').exists()).toBe(false)
	})

	it('REQ-QLNK-005: labelPosition below renders the below-class label', () => {
		const wrapper = mountWidget({ links: [baseLink()], labelPosition: 'below' })
		expect(wrapper.find('.quicklinks-widget__label--below').exists()).toBe(true)
	})

	it('REQ-QLNK-005: labelPosition overlay renders the overlay-class label', () => {
		const wrapper = mountWidget({ links: [baseLink()], labelPosition: 'overlay' })
		expect(wrapper.find('.quicklinks-widget__label--overlay').exists()).toBe(true)
	})

	it('REQ-QLNK-006: columns "auto" uses flex-wrap', () => {
		const wrapper = mountWidget({ links: [baseLink()], columns: 'auto' })
		const list = wrapper.find('.quicklinks-widget__list')
		expect(list.attributes('style')).toContain('flex-wrap: wrap')
	})

	it('REQ-QLNK-006: numeric columns uses CSS grid with repeat(N, 1fr)', () => {
		const wrapper = mountWidget({ links: [baseLink()], columns: 3 })
		const list = wrapper.find('.quicklinks-widget__list')
		expect(list.attributes('style')).toContain('grid-template-columns: repeat(3, 1fr)')
	})

	it('REQ-QLNK-006: invalid columns value falls back to auto', () => {
		const wrapper = mountWidget({ links: [baseLink()], columns: 99 })
		const list = wrapper.find('.quicklinks-widget__list')
		expect(list.attributes('style')).toContain('flex-wrap: wrap')
	})

	it('REQ-QLNK-007: hover effect applies a class on the root', () => {
		const wrapper = mountWidget({ links: [baseLink()], hoverEffect: 'fade' })
		expect(wrapper.find('.quicklinks-widget--hover-fade').exists()).toBe(true)
	})

	it('REQ-QLNK-007: invalid hover effect falls back to lift', () => {
		const wrapper = mountWidget({ links: [baseLink()], hoverEffect: 'bogus' })
		expect(wrapper.find('.quicklinks-widget--hover-lift').exists()).toBe(true)
	})

	it('REQ-QLNK-003: empty icon renders the IconRenderer fallback (Link)', () => {
		const wrapper = mountWidget({ links: [{ label: 'L', url: 'https://e', icon: '' }] })
		// IconRenderer is stubbed — just confirm it is rendered (not an <img>).
		expect(wrapper.find('.quicklinks-widget__icon img').exists()).toBe(false)
	})

	it('REQ-QLNK-003: icon URL renders as <img>', () => {
		const wrapper = mountWidget({
			links: [{ label: 'L', url: 'https://e', icon: '/apps/mydash/resource/x.png' }],
			iconSize: 'medium',
		})
		const img = wrapper.find('.quicklinks-widget__icon img')
		expect(img.exists()).toBe(true)
		expect(img.attributes('src')).toBe('/apps/mydash/resource/x.png')
		expect(img.attributes('width')).toBe('48')
	})

	it('REQ-QLNK-009: external URL click invokes window.open with noopener,noreferrer', async () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mountWidget({ links: [baseLink({ url: 'https://example.com' })] })
		await wrapper.find('a.quicklinks-widget__link').trigger('click')
		expect(openSpy).toHaveBeenCalledWith('https://example.com', '_blank', 'noopener,noreferrer')
		openSpy.mockRestore()
	})

	it('REQ-QLNK-009: edit mode suppresses click navigation', async () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mountWidget(
			{ links: [baseLink({ url: 'https://example.com' })] },
			{ isAdmin: true, canEdit: true },
		)
		await wrapper.find('a.quicklinks-widget__link').trigger('click')
		expect(openSpy).not.toHaveBeenCalled()
		openSpy.mockRestore()
	})

	it('REQ-QLNK-009: relative URL keeps the default same-tab navigation (no window.open)', async () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mountWidget({ links: [baseLink({ url: '/apps/files/' })] })
		await wrapper.find('a.quicklinks-widget__link').trigger('click')
		expect(openSpy).not.toHaveBeenCalled()
		openSpy.mockRestore()
	})

	it('REQ-QLNK-009: openInNewTab=true on a relative URL opens in a new tab', async () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mountWidget({
			links: [baseLink({ url: '/apps/files/', openInNewTab: true })],
		})
		await wrapper.find('a.quicklinks-widget__link').trigger('click')
		expect(openSpy).toHaveBeenCalledWith('/apps/files/', '_blank', 'noopener,noreferrer')
		openSpy.mockRestore()
	})

	it('REQ-QLNK-010: each link is an <a> with href, aria-label, and external rel', () => {
		const wrapper = mountWidget({
			links: [baseLink({ label: 'Docs', url: 'https://docs.example.com' })],
			labelPosition: 'below',
		})
		const a = wrapper.find('a.quicklinks-widget__link')
		expect(a.exists()).toBe(true)
		expect(a.attributes('href')).toBe('https://docs.example.com')
		expect(a.attributes('aria-label')).toBe('Docs')
		expect(a.attributes('target')).toBe('_blank')
		expect(a.attributes('rel')).toBe('noopener noreferrer')
	})

	it('REQ-QLNK-010: aria-label uses hostname when label is overlay-only', () => {
		const wrapper = mountWidget({
			links: [baseLink({ label: 'Docs', url: 'https://docs.example.com' })],
			labelPosition: 'overlay',
		})
		const a = wrapper.find('a.quicklinks-widget__link')
		expect(a.attributes('aria-label')).toContain('docs.example.com')
	})

	it('REQ-QLNK-002: links default to empty array when content omitted', () => {
		const wrapper = mountWidget({})
		expect(wrapper.find('.quicklinks-widget__empty').exists()).toBe(true)
	})

	it('REQ-QLNK-002: solid tile background applies link color', () => {
		const wrapper = mountWidget({
			links: [baseLink({ color: '#ff0000' })],
			tileBackgroundStyle: 'solid',
		})
		const icon = wrapper.find('.quicklinks-widget__icon')
		expect(icon.attributes('style')).toContain('background-color: rgb(255, 0, 0)')
	})

	it('REQ-QLNK-006: filters out non-object link entries', () => {
		const wrapper = mountWidget({ links: [baseLink(), null, 42, baseLink()] })
		const items = wrapper.findAll('.quicklinks-widget__item')
		expect(items.length).toBe(2)
	})
})
