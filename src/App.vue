<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<WorkspaceApp />
</template>

<script>
import WorkspaceApp from './views/WorkspaceApp.vue'

/**
 * Root component — mounts the runtime-shell orchestrator
 * (`WorkspaceApp.vue`) which owns the four-region page chrome and
 * delegates the grid surface to the existing `Views.vue`.
 */
export default {
	name: 'App',
	components: {
		WorkspaceApp,
	},
}
</script>

<style>
/* No styles needed here - all styling lives inside WorkspaceApp/Views. */
</style>
