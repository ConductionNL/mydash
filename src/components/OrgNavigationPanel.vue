<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		v-if="shouldRender"
		class="org-nav"
		:class="positionClass"
		role="navigation"
		:aria-label="t('mydash', 'Organization navigation')">
		<!-- Mobile hamburger (REQ-ONAV-010). Visible only at viewports
		     below 800px via CSS media query. -->
		<button
			type="button"
			class="org-nav__hamburger"
			:aria-label="t('mydash', 'Open organization navigation')"
			@click="openDrawer = true">
			<span class="org-nav__hamburger-bar" />
			<span class="org-nav__hamburger-bar" />
			<span class="org-nav__hamburger-bar" />
			<span class="org-nav__hamburger-label">{{ t('mydash', 'Navigation') }}</span>
		</button>

		<!-- Desktop rail and mobile drawer share the same template body -->
		<div
			class="org-nav__panel"
			:class="{ 'org-nav__panel--drawer-open': openDrawer }">
			<button
				v-if="openDrawer"
				type="button"
				class="org-nav__close"
				:aria-label="t('mydash', 'Close navigation')"
				@click="openDrawer = false">
				×
			</button>
			<ul class="org-nav__tree" role="tree">
				<OrgNavigationItem
					v-for="node in tree"
					:key="node.id"
					:node="node"
					:level="1"
					:current-url="currentUrl"
					@navigate="onNavigate" />
			</ul>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import OrgNavigationItem from './OrgNavigationItem.vue'
import { useOrgNavigationStore } from '../stores/orgNavigation.js'

/**
 * OrgNavigationPanel — renders the org-wide navigation rail/drawer
 * (REQ-ONAV-005, REQ-ONAV-008, REQ-ONAV-010).
 *
 * Hidden when:
 *   - the position setting is 'hidden' (REQ-ONAV-004), OR
 *   - the filtered tree is empty (REQ-ONAV-008).
 *
 * On mount the component:
 *   - subscribes to the store and triggers `fetchTree()` + `fetchPosition()`
 *   - reads `window.location.pathname` to seed the active-item detection
 *
 * Mobile/desktop affordances are CSS-driven (REQ-ONAV-010): a hamburger
 * button is visible below 800px and toggles `openDrawer`; the rail is
 * visible above that breakpoint regardless of the local `openDrawer`
 * state.
 */
export default {
	name: 'OrgNavigationPanel',

	components: {
		OrgNavigationItem,
	},

	data() {
		return {
			openDrawer: false,
			currentUrl: '',
		}
	},

	computed: {
		store() {
			return useOrgNavigationStore()
		},

		tree() {
			return this.store.visibleTree
		},

		shouldRender() {
			return this.store.shouldRender
		},

		positionClass() {
			return ['org-nav--' + (this.store.position || 'hidden')]
		},
	},

	mounted() {
		this.currentUrl = (typeof window !== 'undefined' && window.location)
			? window.location.pathname
			: ''
		// Fire-and-forget — errors are surfaced via store.error.
		this.store.fetchPosition()
		this.store.fetchTree()
	},

	methods: {
		t,

		/**
		 * Auto-close the mobile drawer after a navigation
		 * (REQ-ONAV-010). The link itself handles the actual
		 * navigation via the standard `<a href>` semantics emitted by
		 * `OrgNavigationItem`.
		 */
		onNavigate() {
			this.openDrawer = false
		},
	},
}
</script>

<style scoped>
.org-nav {
	display: flex;
	flex-direction: column;
}

.org-nav__hamburger {
	display: none;
	flex-direction: row;
	align-items: center;
	gap: 6px;
	background: transparent;
	border: none;
	padding: 6px 10px;
	cursor: pointer;
	font: inherit;
	color: var(--color-main-text, #222);
}

.org-nav__hamburger-bar {
	display: block;
	width: 16px;
	height: 2px;
	background: var(--color-main-text, #222);
}

.org-nav__hamburger-label {
	margin-left: 4px;
}

.org-nav__panel {
	display: flex;
	flex-direction: column;
	background: var(--color-main-background, #fff);
	border-right: 1px solid var(--color-border, #ddd);
	min-width: 220px;
	max-width: 320px;
	padding: 12px 8px;
}

.org-nav--top .org-nav__panel {
	border-right: none;
	border-bottom: 1px solid var(--color-border, #ddd);
	flex-direction: row;
	max-width: none;
}

.org-nav--right .org-nav__panel {
	border-right: none;
	border-left: 1px solid var(--color-border, #ddd);
}

.org-nav__close {
	display: none;
	background: transparent;
	border: none;
	font-size: 1.4em;
	cursor: pointer;
	align-self: flex-end;
	padding: 4px 8px;
}

.org-nav__tree {
	list-style: none;
	margin: 0;
	padding: 0;
}

@media (max-width: 799px) {
	.org-nav__hamburger {
		display: inline-flex;
	}

	.org-nav__panel {
		display: none;
		position: fixed;
		top: 0;
		left: 0;
		bottom: 0;
		z-index: 1000;
		min-width: min(280px, 80vw);
		box-shadow: 2px 0 8px rgba(0, 0, 0, 0.2);
	}

	.org-nav__panel--drawer-open {
		display: flex;
	}

	.org-nav__close {
		display: inline-block;
	}
}
</style>
