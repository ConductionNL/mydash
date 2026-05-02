<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="workspace-shell">
		<!-- Region 1: slide-in sidebar (REQ-SHELL-006).
		     The DashboardSwitcherSidebar capability owns the slide-in
		     panel; the backdrop intercepts off-panel clicks and emits
		     `close`. The sidebar is mounted whenever `sidebarOpen` is
		     true so its CSS transition can run. -->
		<template v-if="sidebarOpen">
			<SidebarBackdrop @close="closeSidebar" />
			<DashboardSwitcherSidebar
				:is-open="sidebarOpen"
				:group-name="injectedPrimaryGroupName"
				:user-dashboards="injectedUserDashboards"
				:group-dashboards="injectedGroupDashboards"
				:active-dashboard-id="injectedActiveDashboardId"
				:allow-user-dashboards="injectedAllowUserDashboards"
				@update:open="onSidebarUpdateOpen"
				@switch="onSidebarSwitch"
				@create-dashboard="onSidebarCreate"
				@delete-dashboard="onSidebarDelete" />
		</template>

		<!-- Region 2: hamburger + active-dashboard label strip
		     (REQ-SHELL-004). Always visible regardless of canEdit. -->
		<div class="workspace-shell__strip">
			<button
				type="button"
				class="workspace-shell__hamburger"
				:aria-label="t('mydash', 'Open menu')"
				@click="toggleSidebar">
				<span class="workspace-shell__hamburger-bar" />
				<span class="workspace-shell__hamburger-bar" />
				<span class="workspace-shell__hamburger-bar" />
			</button>
			<span class="workspace-shell__title">
				{{ activeDashboardName }}
			</span>
		</div>

		<!-- Region 3: edit toolbar (REQ-SHELL-003). v-if (NOT v-show)
		     so the DOM stays clean for non-edit users. Add Widget
		     dropdown is sourced from the widget-type registry. -->
		<div v-if="canEdit" class="workspace-shell__toolbar">
			<div class="workspace-shell__toolbar-left">
				<div class="workspace-shell__add-dropdown">
					<button
						type="button"
						class="workspace-shell__add-button"
						:aria-haspopup="true"
						:aria-expanded="showAddDropdown"
						@click.stop="toggleAddDropdown">
						{{ t('mydash', 'Add Widget') }}
					</button>
					<ul
						v-if="showAddDropdown"
						class="workspace-shell__add-menu"
						role="menu"
						@click.stop>
						<li
							v-for="type in availableWidgetTypes"
							:key="type"
							role="menuitem"
							class="workspace-shell__add-item"
							@click="onAddTypeSelected(type)">
							{{ typeDisplayName(type) }}
						</li>
					</ul>
				</div>
			</div>
			<div class="workspace-shell__toolbar-right">
				<button
					type="button"
					class="workspace-shell__save-button"
					:disabled="saving"
					@click="saveLayout">
					{{ saving ? t('mydash', 'Saving…') : t('mydash', 'Save Layout') }}
				</button>
			</div>
		</div>

		<!-- Region 4: grid surface (or empty state).
		     The empty state branches on `allowUserDashboards`
		     (REQ-SHELL-005). When an active dashboard is resolved we
		     defer to the existing Views component which owns the
		     grid + per-widget modals — runtime-shell does not duplicate
		     widget machinery. -->
		<div class="workspace-shell__grid">
			<Views
				v-if="hasActiveDashboard"
				ref="viewsRef" />
			<div v-else class="workspace-shell__empty">
				<p class="workspace-shell__empty-title">
					{{ t('mydash', 'No dashboards available') }}
				</p>
				<p v-if="injectedAllowUserDashboards" class="workspace-shell__empty-hint">
					{{ t('mydash', 'Create your first dashboard') }}
				</p>
				<p v-else class="workspace-shell__empty-hint">
					{{ t('mydash', 'Contact your administrator') }}
				</p>
				<button
					v-if="injectedAllowUserDashboards"
					type="button"
					class="workspace-shell__empty-cta"
					@click="onCreateFirstDashboard">
					{{ t('mydash', 'Create your first dashboard') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'

import Views from './Views.vue'
import SidebarBackdrop from '../components/Workspace/SidebarBackdrop.vue'
import DashboardSwitcherSidebar from '../components/Workspace/DashboardSwitcherSidebar.vue'

import { listWidgetTypes, getWidgetTypeEntry } from '../constants/widgetRegistry.js'
import { useDashboardStore } from '../stores/dashboard.js'
import { api } from '../services/api.js'

/**
 * WorkspaceApp — the runtime-shell page-level orchestrator (REQ-SHELL-001..007).
 *
 * Owns the four-region page chrome: slide-in sidebar (placeholder until
 * `dashboard-switcher-sidebar` lands), hamburger + active-dashboard label
 * strip, edit toolbar (gated on `canEdit`), and the grid container that
 * either shows the dashboard (delegating to `Views.vue` for widget
 * machinery) or the empty-state branch (REQ-SHELL-005).
 *
 * Permission rule (REQ-SHELL-002): `canEdit = isAdmin || dashboardSource === 'user'`.
 * When false, the toolbar is removed from the DOM (`v-if`, not `v-show`)
 * so non-edit users have no edit-only surface to interact with.
 *
 * Initial-state contract (REQ-INIT-002): every key consumed here is
 * injected from the root `provide` set up in `main.js`. Defaults match
 * the JS reader so a missing key never produces `undefined`.
 *
 * Lifecycle (REQ-SHELL-007):
 *  - `mounted()` registers `document.click` after `nextTick()` so the
 *    grid-container ref is non-null before the listener fires.
 *  - `beforeDestroy()` removes the listener.
 *  - The GridStack instance itself is owned by the embedded Views.vue;
 *    its destroy hook fires when Views unmounts (which happens here on
 *    page navigation, satisfying REQ-SHELL-007 grid-destroy scenario).
 */
export default {
	name: 'WorkspaceApp',

	components: {
		Views,
		SidebarBackdrop,
		DashboardSwitcherSidebar,
	},

	inject: {
		injectedIsAdmin: {
			from: 'isAdmin',
			default: false,
		},
		injectedDashboardSource: {
			from: 'dashboardSource',
			default: 'group',
		},
		injectedActiveDashboardId: {
			from: 'activeDashboardId',
			default: '',
		},
		injectedAllowUserDashboards: {
			from: 'allowUserDashboards',
			default: false,
		},
		injectedLayout: {
			from: 'layout',
			default: () => [],
		},
		injectedUserDashboards: {
			from: 'userDashboards',
			default: () => [],
		},
		injectedGroupDashboards: {
			from: 'groupDashboards',
			default: () => [],
		},
		injectedPrimaryGroupName: {
			from: 'primaryGroupName',
			default: '',
		},
	},

	data() {
		return {
			// Local UI state only — every source-of-truth field flows
			// from initial state via inject, NEVER duplicated locally.
			sidebarOpen: false,
			saving: false,
			showAddDropdown: false,
			// Click handler kept on `this` so addEventListener and
			// removeEventListener see the same function reference.
			outsideClickHandler: null,
		}
	},

	computed: {
		/**
		 * REQ-SHELL-002 — admins can edit any dashboard; regular users
		 * can edit only their own personal dashboards.
		 *
		 * @return {boolean}
		 */
		canEdit() {
			return Boolean(this.injectedIsAdmin) || this.injectedDashboardSource === 'user'
		},

		/**
		 * Whether the resolver returned an active dashboard. Drives the
		 * empty-state branch in Region 4 (REQ-SHELL-005).
		 *
		 * @return {boolean}
		 */
		hasActiveDashboard() {
			return Boolean(this.injectedActiveDashboardId)
		},

		/**
		 * Active dashboard's display name (REQ-SHELL-004 active-name
		 * scenario). Resolved from the union of group + user dashboards.
		 * Empty string when no active dashboard is resolved.
		 *
		 * @return {string}
		 */
		activeDashboardName() {
			if (!this.injectedActiveDashboardId) {
				return ''
			}
			const all = [
				...(this.injectedUserDashboards || []),
				...(this.injectedGroupDashboards || []),
			]
			const match = all.find(d => d && d.id === this.injectedActiveDashboardId)
			return match ? (match.name || '') : ''
		},

		/**
		 * Available widget types from the registry (REQ-SHELL-003).
		 * Filters out registry entries with no form (the registry helper
		 * already does this — see `widgetRegistry.js`).
		 *
		 * @return {string[]}
		 */
		availableWidgetTypes() {
			return listWidgetTypes()
		},
	},

	mounted() {
		// REQ-SHELL-007 — register the document-level click listener
		// after `nextTick()` so any grid container refs are non-null
		// before the listener can fire. The Views child mounts the
		// GridStack instance synchronously inside its own `mounted()`,
		// so by the time `nextTick` resolves the grid is ready.
		this.outsideClickHandler = (event) => {
			this.handleClickOutside(event)
		}
		this.$nextTick(() => {
			document.addEventListener('click', this.outsideClickHandler)
		})
	},

	beforeDestroy() {
		// REQ-SHELL-007 — drop the document listener so it never leaks
		// across mounts. The embedded Views.vue handles GridStack
		// destruction in its own `beforeDestroy` via the grid composable.
		if (this.outsideClickHandler) {
			document.removeEventListener('click', this.outsideClickHandler)
			this.outsideClickHandler = null
		}
	},

	methods: {
		t,

		/**
		 * Localised display name for a widget type (REQ-SHELL-003 dropdown
		 * label scenario). Falls back to the registry key when no display
		 * name is registered.
		 *
		 * @param {string} type widget type key
		 * @return {string}
		 */
		typeDisplayName(type) {
			const entry = getWidgetTypeEntry(type)
			return (entry && entry.displayName) || type
		},

		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		closeSidebar() {
			this.sidebarOpen = false
		},

		toggleAddDropdown() {
			this.showAddDropdown = !this.showAddDropdown
		},

		closeAddDropdown() {
			this.showAddDropdown = false
		},

		/**
		 * Document-click handler that closes the Add-Widget dropdown when
		 * the user clicks outside it (REQ-SHELL-007). Inner clicks are
		 * stopped via `@click.stop` on the menu so they never reach here.
		 *
		 * @param {MouseEvent} event the click event
		 */
		handleClickOutside(event) {
			if (!this.showAddDropdown) {
				return
			}
			const target = event.target
			if (target && typeof target.closest === 'function'
				&& target.closest('.workspace-shell__add-dropdown')) {
				return
			}
			this.closeAddDropdown()
		},

		/**
		 * Forward an Add Widget dropdown selection to the embedded Views
		 * component, which owns the unified AddWidgetModal host. The
		 * registry key flows through as `preselectedType` so the modal
		 * opens directly on that sub-form (REQ-WDG-010 deep-link path).
		 *
		 * @param {string} type registry key selected from the dropdown
		 */
		onAddTypeSelected(type) {
			this.closeAddDropdown()
			const views = this.$refs.viewsRef
			if (views && typeof views.openCustomWidgetModal === 'function') {
				views.openCustomWidgetModal(type)
			}
		},

		/**
		 * Switch the active dashboard from the sidebar list. The sidebar
		 * already emits `update:open(false)` BEFORE this `switch(id, source)`
		 * event (REQ-SWITCH-002), so we just defer to the store.
		 *
		 * @param {string|number} id the clicked dashboard id
		 * @param {'group'|'default'|'user'} source the section the row came from
		 */
		async onSidebarSwitch(id, source) {
			if (!id) {
				return
			}
			const store = useDashboardStore()
			try {
				await store.switchDashboard(id, source)
			} catch (error) {
				console.error('[WorkspaceApp] Failed to switch dashboard:', error)
			}
		},

		/**
		 * Sidebar v-model echo — close the sidebar when the panel emits
		 * `update:open(false)` (REQ-SWITCH-002, REQ-SWITCH-005).
		 *
		 * @param {boolean} value desired open state
		 */
		onSidebarUpdateOpen(value) {
			this.sidebarOpen = Boolean(value)
		},

		/**
		 * `+ New Dashboard` row clicked (REQ-SWITCH-005). Defer to the
		 * existing first-dashboard creation path — the sidebar has
		 * already emitted `update:open(false)` so the panel is closing.
		 */
		async onSidebarCreate() {
			await this.onCreateFirstDashboard()
		},

		/**
		 * Personal-row delete clicked (REQ-SWITCH-004). Forward to the
		 * dashboard store; the store decides whether to reload the
		 * visible-dashboards payload.
		 *
		 * @param {string|number} id personal dashboard id to delete
		 */
		async onSidebarDelete(id) {
			if (!id) {
				return
			}
			const store = useDashboardStore()
			try {
				await store.deleteDashboard(id)
			} catch (error) {
				console.error('[WorkspaceApp] Failed to delete dashboard:', error)
			}
		},

		/**
		 * Create the user's first personal dashboard from the empty-state
		 * CTA (REQ-SHELL-005 enabled scenario). Delegates to the dashboard
		 * store so the existing `POST /api/dashboard` flow is reused.
		 */
		async onCreateFirstDashboard() {
			const store = useDashboardStore()
			try {
				await store.createDashboard({
					name: this.t('mydash', 'My dashboard'),
				})
			} catch (error) {
				console.error('[WorkspaceApp] Failed to create first dashboard:', error)
			}
		},

		/**
		 * Persist the current layout (REQ-SHELL-003). Routes to the
		 * personal or group endpoint based on `dashboardSource`. The
		 * Save button is bound to `:disabled="saving"` so concurrent
		 * clicks cannot fire a second request.
		 *
		 * @return {Promise<void>}
		 */
		async saveLayout() {
			if (this.saving) {
				return
			}
			if (!this.injectedActiveDashboardId) {
				return
			}
			this.saving = true
			try {
				const store = useDashboardStore()
				const layout = store.widgetPlacements
				if (this.injectedDashboardSource === 'user') {
					await api.updateDashboard(
						this.injectedActiveDashboardId,
						{ layout },
					)
				} else {
					// Group / default-group dashboards route through the
					// group endpoint. The store already knows the group id
					// because it loaded the visible payload at boot.
					const groupId = store.activeDashboard?.groupId || 'default'
					await api.updateGroupDashboard(
						groupId,
						this.injectedActiveDashboardId,
						{ layout },
					)
				}
			} catch (error) {
				console.error('[WorkspaceApp] Save failed:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.workspace-shell {
	min-height: 100vh;
	width: 100%;
	display: flex;
	flex-direction: column;
}

.workspace-shell__strip {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border, #ddd);
	background: var(--color-main-background, #fff);
}

.workspace-shell__hamburger {
	display: inline-flex;
	flex-direction: column;
	justify-content: space-between;
	width: 24px;
	height: 18px;
	background: transparent;
	border: none;
	padding: 0;
	cursor: pointer;
}

.workspace-shell__hamburger-bar {
	display: block;
	width: 100%;
	height: 2px;
	background: var(--color-main-text, #222);
	border-radius: 2px;
}

.workspace-shell__title {
	font-weight: 600;
	font-size: 1.05em;
	color: var(--color-main-text, #222);
}

.workspace-shell__toolbar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border, #ddd);
	background: var(--color-main-background, #fff);
}

.workspace-shell__toolbar-left,
.workspace-shell__toolbar-right {
	display: flex;
	gap: 8px;
}

.workspace-shell__add-dropdown {
	position: relative;
}

.workspace-shell__add-button,
.workspace-shell__save-button {
	background: var(--color-primary-element, #1976d2);
	color: var(--color-primary-element-text, #fff);
	border: none;
	border-radius: var(--border-radius, 4px);
	padding: 6px 12px;
	cursor: pointer;
	font: inherit;
}

.workspace-shell__save-button[disabled] {
	opacity: 0.6;
	cursor: not-allowed;
}

.workspace-shell__add-menu {
	position: absolute;
	top: 100%;
	left: 0;
	margin: 4px 0 0;
	padding: 4px 0;
	min-width: 160px;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	list-style: none;
	z-index: 1001;
}

.workspace-shell__add-item {
	padding: 8px 12px;
	cursor: pointer;
}

.workspace-shell__add-item:hover {
	background: var(--color-background-hover, #f0f0f0);
}

.workspace-shell__grid {
	flex: 1;
	overflow: auto;
}

.workspace-shell__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	min-height: 60vh;
	text-align: center;
	gap: 12px;
}

.workspace-shell__empty-title {
	font-size: 1.3em;
	font-weight: 600;
	margin: 0;
}

.workspace-shell__empty-hint {
	color: var(--color-text-maxcontrast, #555);
	margin: 0;
}

.workspace-shell__empty-cta {
	background: var(--color-primary-element, #1976d2);
	color: var(--color-primary-element-text, #fff);
	border: none;
	border-radius: var(--border-radius, 4px);
	padding: 8px 16px;
	cursor: pointer;
	font: inherit;
	margin-top: 8px;
}
</style>
