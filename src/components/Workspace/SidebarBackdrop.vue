<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		:class="{ visible: isOpen }"
		class="sidebar-backdrop"
		@click="$emit('close')" />
</template>

<script>
export default {
	name: 'SidebarBackdrop',

	props: {
		/**
		 * Whether the backdrop should be visible
		 */
		isOpen: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],
}
</script>

<style scoped lang="scss">
.sidebar-backdrop {
	position: fixed;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background: rgba(0, 0, 0, 0);
	z-index: 1499;
	opacity: 0;
	pointer-events: none;
	transition: opacity 0.25s ease;

	&.visible {
		background: rgba(0, 0, 0, 0.3);
		opacity: 1;
		pointer-events: auto;
	}
}
</style>
