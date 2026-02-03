<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="widget-renderer">
		<!-- Loading state -->
		<div v-if="loading" class="widget-renderer__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<!-- API Widget V1 or V2 - Use NcDashboardWidget -->
		<template v-else-if="isApiWidget">
			<NcDashboardWidget
				:items="widgetItems"
				:show-more-url="widget.widgetUrl"
				:loading="itemsLoading"
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
		<div v-else ref="legacyWidgetContainer" class="widget-renderer__legacy" />

		<!-- Unknown widget type -->
		<NcEmptyContent
			v-if="!loading && !widget"
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
import { widgetBridge } from '../services/widgetBridge.js'

export default {
	name: 'WidgetRenderer',

	components: {
		NcDashboardWidget,
		NcDashboardWidgetItem,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutline,
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
			loading: true,
			itemsLoading: false,
			refreshInterval: null,
		}
	},

	computed: {
		...mapState(useWidgetStore, ['getWidgetItems']),

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
			immediate: true,
			handler(newWidget) {
				if (newWidget) {
					this.initWidget()
				}
			},
		},
	},

	beforeDestroy() {
		if (this.refreshInterval) {
			clearInterval(this.refreshInterval)
		}
	},

	methods: {
		...mapActions(useWidgetStore, ['loadWidgetItems', 'refreshWidgetItems']),

		async initWidget() {
			if (!this.widget) {
				this.loading = false
				return
			}

			this.loading = true

			try {
				if (this.isApiWidget) {
					// Load widget items from API (supports both v1 and v2).
					await this.loadWidgetItems([this.widget.id])

					// Set up auto-refresh if widget supports it.
					if (this.widget.reloadInterval && this.widget.reloadInterval > 0) {
						this.setupAutoRefresh(this.widget.reloadInterval)
					}
				} else {
					// Legacy widget - mount via callback.
					await this.$nextTick()
					this.mountLegacyWidget()
				}
			} catch (error) {
				console.error('Failed to initialize widget:', error)
			} finally {
				this.loading = false
			}
		},

		mountLegacyWidget() {
			if (!this.$refs.legacyWidgetContainer) return

			// Call the widget's load method to inject scripts
			if (typeof this.widget.load === 'function') {
				this.widget.load()
			}

			// Try to mount via the bridge
			widgetBridge.mountWidget(this.widget.id, this.$refs.legacyWidgetContainer)
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
