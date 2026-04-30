<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="nc-dashboard-widget">
		<!-- Header: title + icon from widgetMeta -->
		<div
			v-if="widgetMeta"
			class="nc-dashboard-widget__header">
			<img
				v-if="widgetMeta.iconUrl"
				:src="widgetMeta.iconUrl"
				:alt="widgetMeta.title || ''"
				class="nc-dashboard-widget__header-icon">
			<span class="nc-dashboard-widget__header-title">{{ widgetMeta.title || actualWidgetId }}</span>
		</div>

		<!-- Native callback container (hidden until usesRegisteredCallback = true) -->
		<div
			v-show="usesRegisteredCallback"
			ref="appContainer"
			class="nc-dashboard-widget__native" />

		<!-- API fallback area -->
		<template v-if="!usesRegisteredCallback">
			<!-- Loading state -->
			<div
				v-if="loading"
				class="nc-dashboard-widget__loading">
				{{ tt('Loading…') }}
			</div>

			<!-- Empty state -->
			<div
				v-else-if="!items || items.length === 0"
				class="nc-dashboard-widget__empty">
				{{ tt('No items available') }}
			</div>

			<!-- Vertical list -->
			<div
				v-else-if="resolvedDisplayMode === 'vertical'"
				class="nc-dashboard-widget__list nc-dashboard-widget__list--vertical">
				<a
					v-for="(item, idx) in items"
					:key="idx"
					:href="item.link || '#'"
					class="nc-dashboard-widget__item nc-dashboard-widget__item--vertical"
					target="_blank"
					rel="noopener noreferrer">
					<img
						v-if="item.iconUrl"
						:src="item.iconUrl"
						:alt="item.title || ''"
						class="nc-dashboard-widget__item-icon nc-dashboard-widget__item-icon--vertical">
					<div class="nc-dashboard-widget__item-text">
						<span class="nc-dashboard-widget__item-title">{{ item.title }}</span>
						<span
							v-if="item.subtitle"
							class="nc-dashboard-widget__item-subtitle">{{ item.subtitle }}</span>
					</div>
				</a>
			</div>

			<!-- Horizontal cards -->
			<div
				v-else
				class="nc-dashboard-widget__list nc-dashboard-widget__list--horizontal">
				<a
					v-for="(item, idx) in items"
					:key="idx"
					:href="item.link || '#'"
					class="nc-dashboard-widget__item nc-dashboard-widget__item--horizontal"
					target="_blank"
					rel="noopener noreferrer">
					<img
						v-if="item.iconUrl"
						:src="item.iconUrl"
						:alt="item.title || ''"
						class="nc-dashboard-widget__item-icon nc-dashboard-widget__item-icon--horizontal">
					<span class="nc-dashboard-widget__item-title nc-dashboard-widget__item-title--horizontal">{{ item.title }}</span>
					<span
						v-if="item.subtitle"
						class="nc-dashboard-widget__item-subtitle nc-dashboard-widget__item-subtitle--horizontal">{{ item.subtitle }}</span>
				</a>
			</div>
		</template>
	</div>
</template>

