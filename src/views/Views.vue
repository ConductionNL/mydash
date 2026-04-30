<template>
	<div id="mydash-app">
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
				:aria-label="isEditMode ? t('mydash', 'Close') : t('mydash', 'Customize')"
				@click="toggleEditMode">
				<template #icon>
					<Close v-if="isEditMode" :size="20" />
					<Cog v-else :size="20" />
				</template>
				{{ isEditMode ? t('mydash', 'Close') : '' }}
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
			<NcButton
				type="tertiary"
				:aria-label="t('mydash', 'Documentation')"
				@click="openLink('https://mydash.app', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Main dashboard grid -->
		<div class="mydash-container" :class="{ 'mydash-edit-mode': isEditMode }">
			<DashboardGrid
				v-if="activeDashboard"
				ref="dashboardGrid"
				:placements="widgetPlacements"
				:widgets="availableWidgets"
				:edit-mode="isEditMode"
				:grid-columns="activeDashboard.gridColumns"
				@update:placements="updatePlacements"
				@widget-remove="removeWidget"
				@widget-edit="openWidgetEditModal"
				@tile-edit="openTileEditorForEdit" />

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
			:dashboards="dashboards"
			:active-dashboard-id="activeDashboard?.id"
			@close="closeWidgetPicker"
			@add="addWidget"
			@add-tile="openTileEditor()"
			@switch-dashboard="switchDashboard"
			@create-dashboard="handleCreateDashboard"
			@edit-dashboard="handleEditDashboard"
			@delete-dashboard="handleDeleteDashboard" />

		<!-- Style editor modal -->
		<WidgetStyleEditor
			v-if="editingPlacement"
			:placement="editingPlacement"
			:open="isStyleEditorOpen"
			@close="closeStyleEditor"
			@update="updateWidgetStyle"
			@delete="deleteWidget" />

		<!-- Add / Edit widget modal (content-level) -->
		<AddWidgetModal
			:show="isWidgetModalOpen"
			:widgets="availableWidgets"
			:editing-widget="editingWidgetContent"
			@close="closeWidgetModal"
			@submit="handleWidgetModalSubmit" />

		<!-- Tile editor modal -->
		<TileEditor
			:open="isTileEditorOpen"
			:tile="editingTile"
			@close="closeTileEditor"
			@save="saveTile"
			@delete="deleteTile" />
	</div>
</template>

<script>
import { mapState, mapActions } from 'pinia'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

// Icons
import Close from 'vue-material-design-icons/Close.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'

// Components
import DashboardGrid from '../components/DashboardGrid.vue'
import WidgetPicker from '../components/WidgetPicker.vue'
import WidgetStyleEditor from '../components/WidgetStyleEditor.vue'
import AddWidgetModal from '../components/Widgets/AddWidgetModal.vue'
import TileEditor from '../components/TileEditor.vue'
import DashboardSwitcher from '../components/DashboardSwitcher.vue'

// Stores
import { useDashboardStore } from '../stores/dashboard.js'
import { useWidgetStore } from '../stores/widgets.js'
import { useTileStore } from '../stores/tiles.js'
import { api } from '../services/api.js'

