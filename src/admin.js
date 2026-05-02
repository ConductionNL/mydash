/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin entry point. Loads the typed initial-state contract via
 * {@link loadInitialState} and exposes every key down the component tree
 * via Vue 2's root `provide` option (REQ-INIT-003, REQ-INIT-004) — Vue 3
 * `app.provide(key, value)` semantics, achieved here through the root
 * options bag because MyDash runs on Vue 2.7.
 *
 * Provided values are plain (non-reactive) snapshots (REQ-INIT-005).
 */

import Vue from 'vue'
import { PiniaVuePlugin, createPinia } from 'pinia'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import AdminSettings from './components/admin/AdminSettings.vue'
import { loadInitialState } from './utils/loadInitialState.js'

// Global functions
Vue.mixin({
	methods: {
		t,
		n,
	},
})

Vue.use(PiniaVuePlugin)
const pinia = createPinia()

// Load the typed initial-state snapshot for the admin page (REQ-INIT-002).
const initialState = loadInitialState('admin')

const app = new Vue({
	el: '#mydash-admin-settings',
	pinia,
	provide: { ...initialState },
	render: h => h(AdminSettings),
})

export default app
