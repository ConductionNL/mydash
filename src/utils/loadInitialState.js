/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Centralised JS reader for the per-page MyDash initial-state contract
 * (REQ-INIT-003). The matching PHP builder lives at
 * `lib/Service/InitialStateBuilder.php`. Adding, removing, or renaming a
 * key here is a deliberate spec change — bump
 * {@link INITIAL_STATE_SCHEMA_VERSION} in lockstep with the PHP constant
 * and update the per-page table below.
 *
 * This module is the only place in the frontend allowed to call
 * `loadState('mydash', ...)`; a CI grep guard in `package.json`'s
 * `lint:initial-state` script enforces that.
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * Schema version compiled into the JS bundle. Compared against the
 * server-pushed `_schemaVersion`; mismatch logs a console warning so
 * deploy-skew between PHP and JS surfaces (REQ-INIT-002).
 */
export const INITIAL_STATE_SCHEMA_VERSION = 2

/**
 * Reserved key carrying the schema version on the wire.
 */
export const SCHEMA_VERSION_KEY = '_schemaVersion'

/**
 * Per-page key/default tables. MUST mirror REQ-INIT-002's Data Model
 * exactly. Defaults guarantee the reader never returns `undefined`.
 */
const PAGE_KEYS = {
	workspace: {
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
	},
	admin: {
		allGroups: [],
		configuredGroups: [],
		widgets: [],
		allowUserDashboards: false,
		linkCreateFileExtensions: ['txt', 'md', 'docx', 'xlsx', 'csv', 'odt'],
	},
}

/**
 * Read every initial-state key declared for the given page, fill defaults
 * for any missing keys, and validate the schema version stamp.
 *
 * @param {('workspace'|'admin')} page Destination page identifier.
 * @return {object} Typed snapshot — never carries `undefined` values.
 * @throws {Error} When `page` is not a known page identifier.
 */
export function loadInitialState(page) {
	const defaults = PAGE_KEYS[page]
	if (!defaults) {
		throw new Error(`loadInitialState: unknown page "${page}" — known: ${Object.keys(PAGE_KEYS).join(', ')}`)
	}

	const state = {}
	for (const [key, fallback] of Object.entries(defaults)) {
		state[key] = readKey(key, fallback)
	}

	const serverVersion = readKey(SCHEMA_VERSION_KEY, null)
	if (serverVersion !== null && serverVersion !== INITIAL_STATE_SCHEMA_VERSION) {
		// eslint-disable-next-line no-console
		console.warn(
			`MyDash initial-state schema mismatch: server v${serverVersion} vs client v${INITIAL_STATE_SCHEMA_VERSION} — refresh recommended`,
		)
	}

	return state
}

/**
 * Wrap loadState so a missing key (the helper throws) silently degrades
 * to the documented default. The reader contract guarantees no
 * `undefined` reads — the JS bundle never crashes when PHP omits a key.
 *
 * @param {string} key The initial-state key.
 * @param {*} fallback Default to use when the key is missing.
 * @return {*} The pushed value, or the fallback.
 */
function readKey(key, fallback) {
	try {
		const value = loadState('mydash', key, fallback)
		return value === undefined ? fallback : value
	} catch (e) {
		return fallback
	}
}
