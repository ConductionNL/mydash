<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Root component — mounts the runtime-shell orchestrator (WorkspaceApp.vue)
  - which owns the four-region page chrome and delegates the grid surface to
  - the existing Views.vue.
  -
  - ADR-036 Decision 8: injects the reactive runtime-manifest ref provided
  - by main.js. The ref is updated once the async fetch in main.js resolves.
  - Provides the live manifest value down the tree via `provide` so future
  - manifest-aware descendants can inject it without prop-drilling.
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
 *
 * Injects the runtime-manifest reactive ref from the root `provide` set up
 * in `main.js` (ADR-036 Decision 8). The manifest starts as the bundled
 * stub and is replaced with the per-user manifest once the async fetch
 * completes.
 */
export default {
	name: 'App',
	components: {
		WorkspaceApp,
	},

	inject: {
		/**
		 * Reactive Vue.observable wrapping the current manifest value.
		 * Initial value is the bundled stub; replaced by the runtime fetch.
		 *
		 * @type {{ value: object }}
		 */
		runtimeManifest: {
			from: 'runtimeManifest',
			default: null,
		},
		/**
		 * Reactive Vue.observable tracking whether the manifest fetch is
		 * in flight. False once the fetch resolves or fails.
		 *
		 * @type {{ value: boolean }}
		 */
		manifestLoading: {
			from: 'manifestLoading',
			default: () => ({ value: false }),
		},
	},

	/** @spec openspec/specs/runtime-shell/spec.md */
	provide() {
		// Re-provide the reactive manifest refs so deeply nested
		// descendants don't need to prop-drill through WorkspaceApp/Views.
		return {
			runtimeManifest: this.runtimeManifest,
			manifestLoading: this.manifestLoading,
		}
	},
}
</script>

<style>
/* No styles needed here - all styling lives inside WorkspaceApp/Views. */
</style>
