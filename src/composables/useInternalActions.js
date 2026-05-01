/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * useInternalActions — singleton internal-action registry (REQ-LBN-005).
 *
 * Exposes a module-level Map<string, function> that other modules may
 * populate at any time. The link-button renderer calls `invoke(id)` on
 * click; concrete actions are registered by other capabilities later.
 *
 * API:
 *   register(id, fn) — add or replace an action
 *   invoke(id)       — call the action; logs a console.warn on miss (no throw)
 *   has(id)          — return true when the id is registered
 */

/**
 * Singleton registry map. Persists across component (re)renders because
 * it lives at module scope — the JS module is only evaluated once.
 *
 * @type {Map<string, function(): void|Promise<void>>}
 */
const _registry = new Map()

/**
 * Register an action under a stable string id.
 *
 * Overwrites any previously registered function for the same id without
 * warning — this is intentional to allow hot reloading / re-registration.
 *
 * @param {string}   id The stable action identifier.
 * @param {function} fn The function to invoke (sync or async).
 * @return {void}
 */
function register(id, fn) {
	_registry.set(id, fn)
}

/**
 * Invoke the action registered under `id`.
 *
 * Logs `console.warn('Unknown internal action: <id>')` when no action is
 * found and returns without throwing, so a mis-configured button cannot
 * crash the page (REQ-LBN-005).
 *
 * @param {string} id The action identifier to invoke.
 * @return {void}
 */
function invoke(id) {
	const fn = _registry.get(id)
	if (typeof fn !== 'function') {
		console.warn(`Unknown internal action: ${id}`)
		return
	}

	fn()
}

/**
 * Check whether an action is registered.
 *
 * @param {string} id The action identifier to check.
 * @return {boolean} True when the id is registered.
 */
function has(id) {
	return _registry.has(id)
}

/**
 * Return the singleton registry interface.
 *
 * Named `useInternalActions` following the composable convention even
 * though it is not a Vue composable — the name communicates intent and
 * makes it easy to call from renderer methods.
 *
 * @return {{register: function, invoke: function, has: function}}
 */
export function useInternalActions() {
	return { register, invoke, has }
}
