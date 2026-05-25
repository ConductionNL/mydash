<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="workspace-shell" :class="orgNavWrapperClass">
		<!-- Org-wide navigation rail (REQ-ONAV-005, REQ-ONAV-008).
		     Rendered above the shell when position='top', otherwise as
		     a side rail. The component itself decides whether to
		     render anything based on the empty-state + position rules. -->
		<OrgNavigationPanel v-if="orgNavStore.shouldRender" />
		<!-- Region 1 (REQ-SHELL-006): the slide-in sidebar mount lived
		     here historically. It is now owned exclusively by the inner
		     `Views.vue` component (the user-facing sidebar with the
		     header cog menu + per-row entries shipped in wave3.2 / 3.3),
		     so this region intentionally renders nothing. The local
		     `sidebarOpen` ref + `toggleSidebar` / `closeSidebar` methods
		     are kept so the REQ-SHELL-004 strip + its lifecycle tests
		     (REQ-SHELL-007 outside-click listener) keep their existing
		     contract; production never flips `sidebarOpen` true because
		     the in-strip hamburger is the only writer and the strip is
		     itself gated on `sidebarOpen` (wave3.1). -->
		<!-- (no Region 1 mount) -->

		<!-- Region 2: hamburger + active-dashboard label strip
		     (REQ-SHELL-004). Always visible regardless of canEdit.
		     The hamburger uses NcButton tertiary so its hover/focus
		     ring matches the Nextcloud account-menu button. The
		     active dashboard's name is rendered as a static label —
		     dashboard switching is owned by the left sidebar
		     (REQ-SWITCH-002). The runtime-shell-trim change removed
		     the previous standalone "Active dashboard" select control
		     plus the entire edit toolbar; auto-save covers the layout
		     persistence path (REQ-GRID-005), and the action menu
		     (gear) reachable inside Views.vue covers Add Custom
		     Widget. -->
		<!-- Title strip is only visible when the sidebar is OPEN.
		     When closed, the floating sidebar-toggle in mydash-floating-controls
		     (top-right) is the only entry point — keeps the dashboard surface
		     uncluttered. -->
		<div v-if="sidebarOpen" class="workspace-shell__strip">
			<NcButton
				type="tertiary"
				:aria-label="t('mydash', 'Open menu')"
				class="workspace-shell__hamburger"
				@click="toggleSidebar">
				<template #icon>
					<MenuIcon :size="20" />
				</template>
			</NcButton>
			<h1 class="workspace-shell__title">
				{{ activeDashboardName }}
			</h1>
		</div>

		<!-- Region 3: grid surface (or empty state).
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

		<!-- Region 4 (footer-customization): branded footer below the
		     dashboard grid. Renders nothing when `effectiveFooter` is
		     null (REQ-FTR-001 disabled scenario, REQ-FTR-006 hidden
		     mode). The payload is resolved server-side by
		     `FooterService::resolveFooterForDashboard()` and surfaced
		     through initial-state injection / store hydration. -->
		<DashboardFooter
			:footer="effectiveFooter"
			:locale="injectedLocale" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import MenuIcon from 'vue-material-design-icons/Menu.vue'

import Views from './Views.vue'
import DashboardFooter from '../components/DashboardFooter.vue'
import OrgNavigationPanel from '../components/OrgNavigationPanel.vue'

import { useDashboardStore } from '../stores/dashboard.js'
import { useOrgNavigationStore } from '../stores/orgNavigation.js'

