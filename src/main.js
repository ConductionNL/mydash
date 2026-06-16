/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Workspace entry point. Implements the ADR-036 Decision 8 runtime-manifest
 * pattern for mydash: a 5-line stub manifest is bundled with the app; at boot
 * the frontend fetches GET /apps/mydash/api/manifest and replaces the stub
 * with the per-user manifest assembled from the user's visible dashboards.
 *
 * mydash is the proving ground for this pattern because it has no "default
 * home page" — every page is user-specific, making a bundled static manifest
 * meaningless. The pattern is what OpenBuilt-built apps will also use.
 *
 * Boot sequence:
 *  1. Vue + Pinia initialised.
 *  2. Initial-state snapshot loaded from the NC server's HTML.
 *  3. Stub manifest imported — used as the initial value while the runtime
 *     fetch is in flight (prevents a flash of empty state in most cases).
 *  4. Vue instance mounted with stub manifest.
 *  5. Runtime fetch fires (fire-and-forget) — when it resolves the reactive
 *     `runtimeManifest` ref is updated; App.vue watches it and passes the
 *     live value down to CnAppRoot.
 */

import Vue from 'vue'
import { PiniaVuePlugin, createPinia } from 'pinia'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import App from './App.vue'
import { loadInitialState } from './utils/loadInitialState.js'
import bundledStub from './manifest.json'

import 'gridstack/dist/gridstack.min.css'
// Note: GridStack v12 dropped the separate `gridstack-extra.min.css`
// helper file — the per-column-count CSS rules used by responsive
// breakpoints are now generated dynamically by the engine at init time.

// Global functions
Vue.mixin({
	methods: {
		t,
		n,
	},
})

Vue.use(PiniaVuePlugin)
const pinia = createPinia()

// Load the typed initial-state snapshot for the workspace page. Every key
// declared in REQ-INIT-002 is filled (defaults applied for missing keys
// by the reader); descendants `inject(key, default)` to read.
const initialState = loadInitialState('workspace')

// --- Runtime manifest loader (ADR-036 Decision 8) ---
//
// `useRuntimeManifest` from @conduction/nextcloud-vue is available in
// nc-vue ≥ 1.0.0-beta.57 but may not be present in the local source alias
// used during development builds. We implement the same contract inline so
// the runtime-manifest wiring works regardless of the local lib version.
//
// The runtime manifest ref is passed as a prop to App.vue; App.vue passes
// it to CnAppRoot (once mydash migrates to CnAppRoot). For now, the ref is
// provided via the root Vue instance's `provide` so any descendant that
// needs it can `inject('runtimeManifest', null)`.
//
// NO deep-merge — the API response fully replaces the stub on success.
const runtimeManifest = Vue.observable({ value: bundledStub })
const manifestLoading = Vue.observable({ value: true })

;(async () => {
	try {
		const url = generateUrl('/apps/mydash/api/manifest')
		const response = await axios.get(url)

		if (response && response.status === 200 && response.data
				&& typeof response.data === 'object'
				&& response.data.$schema) {
			// Replace stub with live per-user manifest (no deep-merge per ADR-036)
			runtimeManifest.value = response.data
		}
	} catch (err) {
		// 404, network error, or unauthenticated — fall back to stub silently.
		// The existing mydash UI works entirely from its Pinia stores and does
		// not depend on the manifest for page routing, so this is non-fatal.
		// eslint-disable-next-line no-console
		console.warn('[mydash] Runtime manifest fetch failed; using stub', err)
	} finally {
		manifestLoading.value = false
	}
})()
// --- End runtime manifest loader ---

const app = new Vue({
	el: '#workspace-vue',
	pinia,
	provide: {
		...initialState,
		// ADR-036 Decision 8: expose runtime manifest refs so descendants
		// can reactively access the live per-user manifest. Wrapped in
		// Vue.observable so mutations trigger re-renders across the tree.
		runtimeManifest,
		manifestLoading,
	},
	render: h => h(App),
})

export default app
