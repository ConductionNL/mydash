/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit test for `widgetRegistry.js` covering REQ-LBL-007: importing
 * the registry exposes a `label` entry with the correct `defaultContent` and
 * the type appears in `listWidgetTypes()` so the AddWidgetModal type picker
 * can list it as a selectable option distinct from `text`.
 */

import { describe, it, expect, beforeEach } from 'vitest'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('widgetRegistry', () => {
	it('REQ-LBL-007: exposes a `label` entry with the proper defaultContent', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.label).toBeDefined()
		expect(widgetRegistry.label.defaultContent).toEqual({
			text: '',
			fontSize: '16px',
			color: '',
			backgroundColor: '',
			fontWeight: 'bold',
			textAlign: 'center',
		})
	})

	it('REQ-LBL-007: `label` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('label')
	})

	it('REQ-LBN-001..007: exposes a `link` entry with the proper defaultContent', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.link).toBeDefined()
		expect(widgetRegistry.link.defaultContent).toEqual({
			label: '',
			url: '',
			icon: '',
			actionType: 'external',
			backgroundColor: '',
			textColor: '',
		})
	})

	it('REQ-LBN-001..007: `link` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('link')
	})

	it('getWidgetTypeEntry returns null for unknown type', async () => {
		const { getWidgetTypeEntry } = await import('../widgetRegistry.js')
		expect(getWidgetTypeEntry('does-not-exist')).toBeNull()
	})

	it('getDefaultContent returns a fresh copy of defaults', async () => {
		const { getDefaultContent } = await import('../widgetRegistry.js')
		const a = getDefaultContent('label')
		const b = getDefaultContent('label')
		expect(a).toEqual(b)
		expect(a).not.toBe(b)
	})

	it('REQ-TXT-004/005: exposes a `text` entry with the proper defaultContent', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.text).toBeDefined()
		expect(widgetRegistry.text.defaultContent).toEqual({
			text: '',
			fontSize: '14px',
			color: '',
			backgroundColor: '',
			textAlign: 'left',
		})
	})

	it('REQ-TXT-004: `text` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('text')
	})

	it('REQ-WDG-014: listWidgetTypes() omits entries without a registered form', async () => {
		// Per-widget proposals (text, image, link-button, nc-dashboard-proxy)
		// each register their sub-form when they ship. Until then those
		// entries either don't exist or carry `form: null` — the picker
		// MUST exclude them so the user is never offered an unconfigurable
		// type. We simulate the situation by mutating the registry in
		// place and asserting the filter behaviour, then reset.
		const mod = await import('../widgetRegistry.js')
		mod.widgetRegistry.__formless_test__ = {
			renderer: {},
			form: null,
			defaultContent: {},
			displayName: 'Formless',
			icon: 'X',
		}
		try {
			const types = mod.listWidgetTypes()
			expect(types).toContain('label')
			expect(types).not.toContain('__formless_test__')
		} finally {
			delete mod.widgetRegistry.__formless_test__
		}
	})

	it('REQ-IMG-005: exposes an `image` entry with renderer + form + defaults', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.image).toBeDefined()
		expect(widgetRegistry.image.renderer).toBeTruthy()
		expect(widgetRegistry.image.form).toBeTruthy()
		expect(widgetRegistry.image.defaultContent).toEqual({
			url: '',
			alt: '',
			link: '',
			fit: 'cover',
		})
	})

	it('REQ-IMG-005: `image` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('image')
	})
})
