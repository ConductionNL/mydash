/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

// Load the workspace initial-state payload via the typed reader
// (REQ-INIT-003) and provide each key down the component tree
// (REQ-INIT-004 / REQ-INIT-005). Plain values only — no ref/reactive wrap.
const workspaceState = loadInitialState('workspace')

const app = new Vue({
	el: '#mydash-app',
	pinia,
	provide() {
		return { ...workspaceState }
	},
	render: h => h(App),
})

export default app
