/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `dashboardIcons.js` covering REQ-ICON-001 through
 * REQ-ICON-006 — the curated registry, the resolver's tolerance for
 * null/undefined/empty/unknown, and the URL discriminator + URL-input
 * special case added by `custom-icon-upload-pattern`.
 */

import { describe, it, expect } from 'vitest'

import {
	DASHBOARD_ICONS,
	DEFAULT_ICON,
	getIconComponent,
	isCustomIconUrl,
} from '../dashboardIcons.js'

const REQUIRED_NAMES = [
	'ViewDashboard',
	'Home',
	'ChartBar',
	'Cog',
	'AccountGroup',
	'Calendar',
	'FileDocument',
	'Bell',
	'Star',
	'Heart',
	'BookOpenVariant',
	'Lightbulb',
	'RocketLaunch',
	'Earth',
	'Briefcase',
]

describe('dashboardIcons', () => {
	describe('REQ-ICON-001: curated registry', () => {
		it('contains every required icon name', () => {
			for (const name of REQUIRED_NAMES) {
				expect(DASHBOARD_ICONS[name]).toBeDefined()
			}
		})

		it('contains at least 15 entries', () => {
			expect(Object.keys(DASHBOARD_ICONS).length).toBeGreaterThanOrEqual(15)
		})

		it('DEFAULT_ICON is the string "ViewDashboard"', () => {
			expect(DEFAULT_ICON).toBe('ViewDashboard')
		})

		it('DASHBOARD_ICONS[DEFAULT_ICON] is defined', () => {
			expect(DASHBOARD_ICONS[DEFAULT_ICON]).toBeDefined()
		})

		it('the registry object is frozen (no parallel ad-hoc mutation)', () => {
			expect(Object.isFrozen(DASHBOARD_ICONS)).toBe(true)
		})
	})

	describe('REQ-ICON-001 / REQ-ICON-002: getIconComponent registry resolution', () => {
		it('resolves a known built-in name to that component', () => {
			expect(getIconComponent('Star')).toBe(DASHBOARD_ICONS.Star)
		})

		it('resolves DEFAULT_ICON name to the default component', () => {
			expect(getIconComponent(DEFAULT_ICON)).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
		})

		it('null falls back to DEFAULT_ICON without throwing', () => {
			expect(() => getIconComponent(null)).not.toThrow()
			expect(getIconComponent(null)).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
		})

		it('undefined falls back to DEFAULT_ICON without throwing', () => {
			expect(() => getIconComponent(undefined)).not.toThrow()
			expect(getIconComponent(undefined)).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
		})

		it('empty string falls back to DEFAULT_ICON without throwing', () => {
			expect(() => getIconComponent('')).not.toThrow()
			expect(getIconComponent('')).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
		})

		it('unknown name falls back to DEFAULT_ICON', () => {
			expect(getIconComponent('NonExistent')).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
		})

		it('never returns null/undefined for non-URL inputs', () => {
			for (const v of [null, undefined, '', 'NonExistent', 'Star', 0, false, {}]) {
				expect(getIconComponent(v)).toBeTruthy()
			}
		})
	})

	describe('REQ-ICON-005: isCustomIconUrl discriminator', () => {
		it('returns true for absolute paths starting with /', () => {
			expect(isCustomIconUrl('/foo.svg')).toBe(true)
			expect(isCustomIconUrl('/apps/mydash/resource/abc.png')).toBe(true)
		})

		it('returns true for an https URL', () => {
			expect(isCustomIconUrl('https://example.com/y.png')).toBe(true)
		})

		it('returns true for an http URL', () => {
			expect(isCustomIconUrl('http://example.com/y.png')).toBe(true)
		})

		it('returns false for built-in registry names', () => {
			expect(isCustomIconUrl('Star')).toBe(false)
			expect(isCustomIconUrl('ViewDashboard')).toBe(false)
			expect(isCustomIconUrl('Home')).toBe(false)
		})

		it('returns false for empty string', () => {
			expect(isCustomIconUrl('')).toBe(false)
		})

		it('returns false for null', () => {
			expect(isCustomIconUrl(null)).toBe(false)
		})

		it('returns false for undefined', () => {
			expect(isCustomIconUrl(undefined)).toBe(false)
		})

		it('returns false for non-string inputs', () => {
			expect(isCustomIconUrl(42)).toBe(false)
			expect(isCustomIconUrl({})).toBe(false)
			expect(isCustomIconUrl([])).toBe(false)
		})
	})

	describe('REQ-ICON-006: getIconComponent URL handling', () => {
		it('returns null for URL inputs (caller must use <img>)', () => {
			expect(getIconComponent('/apps/mydash/resource/x.png')).toBeNull()
			expect(getIconComponent('https://example.com/icon.svg')).toBeNull()
			expect(getIconComponent('http://example.com/icon.png')).toBeNull()
		})

		it('still returns DEFAULT_ICON component for unknown registry names (REQ-ICON-001 holds)', () => {
			const result = getIconComponent('NotARegistryEntry')
			expect(result).toBe(DASHBOARD_ICONS[DEFAULT_ICON])
			expect(result).not.toBeNull()
		})
	})
})
