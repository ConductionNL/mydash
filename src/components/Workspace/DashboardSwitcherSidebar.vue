<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<aside
		class="mydash-sidebar"
		role="complementary"
		:aria-label="t('mydash', 'Dashboards')"
		@click.stop>
		<header class="mydash-sidebar__header">
			<h2 class="mydash-sidebar__title">
				{{ t('mydash', 'Dashboards') }}
			</h2>
		</header>

		<!-- Personal dashboards section. Hidden when the admin disabled
		     personal dashboards (REQ-SHELL-005 mirrors the same flag for
		     the empty-state CTA). -->
		<section
			v-if="allowUserDashboards && userDashboards.length > 0"
			class="mydash-sidebar__section">
			<h3 class="mydash-sidebar__section-title">
				{{ t('mydash', 'My dashboards') }}
			</h3>
			<ul class="mydash-sidebar__list">
				<li
					v-for="dashboard in userDashboards"
					:key="dashboard.id"
					class="mydash-sidebar__item"
					:class="{ 'mydash-sidebar__item--active': dashboard.id === activeDashboardId }">
					<button
						type="button"
						class="mydash-sidebar__item-button"
						@click="$emit('switch', dashboard)">
						{{ dashboard.name }}
					</button>
				</li>
			</ul>
		</section>

		<!-- Group dashboards section -->
		<section
			v-if="groupDashboards.length > 0"
			class="mydash-sidebar__section">
			<h3 class="mydash-sidebar__section-title">
				{{ t('mydash', 'Group dashboards') }}
			</h3>
			<ul class="mydash-sidebar__list">
				<li
					v-for="dashboard in groupDashboards"
					:key="dashboard.id"
					class="mydash-sidebar__item"
					:class="{ 'mydash-sidebar__item--active': dashboard.id === activeDashboardId }">
					<button
						type="button"
						class="mydash-sidebar__item-button"
						@click="$emit('switch', dashboard)">
						{{ dashboard.name }}
					</button>
				</li>
			</ul>
		</section>
	</aside>
</template>

<script>
import { t } from '@nextcloud/l10n'

/**
 * DashboardSwitcherSidebar — minimal placeholder slide-in panel that
 * lists the user's visible dashboards grouped by source (personal vs
 * group). Lives at `/components/Workspace/DashboardSwitcherSidebar.vue`.
 *
 * TODO: replaced when the `dashboard-switcher-sidebar` branch merges.
 * That branch ships the full list rendering, drag-to-reorder, edit /
 * delete affordances, and group-shared dashboard sub-headers. This
 * placeholder gives `runtime-shell` a clean integration surface so the
 * orchestrator can ship without blocking on the sibling.
 *
 * Props:
 *  - `userDashboards` (Array): personal dashboards (`source === 'user'`).
 *  - `groupDashboards` (Array): group-shared dashboards.
 *  - `activeDashboardId` (String): id of the currently active dashboard.
 *  - `allowUserDashboards` (Boolean): admin flag (REQ-SHELL-005).
 *
 * Emits:
 *  - `switch`: `(dashboard)` — user clicked a dashboard list item.
 */
export default {
	name: 'DashboardSwitcherSidebar',

	props: {
		userDashboards: {
			type: Array,
			default: () => [],
		},
		groupDashboards: {
			type: Array,
			default: () => [],
		},
		activeDashboardId: {
			type: String,
			default: '',
		},
		allowUserDashboards: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['switch'],

	methods: {
		t,
	},
}
</script>

<style scoped>
.mydash-sidebar {
	position: fixed;
	top: 50px;
	left: 0;
	bottom: 0;
	width: 280px;
	z-index: 1000;
	background: var(--color-main-background, #fff);
	border-right: 1px solid var(--color-border, #ddd);
	overflow-y: auto;
	padding: 16px;
	box-shadow: 2px 0 8px rgba(0, 0, 0, 0.1);
}

.mydash-sidebar__header {
	margin-bottom: 16px;
}

.mydash-sidebar__title {
	font-size: 1.1em;
	margin: 0;
}

.mydash-sidebar__section {
	margin-bottom: 24px;
}

.mydash-sidebar__section-title {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #555);
	text-transform: uppercase;
	margin: 0 0 8px;
}

.mydash-sidebar__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.mydash-sidebar__item {
	margin-bottom: 4px;
}

.mydash-sidebar__item-button {
	width: 100%;
	text-align: left;
	background: transparent;
	border: none;
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	cursor: pointer;
	color: inherit;
	font: inherit;
}

.mydash-sidebar__item-button:hover {
	background: var(--color-background-hover, #f0f0f0);
}

.mydash-sidebar__item--active .mydash-sidebar__item-button {
	background: var(--color-primary-element-light, #e0e7ff);
	font-weight: 600;
}
</style>
