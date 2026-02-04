<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="mydash-picker" :class="{ 'mydash-picker--open': open }">
		<div class="mydash-picker__header">
			<h2 class="mydash-picker__title">
				{{ t('mydash', 'Add to dashboard') }}
			</h2>
			<NcButton type="tertiary" @click="$emit('close')">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Tabs -->
		<div class="mydash-picker__tabs">
			<button
				class="mydash-picker__tab"
				:class="{ 'mydash-picker__tab--active': activeTab === 'widgets' }"
				@click="activeTab = 'widgets'">
				<ViewModule :size="20" />
				{{ t('mydash', 'Widgets') }}
			</button>
			<button
				class="mydash-picker__tab"
				:class="{ 'mydash-picker__tab--active': activeTab === 'tiles' }"
				@click="activeTab = 'tiles'">
				<ViewGrid :size="20" />
				{{ t('mydash', 'Tiles') }}
			</button>
		</div>

		<!-- Widgets Tab -->
		<div v-if="activeTab === 'widgets'" class="mydash-picker__content">
			<div class="mydash-picker__search">
				<NcTextField
					v-model="searchQuery"
					:placeholder="t('mydash', 'Search widgets...')"
					:show-trailing-button="searchQuery !== ''"
					trailing-button-icon="close"
					@trailing-button-click="searchQuery = ''">
					<template #icon>
						<Magnify :size="20" />
					</template>
				</NcTextField>
			</div>

			<div class="mydash-picker__list">
				<div
					v-for="widget in filteredWidgets"
					:key="widget.id"
					class="mydash-picker__widget"
					:class="{ 'mydash-picker__widget--placed': isPlaced(widget.id) }"
					@click="addWidget(widget)">
					<img
						v-if="widget.iconUrl"
						:src="widget.iconUrl"
						:alt="widget.title"
						class="mydash-picker__widget-icon">
					<span v-else-if="widget.iconClass" :class="widget.iconClass" class="mydash-picker__widget-icon" />
					<div class="mydash-picker__widget-info">
						<span class="mydash-picker__widget-title">{{ widget.title }}</span>
						<span v-if="isPlaced(widget.id)" class="mydash-picker__widget-badge">
							{{ t('mydash', 'Already added') }}
						</span>
					</div>
					<Plus v-if="!isPlaced(widget.id)" :size="20" class="mydash-picker__widget-add" />
					<Check v-else :size="20" class="mydash-picker__widget-check" />
				</div>

				<NcEmptyContent
					v-if="filteredWidgets.length === 0"
					:description="t('mydash', 'No widgets found')">
					<template #icon>
						<Magnify :size="48" />
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<!-- Tiles Tab -->
		<div v-if="activeTab === 'tiles'" class="mydash-picker__content">
			<div class="mydash-picker__tiles-header">
				<NcButton
					type="primary"
					@click="$emit('add-tile')">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('mydash', 'Create Tile') }}
				</NcButton>
			</div>

			<div v-if="tiles.length > 0" class="tiles-list">
				<div
					v-for="tile in tiles"
					:key="tile.id"
					class="tile-item"
					:style="{
						backgroundColor: tile.backgroundColor,
						color: tile.textColor
					}"
					@click="addTile(tile)">
					<svg
						v-if="tile.iconType === 'svg'"
						class="tile-item__icon"
						:style="{ fill: tile.textColor }"
						viewBox="0 0 24 24">
						<path :d="tile.icon" />
					</svg>
					<span v-else-if="tile.iconType === 'class'" :class="tile.icon" class="tile-item__icon" />
					<img v-else-if="tile.iconType === 'url'" :src="tile.icon" class="tile-item__icon" alt="Icon">
					<span v-else-if="tile.iconType === 'emoji'" class="tile-item__icon tile-item__emoji">{{ tile.icon }}</span>
					<span class="tile-item__title">{{ tile.title }}</span>
					<Plus :size="20" class="tile-item__add" />
				</div>
			</div>

			<NcEmptyContent
				v-else
				:description="t('mydash', 'No tiles yet')">
				<template #icon>
					<ViewGrid :size="48" />
				</template>
				<template #action>
					<NcButton type="primary" @click="$emit('add-tile')">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('mydash', 'Create your first tile') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcEmptyContent } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ViewModule from 'vue-material-design-icons/ViewModule.vue'
import ViewGrid from 'vue-material-design-icons/ViewGrid.vue'

