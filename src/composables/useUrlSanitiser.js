/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * useUrlSanitiser — shared client-side URL validation/sanitisation
 * helpers used by the quicklinks widget edit form (REQ-QLNK-008) and
 * available to any future widget that accepts user-supplied URLs.
 *
 * The two helpers form a defence-in-depth pair:
 *
 * - `sanitiseUrl(url)`: normalises a raw input string and rejects values
 *   carrying a dangerous scheme (`javascript:`, `data:`, `vbscript:`, …)
 *   by returning `''`. Sanitisation is intentionally lossy so the form can
 *   strip a hostile value before storing it back into widget content.
 * - `validateUrl(url)`: stricter test that returns `true` only when the
 *   URL is non-empty, ≤ 2048 chars, and starts with `http://`, `https://`,
 *   `mailto:`, `tel:`, or `/` (relative Nextcloud path).
 *
 * Both helpers are pure and synchronous — they run on every keystroke in
 * the form (cheap) and on every link in the array on submit (still cheap).
 *
 * The helpers are deliberately permissive about case (`HTTPS://EXAMPLE`
 * passes because URL parsers are case-insensitive on the scheme) and
 * trim leading/trailing whitespace (so a paste that accidentally carried
 * a trailing newline does not produce a false negative).
 */

/** Maximum URL length the form will accept; mirrors REQ-QLNK-008. */
export const MAX_URL_LENGTH = 2048

/**
 * Schemes the system explicitly refuses. Keeping them in a frozen set
 * lets us reuse the value in tests and in any future server-side mirror.
 */
export const DANGEROUS_SCHEMES = Object.freeze([
	'javascript:',
	'data:',
	'vbscript:',
	'file:',
])

/**
 * Schemes the system accepts in addition to the relative-path prefix.
 */
export const ALLOWED_SCHEMES = Object.freeze([
	'http://',
	'https://',
	'mailto:',
	'tel:',
])

/**
 * Strip whitespace and reject dangerous schemes.
 *
 * Returns the trimmed URL when it is safe, or an empty string when the
 * scheme is dangerous (so callers can drop the value rather than pass it
 * to the renderer where it could become an XSS vector).
 *
 * @param {*} url raw input value (typically a string from a form field)
 * @return {string} the sanitised URL, or '' when unsafe / non-string
 */
/** @spec openspec/specs/link-button-widget/spec.md */
export function sanitiseUrl(url) {
	if (typeof url !== 'string') {
		return ''
	}
	const trimmed = url.trim()
	if (trimmed === '') {
		return ''
	}
	const lowered = trimmed.toLowerCase()
	for (const scheme of DANGEROUS_SCHEMES) {
		if (lowered.startsWith(scheme)) {
			return ''
		}
	}
	return trimmed
}

/**
 * Test whether a URL would be accepted by the form.
 *
 * Validation rules (REQ-QLNK-008):
 *  - non-empty string after trimming
 *  - length ≤ {@link MAX_URL_LENGTH}
 *  - starts with `http://`, `https://`, `mailto:`, `tel:`, or `/`
 *  - does NOT start with any of {@link DANGEROUS_SCHEMES}
 *
 * @param {*} url candidate URL
 * @return {boolean} true when the URL passes every rule
 */
/** @spec openspec/specs/link-button-widget/spec.md */
export function validateUrl(url) {
	if (typeof url !== 'string') {
		return false
	}
	const trimmed = url.trim()
	if (trimmed === '' || trimmed.length > MAX_URL_LENGTH) {
		return false
	}
	const lowered = trimmed.toLowerCase()
	for (const scheme of DANGEROUS_SCHEMES) {
		if (lowered.startsWith(scheme)) {
			return false
		}
	}
	if (trimmed.startsWith('/')) {
		return true
	}
	for (const scheme of ALLOWED_SCHEMES) {
		if (lowered.startsWith(scheme)) {
			return true
		}
	}
	return false
}

/**
 * Convenience: tells whether a URL would open in a new tab by default
 * (external schemes) when the link's `openInNewTab` field is not set.
 *
 * @param {string} url candidate URL
 * @return {boolean} true when the URL is external
 */
/** @spec openspec/specs/link-button-widget/spec.md */
export function isExternalUrl(url) {
	if (typeof url !== 'string') {
		return false
	}
	const lowered = url.trim().toLowerCase()
	return lowered.startsWith('http://') || lowered.startsWith('https://')
}
