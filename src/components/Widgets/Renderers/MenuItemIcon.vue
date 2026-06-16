<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<span class="menu-item-icon">
		<img
			v-if="isCustomIcon"
			:src="icon"
			:width="size"
			:height="size"
			alt="">
		<IconRenderer
			v-else
			:name="icon"
			:size="size" />
	</span>
</template>

<script>
import IconRenderer from '../../Dashboard/IconRenderer.vue'
import { isCustomIconUrl } from '../../../constants/dashboardIcons.js'

/**
 * MenuItemIcon — REQ-MENU-010 icon resolution. Mirrors the dual-mode
 * convention from the link-button widget (REQ-LBN-002): URL-shaped
 * names render as `<img>`, bare names route through `IconRenderer`,
 * and empty values render no icon (the parent suppresses the wrapper
 * via `v-if="item.icon"`).
 *
 * Default size is 20px so the icon fits inside menu rows without
 * forcing them taller than the surrounding text.
 */
export default {
	name: 'MenuItemIcon',

	components: {
		IconRenderer,
	},

	props: {
		/** Icon identifier — MDI registry key OR `/`-/`http`-prefixed URL. */
		icon: {
			type: String,
			default: '',
		},
		/** Square pixel size — REQ-MENU-010 caps at 16-24px. */
		size: {
			type: Number,
			default: 20,
		},
	},

	computed: {
		isCustomIcon() {
			return isCustomIconUrl(this.icon)
		},
	},
}
</script>

<style scoped>
.menu-item-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
}
</style>