/**
 * WorkspaceApp — the runtime-shell page-level orchestrator (REQ-SHELL-001..007).
 *
 * Owns the trimmed three-region page chrome (post `runtime-shell-trim`):
 * slide-in sidebar (`dashboard-switcher` capability), hamburger +
 * active-dashboard label strip styled to match the Nextcloud account
 * button, and the grid container that either shows the dashboard
 * (delegating to `Views.vue` for widget machinery) or the empty-state
 * branch (REQ-SHELL-005).
 *
 * The previous edit toolbar (REQ-SHELL-003, removed) is gone — adding
 * widgets routes through the action menu's "Add custom widget" entry
 * (`unified-add-widget-flow`), and layout persistence is auto-saved by
 * the grid composable on a 300 ms debounce (REQ-GRID-005).
 *
 * Permission rule (REQ-SHELL-002): `canEdit = isAdmin || dashboardSource === 'user'`.
 * It now gates the per-widget context menu and the action menu's edit
 * entries inside `Views.vue` rather than a top toolbar.
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
		NcButton,
		MenuIcon,
		Views,
		DashboardFooter,
		OrgNavigationPanel,
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
		// REQ-FTR-001 / REQ-FTR-006 — initial-state surface for the
		// effective footer payload. Defaults to NULL so the
		// `DashboardFooter` component renders nothing until backend
		// hydration completes.
		injectedEffectiveFooter: {
			from: 'effectiveFooter',
			default: null,
		},
		// REQ-FTR-007 — viewer's NC locale for language-variant
		// selection inside `DashboardFooter`.
		injectedLocale: {
			from: 'viewerLocale',
			default: 'en',
		},
	},

	data() {
		return {
			// Local UI state only — every source-of-truth field flows
			// from initial state via inject, NEVER duplicated locally.
			// `saving` and `showAddDropdown` were removed alongside the
			// edit toolbar (`runtime-shell-trim`).
			sidebarOpen: false,
			// Click handler kept on `this` so addEventListener and
			// removeEventListener see the same function reference.
			outsideClickHandler: null,
		}
	},

	computed: {
		/**
		 * REQ-SHELL-002 — admins can edit any dashboard; regular users
		 * can edit only their own personal dashboards. After
		 * `runtime-shell-trim` this no longer gates a top toolbar; it
		 * gates the per-widget context menu (REQ-WDG-015) and the
		 * action menu's edit entries inside `Views.vue`.
		 *
		 * @return {boolean}
		 */
		/** @spec openspec/specs/runtime-shell/spec.md */
		canEdit() {
			return Boolean(this.injectedIsAdmin) || this.injectedDashboardSource === 'user'
		},

		/**
		 * Whether the resolver returned an active dashboard. Drives the
		 * empty-state branch in Region 3 (REQ-SHELL-005).
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
		/** @spec openspec/specs/runtime-shell/spec.md */
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
		 * Effective footer payload (REQ-FTR-001, REQ-FTR-004, REQ-FTR-006).
		 *
		 * Prefers the active dashboard's `effectiveFooter` field (resolved
		 * by the backend `FooterService::resolveFooterForDashboard()`) so
		 * per-dashboard overrides win over the boot-time global injection.
		 * Falls back to the initial-state injection when the active
		 * dashboard hasn't been hydrated yet.
		 *
		 * @return {object | null}
		 */
		/** @spec openspec/specs/runtime-shell/spec.md */
		effectiveFooter() {
			if (!this.injectedActiveDashboardId) {
				return this.injectedEffectiveFooter
			}
			const all = [
				...(this.injectedUserDashboards || []),
				...(this.injectedGroupDashboards || []),
			]
			const match = all.find(d => d && d.id === this.injectedActiveDashboardId)
			if (match && Object.prototype.hasOwnProperty.call(match, 'effectiveFooter')) {
				return match.effectiveFooter
			}
			return this.injectedEffectiveFooter
		},

		/**
		 * REQ-ONAV-005 — exposed so the template can both check
		 * `shouldRender` AND read `position` to drive the wrapper
		 * layout class.
		 *
		 * @return {object}
		 */
		/** @spec openspec/specs/runtime-shell/spec.md */
		orgNavStore() {
			return useOrgNavigationStore()
		},

		/**
		 * REQ-ONAV-005 — flex direction follows the rail position so
		 * `top` lays the rail above the shell, `right` after, `left`
		 * before, and `hidden` collapses the wrapper to its baseline.
		 *
		 * @return {string[]}
		 */
		/** @spec openspec/specs/runtime-shell/spec.md */
		orgNavWrapperClass() {
			if (!this.orgNavStore.shouldRender) {
				return []
			}
			return ['workspace-shell--org-nav-' + (this.orgNavStore.position || 'hidden')]
		},
	},

	/** @spec openspec/specs/runtime-shell/spec.md */
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

	/** @spec openspec/specs/runtime-shell/spec.md */
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

		/** @spec openspec/specs/runtime-shell/spec.md */
		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		/** @spec openspec/specs/runtime-shell/spec.md */
		closeSidebar() {
			this.sidebarOpen = false
		},

		/**
		 * Document-click handler retained for future shell-level
		 * dismiss flows; the previous Add-Widget dropdown was removed
		 * by `runtime-shell-trim` so the body of this handler is
		 * intentionally a no-op until a new shell-level popover lands.
		 * Keeping the listener wiring lets REQ-SHELL-007's lifecycle
		 * scenarios stay green.
		 *
		 * @param {MouseEvent} _event the click event
		 * @return {void}
		 */
		/** @spec openspec/specs/runtime-shell/spec.md */
		handleClickOutside(_event) {
			// no-op
		},

		/**
		 * Create the user's first personal dashboard from the empty-state
		 * CTA (REQ-SHELL-005 enabled scenario). Delegates to the dashboard
		 * store so the existing `POST /api/dashboard` flow is reused.
		 */
		/** @spec openspec/specs/runtime-shell/spec.md */
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

/* REQ-ONAV-005 — rail position drives the outer layout. The
   inner content (strip, grid) keeps its column layout via the
   `.workspace-shell__strip` etc. blocks below. */
.workspace-shell--org-nav-left,
.workspace-shell--org-nav-right {
	flex-direction: row;
}

.workspace-shell--org-nav-right {
	flex-direction: row-reverse;
}

.workspace-shell--org-nav-top {
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

/* The hamburger uses the NcButton tertiary variant so its hover,
   focus and active rings inherit the Nextcloud account-button
   styling. We keep an empty selector here so future overrides have
   a stable hook without re-introducing the bespoke chrome that the
   `runtime-shell-trim` change removed. */
.workspace-shell__hamburger {
	flex: 0 0 auto;
}

.workspace-shell__title {
	font-weight: 600;
	font-size: 1.05em;
	color: var(--color-main-text, #222);
	margin: 0;
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

<!--
  Global (unscoped) layout rules for the chrome wrapper that wraps the
  Vue mount point. Nextcloud's `#app-mydash` is a `display: flex` row
  container (so apps with a left navigation can sit nav + content side
  by side). MyDash opts out of the navigation rail (the PageController
  sets `id-app-navigation: null`), so the workspace wrapper must claim
  the full available width — without this, `.mydash-workspace` collapses
  to 0px and the dashboard grid renders empty over a blue background.
-->
<style>
.mydash-workspace,
#app-workspace.mydash-workspace,
#app-workspace.mydash-workspace #workspace-vue {
	flex: 1 1 auto;
	min-width: 0;
	width: 100%;
	min-height: 100vh;
}
</style>
