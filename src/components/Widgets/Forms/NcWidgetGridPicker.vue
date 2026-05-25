<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="nc-widget-grid-picker">
		<div
			v-if="normalisedWidgets.length === 0"
			class="nc-widget-grid-picker__empty">
			{{ t('mydash', 'No Nextcloud widgets are installed') }}
		</div>
		<div
			v-else
			ref="grid"
			class="nc-widget-grid-picker__grid"
			role="radiogroup"
			:aria-label="t('mydash', 'Pick a widget')">
			<button
				v-for="(widget, idx) in normalisedWidgets"
				:key="widget.id"
				ref="cards"
				type="button"
				role="radio"
				:aria-checked="value === widget.id ? 'true' : 'false'"
				:aria-label="widget.title"
				:tabindex="cardTabIndex(widget.id, idx)"
				class="nc-widget-grid-picker__card"
				:class="{ 'nc-widget-grid-picker__card--selected': value === widget.id }"
				@click="select(widget.id)"
				@keydown="onKeydown($event, idx)"
				@focus="focusedIndex = idx">
				<span class="nc-widget-grid-picker__icon-wrap">
					<img
						v-if="widget.iconUrl"
						class="nc-widget-grid-picker__icon"
						:src="widget.iconUrl"
						:alt="''"
						aria-hidden="true">
					<span
						v-else
						class="nc-widget-grid-picker__icon nc-widget-grid-picker__icon--placeholder"
						aria-hidden="true">
						{{ initialsFor(widget.title) }}
					</span>
					<span
						v-if="value === widget.id"
						class="nc-widget-grid-picker__check"
						:aria-label="t('mydash', 'Selected')">
						<CheckIcon :size="16" />
					</span>
				</span>
				<span class="nc-widget-grid-picker__title">{{ widget.title }}</span>
			</button>
		</div>
	</div>
</template>

<script>
import CheckIcon from 'vue-material-design-icons/Check.vue'

/**
 * NcWidgetGridPicker renders the Nextcloud-discovered widget catalog as a
 * responsive CSS-grid of icon cards (REQ-WDG-018 picker UX). Each card carries
 * the widget's icon, single-line ellipsised title, and shows a selected-state
 * border + check overlay when active.
 *
 * v-model exposes the widget id (string) — same shape the previous `<select>`
 * used so the parent form's `update:content` payload is unchanged.
 *
 * Keyboard:
 *  - Arrow keys move focus across the grid (tabindex rotation: focused card
 *    has tabindex="0", others "-1")
 *  - Enter / Space on a focused card selects it
 *  - Tab moves focus out of the grid (no card-to-card Tab)
 */
export default {
	name: 'NcWidgetGridPicker',

	components: { CheckIcon },

	model: {
		prop: 'value',
		event: 'input',
	},

	props: {
		value: {
			type: String,
			default: '',
		},
		widgets: {
			type: [Array, Object],
			default: () => [],
		},
	},

	emits: ['input'],

	data() {
		return {
			focusedIndex: 0,
		}
	},

	computed: {
		/** @spec openspec/specs/nc-dashboard-widget-proxy/spec.md */
		normalisedWidgets() {
			// PHP can serialise sequential arrays as objects; mirror the
			// defensive normalisation used by NcDashboardForm.
			const list = Array.isArray(this.widgets)
				? this.widgets
				: (this.widgets && typeof this.widgets === 'object'
					? Object.values(this.widgets)
					: [])
			return list
				.filter((w) => w && typeof w.id === 'string' && w.id !== '')
				.map((w) => ({
					id: w.id,
					title: w.title || w.id,
					iconUrl: typeof w.iconUrl === 'string' && w.iconUrl !== '' ? w.iconUrl : '',
				}))
		},
		/** @spec openspec/specs/nc-dashboard-widget-proxy/spec.md */
		selectedIndex() {
			return this.normalisedWidgets.findIndex((w) => w.id === this.value)
		},
	},

	methods: {
		/** @spec openspec/specs/nc-dashboard-widget-proxy/spec.md */
		cardTabIndex(id, idx) {
			// Selected card always wins for tabindex=0; otherwise the first
			// card is the entry point until the user focuses one.
			if (this.selectedIndex >= 0) {
				return this.selectedIndex === idx ? 0 : -1
			}
			return idx === 0 ? 0 : -1
		},

		/** @spec openspec/specs/nc-dashboard-widget-proxy/spec.md */
		select(id) {
			this.$emit('input', id)
		},

		/** @spec openspec/specs/nc-dashboard-widget-proxy/spec.md */
		initialsFor(title) {
			if (typeof title !== 'string' || title.length === 0) {
				return '?'
			}
			const parts = title.split(/\s+/).filter(Boolean)
			if (parts.length === 1) {
				return parts[0].slice(0, 2).toUpperCase()
			}
			return (parts[0][0] + parts[1][0]).toUpperCase()
		},

		/** @spec openspec/specs/nc-dashboard-widget-proxy/spec.md */
		onKeydown(event, idx) {
			const total = this.normalisedWidgets.length
			if (total === 0) {
				return
			}

			const key = event.key
			if (key === 'Enter' || key === ' ' || key === 'Spacebar') {
				event.preventDefault()
				this.select(this.normalisedWidgets[idx].id)
				return
			}

			let nextIdx = -1
			if (key === 'ArrowRight' || key === 'ArrowDown') {
				nextIdx = (idx + 1) % total
			} else if (key === 'ArrowLeft' || key === 'ArrowUp') {
				nextIdx = (idx - 1 + total) % total
			} else if (key === 'Home') {
				nextIdx = 0
			} else if (key === 'End') {
				nextIdx = total - 1
			}

			if (nextIdx === -1) {
				return
			}
			event.preventDefault()
			this.focusedIndex = nextIdx
			this.$nextTick(() => {
				const cards = this.$refs.cards
				if (Array.isArray(cards) && cards[nextIdx]) {
					cards[nextIdx].focus()
				}
			})
		},
	},
}
</script>

<style scoped>
.nc-widget-grid-picker__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 12px;
}

.nc-widget-grid-picker__card {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 12px 8px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large, var(--border-radius));
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	min-height: 96px;
	transition: border-color 0.12s ease-in-out, background-color 0.12s ease-in-out;
}

.nc-widget-grid-picker__card:hover {
	background: var(--color-background-hover);
}

.nc-widget-grid-picker__card:focus {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.nc-widget-grid-picker__card--selected {
	border-color: var(--color-primary-element);
}

.nc-widget-grid-picker__icon-wrap {
	position: relative;
	width: 40px;
	height: 40px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.nc-widget-grid-picker__icon {
	width: 40px;
	height: 40px;
	object-fit: contain;
	display: block;
}

.nc-widget-grid-picker__icon--placeholder {
	border-radius: 50%;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 600;
	display: flex;
	align-items: center;
	justify-content: center;
}

.nc-widget-grid-picker__check {
	position: absolute;
	bottom: -4px;
	right: -4px;
	width: 20px;
	height: 20px;
	border-radius: 50%;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 0 0 2px var(--color-main-background);
}

.nc-widget-grid-picker__title {
	width: 100%;
	font-size: 13px;
	text-align: center;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.nc-widget-grid-picker__empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
