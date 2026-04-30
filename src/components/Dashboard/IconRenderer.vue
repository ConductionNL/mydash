<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	IconRenderer — capability `dashboard-icons`

	Renders an icon for any field that follows the dashboards.icon
	convention (see src/constants/dashboardIcons.js):

	  - null / '' / unknown → DEFAULT_ICON component
	  - registry key (e.g. 'Star') → that component
	  - URL (starts with '/' or 'http') → <img> tag

	The URL branch is the foundation for the sibling capability
	`custom-icon-upload-pattern`. For THIS change the branch exists in
	the template (so consumers don't have to be rewritten when uploads
	land), but isCustomIconUrl() returns false for any registry key, so
	the image branch only fires once uploaded URLs start being persisted.

	TODO(custom-icon-upload-pattern): no behavioural change required here
	— that change adds the upload + URL-persistence path; this template
	already handles the discriminator.
-->

<template>
	<img
		v-if="isUrl"
		:src="name"
		:width="size"
		:height="size"
		alt="">
	<component
		:is="iconComponent"
		v-else
		:size="size" />
</template>

<script>
import {
	getIconComponent,
	isCustomIconUrl,
} from '../../constants/dashboardIcons.js'

export default {
	name: 'IconRenderer',

	props: {
		/**
		 * Icon identifier — either a registry key, a URL (handled by the
		 * `custom-icon-upload-pattern` capability), or null/empty for the
		 * default icon.
		 */
		name: {
			type: String,
			default: null,
		},

		/**
		 * Icon size in pixels. Applied as the `size` prop on built-in
		 * MDI components and as `width`/`height` on `<img>`.
		 */
		size: {
			type: Number,
			default: 20,
		},
	},

	computed: {
		isUrl() {
			return isCustomIconUrl(this.name)
		},

		iconComponent() {
			return getIconComponent(this.name)
		},
	},
}
</script>
