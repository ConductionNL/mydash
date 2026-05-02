<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="mydash-sidebar-backdrop"
		role="presentation"
		@click="$emit('close')" />
</template>

<script>
/**
 * SidebarBackdrop — a fixed-position click trap rendered behind the
 * dashboard-switcher slide-in sidebar (REQ-SHELL-006). Clicks anywhere
 * in the backdrop area emit `close` so the host can drop `sidebarOpen`.
 *
 * Starts at `top: 50px` to clear the Nextcloud header chrome and spans
 * the rest of the viewport. The sidebar panel itself sits ON TOP of this
 * element with `@click.stop`, so clicks inside the sidebar never reach
 * here (REQ-SHELL-006 click-on-sidebar-itself scenario).
 *
 * Emits:
 *  - `close`: backdrop click — host drops `sidebarOpen`.
 *
 * NOTE: this is the canonical backdrop. The sibling
 * `dashboard-switcher-sidebar` capability owns the slide-in panel itself;
 * this backdrop is paired with it but lives in the shell because the
 * shell owns the `sidebarOpen` state.
 */
export default {
	name: 'SidebarBackdrop',
	emits: ['close'],
}
</script>

<style scoped>
.mydash-sidebar-backdrop {
	position: fixed;
	top: 50px;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 999;
	background: rgba(0, 0, 0, 0.2);
	cursor: pointer;
}
</style>
