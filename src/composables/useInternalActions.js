/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * useInternalActions — singleton frontend registry for the
 * link-button-widget's `internal` action type (REQ-LBN-005).
 *
 * Other capabilities register named functions at any time during the
 * page lifecycle; the link-button renderer looks up the persisted
 * `url` field (the action ID) and invokes the registered function.
 * Missing IDs MUST log `console.warn('Unknown internal action: <id>')`
 * but MUST NOT throw — a stale dashboard placement that references a
 * removed action degrades gracefully instead of breaking the page.
 *
 * The registry is a module-level `Map` shared across every consumer
 * of this composable — calling `useInternalActions()` returns the
 * same `{register, invoke, has}` triple every time. This is the
 * "singleton" contract the spec mandates.
 *
 * Concrete actions are registered by other capabilities (Talk, Mail,
 * Calendar, Files, etc.); this module starts empty by design so that
 * the link-button widget capability has no implicit dependency on
 * any of those features being installed.
 */

/**
 * Module-level registry shared across every `useInternalActions()`
 * caller. Map<actionId, () => void | Promise<void>>.
 *
 * @type {Map<string, () => (void|Promise<void>)>}
 */
const REGISTRY = new Map()

/**
 * Singleton registry composable for the link-button-widget `internal`
 * action type.
 *
 * @return {{
 *   register: (id: string, fn: () => (void|Promise<void>)) => void,
 *   invoke: (id: string) => (void|Promise<void>),
 *   has: (id: string) => boolean,
 * }} The shared `{register, invoke, has}` triple.
 */
export function useInternalActions() {
	/**
	 * Register a named action. Registering a duplicate id replaces the
	 * previously registered function — the latest registration wins,
	 * so capabilities that re-register on hot-reload don't pile up
	 * stale closures.
	 *
	 * @param {string} id action id; used as the persisted `url` field on placements
	 * @param {() => (void|Promise<void>)} fn the function to invoke on click
	 * @return {void}
	 */
	function register(id, fn) {
		if (typeof id !== 'string' || id === '') {
			return
		}
		if (typeof fn !== 'function') {
			return
		}
		REGISTRY.set(id, fn)
	}

	/**
	 * Invoke a registered action by id. Missing ids log a `console.warn`
	 * and return `undefined`; they do NOT throw (REQ-LBN-005).
	 *
	 * @param {string} id action id
	 * @return {void|Promise<void>} whatever the registered function returns
	 */
	function invoke(id) {
		const fn = REGISTRY.get(id)
		if (!fn) {
			// eslint-disable-next-line no-console
			console.warn(`Unknown internal action: ${id}`)
			return undefined
		}
		return fn()
	}

	/**
	 * Test whether an action id is registered. Useful for the form to
	 * preview "this action exists" before persisting.
	 *
	 * @param {string} id action id
	 * @return {boolean} true when the id resolves to a registered function
	 */
	function has(id) {
		return REGISTRY.has(id)
	}

	return { register, invoke, has }
}

/**
 * Test-only helper: empties the singleton registry. Production code
 * MUST NOT call this; tests rely on it for state isolation between
 * `it()` blocks.
 *
 * @return {void}
 */
export function __resetInternalActionsForTest() {
	REGISTRY.clear()
}
