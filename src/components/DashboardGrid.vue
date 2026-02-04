<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div ref="gridContainer" class="mydash-grid">
		<div class="grid-stack">
			<div
				v-for="placement in placements"
				:key="placement.id"
				class="grid-stack-item"
				:gs-id="placement.id"
				:gs-x="placement.gridX"
				:gs-y="placement.gridY"
				:gs-w="placement.gridWidth"
				:gs-h="placement.gridHeight"
				:gs-min-w="2"
				:gs-min-h="2">
				<div class="grid-stack-item-content">
					<!-- Render Tile directly for tile placements. -->
					<TileWidget
						v-if="isTilePlacement(placement)"
						:tile="getTileData(placement)"
						:edit-mode="editMode"
						@remove="$emit('widget-remove', placement.id)" />
					
					<!-- Render Widget with wrapper for widget placements. -->
					<WidgetWrapper
						v-else
						:placement="placement"
						:widget="getWidget(placement.widgetId)"
						:edit-mode="editMode"
						@remove="$emit('widget-remove', placement.id)"
						@style="$emit('widget-style', placement)" />
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { GridStack } from 'gridstack'
import WidgetWrapper from './WidgetWrapper.vue'
import TileWidget from './TileWidget.vue'
import { useTileStore } from '../stores/tiles.js'
import { mapState } from 'pinia'

export default {
	name: 'DashboardGrid',

	components: {
		WidgetWrapper,
		TileWidget,
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
			default: 12,
		},
	},

	emits: ['update:placements', 'widget-remove', 'widget-style'],

	data() {
		return {
			grid: null,
		}
	},

	computed: {
		...mapState(useTileStore, ['tiles']),
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
	},

	beforeDestroy() {
		if (this.grid) {
			this.grid.destroy(false)
		}
	},

	methods: {
		getWidget(widgetId) {
			return this.widgets.find(w => w.id === widgetId)
		},

		isTilePlacement(placement) {
			// Check if this placement is for a tile (widgetId starts with 'tile-').
			return placement.widgetId && placement.widgetId.startsWith('tile-')
		},

		getTileData(placement) {
			// Extract tile ID from widgetId (e.g., 'tile-4' -> 4).
			if (!this.isTilePlacement(placement)) return null
			const tileId = parseInt(placement.widgetId.replace('tile-', ''))
			const tile = this.tiles.find(t => t.id === tileId)
			console.log('[DashboardGrid] getTileData:', {
				placementId: placement.id,
				widgetId: placement.widgetId,
				tileId,
				foundTile: tile ? {
					id: tile.id,
					title: tile.title,
					backgroundColor: tile.backgroundColor,
					textColor: tile.textColor,
					icon: tile.icon?.substring(0, 50),
					iconType: tile.iconType
				} : null
			})
			return tile
		},

		initGrid() {
			this.grid = GridStack.init({
				column: this.gridColumns,
				cellHeight: 80,
				margin: 12,
				float: true,
				animate: true,
				disableDrag: !this.editMode,
				disableResize: !this.editMode,
				removable: false,
			}, this.$refs.gridContainer.querySelector('.grid-stack'))

			// Listen for changes
			this.grid.on('change', (event, items) => {
				this.handleGridChange(items)
			})
		},

		handleGridChange(items) {
			if (!items || items.length === 0) return

			const updatedPlacements = this.placements.map(placement => {
				const gridItem = items.find(item => String(item.id) === String(placement.id))
				if (gridItem) {
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
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 0 10px var(--color-box-shadow);
	overflow: hidden;
}

:deep(.grid-stack-placeholder > .placeholder-content) {
	background: var(--color-primary-element-light);
	border: 2px dashed var(--color-primary-element);
	border-radius: var(--border-radius-large);
}
</style>
