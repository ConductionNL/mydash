/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Workspace entry point. Loads the typed initial-state contract via
 * {@link loadInitialState} and exposes every key down the component tree
 * via Vue 2's root `provide` option (REQ-INIT-003, REQ-INIT-004) — Vue 3
 * `app.provide(key, value)` semantics, achieved here through the root
 * options bag because MyDash runs on Vue 2.7.
 *
 * Provided values are plain (non-reactive) snapshots: descendants that
 * need to mutate a value MUST clone into a local `ref` (REQ-INIT-005).
 */

import Vue from 'vue'
import { PiniaVuePlugin, createPinia } from 'pinia'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import App from './App.vue'
import { loadInitialState } from './utils/loadInitialState.js'

import 'gridstack/dist/gridstack.min.css'
import 'gridstack/dist/gridstack-extra.min.css'

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

const app = new Vue({
	el: '#mydash-app',
	pinia,
	provide: { ...initialState },
	render: h => h(App),
})

export default app