export default {
	name: 'Views',
	components: {
		NcButton,
		NcEmptyContent,
		Close,
		Cog,
		Plus,
		ViewDashboard,
		BookOpenVariantOutline,
		DashboardGrid,
		WidgetPicker,
		WidgetStyleEditor,
		AddWidgetModal,
		TileEditor,
		DashboardSwitcher,
	},
	data() {
		return {
			isEditMode: false,
			isPickerOpen: false,
			isStyleEditorOpen: false,
			editingPlacement: null,
			isTileEditorOpen: false,
			editingTile: null,
			// Add/edit widget modal state
			isWidgetModalOpen: false,
			editingWidgetContent: null,
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
		t,
		...mapActions(useDashboardStore, [
			'switchDashboard',
			'createDashboard',
			'loadDashboards',
			'updatePlacements',
			'addWidgetToDashboard',
			'addTileToDashboard',
			'removeWidgetFromDashboard',
			'updateWidgetPlacement',
		]),
		...mapActions(useTileStore, ['createTile', 'updateTile', 'deleteTile']),

		openLink(url, target) {
			window.open(url, target)
		},
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
			// Use the widget placement helper to compute position
			const gridComponent = this.$refs.dashboardGrid
			let position = null

			if (gridComponent && gridComponent.placeWidget) {
				// Get widget spec (default size if not specified)
				const widget = this.availableWidgets.find(w => w.id === widgetId)
				const spec = {
					w: widget?.defaultWidth ?? 4,
					h: widget?.defaultHeight ?? 4,
				}
				position = gridComponent.placeWidget(spec)
			}

			await this.addWidgetToDashboard(widgetId, position)
		},
		async removeWidget(placementId) {
			await this.removeWidgetFromDashboard(placementId)
		},
		/**
		 * Open AddWidgetModal in edit mode for a placement that carries
		 * widget content (styleConfig.type is set).
		 * Falls through to the style editor for placements without content types.
		 *
		 * @param {object} placement the placement object to edit
		 */
		openWidgetEditModal(placement) {
			const contentType = placement.styleConfig?.type
			if (contentType) {
				this.editingWidgetContent = {
					type: contentType,
					content: placement.styleConfig?.content || {},
					placementId: placement.id,
				}
				this.isWidgetModalOpen = true
			} else {
				// No content type — fall through to the style editor.
				this.openStyleEditor(placement)
			}
		},

		closeWidgetModal() {
			this.isWidgetModalOpen = false
			this.editingWidgetContent = null
		},

		async handleWidgetModalSubmit(payload) {
			if (this.editingWidgetContent?.placementId) {
				// Edit mode: persist content back into styleConfig.
				const existing = this.widgetPlacements.find(p => p.id === this.editingWidgetContent.placementId)
				const updates = {
					styleConfig: {
						...(existing?.styleConfig || {}),
						type: payload.type,
						content: payload.content,
					},
				}
				await this.updateWidgetPlacement(this.editingWidgetContent.placementId, updates)
			}
			this.closeWidgetModal()
		},

		openStyleEditor(placement) {
			this.editingPlacement = placement
			this.isStyleEditorOpen = true
		},
		closeStyleEditor() {
			this.isStyleEditorOpen = false
			this.editingPlacement = null
		},
		async updateWidgetStyle(placementId, updates) {
			console.log('[Views] updateWidgetStyle called with:', placementId, updates)
			await this.updateWidgetPlacement(placementId, updates)
			this.closeStyleEditor()
		},
		async deleteWidget() {
			if (this.editingPlacement?.id) {
				console.log('[Views] Deleting widget:', this.editingPlacement.id)
				await this.removeWidget(this.editingPlacement.id)
				this.closeStyleEditor()
			}
		},
		openTileEditor(tile = null) {
			this.editingTile = tile
			this.isTileEditorOpen = true
		},
		openTileEditorForEdit(placement) {
			// Convert placement data to tile format for editing.
			const tileData = {
				id: placement.id,
				title: placement.tileTitle,
				icon: placement.tileIcon,
				iconType: placement.tileIconType,
				backgroundColor: placement.tileBackgroundColor,
				textColor: placement.tileTextColor,
				linkType: placement.tileLinkType,
				linkValue: placement.tileLinkValue,
			}
			this.openTileEditor(tileData)
		},
		closeTileEditor() {
			this.isTileEditorOpen = false
			this.editingTile = null
		},
		async saveTile(tileData) {
			console.log('[Views] saveTile called with data:', tileData)
			try {
				if (this.editingTile) {
					console.log('[Views] Updating existing tile:', this.editingTile.id)
					// Update existing tile (which is stored as a placement).
					await this.updateWidgetPlacement(this.editingTile.id, {
						tileTitle: tileData.title,
						tileIcon: tileData.icon,
						tileIconType: tileData.iconType,
						tileBackgroundColor: tileData.backgroundColor,
						tileTextColor: tileData.textColor,
						tileLinkType: tileData.linkType,
						tileLinkValue: tileData.linkValue,
					})
					console.log('[Views] Tile updated successfully')
				} else {
					console.log('[Views] Creating new tile for dashboard')
					// Use the widget placement helper to compute position
					const gridComponent = this.$refs.dashboardGrid
					let position = null

					if (gridComponent && gridComponent.placeWidget) {
						// Tiles have default size 2×2
						const spec = { w: 2, h: 2 }
						position = gridComponent.placeWidget(spec)
					}

					// Create new tile using the store action (like widgets).
					await this.addTileToDashboard(tileData, position)
					console.log('[Views] Tile added successfully')
				}
				this.closeTileEditor()
			} catch (error) {
				console.error('[Views] Failed to save tile:', error)
				console.error('[Views] Error details:', error.response?.data)
			}
		},
		async deleteTile() {
			if (this.editingTile?.id) {
				console.log('[Views] Deleting tile:', this.editingTile.id)
				await this.removeWidget(this.editingTile.id)
				this.closeTileEditor()
			}
		},
		async handleCreateDashboard() {
			const name = prompt(this.t('mydash', 'Dashboard name'))
			if (!name) return

			try {
				await this.createDashboard({ name })
			} catch (error) {
				console.error('Failed to create dashboard:', error)
			}
		},
		async handleEditDashboard(dashboard) {
			const name = prompt(this.t('mydash', 'Dashboard name'), dashboard.name)
			if (!name || name === dashboard.name) return

			try {
				await api.updateDashboard(dashboard.id, { name })
				// Refresh dashboards.
				await this.loadDashboards()
			} catch (error) {
				console.error('Failed to update dashboard:', error)
			}
		},
		async handleDeleteDashboard(dashboard) {
			if (!confirm(this.t('mydash', 'Are you sure you want to delete this dashboard?'))) {
				return
			}

			try {
				await api.deleteDashboard(dashboard.id)
				// Refresh dashboards.
				await this.loadDashboards()
			} catch (error) {
				console.error('Failed to delete dashboard:', error)
			}
		},
	},
}
</script>

<style scoped>
#mydash-app {
	min-height: 100vh;
	width: 100%;
	background: transparent;
}

.mydash-floating-controls {
	position: fixed;
	top: 80px;
	right: 16px;
	display: flex;
	gap: 8px;
	align-items: center;
	z-index: 1000;
}

.mydash-container {
	flex: 1;
	padding: 0;
	overflow: auto;
	min-height: calc(100vh - var(--header-height));
}

.mydash-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	min-height: calc(100vh - var(--header-height));
}
</style>
