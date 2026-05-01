<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcSelect
		v-model="selectedDashboard"
		:options="dashboardOptions"
		:input-label="t('mydash', 'Active dashboard')"
		:placeholder="t('mydash', 'Select dashboard')"
		label="label"
		track-by="id"
		class="dashboard-switcher"
		hide-label
		@input="switchDashboard" />
</template>

<script>
import { NcSelect } from '@conduction/nextcloud-vue'

export default {
	name: 'DashboardSwitcher',

	components: {
		NcSelect,
	},

	props: {
		dashboards: {
			type: Array,
			required: true,
		},
		activeId: {
			type: [Number, String],
			default: null,
		},
	},

	emits: ['switch'],

	computed: {
		dashboardOptions() {
			return this.dashboards.map(d => ({
				id: d.id,
				label: d.name,
			}))
		},

		selectedDashboard: {
			get() {
				return this.dashboardOptions.find(d => d.id === this.activeId) || null
			},
			set() {
				// Handled by @input
			},
		},
	},

	methods: {
		switchDashboard(option) {
			if (option && option.id !== this.activeId) {
				this.$emit('switch', option.id)
			}
		},
	},
}
</script>

<style scoped>
.dashboard-switcher {
	min-width: 200px;
}
</style>
