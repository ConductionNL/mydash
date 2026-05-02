<template>
	<div id="mydash-app">
		<!-- Floating controls in top right -->
		<div class="mydash-floating-controls">
			<DashboardSwitcher
				v-if="dashboards.length > 1"
				:dashboards="dashboards"
				:active-id="activeDashboard?.id"
				@switch="switchDashboard" />
			<DashboardConfigMenu
				:dashboards="dashboards"
				:active-dashboard-id="activeDashboard?.id"
				:is-edit-mode="isEditMode"
				:can-edit="canEdit"
				:is-active-owner="activeDashboard?.isOwner !== false"
				@switch-dashboard="switchDashboard"
				@create-dashboard="handleCreateDashboard"
				@toggle-edit="toggleEditMode"
				@open-config="openConfigModal"
				@add-tile="openTileEditor()"
				@add-widget="openWidgetModal"
				@add-custom-widget="openCustomWidgetModal()" />
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
				@widget-edit="openStyleEditor"
				@tile-edit="openTileEditorForEdit" />

			<!-- Empty-state shell. The "Create dashboard" affordance is gated
			     by the admin `allow_user_dashboards` flag (REQ-ASET-003,
			     extended). When the flag is off the button MUST be hidden
			     and the description swapped for a localised explainer so
			     the workspace never offers an action that would 403. -->
			<div v-else class="mydash-empty">
				<NcEmptyContent
					:name="t('mydash', 'No dashboard yet')"
					:description="emptyStateDescription">
					<template #icon>
						<ViewDashboard :size="64" />
					</template>
					<template v-if="allowUserDashboards" #action>
						<NcButton type="primary" @click="handleCreateDashboard">
							{{ t('mydash', 'Create dashboard') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<!-- Widget picker modal -->
		<WidgetPickerModal
			:open="isWidgetModalOpen"
			:widgets="availableWidgets"
			:placed-widget-ids="placedWidgetIds"
			@close="closeWidgetModal"
			@add="addWidget" />

		<!-- Custom widget add/edit modal — registry-driven host for label,
		     text, image, link-button, etc. (REQ-WDG-010..014). The modal does
		     no API calls itself; this view persists the emitted payload. -->
		<AddWidgetModal
			:show="isCustomWidgetModalOpen"
			:preselected-type="customWidgetPreselectedType"
			:editing-widget="customWidgetEditing"
			@close="closeCustomWidgetModal"
			@submit="saveCustomWidget" />

		<!-- Dashboard configuration modal (also used for creating a new dashboard) -->
		<DashboardConfigModal
			:open="isConfigModalOpen"
			:dashboard="configModalMode === 'create' ? null : activeDashboard"
			:mode="configModalMode"
			:can-delete="dashboards.length > 1"
			@close="closeConfigModal"
			@save="saveDashboardConfig"
			@delete="deleteCurrentDashboard" />

		<!-- Style editor modal -->
		<WidgetStyleEditor
			v-if="editingPlacement"
			:placement="editingPlacement"
			:open="isStyleEditorOpen"
			@close="closeStyleEditor"
			@update="updateWidgetStyle"
			@delete="deleteWidget" />

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
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'

// Components
import DashboardGrid from '../components/DashboardGrid.vue'
import WidgetPickerModal from '../components/WidgetPickerModal.vue'
import WidgetStyleEditor from '../components/WidgetStyleEditor.vue'
import TileEditor from '../components/TileEditor.vue'
import DashboardSwitcher from '../components/DashboardSwitcher.vue'
import DashboardConfigMenu from '../components/DashboardConfigMenu.vue'
import DashboardConfigModal from '../components/DashboardConfigModal.vue'
import AddWidgetModal from '../components/Widgets/AddWidgetModal.vue'

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
		ViewDashboard,
		DashboardGrid,
		WidgetPickerModal,
		WidgetStyleEditor,
		TileEditor,
		DashboardSwitcher,
		DashboardConfigMenu,
		DashboardConfigModal,
		AddWidgetModal,
	},
	// REQ-INIT-004 / REQ-ASET-003: pull the typed admin flag down the tree.
	// Default `false` keeps the UX safe even if the value is missing.
	inject: {
		allowUserDashboards: {
			from: 'allowUserDashboards',
			default: false,
		},
	},
	data() {
		return {
			isEditMode: false,
			isWidgetModalOpen: false,
			isConfigModalOpen: false,
			configModalMode: 'edit',
			isStyleEditorOpen: false,
			editingPlacement: null,
			isTileEditorOpen: false,
			editingTile: null,
			// Custom widget add/edit modal state. `customWidgetEditing`
			// non-null = edit mode; `customWidgetPreselectedType` non-null =
			// type-specific deep-link from the toolbar.
			isCustomWidgetModalOpen: false,
			customWidgetPreselectedType: null,
			customWidgetEditing: null,
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
		/**
		 * Empty-state copy. When personal dashboards are disabled by the
		 * admin we swap the friendly "create one" prompt for a localised
		 * explainer (REQ-ASET-003). The translatable English source is
		 * kept short so the layout doesn't wrap awkwardly.
		 *
		 * @return {string}
		 */
		emptyStateDescription() {
			if (this.allowUserDashboards) {
				return this.t('mydash', 'Create your first dashboard to get started')
			}
			return this.t('mydash', 'Personal dashboards are not enabled by your administrator')
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

		toggleEditMode() {
			this.isEditMode = !this.isEditMode
			if (!this.isEditMode) {
				this.closeWidgetModal()
				this.closeStyleEditor()
			}
		},
		openWidgetModal() {
			if (!this.isEditMode) {
				this.isEditMode = true
			}
			this.isWidgetModalOpen = true
		},
		closeWidgetModal() {
			this.isWidgetModalOpen = false
		},
		/**
		 * Open the registry-driven custom widget modal in create mode.
		 * Pass a `type` to deep-link to a specific sub-form (REQ-WDG-010
		 * preselected-type scenario); omit it for the type-picker flow.
		 *
		 * @param {string|null} type registry key, or null for picker flow
		 */
		openCustomWidgetModal(type = null) {
			if (!this.isEditMode) {
				this.isEditMode = true
			}
			this.customWidgetPreselectedType = type
			this.customWidgetEditing = null
			this.isCustomWidgetModalOpen = true
		},
		/**
		 * Open the modal in edit mode for an existing custom-type
		 * placement. The placement's type is immutable in edit mode
		 * (REQ-WDG-010), so the type select is hidden.
		 *
		 * @param {object} placement existing placement record with type+content
		 */
		openCustomWidgetEdit(placement) {
			this.customWidgetEditing = placement
			this.customWidgetPreselectedType = null
			this.isCustomWidgetModalOpen = true
		},
		closeCustomWidgetModal() {
			this.isCustomWidgetModalOpen = false
			this.customWidgetPreselectedType = null
			this.customWidgetEditing = null
		},
		/**
		 * Persist the `{type, content}` payload emitted by AddWidgetModal.
		 * In create mode we route through `addWidgetToDashboard` (which
		 * the per-widget proposals will extend to accept custom-type
		 * payloads); in edit mode we route through `updateWidgetPlacement`.
		 *
		 * The per-widget capability proposals own the actual API contract
		 * — this view simply forwards the payload, mirroring how the tile
		 * editor and style editor work today.
		 *
		 * @param {{type: string, content: object}} payload the widget add/edit payload from AddWidgetModal
		 */
		async saveCustomWidget(payload) {
			try {
				if (this.customWidgetEditing?.id) {
					await this.updateWidgetPlacement(
						this.customWidgetEditing.id,
						{ content: payload.content },
					)
				} else {
					await this.addWidgetToDashboard({
						type: payload.type,
						content: payload.content,
					})
				}
				this.closeCustomWidgetModal()
			} catch (error) {
				console.error('[Views] Failed to save custom widget:', error)
			}
		},
		openConfigModal() {
			this.configModalMode = 'edit'
			this.isConfigModalOpen = true
		},
		openCreateDashboardModal() {
			this.configModalMode = 'create'
			this.isConfigModalOpen = true
		},
		closeConfigModal() {
			this.isConfigModalOpen = false
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
		async updateWidgetStyle(placementId, updates) {
			await this.updateWidgetPlacement(placementId, updates)
			this.closeStyleEditor()
		},
		async deleteWidget() {
			if (this.editingPlacement?.id) {
				await this.removeWidget(this.editingPlacement.id)
				this.closeStyleEditor()
			}
		},
		openTileEditor(tile = null) {
			if (!this.isEditMode) {
				this.isEditMode = true
			}
			this.editingTile = tile
			this.isTileEditorOpen = true
		},
		openTileEditorForEdit(placement) {
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
			try {
				if (this.editingTile) {
					await this.updateWidgetPlacement(this.editingTile.id, {
						tileTitle: tileData.title,
						tileIcon: tileData.icon,
						tileIconType: tileData.iconType,
						tileBackgroundColor: tileData.backgroundColor,
						tileTextColor: tileData.textColor,
						tileLinkType: tileData.linkType,
						tileLinkValue: tileData.linkValue,
					})
				} else {
					await this.addTileToDashboard(tileData)
				}
				this.closeTileEditor()
			} catch (error) {
				console.error('[Views] Failed to save tile:', error)
			}
		},
		async deleteTile() {
			if (this.editingTile?.id) {
				await this.removeWidget(this.editingTile.id)
				this.closeTileEditor()
			}
		},
		handleCreateDashboard() {
			this.openCreateDashboardModal()
		},
		async saveDashboardConfig({ id, name, description }) {
			try {
				if (id == null) {
					await this.createDashboard({ name, description })
				} else {
					await api.updateDashboard(id, { name, description })
					await this.loadDashboards()
				}
				this.closeConfigModal()
			} catch (error) {
				console.error('Failed to save dashboard:', error)
			}
		},
		async deleteCurrentDashboard(dashboard) {
			if (!confirm(this.t('mydash', 'Are you sure you want to delete this dashboard?'))) {
				return
			}

			try {
				await api.deleteDashboard(dashboard.id)
				await this.loadDashboards()
				this.closeConfigModal()
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
	right: 44px;
	display: flex;
	gap: 8px;
	align-items: center;
	z-index: 1000;
}

/* Strip the visible text on the menu trigger button — we want icon-only.
   NcActions renders its aria-label as button text in this version. */
.mydash-floating-controls :deep(.action-item__menutoggle .button-vue__text) {
	display: none;
}
.mydash-floating-controls :deep(.action-item__menutoggle) {
	width: var(--default-clickable-area, 44px);
	min-width: var(--default-clickable-area, 44px);
	padding: 0;
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
