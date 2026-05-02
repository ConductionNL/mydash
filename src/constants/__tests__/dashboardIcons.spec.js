/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit test for `dashboardIcons.js` covering the discriminator and
 * resolver behaviour added by `custom-icon-upload-pattern`:
 *
 *   - REQ-ICON-005: `isCustomIconUrl(name)` truth table
 *     (URL prefixes vs. registry names vs. null/undefined/'')
 *   - REQ-ICON-006: `getIconComponent(name)` returns `null` for URL
 *     inputs while still returning `DEFAULT_ICON` for unknown registry
 *     names (so REQ-ICON-001 still holds).
 */

import { describe, it, expect } from 'vitest'

import {
	DASHBOARD_ICONS,
	DEFAULT_ICON,
	getIconComponent,
	isCustomIconUrl,
} from '../dashboardIcons.js'

describe('isCustomIconUrl (REQ-ICON-005)', () => {
	it('returns true for absolute paths starting with /', () => {
		expect(isCustomIconUrl('/apps/mydash/resource/abc.png')).toBe(true)
		expect(isCustomIconUrl('/foo')).toBe(true)
	})

	it('returns true for http and https URLs', () => {
		expect(isCustomIconUrl('http://example.com/icon.png')).toBe(true)
		expect(isCustomIconUrl('https://example.com/icon.svg')).toBe(true)
	})

	it('returns false for built-in registry names', () => {
		expect(isCustomIconUrl('Star')).toBe(false)
		expect(isCustomIconUrl('ViewDashboard')).toBe(false)
		expect(isCustomIconUrl('Home')).toBe(false)
	})

	it('returns false for null, undefined, and empty string', () => {
		expect(isCustomIconUrl(null)).toBe(false)
		expect(isCustomIconUrl(undefined)).toBe(false)
		expect(isCustomIconUrl('')).toBe(false)
	})

	it('returns false for non-string inputs', () => {
		expect(isCustomIconUrl(42)).toBe(false)
		expect(isCustomIconUrl({})).toBe(false)
		expect(isCustomIconUrl([])).toBe(false)
	})
})

describe('getIconComponent (REQ-ICON-006)', () => {
	it('returns null for URL inputs', () => {
		expect(getIconComponent('/apps/mydash/resource/x.png')).toBeNull()
		expect(getIconComponent('https://example.com/icon.svg')).toBeNull()
		expect(getIconComponent('http://example.com/icon.png')).toBeNull()
	})

	it('returns the registered component for a known registry name', () => {
		expect(getIconComponent('Star')).toBe(DASHBOARD_ICONS.Star)
		expect(getIconComponent('Home')).toBe(DASHBOARD_ICONS.Home)
	})

	it('returns DEFAULT_ICON component for an unknown registry name (REQ-ICON-001)', () => {
		const result = getIconComponent('NotARegistryEntry')
		expect(result).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
		expect(result).not.toBeNull()
	})

	it('returns DEFAULT_ICON component for null/undefined/empty input', () => {
		expect(getIconComponent(null)).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
		expect(getIconComponent(undefined)).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
		expect(getIconComponent('')).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
	})
})
