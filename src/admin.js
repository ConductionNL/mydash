/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

// Load the admin initial-state payload via the typed reader
// (REQ-INIT-003) and provide each key down the component tree
// (REQ-INIT-004 / REQ-INIT-005). Plain values only — no ref/reactive wrap.
const adminState = loadInitialState('admin')

const app = new Vue({
	el: '#mydash-admin-settings',
	pinia,
	provide() {
		return { ...adminState }
	},
	render: h => h(AdminSettings),
})

export default app
