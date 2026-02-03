<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="mydash-picker" :class="{ 'mydash-picker--open': open }">
		<div class="mydash-picker__header">
			<h2 class="mydash-picker__title">
				{{ t('mydash', 'Add widgets') }}
			</h2>
			<NcButton type="tertiary" @click="$emit('close')">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>

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
</template>

<script>
import { NcButton, NcTextField, NcEmptyContent } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Check from 'vue-material-design-icons/Check.vue'

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
	},

	emits: ['close', 'add'],

	data() {
		return {
			searchQuery: '',
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
			// Allow adding the same widget multiple times
			this.$emit('add', widget.id)
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

.mydash-picker__search {
	padding: 16px;
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
</style>
