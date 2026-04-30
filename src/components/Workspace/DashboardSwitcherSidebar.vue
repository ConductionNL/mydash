<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<aside
		:class="{ open: isOpen }"
		class="dashboard-switcher-sidebar"
		@keydown.esc="handleEsc">
		<!-- Primary group section -->
		<template v-if="matchedGroupDashboards.length > 0">
			<div class="sidebar-section-heading">
				{{ groupName || t('mydash', 'Dashboards') }}
			</div>
			<div class="sidebar-section">
				<button
					v-for="dashboard in matchedGroupDashboards"
					:key="`group-${dashboard.id}`"
					:class="{ active: dashboard.id === activeDashboardId }"
					class="sidebar-item sidebar-item--dashboard"
					:aria-label="dashboard.name"
					@click="handleDashboardClick(dashboard.id, 'group')">
					<IconRenderer :name="dashboard.icon" :size="20" />
					<span class="sidebar-item__label">{{ dashboard.name }}</span>
				</button>
			</div>
		</template>

		<!-- Divider between sections -->
		<div
			v-if="matchedGroupDashboards.length > 0 && (defaultGroupDashboards.length > 0 || userDashboardsVisible)"
			class="sidebar-divider" />

		<!-- Default group section -->
		<template v-if="defaultGroupDashboards.length > 0">
			<div class="sidebar-section-heading">
				{{ t('mydash', 'Default') }}
			</div>
			<div class="sidebar-section">
				<button
					v-for="dashboard in defaultGroupDashboards"
					:key="`default-${dashboard.id}`"
					:class="{ active: dashboard.id === activeDashboardId }"
					class="sidebar-item sidebar-item--dashboard"
					:aria-label="dashboard.name"
					@click="handleDashboardClick(dashboard.id, 'default')">
					<IconRenderer :name="dashboard.icon" :size="20" />
					<span class="sidebar-item__label">{{ dashboard.name }}</span>
				</button>
			</div>
		</template>

		<!-- Divider between sections -->
		<div
			v-if="(matchedGroupDashboards.length > 0 || defaultGroupDashboards.length > 0) && userDashboardsVisible"
			class="sidebar-divider" />

		<!-- My Dashboards section -->
		<template v-if="userDashboardsVisible">
			<div class="sidebar-section-heading">
				{{ t('mydash', 'My Dashboards') }}
			</div>
			<div class="sidebar-section">
				<!-- User dashboards -->
				<div
					v-for="dashboard in userDashboards"
					:key="`user-${dashboard.id}`"
					class="sidebar-item-wrapper">
					<button
						:class="{ active: dashboard.id === activeDashboardId }"
						class="sidebar-item sidebar-item--dashboard"
						:aria-label="dashboard.name"
						@click="handleDashboardClick(dashboard.id, 'user')">
						<IconRenderer :name="dashboard.icon" :size="20" />
						<span class="sidebar-item__label">{{ dashboard.name }}</span>
					</button>
					<button
						v-if="userDashboards.length > 0"
						class="sidebar-item-delete"
						:aria-label="t('mydash', 'Delete dashboard')"
						@click.stop="handleDeleteClick(dashboard.id)">
						<Close :size="16" />
					</button>
				</div>

				<!-- Create dashboard button -->
				<button
					v-if="allowUserDashboards"
					class="sidebar-item sidebar-item--action"
					@click="handleCreateClick">
					<Plus :size="20" />
					<span class="sidebar-item__label">{{ t('mydash', '+ New Dashboard') }}</span>
				</button>
			</div>
		</template>
	</aside>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import IconRenderer from '../Dashboard/IconRenderer.vue'

