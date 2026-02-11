<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="widget-renderer">
		<!-- Custom Tile Widget -->
		<TileWidget
			v-if="isTileWidget && tileData"
			:tile="tileData" />

		<!-- API Widget V1 or V2 - Use NcDashboardWidget -->
		<template v-else-if="isApiWidget">
			<NcDashboardWidget
				:items="widgetItems"
				:show-more-url="widget.widgetUrl"
				:loading="loading || itemsLoading"
				:item-menu="false"
				:round-icons="widget.itemIconsRound">
				<template #empty-content>
					<NcEmptyContent
						v-if="emptyContentMessage"
						:description="emptyContentMessage">
						<template #icon>
							<span :class="widget.iconClass" />
						</template>
					</NcEmptyContent>
				</template>
			</NcDashboardWidget>
		</template>

		<!-- Legacy Widget - Mount via callback -->
		<div v-else-if="!loading" ref="legacyWidgetContainer" class="widget-renderer__legacy" />

		<!-- Loading state for unknown widget types -->
		<div v-else-if="loading" class="widget-renderer__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<!-- Unknown widget type -->
		<NcEmptyContent
			v-else
			:description="t('mydash', 'Widget not available')">
			<template #icon>
				<AlertCircleOutline :size="48" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import { mapState, mapActions } from 'pinia'
import { useWidgetStore } from '../stores/widgets.js'
import { useTileStore } from '../stores/tiles.js'
import { widgetBridge } from '../services/widgetBridge.js'
import TileWidget from './TileWidget.vue'

