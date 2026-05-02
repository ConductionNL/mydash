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
				@widget-right-click="onWidgetRightClick"
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

		<!-- Widget right-click context menu (REQ-WDG-015..017). The
		     popover renders only in edit mode, anchored at the cursor.
		     Clicking Edit reuses AddWidgetModal with the placement set
		     as `editingWidget`; Remove calls the placement-delete path
		     of REQ-WDG-005; Cancel is a no-op close. -->
		<WidgetContextMenu
			v-if="grid.state.contextMenuOpen"
			:top="grid.state.contextMenuPosition.y"
			:left="grid.state.contextMenuPosition.x"
			@edit="grid.triggerEdit()"
			@remove="grid.triggerRemove()"
			@close="grid.closeContextMenu()" />
	</div>
</template>

<script>
import Vue from 'vue'
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
import WidgetContextMenu from '../components/Widgets/WidgetContextMenu.vue'

// Stores
import { useDashboardStore } from '../stores/dashboard.js'
import { useWidgetStore } from '../stores/widgets.js'
import { useTileStore } from '../stores/tiles.js'
import { api } from '../services/api.js'

// Composables
import { useGridManager } from '../composables/useGridManager.js'

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
		WidgetContextMenu,
	},
	setup() {
		// Reactive `canEdit` proxy handed to the grid manager composable.
		// Wrapped in Vue.observable so the composable's
		// `onWidgetRightClick` early-return tracks the live value without
		// re-creating the composable on every edit-mode toggle. When the
		// runtime-shell capability ships, this will be replaced by the
		// typed provide/inject contract and removed from local state.
		const canEditRef = Vue.observable({ value: false })

		// `selectedWidget` from the popover may live in either of two
		// edit paths. The host-side callbacks resolve which one to use
		// (custom-type widgets → AddWidgetModal; nextcloud-widget tiles →
		// WidgetStyleEditor) and the placement-delete path is the same
		// `removeWidgetFromDashboard` action used by the existing remove
		// flow. The host wires these via methods after instantiation so
		// `this` is bound to the component when the callbacks fire.
		const grid = useGridManager({
			canEdit: canEditRef,
			onEdit(widget) {
				// `this` is the Vue instance once we bind in `created()`.
				grid._host?.handleContextMenuEdit(widget)
			},
			onRemove(widget) {
				grid._host?.handleContextMenuRemove(widget)
			},
		})

		return { canEditRef, grid }
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
		/**
		 * Whether the right-click context menu (REQ-WDG-015) should open
		 * for the current dashboard. Requires both the user permission
		 * gate and the workspace shell's edit-mode toggle. View mode
		 * intentionally falls through to the browser's native menu.
		 *
		 * @return {boolean}
		 */
		canEditForContextMenu() {
			return this.canEdit && this.isEditMode
		},
		placedWidgetIds() {
			return this.widgetPlacements.map(p => p.widgetId)
		},
	},
	watch: {
		/**
		 * Mirror the combined edit-mode / permission gate into the
		 * Vue.observable proxy the grid manager composable owns. The
		 * proxy is the only thing the composable reads, so this watcher
		 * is what keeps the right-click guard live.
		 *
		 * @param {boolean} value the new combined edit/permission value
		 */
		canEditForContextMenu: {
			immediate: true,
			handler(value) {
				if (this.canEditRef) {
					this.canEditRef.value = !!value
				}
			},
		},
	},
	async created() {
		// Bind the host onto the grid composable so its onEdit / onRemove
		// callbacks can delegate to component methods. The composable was
		// instantiated in `setup()` which has no access to `this`.
		this.grid._host = this

		const dashboardStore = useDashboardStore()
		const widgetStore = useWidgetStore()
		const tileStore = useTileStore()

		await Promise.all([
			dashboardStore.loadDashboards(),
			widgetStore.loadAvailableWidgets(),
			tileStore.loadTiles(),
		])
	},
	mounted() {
		// Attach the document-level click listener (REQ-WDG-016 outside-
		// click closes popover). Detached in beforeDestroy so we never
		// leak a listener across mounts.
		this.grid.attach()
	},
	beforeDestroy() {
		this.grid.detach()
		// Drop the host pointer to avoid retaining the Vue instance.
		this.grid._host = null
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
				// Leaving edit mode also dismisses any open right-click
				// popover so view mode never carries an edit-only surface.
				this.grid.closeContextMenu()
			}
		},

		/**
		 * DashboardGrid forwards every right-click on a placement here
		 * (REQ-WDG-015). The composable owns the early-return + viewport
		 * clamp + state mutation; we just forward the event so view mode
		 * never calls `preventDefault()`.
		 *
		 * @param {MouseEvent} event the contextmenu event
		 * @param {object} placement the placement under the cursor
		 */
		onWidgetRightClick(event, placement) {
			this.grid.onWidgetRightClick(event, placement)
		},

		/**
		 * Edit click from the popover (REQ-WDG-015 edit scenario). Custom-
		 * type placements (label, text, image, link-button, …) reuse the
		 * unified AddWidgetModal with `editingWidget` set (REQ-WDG-010);
		 * all other placements fall through to the legacy style editor so
		 * the popover is useful for stock Nextcloud widgets too.
		 *
		 * @param {object} placement the placement to edit
		 */
		handleContextMenuEdit(placement) {
			if (placement && placement.type) {
				this.openCustomWidgetEdit(placement)
				return
			}
			this.openStyleEditor(placement)
		},

		/**
		 * Remove click from the popover (REQ-WDG-015 remove scenario).
		 * Routes through the same store action as the existing remove
		 * flow so the placement-delete path of REQ-WDG-005 (DELETE
		 * `/api/placements/{id}`) remains the single source of truth.
		 *
		 * @param {object} placement the placement to delete
		 */
		async handleContextMenuRemove(placement) {
			if (!placement?.id) {
				return
			}
			try {
				await this.removeWidget(placement.id)
			} catch (error) {
				console.error('[Views] Failed to remove widget via context menu:', error)
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
