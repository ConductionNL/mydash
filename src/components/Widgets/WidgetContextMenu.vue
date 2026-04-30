<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<transition name="fade">
		<div
			v-if="show"
			class="widget-context-menu"
			:style="{
				top: clampedY + 'px',
				left: clampedX + 'px',
			}"
			@click.stop>
			<NcButton
				type="tertiary"
				size="small"
				@click="handleEdit">
				<template #icon>
					<Pencil :size="16" />
				</template>
				{{ t('mydash', 'Edit') }}
			</NcButton>
			<NcButton
				type="tertiary"
				size="small"
				@click="handleRemove">
				<template #icon>
					<Delete :size="16" />
				</template>
				{{ t('mydash', 'Remove') }}
			</NcButton>
			<NcButton
				type="tertiary"
				size="small"
				@click="handleCancel">
				{{ t('mydash', 'Cancel') }}
			</NcButton>
		</div>
	</transition>
</template>

<script>
import { NcButton } from '@conduction/nextcloud-vue'
import { t } from '@nextcloud/l10n'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'WidgetContextMenu',

	components: {
		NcButton,
		Pencil,
		Delete,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		x: {
			type: Number,
			default: 0,
		},
		y: {
			type: Number,
			default: 0,
		},
		widget: {
			type: Object,
			default: null,
		},
	},

	emits: ['edit', 'remove', 'close'],

	data() {
		return {
			windowWidth: typeof window !== 'undefined' ? window.innerWidth : 0,
			windowHeight: typeof window !== 'undefined' ? window.innerHeight : 0,
		}
	},

	computed: {
		clampedX() {
			const menuWidth = 150 // min-width from CSS
			if (this.x + menuWidth > this.windowWidth) {
				return Math.max(0, this.windowWidth - menuWidth)
			}
			return this.x
		},

		clampedY() {
			const menuHeight = 120 // Approximate height for 3 buttons
			if (this.y + menuHeight > this.windowHeight) {
				return Math.max(0, this.windowHeight - menuHeight)
			}
			return this.y
		},
	},

	watch: {
		show(newVal) {
			if (newVal) {
				this.updateViewportSize()
			}
		},
	},

	mounted() {
		window.addEventListener('resize', this.updateViewportSize)
	},

	beforeDestroy() {
		window.removeEventListener('resize', this.updateViewportSize)
	},

	methods: {
		t,

		updateViewportSize() {
			this.windowWidth = window.innerWidth
			this.windowHeight = window.innerHeight
		},

		handleEdit() {
			this.$emit('edit', this.widget)
			this.$emit('close')
		},

		handleRemove() {
			this.$emit('remove', this.widget)
			this.$emit('close')
		},

		handleCancel() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.widget-context-menu {
	position: fixed;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	z-index: 10000;
	min-width: 150px;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.widget-context-menu :deep(.nc-button) {
	justify-content: flex-start;
	padding: 8px 12px;
	border-radius: 0;
	border-bottom: 1px solid var(--color-border);
}

.widget-context-menu :deep(.nc-button:last-child) {
	border-bottom: none;
}

.widget-context-menu :deep(.nc-button:hover) {
	background: var(--color-background-hover);
}

.fade-enter-active,
.fade-leave-active {
	transition: opacity 0.15s ease;
}

.fade-enter,
.fade-leave-to {
	opacity: 0;
}
</style>
