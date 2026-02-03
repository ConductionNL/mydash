/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import { PiniaVuePlugin, createPinia } from 'pinia'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import App from './App.vue'

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

const app = new Vue({
	el: '#mydash-app',
	pinia,
	render: h => h(App),
})

export default app