<script>
/**
 * NcDashboardWidget
 *
 * Renders any Nextcloud Dashboard widget inside a MyDash grid cell. Supports
 * two modes:
 *
 *   1. Native callback (preferred) — uses widgetBridge.mountWidget when the
 *      widget bundle has registered via OCA.Dashboard.register.
 *   2. API list fallback — issues GET /ocs/v2.php/apps/mydash/api/widgets/items
 *      and renders items in vertical-list or horizontal-card layout.
 *
 * On mount a race is started: the API request fires immediately while
 * pollForCallback checks every 200 ms (up to 15 retries, ~3 s). Whichever
 * wins first becomes the final render; the other is suppressed (no flicker).
 *
 * @spec openspec/changes/nc-dashboard-widget-proxy/specs/widgets/spec.md#req-wdg-018
 * @spec openspec/changes/nc-dashboard-widget-proxy/specs/widgets/spec.md#req-wdg-019
 * @spec openspec/changes/nc-dashboard-widget-proxy/specs/widgets/spec.md#req-wdg-020
 * @spec openspec/changes/nc-dashboard-widget-proxy/specs/widgets/spec.md#req-wdg-021
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { widgetBridge } from '../../../services/widgetBridge.js'

export default {
	name: 'NcDashboardWidget',

	props: {
		/**
		 * Full persisted widget object (includes `content` sub-object).
		 */
		widget: {
			type: Object,
			required: true,
		},

		/**
		 * Widget ID override — falls back to widget.content.widgetId.
		 */
		widgetId: {
			type: String,
			default: '',
		},

		/**
		 * Display mode for the API fallback list.
		 */
		displayMode: {
			type: String,
			default: 'vertical',
			validator(value) {
				return ['vertical', 'horizontal'].includes(value)
			},
		},
	},

	data() {
		return {
			/** True once the native callback path took over */
			usesRegisteredCallback: false,
			/** True while the API request is in flight */
			loading: false,
			/** Items from the API fallback response */
			items: [],
			/** AbortController for cancelling the poll + request on destroy */
			abortController: null,
			/** Whether the race is already decided (prevents dual-write) */
			raceDecided: false,
		}
	},

	computed: {
		actualWidgetId() {
			return this.widgetId || (this.widget && this.widget.content && this.widget.content.widgetId) || ''
		},

		resolvedDisplayMode() {
			const fromContent = this.widget && this.widget.content && this.widget.content.displayMode
			const mode = this.displayMode !== 'vertical' ? this.displayMode : (fromContent || this.displayMode)
			return ['vertical', 'horizontal'].includes(mode) ? mode : 'vertical'
		},

		/**
		 * All available widgets from initial state (injected or window global).
		 * PHP may serialise a sequential array as a JSON object with numeric keys —
		 * normalise both shapes here.
		 */
		availableWidgets() {
			const injected = (window.__initialState && window.__initialState.widgets)
				|| (window.OCA && window.OCA.MyDash && window.OCA.MyDash.initialState && window.OCA.MyDash.initialState.widgets)
				|| []
			return Array.isArray(injected) ? injected : Object.values(injected)
		},

		/**
		 * Widget metadata (title, iconUrl) for the header, looked up by id.
		 */
		widgetMeta() {
			if (!this.actualWidgetId) {
				return null
			}
			return this.availableWidgets.find((w) => w.id === this.actualWidgetId) || null
		},
	},

	mounted() {
		const id = this.actualWidgetId
		if (!id) {
			return
		}

		// Native fast-path: callback already registered
		if (widgetBridge.hasWidgetCallback(id)) {
			this.usesRegisteredCallback = true
			this.$nextTick(() => {
				widgetBridge.mountWidget(id, this.$refs.appContainer, { widget: this.widget })
			})
			return
		}

		// Race: API fallback + poll for late-arriving native callback
		this.abortController = new AbortController()
		const signal = this.abortController.signal

		// 1. Start API request
		this.loading = true
		this._fetchItems(id, signal)

		// 2. Start poll; if it wins, switch to native mode
		widgetBridge.pollForCallback(id, { signal }).then((found) => {
			if (!found || signal.aborted) {
				return
			}
			if (this.raceDecided) {
				// Poll won — switch even if API completed already
			}
			this.raceDecided = true
			this.usesRegisteredCallback = true
			this.$nextTick(() => {
				widgetBridge.mountWidget(id, this.$refs.appContainer, { widget: this.widget })
			})
		})
	},

	beforeDestroy() {
		if (this.abortController) {
			this.abortController.abort()
		}
	},

	methods: {
		tt(key) {
			if (typeof t === 'function') {
				return t('mydash', key)
			}
			return key
		},

		async _fetchItems(widgetId, signal) {
			try {
				const url = generateOcsUrl('/apps/mydash/api/widgets/items')
				const response = await axios.get(url, {
					params: { 'widgets[]': widgetId, limit: 7 },
					signal,
				})

				if (signal.aborted) {
					return
				}

				// If poll already won the race, discard API result
				if (this.usesRegisteredCallback) {
					return
				}

				const data = response.data
				const itemsMap = (data && data.items) || {}
				const raw = itemsMap[widgetId]
				this.items = Array.isArray(raw) ? raw : Object.values(raw || {})
				this.raceDecided = true
			} catch (err) {
				if (signal && signal.aborted) {
					return
				}
				console.error('[NcDashboardWidget] Failed to fetch items for', widgetId, err)
				this.items = []
			} finally {
				if (!signal || !signal.aborted) {
					this.loading = false
				}
			}
		},
	},
}
</script>

<style scoped>
.nc-dashboard-widget {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	box-sizing: border-box;
	overflow: hidden;
}

/* Header */
.nc-dashboard-widget__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 8px 4px;
	flex-shrink: 0;
}

.nc-dashboard-widget__header-icon {
	width: 20px;
	height: 20px;
	object-fit: contain;
	flex-shrink: 0;
}

.nc-dashboard-widget__header-title {
	font-weight: 600;
	font-size: 14px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--color-main-text);
}

/* Native container */
.nc-dashboard-widget__native {
	flex: 1;
	overflow: hidden;
}

/* Loading & empty states */
.nc-dashboard-widget__loading,
.nc-dashboard-widget__empty {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 1;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	text-align: center;
	padding: 12px;
}

/* Shared list wrapper */
.nc-dashboard-widget__list {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
}

/* Vertical list */
.nc-dashboard-widget__list--vertical {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px 8px;
}

.nc-dashboard-widget__item--vertical {
	display: flex;
	align-items: center;
	gap: 8px;
	text-decoration: none;
	color: var(--color-main-text);
	overflow: hidden;
}

.nc-dashboard-widget__item--vertical:hover {
	background-color: var(--color-background-hover);
	border-radius: 4px;
}

.nc-dashboard-widget__item-icon--vertical {
	width: 32px;
	height: 32px;
	flex-shrink: 0;
	object-fit: contain;
}

.nc-dashboard-widget__item-text {
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.nc-dashboard-widget__item-title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 13px;
}

.nc-dashboard-widget__item-subtitle {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

/* Horizontal cards */
.nc-dashboard-widget__list--horizontal {
	display: flex;
	flex-direction: row;
	flex-wrap: wrap;
	gap: 12px;
	padding: 8px;
	align-content: flex-start;
}

.nc-dashboard-widget__item--horizontal {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 120px;
	text-decoration: none;
	color: var(--color-main-text);
	padding: 8px 4px;
	border-radius: 4px;
	box-sizing: border-box;
	overflow: hidden;
}

.nc-dashboard-widget__item--horizontal:hover {
	background-color: var(--color-background-hover);
}

.nc-dashboard-widget__item-icon--horizontal {
	width: 44px;
	height: 44px;
	flex-shrink: 0;
	object-fit: contain;
	margin-bottom: 4px;
}

.nc-dashboard-widget__item-title--horizontal {
	font-size: 12px;
	text-align: center;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	width: 100%;
}

.nc-dashboard-widget__item-subtitle--horizontal {
	font-size: 11px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	width: 100%;
}
</style>
