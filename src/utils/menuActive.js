/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Helpers for the menu widget's active-item detection (REQ-MENU-006).
 *
 * `isActiveItem` decides whether a single URL "matches" the current page
 * URL: external URLs (http/https) are never matched against
 * `window.location` because that would produce false positives on
 * unrelated hosts (REQ-MENU-006 scenario "External URLs don't auto-
 * highlight"). Internal URLs match either exactly (`/users` ===
 * `/users`) or as a path prefix (`/users` is in-path for `/users/alice`).
 *
 * `computeActivePath` walks a (possibly nested) `items` array and returns
 * `{path, leafKey}` where `path` maps dotted-string keys (`'0'`, `'1.2'`,
 * `'1.2.3'`) to either `'active'` (leaf match) or `'in-path'` (ancestor
 * of an active leaf). The deepest active match wins so the highlight
 * never bleeds onto unrelated branches.
 */

/**
 * Whether a URL string should be considered external (http/https).
 *
 * @param {string} url URL or path.
 * @return {boolean} true when the URL has an absolute http(s) scheme.
 */
/** @spec openspec/specs/menu-widget/spec.md */
export function isExternalUrl(url) {
	return typeof url === 'string' && /^https?:\/\//i.test(url)
}

/**
 * Decide if a menu item URL matches the current location.
 *
 * Internal URLs match exactly or as a path prefix; external URLs never
 * match (they are always opened in a new tab and the user is presumed
 * to leave the dashboard).
 *
 * @param {string|undefined|null} itemUrl URL stored on the item.
 * @param {{pathname: string, host?: string}} currentLocation Live location bag.
 * @return {boolean} true when the URL is a same-page or prefix match.
 */
/** @spec openspec/specs/menu-widget/spec.md */
export function isActiveItem(itemUrl, currentLocation) {
	if (typeof itemUrl !== 'string' || itemUrl === '') {
		return false
	}
	if (isExternalUrl(itemUrl)) {
		return false
	}
	const pathname = currentLocation && typeof currentLocation.pathname === 'string'
		? currentLocation.pathname
		: '/'
	if (itemUrl === pathname) {
		return true
	}
	// Prefix match: "/users" matches "/users/alice" but NOT "/usersalice".
	if (pathname.startsWith(`${itemUrl}/`)) {
		return true
	}
	return false
}

/**
 * Walk a menu tree and tag every item that is "active" (the deepest
 * URL match) or "in-path" (an ancestor of the deepest match).
 *
 * The walk picks the longest internal match so that for a tree like
 * `Users → Alice (/users/alice)` the leaf `/users/alice` wins on the
 * page `/users/alice/profile`, leaving `/users` as in-path.
 *
 * @param {object} args Arguments.
 * @param {Array} args.items Menu items array.
 * @param {{pathname: string, host?: string}} args.currentLocation Location bag.
 * @return {{path: Record<string,'active'|'in-path'>, leafKey: string|null}} Map and leaf.
 */
/** @spec openspec/specs/menu-widget/spec.md */
export function computeActivePath({ items, currentLocation }) {
	const path = {}
	if (!Array.isArray(items)) {
		return { path, leafKey: null }
	}

	// First pass: collect every (key, url) pair so we can pick the
	// longest match.
	const candidates = []
	const walk = (list, prefix) => {
		list.forEach((item, idx) => {
			const key = prefix === '' ? `${idx}` : `${prefix}.${idx}`
			candidates.push({ key, url: item?.url, item })
			if (Array.isArray(item?.children) && item.children.length > 0) {
				walk(item.children, key)
			}
		})
	}
	walk(items, '')

	let bestKey = null
	let bestLen = -1
	candidates.forEach(({ key, url }) => {
		if (!isActiveItem(url, currentLocation)) {
			return
		}
		// Prefer the longest URL (deepest match).
		const len = typeof url === 'string' ? url.length : 0
		if (len > bestLen) {
			bestLen = len
			bestKey = key
		}
	})

	if (bestKey !== null) {
		path[bestKey] = 'active'
		// Mark every ancestor key as in-path.
		const parts = bestKey.split('.')
		for (let i = 1; i < parts.length; i++) {
			const ancestorKey = parts.slice(0, i).join('.')
			if (path[ancestorKey] !== 'active') {
				path[ancestorKey] = 'in-path'
			}
		}
	}

	return { path, leafKey: bestKey }
}