export default {
	name: 'WidgetRenderer',

	components: {
		NcDashboardWidget,
		NcDashboardWidgetItem,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutline,
		TileWidget,
	},

	props: {
		widget: {
			type: Object,
			default: null,
		},
		placement: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			loading: false,  // Start false, will be set to true for API widgets only
			itemsLoading: false,
			refreshInterval: null,
		}
	},

	computed: {
		...mapState(useWidgetStore, ['getWidgetItems']),
		...mapState(useTileStore, ['tiles']),

		isTileWidget() {
			return this.placement.widgetId && this.placement.widgetId.startsWith('tile-')
		},

		tileId() {
			if (!this.isTileWidget) return null
			return parseInt(this.placement.widgetId.replace('tile-', ''))
		},

		tileData() {
			if (!this.isTileWidget) return null
			return this.tiles.find(t => t.id === this.tileId)
		},

		isApiWidgetV2() {
			return this.widget?.itemApiVersions?.includes(2)
		},

		isApiWidgetV1() {
			return this.widget?.itemApiVersions?.includes(1)
		},

		isApiWidget() {
			return this.isApiWidgetV1 || this.isApiWidgetV2
		},

		widgetItemsData() {
			return this.getWidgetItems(this.widget?.id)
		},

		widgetItems() {
			const items = this.widgetItemsData.items || []
			// Transform items to NcDashboardWidget format.
			return items.map(item => ({
				id: item.sinceId || item.id || String(Math.random()),
				targetUrl: item.link || item.targetUrl || '',
				avatarUrl: item.iconUrl || item.avatarUrl || '',
				avatarUsername: item.avatarUsername || '',
				overlayIconUrl: item.overlayIconUrl || '',
				mainText: item.title || item.mainText || '',
				subText: item.subtitle || item.subText || '',
			}))
		},

		emptyContentMessage() {
			return this.widgetItemsData.emptyContentMessage || ''
		},
	},

	watch: {
		widget: {
			immediate: false,  // Don't run immediately, wait for mounted
			handler(newWidget) {
				console.log('[WidgetRenderer] widget watch triggered:', newWidget?.id, newWidget)
				if (newWidget || this.isTileWidget) {
					this.initWidget()
				}
			},
		},
		placement: {
			immediate: false,  // Don't run immediately
			handler() {
				console.log('[WidgetRenderer] placement watch triggered:', this.placement)
				if (this.isTileWidget) {
					this.loading = false
				}
			},
		},
	},

	mounted() {
		// Initialize widget after component is mounted and refs are available
		console.log('[WidgetRenderer] mounted hook called')
		if (this.widget || this.isTileWidget) {
			this.initWidget()
		}
	},

	beforeDestroy() {
		if (this.refreshInterval) {
			clearInterval(this.refreshInterval)
		}
	},

	methods: {
		...mapActions(useWidgetStore, ['loadWidgetItems', 'refreshWidgetItems']),

		async initWidget() {
			console.log('[WidgetRenderer] initWidget called:', {
				widgetId: this.widget?.id,
				isTileWidget: this.isTileWidget,
				isApiWidget: this.isApiWidget,
				isApiWidgetV1: this.isApiWidgetV1,
				isApiWidgetV2: this.isApiWidgetV2,
				itemApiVersions: this.widget?.itemApiVersions,
				fullWidget: this.widget
			})

			if (!this.widget && !this.isTileWidget) {
				this.loading = false
				return
			}

			// Tiles don't need initialization.
			if (this.isTileWidget) {
				this.loading = false
				return
			}

			console.log('[WidgetRenderer] Initializing widget:', this.widget.id, this.widget)

			// Only show loading for API widgets
			// Legacy widgets render themselves, so we don't need a loading state
			const isLegacy = !this.isApiWidget
			if (!isLegacy) {
				this.loading = true
			}

			try {
				if (this.isApiWidget) {
					console.log('[WidgetRenderer] Detected as API widget')
					// Load widget items from API (supports both v1 and v2).
					await this.loadWidgetItems([this.widget.id])

					// Set up auto-refresh if widget supports it.
					if (this.widget.reloadInterval && this.widget.reloadInterval > 0) {
						this.setupAutoRefresh(this.widget.reloadInterval)
					}
				} else {
					console.log('[WidgetRenderer] Legacy widget detected:', this.widget.id)
					// Legacy widget - mount via callback.
					// Wait for DOM to be ready
					await this.$nextTick()
					// Give it a bit more time for the ref to be available
					await new Promise(resolve => setTimeout(resolve, 50))
					this.mountLegacyWidget()
				}
			} catch (error) {
				console.error('Failed to initialize widget:', error)
			} finally {
				if (!isLegacy) {
					this.loading = false
				}
			}
		},

		mountLegacyWidget() {
			if (!this.$refs.legacyWidgetContainer) {
				console.error('[WidgetRenderer] No legacyWidgetContainer ref found!')
				return
			}

			console.log('[WidgetRenderer] Mounting legacy widget:', this.widget.id, 'Container:', this.$refs.legacyWidgetContainer)

			// Widget scripts are loaded with defer, so we need to wait for them
			// to register their callbacks. Try multiple times with increasing delays.
			const tryMount = (attempt = 0, maxAttempts = 20) => {
				console.log(`[WidgetRenderer] Mount attempt ${attempt + 1}/${maxAttempts} for:`, this.widget.id)
				
				// Check if callback is registered
				if (widgetBridge.hasWidgetCallback(this.widget.id)) {
					console.log('[WidgetRenderer] Callback found! Mounting:', this.widget.id)
					// Pass widget data to the bridge so callbacks can access it
					widgetBridge.mountWidget(this.widget.id, this.$refs.legacyWidgetContainer, this.widget)
					console.log('[WidgetRenderer] After mountWidget, container innerHTML length:', this.$refs.legacyWidgetContainer?.innerHTML.length)
				} else if (attempt < maxAttempts) {
					// Try again after a short delay
					const delay = Math.min(100 * (attempt + 1), 1000) // Exponential backoff up to 1s
					console.log(`[WidgetRenderer] Callback not found yet, retrying in ${delay}ms...`)
					setTimeout(() => tryMount(attempt + 1, maxAttempts), delay)
				} else {
					console.error('[WidgetRenderer] Failed to mount widget after', maxAttempts, 'attempts:', this.widget.id)
					console.log('[WidgetRenderer] Available callbacks:', widgetBridge.getRegisteredWidgetIds())
				}
			}

			// Start trying immediately
			this.$nextTick(() => {
				tryMount()
			})
		},

		setupAutoRefresh(intervalSeconds) {
			if (this.refreshInterval) {
				clearInterval(this.refreshInterval)
			}

			this.refreshInterval = setInterval(() => {
				this.itemsLoading = true
				this.refreshWidgetItems(this.widget.id).finally(() => {
					this.itemsLoading = false
				})
			}, intervalSeconds * 1000)
		},
	},
}
</script>

<style scoped>
.widget-renderer {
	height: 100%;
	padding: 16px;
}

.widget-renderer__loading {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.widget-renderer__legacy {
	height: 100%;
}
</style>