export default {
	name: 'DashboardSwitcherSidebar',

	components: {
		IconRenderer,
		Close,
		Plus,
	},

	props: {
		/**
		 * Whether the sidebar is open (controlled via v-model:open)
		 */
		isOpen: {
			type: Boolean,
			default: false,
		},

		/**
		 * Display name of the user's primary group
		 */
		groupName: {
			type: String,
			default: '',
		},

		/**
		 * Dashboards from the group (both matched and default-source)
		 * Shape: {id, name, icon, source: 'group' | 'default'}
		 */
		groupDashboards: {
			type: Array,
			default: () => [],
		},

		/**
		 * User's personal dashboards
		 * Shape: {id, name, icon}
		 */
		userDashboards: {
			type: Array,
			default: () => [],
		},

		/**
		 * ID of the currently active dashboard (for highlighting)
		 */
		activeDashboardId: {
			type: String,
			default: '',
		},

		/**
		 * When true, the "+ New Dashboard" button is shown in the personal section
		 */
		allowUserDashboards: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['switch', 'create-dashboard', 'delete-dashboard', 'update:open'],

	computed: {
		/**
		 * Primary group dashboards (source !== 'default')
		 */
		matchedGroupDashboards() {
			return this.groupDashboards.filter(d => d.source !== 'default')
		},

		/**
		 * Default group dashboards (source === 'default')
		 */
		defaultGroupDashboards() {
			return this.groupDashboards.filter(d => d.source === 'default')
		},

		/**
		 * Whether the "My Dashboards" section is visible
		 */
		userDashboardsVisible() {
			return this.userDashboards.length > 0 || this.allowUserDashboards
		},
	},

	methods: {
		t,

		/**
		 * Handle dashboard click: close sidebar, then emit switch
		 * @param {string} dashboardId - The ID of the dashboard to switch to
		 * @param {string} source - The source of the dashboard ('group', 'default', 'user')
		 */
		handleDashboardClick(dashboardId, source) {
			this.$emit('update:open', false)
			this.$emit('switch', dashboardId, source)
		},

		/**
		 * Handle delete button click (no switch emit)
		 * @param {string} dashboardId - The ID of the dashboard to delete
		 */
		handleDeleteClick(dashboardId) {
			this.$emit('delete-dashboard', dashboardId)
		},

		/**
		 * Handle create button click: close sidebar, then emit create-dashboard
		 */
		handleCreateClick() {
			this.$emit('update:open', false)
			this.$emit('create-dashboard')
		},

		/**
		 * Handle Esc key to close the sidebar
		 */
		handleEsc() {
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped lang="scss">
.dashboard-switcher-sidebar {
	position: fixed;
	top: 50px;
	left: 0;
	width: 280px;
	max-height: calc(100vh - 50px);
	background: var(--color-main-background);
	border-right: 1px solid var(--color-border);
	z-index: 1500;
	overflow-y: auto;
	transform: translateX(-100%);
	transition: transform 0.25s ease;

	&.open {
		transform: translateX(0);
	}

	.sidebar-section-heading {
		padding: 12px 16px;
		font-size: 12px;
		font-weight: 600;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
		letter-spacing: 0.5px;
	}

	.sidebar-section {
		display: flex;
		flex-direction: column;
	}

	.sidebar-item {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 8px 16px;
		background: none;
		border: none;
		cursor: pointer;
		color: var(--color-text-base);
		font-size: 14px;
		text-align: left;
		transition: background-color 0.15s ease;

		&:hover {
			background-color: var(--color-background-hover);
		}

		&.active {
			background-color: var(--color-primary-element-light);
		}

		&.active :deep(svg) {
			color: var(--color-primary-element);
		}

		&--dashboard {
			padding-left: 16px;
		}

		&--action {
			color: var(--color-primary-element);
			font-weight: 500;

			&:hover {
				background-color: var(--color-primary-element-light);
			}
		}

		&__label {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
			flex: 1;
		}
	}

	.sidebar-item-wrapper {
		position: relative;
		display: flex;
		align-items: center;

		.sidebar-item {
			flex: 1;
		}

		&:hover .sidebar-item-delete {
			display: inline-flex;
		}
	}

	.sidebar-item-delete {
		display: none;
		align-items: center;
		justify-content: center;
		width: 32px;
		height: 32px;
		padding: 0;
		background: none;
		border: none;
		cursor: pointer;
		color: var(--color-text-maxcontrast);
		transition: color 0.15s ease;

		&:hover {
			color: var(--color-error);
		}
	}

	.sidebar-divider {
		height: 1px;
		background: var(--color-border);
		margin: 8px 0;
	}
}
</style>
