<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcContent app-name="mydash">
		<NcAppContent>
			<!-- Floating controls in top right -->
			<div class="mydash-floating-controls">
				<DashboardSwitcher
					v-if="dashboards.length > 1"
					:dashboards="dashboards"
					:active-id="activeDashboard?.id"
					@switch="switchDashboard" />
				<NcButton
					v-if="canEdit"
					:type="isEditMode ? 'primary' : 'secondary'"
					:aria-label="isEditMode ? t('mydash', 'Save') : t('mydash', 'Customize')"
					@click="toggleEditMode">
					<template #icon>
						<ContentSave v-if="isEditMode" :size="20" />
						<Cog v-else :size="20" />
					</template>
					{{ isEditMode ? t('mydash', 'Save') : '' }}
				</NcButton>
				<NcButton
					v-if="isEditMode"
					type="secondary"
					@click="openWidgetPicker">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('mydash', 'Add') }}
				</NcButton>
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
				:tiles="tiles"
				:placed-widget-ids="placedWidgetIds"
				@close="closeWidgetPicker"
				@add="addWidget"
				@add-tile="openTileEditor()" />

			<!-- Style editor modal -->
			<WidgetStyleEditor
				v-if="editingPlacement"
				:placement="editingPlacement"
				:open="isStyleEditorOpen"
				@close="closeStyleEditor"
				@update="updateWidgetStyle" />

			<!-- Tile editor modal -->
			<TileEditor
				:open="isTileEditorOpen"
				:tile="editingTile"
				@close="closeTileEditor"
				@save="saveTile" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { mapState, mapActions } from 'pinia'
import { NcContent, NcAppContent, NcButton, NcEmptyContent } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'

import { useDashboardStore } from './stores/dashboard.js'
import { useWidgetStore } from './stores/widgets.js'
import { useTileStore } from './stores/tiles.js'

import DashboardGrid from './components/DashboardGrid.vue'
import DashboardSwitcher from './components/DashboardSwitcher.vue'
import WidgetPicker from './components/WidgetPicker.vue'
import WidgetStyleEditor from './components/WidgetStyleEditor.vue'
import TileEditor from './components/TileEditor.vue'

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppContent,
		NcButton,
		NcEmptyContent,
		ContentSave,
		Pencil,
		Cog,
		Plus,
		ViewDashboard,
		DashboardGrid,
		DashboardSwitcher,
		WidgetPicker,
		WidgetStyleEditor,
		TileEditor,
	},

	data() {
		return {
			isEditMode: false,
			isPickerOpen: false,
			isStyleEditorOpen: false,
			editingPlacement: null,
			isTileEditorOpen: false,
			editingTile: null,
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
		...mapState(useTileStore, ['tiles']),

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
		const tileStore = useTileStore()

		await Promise.all([
			dashboardStore.loadDashboards(),
			widgetStore.loadAvailableWidgets(),
			tileStore.loadTiles(),
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
		...mapActions(useTileStore, ['createTile', 'updateTile', 'deleteTile']),

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

		openTileEditor(tile = null) {
			this.editingTile = tile
			this.isTileEditorOpen = true
		},

		closeTileEditor() {
			this.isTileEditorOpen = false
			this.editingTile = null
		},

		async saveTile(tileData) {
			try {
				if (this.editingTile) {
					await this.updateTile(this.editingTile.id, tileData)
				} else {
					await this.createTile(tileData)
				}
				this.closeTileEditor()
			} catch (error) {
				console.error('Failed to save tile:', error)
			}
		},

		async removeTile(tileId) {
			try {
				await this.deleteTile(tileId)
			} catch (error) {
				console.error('Failed to remove tile:', error)
			}
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
