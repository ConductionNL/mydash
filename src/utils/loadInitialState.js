/**
 * Typed reader for the MyDash initial-state contract.
 *
 * Mirrors {@link OCA\MyDash\Service\InitialStateBuilder} on the JS side
 * (REQ-INIT-002 / REQ-INIT-003). The function reads every key declared for
 * the requested page via `loadState('mydash', key, default)`, fills missing
 * keys with the spec defaults, and warns if the server-pushed schema version
 * does not match the version this bundle was compiled against.
 *
 * Direct `loadState('mydash', ...)` calls outside this module are forbidden
 * by a grep lint test (REQ-INIT-003 scenario "Direct loadState rejected").
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * Compiled-in initial-state schema version. MUST equal the PHP value in
 * `OCA\\MyDash\\Service\\InitialStateBuilder::INITIAL_STATE_SCHEMA_VERSION`.
 *
 * @type {number}
 */
export const INITIAL_STATE_SCHEMA_VERSION = 1

/**
 * Reserved key used to stamp the schema version on every payload.
 *
 * @type {string}
 */
export const SCHEMA_VERSION_KEY = '_schemaVersion'

/**
 * Per-page key/default tables. Mirrors REQ-INIT-002 Data Model exactly.
 *
 * Keep the key strings byte-identical to the PHP setters in
 * `InitialStateBuilder` — renaming at this boundary is a spec change.
 */
const PAGE_KEYS = Object.freeze({
	workspace: Object.freeze({
		widgets: [],
		layout: [],
		primaryGroup: 'default',
		primaryGroupName: '',
		isAdmin: false,
		activeDashboardId: '',
		dashboardSource: 'group',
		groupDashboards: [],
		userDashboards: [],
		allowUserDashboards: false,
	}),
	admin: Object.freeze({
		allGroups: [],
		configuredGroups: [],
		widgets: [],
		allowUserDashboards: false,
	}),
})

/**
 * Load the typed initial-state object for the given page.
 *
 * @param {'workspace'|'admin'} page The Vue mount this state targets.
 * @return {object} The default-filled, never-`undefined` state object.
 * @throws {Error} If `page` is not a known page identifier.
 */
export function loadInitialState(page) {
	const defaults = PAGE_KEYS[page]
	if (defaults === undefined) {
		throw new Error(`MyDash loadInitialState: unknown page "${page}"`)
	}

	const state = {}
	for (const [key, fallback] of Object.entries(defaults)) {
		state[key] = loadState('mydash', key, fallback)
	}

	const serverVersion = loadState('mydash', SCHEMA_VERSION_KEY, null)
	if (serverVersion !== null && serverVersion !== INITIAL_STATE_SCHEMA_VERSION) {
		// eslint-disable-next-line no-console
		console.warn(
			`MyDash initial-state schema mismatch: server v${serverVersion} `
			+ `vs client v${INITIAL_STATE_SCHEMA_VERSION} — refresh recommended`,
		)
	}

	return state
}

/**
 * Read-only access to the per-page key set. Exported for tests and lint
 * tooling — production code should call {@link loadInitialState} instead.
 *
 * @param {'workspace'|'admin'} page The page identifier.
 * @return {string[]} The list of declared keys for the page.
 */
export function getDeclaredKeys(page) {
	const defaults = PAGE_KEYS[page]
	if (defaults === undefined) {
		throw new Error(`MyDash getDeclaredKeys: unknown page "${page}"`)
	}
	return Object.keys(defaults)
}
