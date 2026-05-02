<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal
		v-if="open"
		size="normal"
		:name="t('mydash', 'Add widget')"
		@close="$emit('close')">
		<div class="widget-picker">
			<h2 class="widget-picker__title">
				{{ t('mydash', 'Add widget') }}
			</h2>

			<div class="widget-picker__search">
				<NcTextField
					:value="searchQuery"
					:label="t('mydash', 'Search widgets')"
					:placeholder="t('mydash', 'Search widgets…')"
					:show-trailing-button="searchQuery !== ''"
					trailing-button-icon="close"
					@update:value="searchQuery = $event"
					@trailing-button-click="searchQuery = ''">
					<template #icon>
						<Magnify :size="20" />
					</template>
				</NcTextField>
			</div>

			<div class="widget-picker__list">
				<button
					v-for="widget in filteredWidgets"
					:key="widget.id"
					class="widget-picker__widget"
					:class="{ 'widget-picker__widget--placed': isPlaced(widget.id) }"
					@click="addWidget(widget)">
					<img
						v-if="widget.iconUrl"
						:src="widget.iconUrl"
						:alt="widget.title"
						class="widget-picker__widget-icon">
					<span
						v-else-if="widget.iconClass"
						:class="widget.iconClass"
						class="widget-picker__widget-icon" />
					<div class="widget-picker__widget-info">
						<span class="widget-picker__widget-title">{{ widget.title }}</span>
						<span v-if="isPlaced(widget.id)" class="widget-picker__widget-badge">
							{{ t('mydash', 'Already added') }}
						</span>
					</div>
					<Plus v-if="!isPlaced(widget.id)" :size="20" class="widget-picker__widget-action" />
					<Check v-else :size="20" class="widget-picker__widget-action widget-picker__widget-action--check" />
				</button>

				<NcEmptyContent
					v-if="filteredWidgets.length === 0"
					:description="t('mydash', 'No widgets found')">
					<template #icon>
						<Magnify :size="48" />
					</template>
				</NcEmptyContent>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcTextField, NcEmptyContent } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Check from 'vue-material-design-icons/Check.vue'

export default {
	name: 'WidgetPickerModal',

	components: {
		NcModal,
		NcTextField,
		NcEmptyContent,
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
				const aPlaced = this.isPlaced(a.id)
				const bPlaced = this.isPlaced(b.id)
				if (aPlaced !== bPlaced) {
					return aPlaced ? 1 : -1
				}
				return (a.order || 0) - (b.order || 0)
			})
		},
	},

	watch: {
		open(isOpen) {
			if (!isOpen) {
				this.searchQuery = ''
			}
		},
	},

	methods: {
		t,
		isPlaced(widgetId) {
			return this.placedWidgetIds.includes(widgetId)
		},
		addWidget(widget) {
			this.$emit('add', widget.id)
		},
	},
}
</script>

<style scoped>
.widget-picker {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-height: 80vh;
}

.widget-picker__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.widget-picker__search {
	flex-shrink: 0;
}

.widget-picker__list {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.widget-picker__widget {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px;
	border: none;
	background: transparent;
	border-radius: var(--border-radius-large);
	cursor: pointer;
	transition: background var(--animation-quick) ease;
	text-align: left;
	width: 100%;
}

.widget-picker__widget:hover,
.widget-picker__widget:focus {
	background: var(--color-background-hover);
}

.widget-picker__widget--placed {
	opacity: 0.6;
}

.widget-picker__widget-icon {
	width: 32px;
	height: 32px;
	flex-shrink: 0;
}

.widget-picker__widget-info {
	flex: 1;
	min-width: 0;
}

.widget-picker__widget-title {
	display: block;
	font-weight: 500;
	color: var(--color-main-text);
}

.widget-picker__widget-badge {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.widget-picker__widget-action {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.widget-picker__widget-action--check {
	color: var(--color-success);
}
</style>
