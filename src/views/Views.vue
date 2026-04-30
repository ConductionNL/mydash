<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div id="mydash-app">
		<!-- Sidebar backdrop: click outside to close (REQ-SHELL-006) -->
		<SidebarBackdrop
			:is-open="sidebarOpen"
			@close="sidebarOpen = false" />

		<!-- Slide-in sidebar (REQ-SHELL-003 / REQ-SHELL-004) -->
		<DashboardSwitcherSidebar
			:is-open="sidebarOpen"
			:group-name="primaryGroupName"
			:group-dashboards="groupDashboards"
			:user-dashboards="userDashboards"
			:active-dashboard-id="activeDashboard ? activeDashboard.id : ''"
			:allow-user-dashboards="allowUserDashboards"
			@update:open="sidebarOpen = $event"
			@switch="onSwitchDashboard"
			@create-dashboard="onCreateDashboard"
			@delete-dashboard="onDeleteDashboard" />

		<!-- Main content area -->
		<div class="mydash-main">
			<!-- Header strip: hamburger + active-dashboard label (REQ-SHELL-004) -->
			<div class="mydash-header-strip">
				<button
					class="mydash-hamburger"
					:aria-label="t('mydash', 'Open navigation')"
					@click="sidebarOpen = !sidebarOpen">
					<MenuIcon :size="20" />
				</button>
				<span class="mydash-active-dashboard-label">
					{{ activeDashboard ? activeDashboard.name : '' }}
				</span>

				<!-- Edit toolbar (REQ-SHELL-003): only when canEdit -->
				<div v-if="canEdit" class="mydash-toolbar">
					<NcButton
						:type="isEditMode ? 'primary' : 'secondary'"
						:aria-label="isEditMode ? t('mydash', 'Close editing') : t('mydash', 'Customize')"
						@click="toggleEditMode">
						<template #icon>
							<Close v-if="isEditMode" :size="20" />
							<Cog v-else :size="20" />
						</template>
						{{ isEditMode ? t('mydash', 'Close') : '' }}
					</NcButton>

					<template v-if="isEditMode">
						<NcButton
							type="secondary"
							:aria-label="t('mydash', 'Add Widget')"
							@click="openWidgetPicker">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('mydash', 'Add Widget') }}
						</NcButton>

						<NcButton
							type="secondary"
							:aria-label="t('mydash', 'Save Layout')"
							:disabled="saving"
							@click="saveLayout">
							<template #icon>
								<ContentSave :size="20" />
							</template>
							{{ t('mydash', 'Save Layout') }}
						</NcButton>
					</template>
				</div>
			</div>

			<!-- Main dashboard grid (REQ-SHELL-001) -->
			<div class="mydash-container" :class="{ 'mydash-edit-mode': isEditMode }">
				<!-- Grid: shown when active dashboard exists (REQ-SHELL-005) -->
				<DashboardGrid
					v-if="activeDashboard"
					ref="dashboardGrid"
					:placements="widgetPlacements"
					:widgets="availableWidgets"
					:edit-mode="isEditMode && canEdit"
					:grid-columns="activeDashboard.gridColumns"
					@update:placements="updatePlacements"
					@widget-remove="removeWidget"
					@widget-edit="openWidgetEditModal"
					@tile-edit="openTileEditorForEdit" />

				<!-- Empty state: shown when no active dashboard (REQ-SHELL-005) -->
				<div v-else class="mydash-empty">
					<NcEmptyContent
						:name="t('mydash', 'You have no dashboards yet')"
						:description="allowUserDashboards
							? t('mydash', 'Create your first dashboard to get started')
							: t('mydash', 'Personal dashboards are not enabled. Ask your administrator.')">
						<template #icon>
							<ViewDashboard :size="64" />
						</template>
						<template v-if="allowUserDashboards" #action>
							<NcButton type="primary" @click="onCreateDashboard">
								{{ t('mydash', 'Create your first dashboard') }}
							</NcButton>
						</template>
					</NcEmptyContent>
				</div>
			</div>
		</div>

		<!-- Widget picker sidebar -->
		<WidgetPicker
			:open="isPickerOpen"
			:widgets="availableWidgets"
			:placed-widget-ids="placedWidgetIds"
			:dashboards="dashboards"
			:active-dashboard-id="activeDashboard ? activeDashboard.id : ''"
			@close="closeWidgetPicker"
			@add="addWidget"
			@add-tile="openTileEditor()"
			@switch-dashboard="onSwitchDashboard"
			@create-dashboard="onCreateDashboard"
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
import { showSuccess, showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

// Icons
import Close from 'vue-material-design-icons/Close.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import MenuIcon from 'vue-material-design-icons/Menu.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

// Components
import DashboardGrid from '../components/DashboardGrid.vue'
import WidgetPicker from '../components/WidgetPicker.vue'
import WidgetStyleEditor from '../components/WidgetStyleEditor.vue'
import AddWidgetModal from '../components/Widgets/AddWidgetModal.vue'
import TileEditor from '../components/TileEditor.vue'
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
		Close,
		Cog,
		Plus,
		MenuIcon,
		ViewDashboard,
		ContentSave,
		DashboardGrid,
		WidgetPicker,
		WidgetStyleEditor,
		AddWidgetModal,
		TileEditor,
		DashboardSwitcherSidebar,
		SidebarBackdrop,
	},

	/**
	 * Inject workspace initial-state values provided from main.js (REQ-INIT-004 / REQ-INIT-005).
	 * These are plain values pushed by InitialStateBuilder via Vue.provide() at the root.
	 */
	inject: {
		isAdmin: { default: false },
		dashboardSource: { default: 'group' },
		allowUserDashboards: { default: false },
		primaryGroupName: { default: '' },
		groupDashboards: { default: () => [] },
		userDashboards: { default: () => [] },
	},

	data() {
		return {
			sidebarOpen: false,
			isEditMode: false,
			saving: false,
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
			'loading',
		]),
		...mapState(useWidgetStore, ['availableWidgets']),
		...mapState(useTileStore, ['tiles']),

		/**
		 * REQ-SHELL-002: canEdit gate — admins can always edit; regular users
		 * only when viewing their own personal dashboard (source = 'user').
		 *
		 * @return {boolean}
		 */
		canEdit() {
			return this.isAdmin || this.dashboardSource === 'user'
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

		// ─── Toolbar ───────────────────────────────────────────────────────

		toggleEditMode() {
			this.isEditMode = !this.isEditMode
			if (!this.isEditMode) {
				this.closeWidgetPicker()
				this.closeStyleEditor()
			}
		},

		/**
		 * REQ-SHELL-003: Save Layout — PUT current placements to the correct
		 * endpoint based on dashboardSource.
		 */
		async saveLayout() {
			if (this.saving || !this.activeDashboard) return

			this.saving = true
			try {
				const layout = this.widgetPlacements.map(p => ({
					id: p.id,
					gridX: p.gridX,
					gridY: p.gridY,
					gridWidth: p.gridWidth,
					gridHeight: p.gridHeight,
				}))

				if (this.dashboardSource === 'group' || this.dashboardSource === 'default') {
					await api.updateGroupDashboard(
						this.activeDashboard.groupId,
						this.activeDashboard.uuid || this.activeDashboard.id,
						{ layout },
					)
				} else {
					await api.updateDashboard(
						this.activeDashboard.id,
						{ layout },
					)
				}

				showSuccess(t('mydash', 'Layout saved'))
			} catch (error) {
				console.error('[Views] saveLayout failed:', error)
				showError(t('mydash', 'Failed to save layout'))
			} finally {
				this.saving = false
			}
		},

		// ─── Sidebar event handlers ────────────────────────────────────────

		/**
		 * REQ-SHELL-003: Switch to another dashboard (REQ-DASH-018).
		 *
		 * @param {string} dashboardId - Dashboard ID to switch to
		 * @param {string} source - Source type ('group' | 'default' | 'user')
		 */
		async onSwitchDashboard(dashboardId, source) {
			this.sidebarOpen = false
			try {
				await this.switchDashboard(dashboardId)
			} catch (error) {
				console.error('[Views] Failed to switch dashboard:', error)
			}
		},

		/**
		 * REQ-SHELL-004: Create a new personal dashboard.
		 */
		async onCreateDashboard() {
			const name = prompt(t('mydash', 'Dashboard name'))
			if (!name) return

			try {
				await this.createDashboard(name)
			} catch (error) {
				console.error('[Views] Failed to create dashboard:', error)
			}
		},

		/**
		 * REQ-SHELL-003: Delete a dashboard with confirmation.
		 *
		 * @param {string} dashboardId - Dashboard ID to delete
		 */
		async onDeleteDashboard(dashboardId) {
			if (!confirm(t('mydash', 'Are you sure you want to delete this dashboard?'))) {
				return
			}

			try {
				await api.deleteDashboard(dashboardId)
				// Refresh dashboards.
				await this.loadDashboards()
			} catch (error) {
				console.error('[Views] Failed to delete dashboard:', error)
			}
		},

		// ─── Widget picker ─────────────────────────────────────────────────

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

		// ─── Style editor ──────────────────────────────────────────────────

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

		// ─── Tile editor ───────────────────────────────────────────────────

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
			try {
				if (this.editingTile) {
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
				} else {
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

		// ─── Legacy dashboard CRUD (used by WidgetPicker) ──────────────────

		async handleEditDashboard(dashboard) {
			const name = prompt(t('mydash', 'Dashboard name'), dashboard.name)
			if (!name || name === dashboard.name) return

			try {
				await api.updateDashboard(dashboard.id, { name })
				// Refresh dashboards.
				await this.loadDashboards()
			} catch (error) {
				console.error('[Views] Failed to update dashboard:', error)
			}
		},
		async handleDeleteDashboard(dashboard) {
			await this.onDeleteDashboard(dashboard.id)
		},
	},
}
</script>

<style scoped>
#mydash-app {
	min-height: 100vh;
	width: 100%;
	background: transparent;
	display: flex;
	flex-direction: column;
}

/* REQ-SHELL-003 / REQ-SHELL-004: Header strip with hamburger + label + toolbar */
.mydash-header-strip {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
	position: sticky;
	top: var(--header-height, 50px);
	z-index: 100;
	flex-shrink: 0;
}

.mydash-hamburger {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	padding: 0;
	background: none;
	border: none;
	border-radius: var(--border-radius);
	cursor: pointer;
	color: var(--color-text-base);
	flex-shrink: 0;
	transition: background-color 0.15s ease;
}

.mydash-hamburger:hover {
	background-color: var(--color-background-hover);
}

.mydash-active-dashboard-label {
	font-weight: 600;
	font-size: 15px;
	color: var(--color-text-base);
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* REQ-SHELL-003: Toolbar at right of header strip */
.mydash-toolbar {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}

/* REQ-SHELL-001: Main content wraps header + grid */
.mydash-main {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-height: 0;
}

.mydash-container {
	flex: 1;
	padding: 0;
	overflow: auto;
	min-height: calc(100vh - var(--header-height, 50px) - 53px);
}

/* REQ-SHELL-005: Empty-state centred inside grid area */
.mydash-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	min-height: calc(100vh - var(--header-height, 50px) - 53px);
}
</style>
