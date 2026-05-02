<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	SidebarBackdrop — capability `dashboard-switcher`

	Click-to-close backdrop for any open sidebar (currently
	`DashboardSwitcherSidebar`). Kept as a separate component so the
	sidebar's own DOM stays focused on navigation; the parent (currently
	`Views.vue`, eventually `WorkspaceApp.vue` once `runtime-shell` ships)
	conditionally renders this alongside the open sidebar and listens for
	`@click` to close it.

	Intentionally tiny — no props, no state — because the parent owns the
	open/closed state and the close intent.
-->

<template>
	<div
		class="sidebar-backdrop"
		role="presentation"
		@click="$emit('click')" />
</template>

<script>
export default {
	name: 'SidebarBackdrop',

	emits: ['click'],
}
</script>

<style scoped>
.sidebar-backdrop {
	position: fixed;
	top: 50px;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.25);
	z-index: 1499;
	cursor: pointer;
}
</style>