export default {
	name: 'WidgetPicker',

	components: {
		NcButton,
		NcTextField,
		NcEmptyContent,
		Close,
		Magnify,
		Plus,
		Check,
		ViewModule,
		ViewGrid,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		widgets: {
			type: Array,
			required: true,
		},
		placedWidgetIds: {
			type: Array,
			default: () => [],
		},
		tiles: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'add', 'add-tile', 'add-tile-to-dashboard', 'edit-tile'],

	data() {
		return {
			searchQuery: '',
			activeTab: 'widgets',
		}
	},

	computed: {
		filteredWidgets() {
			if (!this.searchQuery) {
				return this.sortedWidgets
			}

			const query = this.searchQuery.toLowerCase()
			return this.sortedWidgets.filter(
				w => w.title.toLowerCase().includes(query),
			)
		},

		sortedWidgets() {
			return [...this.widgets].sort((a, b) => {
				// Show not-placed widgets first
				const aPlaced = this.isPlaced(a.id)
				const bPlaced = this.isPlaced(b.id)
				if (aPlaced !== bPlaced) {
					return aPlaced ? 1 : -1
				}
				// Then sort by order
				return (a.order || 0) - (b.order || 0)
			})
		},
	},

	methods: {
		isPlaced(widgetId) {
			return this.placedWidgetIds.includes(widgetId)
		},

		addWidget(widget) {
			// Allow adding the same widget multiple times.
			this.$emit('add', widget.id)
		},

		addTile(tile) {
			// Emit event to add tile as a widget (using tile-{id} as widget ID).
			this.$emit('add', `tile-${tile.id}`)
		},
	},
}
</script>

<style scoped>
.mydash-picker {
	position: fixed;
	right: 0;
	top: 50px;
	bottom: 0;
	width: 320px;
	background: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	transform: translateX(100%);
	transition: transform 0.2s ease;
	z-index: 1000;
	display: flex;
	flex-direction: column;
}

.mydash-picker--open {
	transform: translateX(0);
}

.mydash-picker__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
}

.mydash-picker__title {
	font-size: 18px;
	font-weight: 600;
	margin: 0;
}

.mydash-picker__tabs {
	display: flex;
	border-bottom: 1px solid var(--color-border);
}

.mydash-picker__tab {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 12px 16px;
	background: none;
	border: none;
	cursor: pointer;
	font-size: 14px;
	font-weight: 500;
	color: var(--color-text-lighter);
	transition: all 0.2s ease;
	border-bottom: 2px solid transparent;
}

.mydash-picker__tab:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.mydash-picker__tab--active {
	color: var(--color-primary-element);
	border-bottom-color: var(--color-primary-element);
}

.mydash-picker__content {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
}

.mydash-picker__search {
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
}

.mydash-picker__tiles-header {
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
}

.mydash-picker__list {
	flex: 1;
	overflow-y: auto;
	padding: 0 16px 16px;
}

.mydash-picker__widget {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.1s ease;
}

.mydash-picker__widget:hover {
	background: var(--color-background-hover);
}

.mydash-picker__widget--placed {
	opacity: 0.6;
}

.mydash-picker__widget-icon {
	width: 32px;
	height: 32px;
	flex-shrink: 0;
}

.mydash-picker__widget-info {
	flex: 1;
	min-width: 0;
}

.mydash-picker__widget-title {
	display: block;
	font-weight: 500;
}

.mydash-picker__widget-badge {
	display: block;
	font-size: 12px;
	color: var(--color-text-lighter);
}

.mydash-picker__widget-add,
.mydash-picker__widget-check {
	flex-shrink: 0;
	color: var(--color-text-lighter);
}

.mydash-picker__widget-check {
	color: var(--color-success);
}

.tiles-list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
	gap: 8px;
	padding: 16px;
}

.tile-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 12px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: transform 0.2s ease, opacity 0.2s ease;
	position: relative;
}

.tile-item:hover {
	transform: scale(1.05);
	opacity: 0.9;
}

.tile-item__icon {
	font-size: 32px;
	width: 32px;
	height: 32px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.tile-item__icon.tile-item__emoji {
	filter: none !important;
}

/* For class-based icons, invert to white */
.tile-item__icon:not(.tile-item__emoji):not(svg) {
	filter: brightness(0) invert(1);
}

.tile-item__icon img {
	width: 100%;
	height: 100%;
	object-fit: contain;
	filter: none;
}

.tile-item__title {
	font-size: 11px;
	font-weight: 600;
	text-align: center;
	word-break: break-word;
	line-height: 1.2;
}

.tile-item__add {
	position: absolute;
	top: 4px;
	right: 4px;
	opacity: 0;
	transition: opacity 0.2s ease;
}

.tile-item:hover .tile-item__add {
	opacity: 1;
}
</style>
