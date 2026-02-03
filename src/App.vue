<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcContent app-name="mydash">
		<NcAppContent>
			<!-- Header with greeting and controls -->
			<div class="mydash-header">
				<div class="mydash-header__left">
					<h1 class="mydash-header__greeting">
						{{ greeting }}
					</h1>
					<DashboardSwitcher
						v-if="dashboards.length > 1"
						:dashboards="dashboards"
						:active-id="activeDashboard?.id"
						@switch="switchDashboard" />
				</div>
				<div class="mydash-header__actions">
					<NcButton
						v-if="canEdit"
						:type="isEditMode ? 'primary' : 'secondary'"
						@click="toggleEditMode">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ isEditMode ? t('mydash', 'Done') : t('mydash', 'Customize') }}
					</NcButton>
					<NcButton
						v-if="isEditMode"
						type="secondary"
						@click="openWidgetPicker">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('mydash', 'Add widget') }}
					</NcButton>
				</div>
			</div>

			<!-- Main dashboard grid -->
			<div class="mydash-container" :class="{ 'mydash-edit-mode': isEditMode }">
				<DashboardGrid
					v-if="activeDashboard"
					:placements="widgetPlacements"
					:widgets="availableWidgets"
					:edit-mode="isEditMode"
					:grid-columns="activeDashboard.gridColumns"
					@update:placements="updatePlacements"
					@widget-remove="removeWidget"
					@widget-style="openStyleEditor" />

				<div v-else class="mydash-empty">
					<NcEmptyContent
						:name="t('mydash', 'No dashboard yet')"
						:description="t('mydash', 'Create your first dashboard to get started')">
						<template #icon>
							<ViewDashboard :size="64" />
						</template>
						<template #action>
							<NcButton type="primary" @click="createDashboard">
								{{ t('mydash', 'Create dashboard') }}
							</NcButton>
						</template>
					</NcEmptyContent>
				</div>
			</div>

			<!-- Widget picker sidebar -->
			<WidgetPicker
				:open="isPickerOpen"
				:widgets="availableWidgets"
				:placed-widget-ids="placedWidgetIds"
				@close="closeWidgetPicker"
				@add="addWidget" />

			<!-- Style editor modal -->
			<WidgetStyleEditor
				v-if="editingPlacement"
				:placement="editingPlacement"
				:open="isStyleEditorOpen"
				@close="closeStyleEditor"
				@update="updateWidgetStyle" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { mapState, mapActions } from 'pinia'
import { NcContent, NcAppContent, NcButton, NcEmptyContent } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'

import { useDashboardStore } from './stores/dashboard.js'
import { useWidgetStore } from './stores/widgets.js'

import DashboardGrid from './components/DashboardGrid.vue'
import DashboardSwitcher from './components/DashboardSwitcher.vue'
import WidgetPicker from './components/WidgetPicker.vue'
import WidgetStyleEditor from './components/WidgetStyleEditor.vue'

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppContent,
		NcButton,
		NcEmptyContent,
		Pencil,
		Plus,
		ViewDashboard,
		DashboardGrid,
		DashboardSwitcher,
		WidgetPicker,
		WidgetStyleEditor,
	},

	data() {
		return {
			isEditMode: false,
			isPickerOpen: false,
			isStyleEditorOpen: false,
			editingPlacement: null,
		}
	},

	computed: {
		...mapState(useDashboardStore, [
			'dashboards',
			'activeDashboard',
			'widgetPlacements',
			'permissionLevel',
			'loading',
		]),
		...mapState(useWidgetStore, ['availableWidgets']),

		greeting() {
			const hour = new Date().getHours()
			const name = OC.getCurrentUser().displayName || OC.getCurrentUser().uid

			if (hour < 5) {
				return this.t('mydash', 'Good night, {name}', { name })
			} else if (hour < 12) {
				return this.t('mydash', 'Good morning, {name}', { name })
			} else if (hour < 18) {
				return this.t('mydash', 'Good afternoon, {name}', { name })
			} else {
				return this.t('mydash', 'Good evening, {name}', { name })
			}
		},

		canEdit() {
			return this.permissionLevel !== 'view_only'
		},

		placedWidgetIds() {
			return this.widgetPlacements.map(p => p.widgetId)
		},
	},

	async created() {
		const dashboardStore = useDashboardStore()
		const widgetStore = useWidgetStore()

		await Promise.all([
			dashboardStore.loadDashboards(),
			widgetStore.loadAvailableWidgets(),
		])
	},

	methods: {
		...mapActions(useDashboardStore, [
			'switchDashboard',
			'createDashboard',
			'updatePlacements',
			'addWidgetToDashboard',
			'removeWidgetFromDashboard',
			'updateWidgetPlacement',
		]),

		toggleEditMode() {
			this.isEditMode = !this.isEditMode
			if (!this.isEditMode) {
				this.closeWidgetPicker()
				this.closeStyleEditor()
			}
		},

		openWidgetPicker() {
			this.isPickerOpen = true
		},

		closeWidgetPicker() {
			this.isPickerOpen = false
		},

		async addWidget(widgetId) {
			await this.addWidgetToDashboard(widgetId)
		},

		async removeWidget(placementId) {
			await this.removeWidgetFromDashboard(placementId)
		},

		openStyleEditor(placement) {
			this.editingPlacement = placement
			this.isStyleEditorOpen = true
		},

		closeStyleEditor() {
			this.isStyleEditorOpen = false
			this.editingPlacement = null
		},

		async updateWidgetStyle(placementId, styleConfig) {
			await this.updateWidgetPlacement(placementId, { styleConfig })
			this.closeStyleEditor()
		},
	},
}
</script>

<style scoped>
.mydash-container {
	flex: 1;
	padding: 24px;
	overflow: auto;
}

.mydash-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}
</style>
