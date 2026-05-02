<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="nc-dashboard-widget">
		<header class="nc-dashboard-widget__header">
			<img
				v-if="widgetIconUrl"
				class="nc-dashboard-widget__header-icon"
				:src="widgetIconUrl"
				alt="">
			<span class="nc-dashboard-widget__header-title">{{ widgetTitle }}</span>
		</header>

		<!--
			Native callback container — always rendered, but kept hidden until
			we actually mount via the bridge. This avoids any flicker when the
			poll wins after the API list has already painted: we mount into
			this container, flip the visibility flag, and the API list is
			hidden in the same render tick.
		-->
		<div
			v-show="mode === 'native'"
			ref="nativeContainer"
			class="nc-dashboard-widget__native" />

		<div
			v-if="mode !== 'native'"
			class="nc-dashboard-widget__body"
			:class="bodyClass">
			<div v-if="loading" class="nc-dashboard-widget__loading">
				{{ t('mydash', 'Loading…') }}
			</div>

			<div v-else-if="items.length === 0" class="nc-dashboard-widget__empty">
				{{ t('mydash', 'No items available') }}
			</div>

			<template v-else>
				<a
					v-for="(item, idx) in items"
					:key="item.sinceId || idx"
					class="nc-dashboard-widget__item"
					:class="itemClass"
					:href="item.link || '#'"
					:title="item.title">
					<span class="nc-dashboard-widget__item-icon-wrap">
						<img
							v-if="item.iconUrl"
							class="nc-dashboard-widget__item-icon"
							:src="item.iconUrl"
							alt="">
						<img
							v-if="item.overlayIconUrl"
							class="nc-dashboard-widget__item-overlay"
							:src="item.overlayIconUrl"
							alt="">
					</span>
					<span class="nc-dashboard-widget__item-text">
						<span class="nc-dashboard-widget__item-title">{{ item.title }}</span>
						<span v-if="item.subtitle" class="nc-dashboard-widget__item-subtitle">{{ item.subtitle }}</span>
					</span>
				</a>
			</template>
		</div>
	</div>
</template>

<script>
import { widgetBridge } from '../../../services/widgetBridge.js'
import { api } from '../../../services/api.js'

/**
 * PHP can serialise empty arrays as objects and sequential numeric arrays
 * as objects with string keys; normalise to a JS array (tasks.md §2).
 *
 * @param {Array|object|null|undefined} input the catalog blob from initial state
 * @return {Array} the normalised array
 */
export function normaliseWidgetCatalog(input) {
	if (Array.isArray(input)) {
		return input
	}
	if (input && typeof input === 'object') {
		return Object.values(input)
	}
	return []
}

/**
 * Pull the items array out of the per-widget envelope, tolerating both the
 * flat `WidgetItem[]` shape and the wrapped `{items, ...}` shape.
 *
 * @param {*} widgetData the per-widget API payload
 * @return {Array} the items array (possibly empty)
 */
export function extractItems(widgetData) {
	if (!widgetData) {
		return []
	}
	if (Array.isArray(widgetData)) {
		return widgetData
	}
	if (Array.isArray(widgetData.items)) {
		return widgetData.items
	}
	if (widgetData.items && typeof widgetData.items === 'object') {
		return Object.values(widgetData.items)
	}
	return []
}

/**
 * NcDashboardWidget renders any Nextcloud Dashboard widget inside a MyDash
 * grid cell (REQ-WDG-018, REQ-WDG-019, REQ-WDG-020, REQ-WDG-021).
 *
 * Two-mode rendering:
 *  - **native** — the widget bundle has registered a callback via
 *    `OCA.Dashboard.register` (legacy-widget-bridge REQ-LWB-002). We hand
 *    the render container to the bridge and let the widget paint itself.
 *  - **api** — fall back to `GET /api/widgets/items?widgets[]={id}&limit=7`
 *    and render a flat list of `{title, subtitle, link, iconUrl}` cards.
 *
 * On mount we call `widgetBridge.hasWidgetCallback(widgetId)` first. If the
 * callback is already registered we mount natively immediately and skip the
 * API call entirely. Otherwise we kick off the API fetch AND start a 200 ms
 * × 15 retries poll for the callback. If the poll wins we switch to native
 * mode (the v-show flips and the API list is hidden in the same tick — no
 * flicker). If the poll exhausts, the API list remains as the final state.
 *
 * The widget's own `widgets` initial-state list (REQ-WDG-001 / REQ-INIT-002)
 * is injected as `widgetsCatalog` and consulted to look up the title +
 * iconUrl shown in the header.
 */
