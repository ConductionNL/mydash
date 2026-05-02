<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div ref="gridContainer" class="mydash-grid">
		<div class="grid-stack">
			<div
				v-for="placement in placements"
				:key="getPlacementKey(placement)"
				class="grid-stack-item"
				:gs-id="placement.id"
				:gs-x="placement.gridX"
				:gs-y="placement.gridY"
				:gs-w="placement.gridWidth"
				:gs-h="placement.gridHeight"
				:gs-min-w="2"
				:gs-min-h="2"
				@contextmenu="onItemContextMenu($event, placement)">
				<div class="grid-stack-item-content">
					<!-- Render Tile directly for tile placements. -->
					<TileWidget
						v-if="isTilePlacement(placement)"
						:tile="getTileData(placement)"
						:edit-mode="editMode"
						@edit="$emit('tile-edit', placement)" />

					<!-- Render Widget with wrapper for widget placements. -->
					<WidgetWrapper
						v-else
						:placement="placement"
						:widget="getWidget(placement.widgetId)"
						:edit-mode="editMode"
						@remove="$emit('widget-remove', placement.id)"
						@style="$emit('widget-style', placement)"
						@edit="$emit('widget-edit', placement)" />
				</div>
			</div>
		</div>

		<!-- Widget context menu popover -->
		<WidgetContextMenu
			:show="contextMenu.show"
			:x="contextMenu.x"
			:y="contextMenu.y"
			:widget="contextMenu.widget"
			@edit="onContextEdit"
			@remove="onContextRemove"
			@close="closeContextMenu" />
	</div>
</template>

<script>
import { GridStack } from 'gridstack'
import {
	CELL_HEIGHT,
	GRID_MARGIN,
	DEFAULT_COLUMNS,
	getColumnOpts,
	syncCellHeightCssVar,
} from '../composables/useGridManager.js'
import WidgetWrapper from './WidgetWrapper.vue'
import TileWidget from './TileWidget.vue'
import WidgetContextMenu from './Widgets/WidgetContextMenu.vue'
import { placeNewWidget } from '../utils/widgetPlacement.js'

