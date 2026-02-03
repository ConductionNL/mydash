<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="mydash-widget" :style="widgetStyles">
		<!-- Widget header -->
		<div v-if="showHeader" class="mydash-widget__header" :style="headerStyles">
			<div class="mydash-widget__header-left">
				<img
					v-if="widgetIconUrl"
					:src="widgetIconUrl"
					:alt="widgetTitle"
					class="mydash-widget__icon">
				<span v-else-if="widget?.iconClass" :class="widget.iconClass" class="mydash-widget__icon" />
				<h3 class="mydash-widget__title">
					{{ widgetTitle }}
				</h3>
			</div>
			<div v-if="editMode" class="mydash-widget__actions">
				<NcButton type="tertiary" @click="$emit('style', placement)">
					<template #icon>
						<Palette :size="20" />
					</template>
				</NcButton>
				<NcButton
					v-if="canRemove"
					type="tertiary"
					@click="$emit('remove')">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<!-- Widget content -->
		<div class="mydash-widget__content">
			<WidgetRenderer
				:widget="widget"
				:placement="placement" />
		</div>

		<!-- Widget footer with buttons -->
		<div v-if="widgetButtons.length > 0" class="mydash-widget__footer">
			<NcButton
				v-for="button in widgetButtons"
				:key="button.link"
				type="tertiary"
				:href="button.link">
				{{ button.text }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Palette from 'vue-material-design-icons/Palette.vue'
import Close from 'vue-material-design-icons/Close.vue'
import WidgetRenderer from './WidgetRenderer.vue'

export default {
	name: 'WidgetWrapper',

	components: {
		NcButton,
		Palette,
		Close,
		WidgetRenderer,
	},

	props: {
		placement: {
			type: Object,
			required: true,
		},
		widget: {
			type: Object,
			default: null,
		},
		editMode: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['remove', 'style'],

	computed: {
		showHeader() {
			return this.placement.showTitle !== false
		},

		widgetTitle() {
			return this.placement.customTitle || this.widget?.title || this.t('mydash', 'Widget')
		},

		widgetIconUrl() {
			return this.widget?.iconUrl || null
		},

		widgetButtons() {
			return this.widget?.buttons || []
		},

		canRemove() {
			// Can't remove compulsory widgets unless full permission
			return !this.placement.isCompulsory
		},

		styleConfig() {
			return this.placement.styleConfig || {}
		},

		widgetStyles() {
			const styles = {}

			if (this.styleConfig.backgroundColor) {
				styles.backgroundColor = this.styleConfig.backgroundColor
				if (this.styleConfig.backgroundOpacity !== undefined) {
					const opacity = this.styleConfig.backgroundOpacity
					// Convert hex to rgba with opacity
					styles.backgroundColor = this.hexToRgba(
						this.styleConfig.backgroundColor,
						opacity,
					)
				}
			}

			if (this.styleConfig.borderStyle && this.styleConfig.borderStyle !== 'none') {
				styles.border = `${this.styleConfig.borderWidth || 1}px ${this.styleConfig.borderStyle} ${this.styleConfig.borderColor || 'var(--color-border)'}`
			}

			if (this.styleConfig.borderRadius !== undefined) {
				styles.borderRadius = `${this.styleConfig.borderRadius}px`
			}

			if (this.styleConfig.padding) {
				const p = this.styleConfig.padding
				styles.padding = `${p.top || 0}px ${p.right || 0}px ${p.bottom || 0}px ${p.left || 0}px`
			}

			return styles
		},

		headerStyles() {
			const styles = {}

			if (this.styleConfig.headerStyle) {
				if (this.styleConfig.headerStyle.backgroundColor) {
					styles.backgroundColor = this.styleConfig.headerStyle.backgroundColor
				}
				if (this.styleConfig.headerStyle.textColor) {
					styles.color = this.styleConfig.headerStyle.textColor
				}
			}

			return styles
		},
	},

	methods: {
		hexToRgba(hex, opacity) {
			if (!hex) return 'transparent'
			const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex)
			if (!result) return hex
			return `rgba(${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}, ${opacity})`
		},
	},
}
</script>

<style scoped>
.mydash-widget {
	height: 100%;
	display: flex;
	flex-direction: column;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.mydash-widget__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.mydash-widget__header-left {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.mydash-widget__icon {
	width: 24px;
	height: 24px;
	flex-shrink: 0;
}

.mydash-widget__title {
	font-weight: 600;
	font-size: 14px;
	margin: 0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.mydash-widget__content {
	flex: 1;
	overflow: auto;
	min-height: 0;
}

.mydash-widget__actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.mydash-widget__footer {
	display: flex;
	justify-content: flex-end;
	padding: 8px 16px;
	border-top: 1px solid var(--color-border);
	flex-shrink: 0;
}
</style>