export default {
	name: 'NcDashboardWidget',

	inject: {
		widgetsCatalog: {
			from: 'widgets',
			default: () => [],
		},
	},

	props: {
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			/** @type {'pending'|'native'|'api'} mounting mode */
			mode: 'pending',
			loading: false,
			items: [],
			abortController: null,
		}
	},

	computed: {
		widgetId() {
			return typeof this.content?.widgetId === 'string' ? this.content.widgetId : ''
		},

		displayMode() {
			return this.content?.displayMode === 'horizontal' ? 'horizontal' : 'vertical'
		},

		widgetMeta() {
			// REQ-WDG-020: header title + iconUrl come from IManager::getWidgets()
			// metadata (the `widgets` initial-state list, REQ-INIT-002).
			const list = normaliseWidgetCatalog(this.widgetsCatalog)
			return list.find((w) => w && w.id === this.widgetId) || null
		},

		widgetTitle() {
			return this.widgetMeta?.title || this.widgetId || t('mydash', 'Widget')
		},

		widgetIconUrl() {
			return this.widgetMeta?.iconUrl || ''
		},

		bodyClass() {
			return `nc-dashboard-widget__body--${this.displayMode}`
		},

		itemClass() {
			return `nc-dashboard-widget__item--${this.displayMode}`
		},
	},

	mounted() {
		this.tryMount()
	},

	beforeDestroy() {
		this.cancelPoll()
	},

	methods: {
		/**
		 * Attempt to mount the widget. First synchronous check is for an
		 * already-registered callback (REQ-WDG-019 native fast-path). When
		 * absent we kick off API loading AND a polling watcher so a late
		 * bundle load can still upgrade us to native mode.
		 *
		 * @return {Promise<void>} resolves when the initial mount step is wired up
		 */
		async tryMount() {
			if (!this.widgetId) {
				this.mode = 'api'
				this.items = []
				return
			}

			if (widgetBridge.hasWidgetCallback(this.widgetId)) {
				this.mountNative()
				return
			}

			this.mode = 'api'
			this.loading = true
			this.startPoll()
			this.loadApiItems()
		},

		/**
		 * Mount the widget natively via the legacy bridge.
		 */
		mountNative() {
			this.mode = 'native'
			// Wait one tick so the v-show flip has updated the DOM and the
			// native container exists in the layout.
			this.$nextTick(() => {
				const container = this.$refs.nativeContainer
				if (container) {
					widgetBridge.mountWidget(this.widgetId, container, this.widgetMeta || {})
				}
			})
		},

		/**
		 * Start the 200 ms × 15 retries poll for callback registration
		 * (REQ-LWB-005). When it resolves true we switch to native mode and
		 * abandon any in-flight or completed API render (REQ-WDG-019).
		 */
		startPoll() {
			this.cancelPoll()
			this.abortController = new AbortController()
			widgetBridge
				.pollForCallback(this.widgetId, { signal: this.abortController.signal })
				.then((registered) => {
					if (registered && this.mode !== 'native') {
						this.mountNative()
					}
				})
		},

		cancelPoll() {
			if (this.abortController) {
				this.abortController.abort()
				this.abortController = null
			}
		},

		/**
		 * Fetch the API fallback list. Defensive: malformed responses
		 * collapse to an empty list and surface the empty-state message
		 * (REQ-WDG-021) instead of throwing.
		 *
		 * @return {Promise<void>} resolves when items are loaded or the request fails
		 */
		async loadApiItems() {
			try {
				const response = await api.getWidgetItems([this.widgetId])
				const payload = response?.data
				let widgetData = null
				if (payload && typeof payload === 'object') {
					if (payload.items && typeof payload.items === 'object') {
						widgetData = payload.items[this.widgetId] || null
					} else if (payload[this.widgetId]) {
						widgetData = payload[this.widgetId]
					}
				}

				if (this.mode === 'native') {
					// Poll already won and switched us — drop the result.
					return
				}

				this.items = extractItems(widgetData)
			} catch (e) {
				if (this.mode !== 'native') {
					this.items = []
				}
			} finally {
				this.loading = false
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
	overflow: hidden;
}

.nc-dashboard-widget__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	font-weight: bold;
}

.nc-dashboard-widget__header-icon {
	width: 20px;
	height: 20px;
	flex: 0 0 auto;
}

.nc-dashboard-widget__header-title {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.nc-dashboard-widget__native {
	flex: 1;
	overflow: auto;
}

.nc-dashboard-widget__body {
	flex: 1;
	overflow: auto;
	padding: 8px;
}

.nc-dashboard-widget__body--vertical {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.nc-dashboard-widget__body--horizontal {
	display: flex;
	flex-direction: row;
	flex-wrap: wrap;
	gap: 12px;
}

.nc-dashboard-widget__loading,
.nc-dashboard-widget__empty {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 1;
	min-height: 64px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.nc-dashboard-widget__item {
	display: flex;
	text-decoration: none;
	color: var(--color-main-text);
	border-radius: var(--border-radius);
	padding: 4px;
}

.nc-dashboard-widget__item:hover {
	background-color: var(--color-background-hover);
}

.nc-dashboard-widget__item--vertical {
	flex-direction: row;
	align-items: center;
	gap: 8px;
}

.nc-dashboard-widget__item--vertical .nc-dashboard-widget__item-icon-wrap {
	width: 32px;
	height: 32px;
	flex: 0 0 32px;
	position: relative;
}

.nc-dashboard-widget__item--vertical .nc-dashboard-widget__item-icon {
	width: 32px;
	height: 32px;
	object-fit: cover;
	border-radius: 50%;
}

.nc-dashboard-widget__item--horizontal {
	flex-direction: column;
	align-items: center;
	width: 120px;
	text-align: center;
}

.nc-dashboard-widget__item--horizontal .nc-dashboard-widget__item-icon-wrap {
	width: 44px;
	height: 44px;
	flex: 0 0 44px;
	position: relative;
}

.nc-dashboard-widget__item--horizontal .nc-dashboard-widget__item-icon {
	width: 44px;
	height: 44px;
	object-fit: cover;
	border-radius: 50%;
}

.nc-dashboard-widget__item-overlay {
	position: absolute;
	right: -2px;
	bottom: -2px;
	width: 14px;
	height: 14px;
}

.nc-dashboard-widget__item-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1;
}

.nc-dashboard-widget__item-title {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.nc-dashboard-widget__item-subtitle {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
