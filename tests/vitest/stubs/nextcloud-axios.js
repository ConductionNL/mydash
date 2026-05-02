/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest stub for `@nextcloud/axios`.
 *
 * The real package is ESM-only and ships an `exports` map that Vite's
 * unit-test transform pipeline cannot resolve in the worker context.
 * Component / store / service tests that actually exercise HTTP code
 * paths still use `vi.mock('@nextcloud/axios', () => ...)` and override
 * the methods on a per-test basis. Tests that only happen to transitively
 * import a module that uses axios (e.g. widgetRegistry pulling in
 * NcDashboardWidget which imports api.js) get a noop default export
 * instead of crashing the whole spec file at module load time.
 */

const noop = () => Promise.resolve({ data: null })

const axios = {
	get: noop,
	post: noop,
	put: noop,
	patch: noop,
	delete: noop,
	request: noop,
	create: () => axios,
	defaults: { headers: { common: {} } },
}

export default axios
export { axios }
