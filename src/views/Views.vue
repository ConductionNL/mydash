<template>
	<div id="mydash-app">
		<!-- Slide-in sidebar (REQ-SWITCH-001..007). Wired with Vue 2's
		     v-model rebind (model: { prop: 'isOpen', event: 'update:open' })
		     so this template can use plain `v-model` while the sidebar
		     emits the `update:open(boolean)` event mandated by the spec.
		     Once `runtime-shell` ships and replaces this view with
		     `WorkspaceApp.vue`, the same binding shape applies. -->
		<DashboardSwitcherSidebar
			v-model="sidebarOpen"
			:group-name="primaryGroupName"
			:group-dashboards="sidebarGroupDashboards"
			:user-dashboards="sidebarUserDashboards"
			:active-dashboard-id="activeDashboard?.id"
			:allow-user-dashboards="allowUserDashboards"
			@switch="onSidebarSwitch"
			@create-dashboard="onSidebarCreateDashboard"
			@delete-dashboard="onSidebarDeleteDashboard" />
		<SidebarBackdrop
			v-if="sidebarOpen"
			@click="sidebarOpen = false" />

		<!-- Floating controls in top right -->
		<div class="mydash-floating-controls">
			<NcButton
				type="tertiary"
				:aria-label="t('mydash', 'Dashboards')"
				class="mydash-sidebar-toggle"
				@click="sidebarOpen = !sidebarOpen">
				<template #icon>
					<MenuIcon :size="20" />
				</template>
			</NcButton>
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
	</div>
</template>

<script>
import { mapState, mapActions } from 'pinia'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

// Icons
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import MenuIcon from 'vue-material-design-icons/Menu.vue'

// Components
import DashboardGrid from '../components/DashboardGrid.vue'
import WidgetPickerModal from '../components/WidgetPickerModal.vue'
import WidgetStyleEditor from '../components/WidgetStyleEditor.vue'
import TileEditor from '../components/TileEditor.vue'
import DashboardSwitcher from '../components/DashboardSwitcher.vue'
import DashboardConfigMenu from '../components/DashboardConfigMenu.vue'
import DashboardConfigModal from '../components/DashboardConfigModal.vue'
import AddWidgetModal from '../components/Widgets/AddWidgetModal.vue'
import DashboardSwitcherSidebar from '../components/Workspace/DashboardSwitcherSidebar.vue'
import SidebarBackdrop from '../components/Workspace/SidebarBackdrop.vue'

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
		MenuIcon,
		DashboardGrid,
		WidgetPickerModal,
		WidgetStyleEditor,
		TileEditor,
		DashboardSwitcher,
		DashboardConfigMenu,
		DashboardConfigModal,
		AddWidgetModal,
		DashboardSwitcherSidebar,
		SidebarBackdrop,
	},
	// Inject the typed initial-state snapshot pushed from `src/main.js`
	// (REQ-INIT-003..005). Defaults match the reader contract so the
	// sidebar still mounts when running under tests that don't set a
	// provider (e.g. Vitest harness) — see DashboardSwitcherSidebar specs.
	inject: {
		primaryGroupName: { default: '' },
		allowUserDashboards: { default: false },
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
			// `dashboard-switcher` capability state — controlled here, the
			// sidebar emits update:open(boolean) via its v-model rebind.
			sidebarOpen: false,
		}
	},
	computed: {
		...mapState(useDashboardStore, [
			'dashboards',
			'activeDashboard',
			'widgetPlacements',
			'permissionLevel',
			'loading',
			'userDashboards',
			'groupSharedDashboards',
			'defaultGroupDashboards',
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
		 * Combined input for the sidebar's `groupDashboards` prop —
		 * primary-group + default-group rows, each carrying their `source`
		 * discriminator from `/api/dashboards/visible` (REQ-DASH-013).
		 *
		 * @return {Array<object>} Concatenated group + default dashboards.
		 */
		sidebarGroupDashboards() {
			return [...this.groupSharedDashboards, ...this.defaultGroupDashboards]
		},
		/**
		 * Personal dashboards for the sidebar's `userDashboards` prop.
		 * Aliased so the sidebar's prop name reads naturally in the
		 * template even if the store getter is renamed later.
		 *
		 * @return {Array<object>} Dashboards with `source === 'user'`.
		 */
		sidebarUserDashboards() {
			return this.userDashboards
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
		/**
		 * Handle a switch emitted by `DashboardSwitcherSidebar`. The sidebar
		 * passes the row's `source` discriminator alongside the id so we
		 * can pick the correct API endpoint per REQ-DASH-013/REQ-DASH-014:
		 *
		 *   - `'user'`    → personal dashboard endpoint (already the
		 *                   default in `dashboardStore.switchDashboard`)
		 *   - `'group'`   → primary group endpoint
		 *   - `'default'` → default group endpoint
		 *
		 * The store currently fetches via `getDashboardById`, which works
		 * for every visible-to-user record regardless of source — the
		 * group/default branches stay identical for now and exist to make
		 * the source contract visible to readers (and to keep the fan-out
		 * easy when source-specific endpoints land).
		 *
		 * @param {string|number} id Dashboard id from the clicked row.
		 * @param {'group'|'default'|'user'} source Section discriminator.
		 */
		// eslint-disable-next-line no-unused-vars
		async onSidebarSwitch(id, source) {
			// `source` is currently informational — `switchDashboard`
			// resolves any visible dashboard via /api/dashboard/{id}. The
			// signature is kept explicit so per-source behaviour can land
			// without re-touching this view (and so the load-bearing
			// REQ-SWITCH-002 contract is visible at the call site).
			await this.switchDashboard(id)
		},
		/**
		 * Sidebar `+ New Dashboard` row handler — opens the create
		 * dashboard modal flow already used by the topbar config menu.
		 */
		onSidebarCreateDashboard() {
			this.openCreateDashboardModal()
		},
		/**
		 * Sidebar personal-row delete handler. Mirrors the topbar
		 * deletion flow (confirm → API → reload) but operates on an
		 * arbitrary id rather than the active dashboard.
		 *
		 * @param {string|number} id Personal dashboard id to delete.
		 */
		async onSidebarDeleteDashboard(id) {
			if (!confirm(this.t('mydash', 'Are you sure you want to delete this dashboard?'))) {
				return
			}
			try {
				await api.deleteDashboard(id)
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
	right: 44px;
	display: flex;
	gap: 8px;
	align-items: center;
	z-index: 1000;
}

.mydash-sidebar-toggle {
	/* Hint that the sidebar opens from the left even though the toggle
	   itself lives in the top-right cluster. */
	margin-right: auto;
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