export default {
	name: 'DashboardGrid',

	components: {
		WidgetWrapper,
		TileWidget,
		WidgetContextMenu,
	},

	props: {
		placements: {
			type: Array,
			required: true,
		},
		widgets: {
			type: Array,
			required: true,
		},
		editMode: {
			type: Boolean,
			default: false,
		},
		gridColumns: {
			type: Number,
			default: DEFAULT_COLUMNS,
		},
	},

	emits: [
		'update:placements',
		'widget-remove',
		'widget-style',
		'tile-edit',
		'widget-edit',
		'widget-right-click',
	],

	data() {
		return {
			grid: null,
			viewportRows: 8,
			contextMenu: {
				show: false,
				x: 0,
				y: 0,
				widget: null,
			},
		}
	},

	watch: {
		editMode(newVal) {
			if (this.grid) {
				if (newVal) {
					this.grid.enable()
				} else {
					this.grid.disable()
				}
			}
		},

		placements: {
			deep: true,
			handler(newPlacements) {
				if (this.grid) {
					this.syncGridItems(newPlacements)
				}
			},
		},
	},

	mounted() {
		this.initGrid()
		this.computeViewportRows()
		window.addEventListener('resize', this.computeViewportRows)
		document.addEventListener('click', this.handleDocumentClick)
	},

	beforeDestroy() {
		if (this.grid) {
			this.grid.destroy(false)
		}
		window.removeEventListener('resize', this.computeViewportRows)
		document.removeEventListener('click', this.handleDocumentClick)
	},

	methods: {
		/**
		 * Forward right-click events on a grid item up to the workspace
		 * shell (REQ-WDG-015). The shell is responsible for deciding
		 * whether to swallow the native menu (edit mode) or let it through
		 * (view mode) — this component MUST NOT call `preventDefault()`
		 * itself, otherwise view-mode loses the browser's native menu.
		 *
		 * @param {MouseEvent} event the contextmenu event
		 * @param {object} placement the placement under the cursor
		 */
		onItemContextMenu(event, placement) {
			this.$emit('widget-right-click', event, placement)
		},

		/**
		 * Place a new widget using the collision placement algorithm
		 * (REQ-GRID-006, REQ-GRID-014). Returns the placement position
		 * `{x, y, w, h}` for the new widget. Caller MUST persist this
		 * position via the standard updatePlacements API.
		 *
		 * @param {object} spec widget spec with optional {w, h} dimensions
		 * @return {object} placement position {x, y, w, h}
		 */
		placeWidget(spec) {
			return placeNewWidget(spec, this.placements, this.grid, this.viewportRows)
		},

		/**
		 * Compute viewport rows from the grid container height.
		 * Called on mount and resize events.
		 */
		computeViewportRows() {
			if (!this.$refs.gridContainer) return
			const containerHeight = this.$refs.gridContainer.offsetHeight
			const rowHeight = CELL_HEIGHT + GRID_MARGIN
			this.viewportRows = Math.ceil(containerHeight / rowHeight)
		},

		getPlacementKey(placement) {
			// Generate a key that changes when placement properties update.
			// Include updatedAt or stringify relevant properties to force re-render.
			return `${placement.id}-${placement.updatedAt || Date.now()}-${JSON.stringify(placement.styleConfig || {})}`
		},

		getWidget(widgetId) {
			return this.widgets.find(w => w.id === widgetId)
		},

		isTilePlacement(placement) {
			// Check if this placement is for a tile (has tileType field).
			return placement.tileType === 'custom'
		},

		getTileData(placement) {
			// Return tile data from the placement itself.
			if (!this.isTilePlacement(placement)) return null

			return {
				id: placement.id,
				title: placement.tileTitle,
				icon: placement.tileIcon,
				iconType: placement.tileIconType,
				backgroundColor: placement.tileBackgroundColor,
				textColor: placement.tileTextColor,
				linkType: placement.tileLinkType,
				linkValue: placement.tileLinkValue,
			}
		},

		initGrid() {
			// Mirror the JS cell-height constant into the CSS custom
			// property BEFORE GridStack initialises so any first-paint
			// `calc()` expression already reads the correct value.
			// See REQ-GRID-012 (cell geometry constants).
			syncCellHeightCssVar()

			this.grid = GridStack.init({
				column: this.gridColumns,
				cellHeight: CELL_HEIGHT,
				margin: GRID_MARGIN,
				float: true,
				animate: true,
				disableDrag: !this.editMode,
				disableResize: !this.editMode,
				removable: false,
				// REQ-GRID-007 (responsive breakpoints): four explicit
				// width:column entries with the `moveScale` reflow
				// algorithm so widgets proportionally rescale on
				// viewport changes.
				columnOpts: getColumnOpts(),
			}, this.$refs.gridContainer.querySelector('.grid-stack'))

			// Listen for changes
			this.grid.on('change', (event, items) => {
				this.handleGridChange(items)
			})
		},

		handleGridChange(items) {
			if (!items || items.length === 0) return

			console.log('[DashboardGrid] Grid change detected. Items count:', items.length)

			const updatedPlacements = this.placements.map(placement => {
				const gridItem = items.find(item => String(item.id) === String(placement.id))
				if (gridItem) {
					console.log(`[DashboardGrid] Updating placement ${placement.id}:`, {
						from: { x: placement.gridX, y: placement.gridY, w: placement.gridWidth, h: placement.gridHeight },
						to: { x: gridItem.x, y: gridItem.y, w: gridItem.w, h: gridItem.h },
					})
					return {
						...placement,
						gridX: gridItem.x,
						gridY: gridItem.y,
						gridWidth: gridItem.w,
						gridHeight: gridItem.h,
					}
				}
				return placement
			})

			console.log('[DashboardGrid] Emitting updated placements, count:', updatedPlacements.length)
			this.$emit('update:placements', updatedPlacements)
		},

		syncGridItems(placements) {
			// Add new items
			for (const placement of placements) {
				const existingEl = this.grid.engine.nodes.find(
					n => String(n.id) === String(placement.id),
				)
				if (!existingEl) {
					// Item doesn't exist in grid, will be added by Vue reactivity
					this.$nextTick(() => {
						this.grid.makeWidget(`[gs-id="${placement.id}"]`)
					})
				}
			}

			// Remove items that no longer exist
			const placementIds = placements.map(p => String(p.id))
			const nodesToRemove = this.grid.engine.nodes.filter(
				n => !placementIds.includes(String(n.id)),
			)
			for (const node of nodesToRemove) {
				const el = this.$refs.gridContainer.querySelector(`[gs-id="${node.id}"]`)
				if (el) {
					this.grid.removeWidget(el, false)
				}
			}
		},

		/**
		 * Handle right-click on a grid item (REQ-WDG-015).
		 * Only open context menu in edit mode.
		 *
		 * @param {MouseEvent} event right-click event
		 * @param {object} placement widget placement object
		 */
		onWidgetRightClick(event, placement) {
			if (!this.editMode) {
				// In view mode, let the browser native menu appear
				return
			}

			event.preventDefault()

			this.contextMenu.show = true
			this.contextMenu.x = event.clientX
			this.contextMenu.y = event.clientY
			this.contextMenu.widget = placement
		},

		/**
		 * Close the context menu (REQ-WDG-016).
		 */
		closeContextMenu() {
			this.contextMenu.show = false
			this.contextMenu.widget = null
		},

		/**
		 * Handle context menu "Edit" click (REQ-WDG-015).
		 * Emits widget-edit event to parent.
		 *
		 * @param {object} placement widget placement object
		 */
		onContextEdit(placement) {
			this.$emit('widget-edit', placement)
		},

		/**
		 * Handle context menu "Remove" click (REQ-WDG-015).
		 * Emits widget-remove event to parent.
		 *
		 * @param {object} placement widget placement object
		 */
		onContextRemove(placement) {
			this.$emit('widget-remove', placement.id)
		},

		/**
		 * Document-level click handler to close context menu on outside click (REQ-WDG-016).
		 *
		 * @param {MouseEvent} event click event
		 */
		handleDocumentClick(event) {
			if (!this.contextMenu.show) {
				return
			}

			// Check if click is inside the context menu
			const menu = document.querySelector('.widget-context-menu')
			if (menu && menu.contains(event.target)) {
				// Click is inside the menu, let the menu's handlers deal with it
				return
			}

			// Click is outside the menu, close it
			this.closeContextMenu()
		},
	},
}
</script>

<style scoped>
.mydash-grid {
	width: 100%;
	min-height: 400px;
}

.grid-stack {
	background: transparent;
}

:deep(.grid-stack-item-content) {
	background: var(--color-main-background-blur);
	backdrop-filter: var(--filter-background-blur);
	-webkit-backdrop-filter: var(--filter-background-blur);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

:deep(.grid-stack-placeholder > .placeholder-content) {
	background: var(--color-primary-element-light);
	border: 2px dashed var(--color-primary-element);
	border-radius: var(--border-radius-large);
}
</style>
