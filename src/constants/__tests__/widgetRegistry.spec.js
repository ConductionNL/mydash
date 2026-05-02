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
})
