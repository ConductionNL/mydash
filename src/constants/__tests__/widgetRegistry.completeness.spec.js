/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * REQ-WDG-023: completeness guard for `src/constants/widgetRegistry.js`.
 *
 * The Add Custom Widget picker (REQ-WDG-010) consults `listWidgetTypes()`
 * (REQ-WDG-014) to enumerate selectable widget types. Because the registry
 * file is a heavy merge-conflict site -- many widget capabilities ship in
 * parallel branches that all touch the same object -- a regression that
 * silently drops a registered type would silently disappear from the
 * picker UX without any test signal. This spec asserts:
 *
 *   1. The registry's key set EQUALS a canonical EXPECTED_TYPES constant
 *      (any drift in either direction fails the build with a precise
 *      diff message).
 *   2. Every EXPECTED_TYPES entry surfaces in `listWidgetTypes(t)` with a
 *      non-null `form` AND a non-null `renderer` -- i.e. the picker can
 *      actually offer the type to users.
 *   3. Every registered entry carries `displayName`, `defaultContent`,
 *      and `icon` so the picker tile renders cleanly.
 *
 * When a new widget capability lands, EXPECTED_TYPES MUST be updated in the
 * same commit -- the failing diff will tell the author exactly which key
 * to add (or remove on deprecation).
 */

import { describe, it, expect, beforeEach } from 'vitest'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

// Canonical widget type set. Sorted alphabetically. Update in lockstep
// with `src/constants/widgetRegistry.js` whenever a widget capability is
// added or removed -- the tests below will fail with a precise diff if
// this constant drifts out of sync with the registry.
const EXPECTED_TYPES = [
	'calendar',
	'container',
	'divider',
	'files',
	'header',
	'image',
	'label',
	'link',
	'links',
	'menu',
	'nc-widget',
	'news',
	'people',
	'quicklinks',
	'text',
	'tile',
	'video',
]

describe('widgetRegistry completeness (REQ-WDG-023)', () => {
	it('registry keys MUST equal EXPECTED_TYPES (set equality)', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		const actual = Object.keys(widgetRegistry).sort()
		const expected = [...EXPECTED_TYPES].sort()

		const missingFromRegistry = expected.filter((t) => !actual.includes(t))
		const unexpectedInRegistry = actual.filter((t) => !expected.includes(t))

		const diagnostic = []
		if (missingFromRegistry.length) {
			diagnostic.push(
				`REGRESSION: type(s) silently dropped from widgetRegistry: ${JSON.stringify(missingFromRegistry)}`,
			)
		}
		if (unexpectedInRegistry.length) {
			diagnostic.push(
				`DRIFT: widgetRegistry contains type(s) not in EXPECTED_TYPES: ${JSON.stringify(unexpectedInRegistry)}. `
				+ 'Update EXPECTED_TYPES in widgetRegistry.completeness.spec.js',
			)
		}

		expect(actual, diagnostic.join(' | ')).toEqual(expected)
	})

	it('listWidgetTypes() MUST surface every EXPECTED_TYPES entry with non-null form + renderer', async () => {
		const { widgetRegistry, listWidgetTypes } = await import('../widgetRegistry.js')
		const surfaced = listWidgetTypes()

		for (const type of EXPECTED_TYPES) {
			const entry = widgetRegistry[type]
			expect(entry, `EXPECTED_TYPES member '${type}' missing from widgetRegistry`).toBeDefined()
			expect(entry.form, `EXPECTED_TYPES member '${type}' has null/undefined form -- would be filtered out by listWidgetTypes`).toBeTruthy()
			expect(entry.renderer, `EXPECTED_TYPES member '${type}' has null/undefined renderer -- placement would not render`).toBeTruthy()
			expect(surfaced, `EXPECTED_TYPES member '${type}' missing from listWidgetTypes() output -- picker would not offer it`).toContain(type)
		}
	})

	it('every registered entry MUST have displayName, defaultContent, and icon set', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')

		for (const type of Object.keys(widgetRegistry)) {
			const entry = widgetRegistry[type]
			expect(entry.displayName, `entry '${type}' missing displayName`).toBeTruthy()
			expect(entry.icon, `entry '${type}' missing icon`).toBeTruthy()
			expect(entry.defaultContent, `entry '${type}' missing defaultContent`).toBeDefined()
			expect(typeof entry.defaultContent, `entry '${type}' defaultContent must be an object`).toBe('object')
			expect(entry.defaultContent, `entry '${type}' defaultContent must not be null`).not.toBeNull()
		}
	})
})
