/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for the `useUrlSanitiser` helpers covering
 * REQ-QLNK-008 (URL sanitisation) — accepting `http(s)://`, `mailto:`,
 * `tel:`, and relative `/` URLs while rejecting `javascript:`, `data:`,
 * `vbscript:`, empty values, and oversized inputs.
 */

import { describe, it, expect } from 'vitest'
import { sanitiseUrl, validateUrl, isExternalUrl, MAX_URL_LENGTH } from '../useUrlSanitiser.js'

describe('useUrlSanitiser', () => {
	describe('sanitiseUrl', () => {
		it('REQ-QLNK-008: trims whitespace from a safe URL', () => {
			expect(sanitiseUrl('  https://example.com  ')).toBe('https://example.com')
		})

		it('REQ-QLNK-008: drops javascript: URLs', () => {
			expect(sanitiseUrl('javascript:alert(1)')).toBe('')
			expect(sanitiseUrl('JAVASCRIPT:alert(1)')).toBe('')
		})

		it('REQ-QLNK-008: drops data: URLs', () => {
			expect(sanitiseUrl('data:text/html,<img src=x onerror=alert(1)>')).toBe('')
		})

		it('REQ-QLNK-008: drops vbscript: URLs', () => {
			expect(sanitiseUrl('vbscript:msgbox(1)')).toBe('')
		})

		it('REQ-QLNK-008: returns empty string for non-string input', () => {
			expect(sanitiseUrl(null)).toBe('')
			expect(sanitiseUrl(undefined)).toBe('')
			expect(sanitiseUrl(42)).toBe('')
		})

		it('REQ-QLNK-008: returns empty string for empty/whitespace input', () => {
			expect(sanitiseUrl('')).toBe('')
			expect(sanitiseUrl('   ')).toBe('')
		})
	})

	describe('validateUrl', () => {
		it('REQ-QLNK-008: accepts http(s) URLs', () => {
			expect(validateUrl('http://example.com')).toBe(true)
			expect(validateUrl('https://example.com/path?q=1')).toBe(true)
		})

		it('REQ-QLNK-008: accepts relative Nextcloud paths', () => {
			expect(validateUrl('/apps/files/')).toBe(true)
		})

		it('REQ-QLNK-008: accepts mailto and tel', () => {
			expect(validateUrl('mailto:user@example.com')).toBe(true)
			expect(validateUrl('tel:+311234567')).toBe(true)
		})

		it('REQ-QLNK-008: rejects empty or null', () => {
			expect(validateUrl('')).toBe(false)
			expect(validateUrl(null)).toBe(false)
			expect(validateUrl(undefined)).toBe(false)
		})

		it('REQ-QLNK-008: rejects javascript:, data:, vbscript:', () => {
			expect(validateUrl('javascript:void(0)')).toBe(false)
			expect(validateUrl('data:text/html,abc')).toBe(false)
			expect(validateUrl('vbscript:msgbox(1)')).toBe(false)
		})

		it('REQ-QLNK-008: rejects URLs over MAX_URL_LENGTH chars', () => {
			const long = 'https://example.com/' + 'a'.repeat(MAX_URL_LENGTH)
			expect(validateUrl(long)).toBe(false)
		})

		it('REQ-QLNK-008: rejects unknown schemes', () => {
			expect(validateUrl('ftp://example.com')).toBe(false)
			expect(validateUrl('about:blank')).toBe(false)
		})
	})

	describe('isExternalUrl', () => {
		it('returns true for http(s) URLs', () => {
			expect(isExternalUrl('https://example.com')).toBe(true)
			expect(isExternalUrl('http://example.com')).toBe(true)
		})

		it('returns false for relative paths and mailto/tel', () => {
			expect(isExternalUrl('/apps/files/')).toBe(false)
			expect(isExternalUrl('mailto:user@example.com')).toBe(false)
			expect(isExternalUrl('')).toBe(false)
		})
	})
})
