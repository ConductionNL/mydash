<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	DashboardSwitcherSidebar — capability `dashboard-switcher`

	Slide-in left navigation panel that lists every dashboard visible to
	the user, grouped by `source` discriminator (REQ-SWITCH-001):

	  1. Primary group dashboards (`source !== 'default'` from `groupDashboards`)
	  2. Default group dashboards (`source === 'default'` from `groupDashboards`)
	  3. Personal dashboards (`userDashboards`)

	Empty sections collapse entirely (no orphan headings). Clicking a row
	emits `update:open(false)` THEN `switch(id, source)` (REQ-SWITCH-002).
	The `source` discriminator on each emit is load-bearing — the parent
	uses it to pick the correct API endpoint (group vs default vs user).

	Personal rows expose a hover-revealed delete button (CSS `display: none →
	inline-flex`) emitting `delete-dashboard(id)` with `@click.stop` so it
	never triggers a switch (REQ-SWITCH-004).

	`+ New Dashboard` row appears at the end of the personal section ONLY
	when `allowUserDashboards === true`; clicking it emits `update:open(false)`
	then `create-dashboard()` (REQ-SWITCH-005).

	Slide-in is CSS-only via `transform: translateX(-100%) ↔ translateX(0)`
	over 0.25s ease (REQ-SWITCH-006). Esc closes (WCAG 4.3).

	Vue 2 v-model wiring: declares `model: { prop: 'isOpen', event:
	'update:open' }` so a parent template can use `v-model="sidebarOpen"`
	while we still emit the `update:open(boolean)` event mandated by the
	spec. The downstream runtime-shell component should adopt the same
	binding when it ships.

	Icon rendering MUST go through the shared `IconRenderer` from
	`dashboard-icons` — no inline `v-if="iconUrl"` branches here
	(REQ-SWITCH-007).
-->

<template>
	<aside
		class="dashboard-switcher-sidebar"
		:class="{ open: isOpen }"
		role="navigation"
		:aria-hidden="ariaHiddenAttr"
		:aria-label="t('mydash', 'Dashboards')"
		@keydown.esc="onEscClose">
		<div class="dashboard-switcher-sidebar__header">
			<h2 class="dashboard-switcher-sidebar__title">
				{{ t('mydash', 'Dashboards') }}
			</h2>
			<button
				type="button"
				class="dashboard-switcher-sidebar__close"
				:aria-label="t('mydash', 'Close')"
				@click="onCloseClick">
				<Close :size="20" />
			</button>
		</div>

		<div class="dashboard-switcher-sidebar__body">
			<!-- 1. Primary group dashboards -->
			<section
				v-if="primaryGroupDashboards.length > 0"
				class="dashboard-switcher-sidebar__section"
				data-section="group">
				<h3 class="dashboard-switcher-sidebar__heading">
					{{ primaryGroupHeading }}
				</h3>
				<ul class="dashboard-switcher-sidebar__list">
					<li
						v-for="dashboard in primaryGroupDashboards"
						:key="`group-${dashboard.id}`"
						class="dashboard-switcher-sidebar__item"
						:class="{ active: isActive(dashboard.id) }"
						data-source="group"
						tabindex="0"
						role="button"
						:aria-label="dashboard.name"
						@click="onSwitch(dashboard.id, 'group')"
						@keydown.enter="onSwitch(dashboard.id, 'group')"
						@keydown.space.prevent="onSwitch(dashboard.id, 'group')">
						<span class="dashboard-switcher-sidebar__icon">
							<IconRenderer :name="dashboard.icon" :size="20" />
						</span>
						<span class="dashboard-switcher-sidebar__label">{{ dashboard.name }}</span>
					</li>
				</ul>
			</section>

			<!-- Divider 1 ↔ 2 -->
			<hr
				v-if="primaryGroupDashboards.length > 0 && defaultGroupDashboards.length > 0"
				class="dashboard-switcher-sidebar__divider">

			<!-- 2. Default group dashboards -->
			<section
				v-if="defaultGroupDashboards.length > 0"
				class="dashboard-switcher-sidebar__section"
				data-section="default">
				<h3 class="dashboard-switcher-sidebar__heading">
					{{ t('mydash', 'Default') }}
				</h3>
				<ul class="dashboard-switcher-sidebar__list">
					<li
						v-for="dashboard in defaultGroupDashboards"
						:key="`default-${dashboard.id}`"
						class="dashboard-switcher-sidebar__item"
						:class="{ active: isActive(dashboard.id) }"
						data-source="default"
						tabindex="0"
						role="button"
						:aria-label="dashboard.name"
						@click="onSwitch(dashboard.id, 'default')"
						@keydown.enter="onSwitch(dashboard.id, 'default')"
						@keydown.space.prevent="onSwitch(dashboard.id, 'default')">
						<span class="dashboard-switcher-sidebar__icon">
							<IconRenderer :name="dashboard.icon" :size="20" />
						</span>
						<span class="dashboard-switcher-sidebar__label">{{ dashboard.name }}</span>
					</li>
				</ul>
			</section>

			<!-- Divider before personal section (only when prev section non-empty) -->
			<hr
				v-if="(primaryGroupDashboards.length > 0 || defaultGroupDashboards.length > 0) && showPersonalSection"
				class="dashboard-switcher-sidebar__divider">

			<!-- 3. Personal dashboards -->
			<section
				v-if="showPersonalSection"
				class="dashboard-switcher-sidebar__section"
				data-section="user">
				<h3 class="dashboard-switcher-sidebar__heading">
					{{ t('mydash', 'My Dashboards') }}
				</h3>
				<ul class="dashboard-switcher-sidebar__list">
					<li
						v-for="dashboard in userDashboards"
						:key="`user-${dashboard.id}`"
						class="dashboard-switcher-sidebar__item dashboard-switcher-sidebar__item--personal"
						:class="{ active: isActive(dashboard.id) }"
						data-source="user"
						tabindex="0"
						role="button"
						:aria-label="dashboard.name"
						@click="onSwitch(dashboard.id, 'user')"
						@keydown.enter="onSwitch(dashboard.id, 'user')"
						@keydown.space.prevent="onSwitch(dashboard.id, 'user')">
						<span class="dashboard-switcher-sidebar__icon">
							<IconRenderer :name="dashboard.icon" :size="20" />
						</span>
						<span class="dashboard-switcher-sidebar__label">{{ dashboard.name }}</span>
						<button
							type="button"
							class="dashboard-switcher-sidebar__delete"
							:aria-label="t('mydash', 'Delete dashboard')"
							@click.stop="onDelete(dashboard.id)">
							<Close :size="16" />
						</button>
					</li>

					<li
						v-if="allowUserDashboards"
						class="dashboard-switcher-sidebar__item dashboard-switcher-sidebar__item--create"
						data-action="create"
						tabindex="0"
						role="button"
						:aria-label="t('mydash', '+ New Dashboard')"
						@click="onCreate"
						@keydown.enter="onCreate"
						@keydown.space.prevent="onCreate">
						<span class="dashboard-switcher-sidebar__icon">
							<Plus :size="20" />
						</span>
						<span class="dashboard-switcher-sidebar__label">
							{{ t('mydash', '+ New Dashboard') }}
						</span>
					</li>
				</ul>
			</section>
		</div>
	</aside>
</template>

<script>
import { t } from '@nextcloud/l10n'

import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

import IconRenderer from '../Dashboard/IconRenderer.vue'

export default {
	name: 'DashboardSwitcherSidebar',

	components: {
		Close,
		Plus,
		IconRenderer,
	},

	/**
	 * Vue 2 `v-model` rebind: parent can write `v-model="sidebarOpen"` and
	 * we will read from `isOpen` and emit `update:open(boolean)`. This is
	 * the Vue 2.7 equivalent of Vue 3's `v-model:open` syntax.
	 */
	model: {
		prop: 'isOpen',
		event: 'update:open',
	},

	props: {
		/**
		 * Controlled by the parent via `v-model` (rebound to `isOpen` /
		 * `update:open` above).
		 */
		isOpen: {
			type: Boolean,
			required: true,
		},

		/**
		 * Display name of the user's primary group; falls back to the
		 * generic `Dashboards` label when omitted.
		 */
		groupName: {
			type: String,
			default: null,
		},

		/**
		 * Combined matched + folded default group dashboards from
		 * `/api/dashboards/visible`. Each row carries a `source: 'group' |
		 * 'default'` discriminator that drives section bucketing.
		 */
		groupDashboards: {
			type: Array,
			required: true,
			validator(value) {
				return Array.isArray(value)
			},
		},

		/**
		 * Personal dashboards (`source === 'user'`).
		 */
		userDashboards: {
			type: Array,
			required: true,
			validator(value) {
				return Array.isArray(value)
			},
		},

		/**
		 * Id of the currently active dashboard for highlighting
		 * (REQ-SWITCH-003). At most one row may carry the `.active` class
		 * at a time.
		 */
		activeDashboardId: {
			type: [String, Number],
			default: null,
		},

		/**
		 * When true, the personal section ends with a `+ New Dashboard`
		 * row; when false the row MUST NOT be in the DOM (REQ-SWITCH-005).
		 */
		allowUserDashboards: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['switch', 'create-dashboard', 'delete-dashboard', 'update:open'],

	computed: {
		primaryGroupDashboards() {
			return this.groupDashboards.filter(d => d.source !== 'default')
		},

		defaultGroupDashboards() {
			return this.groupDashboards.filter(d => d.source === 'default')
		},

		primaryGroupHeading() {
			return this.groupName || t('mydash', 'Dashboards')
		},

		/**
		 * Personal section is rendered when there is at least one personal
		 * dashboard OR the user is allowed to create one (REQ-SWITCH-001).
		 */
		showPersonalSection() {
			return this.userDashboards.length > 0 || this.allowUserDashboards === true
		},

		/**
		 * Vue 2's `:aria-hidden="false"` removes the attribute entirely
		 * rather than writing the literal string `"false"`. Screen readers
		 * (and the WCAG rule that asks for an explicit `false` while open)
		 * need the attribute to remain present, so we bind a string
		 * explicitly.
		 *
		 * @return {'true'|'false'} String form of `!isOpen`.
		 */
		ariaHiddenAttr() {
			return this.isOpen ? 'false' : 'true'
		},
	},

	methods: {
		t,

		isActive(id) {
			return this.activeDashboardId != null && id === this.activeDashboardId
		},

		/**
		 * Click handler for a dashboard row. MUST emit `update:open(false)`
		 * BEFORE `switch(id, source)` so the parent can close the sidebar
		 * in the same tick (REQ-SWITCH-002).
		 *
		 * @param {string|number} id Dashboard id of the clicked row.
		 * @param {'group'|'default'|'user'} source Section the row was rendered in.
		 */
		onSwitch(id, source) {
			this.$emit('update:open', false)
			this.$emit('switch', id, source)
		},

		/**
		 * Click handler for the personal-row delete button. MUST emit
		 * `delete-dashboard(id)` only — never `switch` or `update:open`
		 * (REQ-SWITCH-004). The template uses `@click.stop` to prevent
		 * the parent row's switch handler from firing.
		 *
		 * @param {string|number} id Personal dashboard id to delete.
		 */
		onDelete(id) {
			this.$emit('delete-dashboard', id)
		},

		/**
		 * Click handler for the `+ New Dashboard` row. MUST emit
		 * `update:open(false)` BEFORE `create-dashboard()` (REQ-SWITCH-005).
		 */
		onCreate() {
			this.$emit('update:open', false)
			this.$emit('create-dashboard')
		},

		onCloseClick() {
			this.$emit('update:open', false)
		},

		onEscClose() {
			if (this.isOpen) {
				this.$emit('update:open', false)
			}
		},
	},
}
</script>

<style scoped>
.dashboard-switcher-sidebar {
	position: fixed;
	top: 50px;
	left: 0;
	bottom: 0;
	width: 280px;
	z-index: 1500;
	background: var(--color-main-background, #fff);
	border-right: 1px solid var(--color-border, #e0e0e0);
	box-shadow: 2px 0 8px rgba(0, 0, 0, 0.08);
	transform: translateX(-100%);
	transition: transform 0.25s ease;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.dashboard-switcher-sidebar.open {
	transform: translateX(0);
}

.dashboard-switcher-sidebar__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
	flex: 0 0 auto;
}

.dashboard-switcher-sidebar__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text, #222);
}

.dashboard-switcher-sidebar__close {
	background: transparent;
	border: 0;
	padding: 4px;
	border-radius: 4px;
	cursor: pointer;
	color: var(--color-main-text, #222);
	display: inline-flex;
	align-items: center;
	justify-content: center;
}

.dashboard-switcher-sidebar__close:hover,
.dashboard-switcher-sidebar__close:focus {
	background: var(--color-background-hover, #f5f5f5);
}

.dashboard-switcher-sidebar__body {
	flex: 1 1 auto;
	overflow-y: auto;
	padding: 8px 0;
}

.dashboard-switcher-sidebar__section {
	padding: 8px 0;
}

.dashboard-switcher-sidebar__heading {
	margin: 0 0 4px 0;
	padding: 4px 16px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast, #757575);
}

.dashboard-switcher-sidebar__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.dashboard-switcher-sidebar__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 16px;
	cursor: pointer;
	user-select: none;
	color: var(--color-main-text, #222);
	transition: background-color 0.15s ease;
}

.dashboard-switcher-sidebar__item:hover,
.dashboard-switcher-sidebar__item:focus {
	background: var(--color-background-hover, #f5f5f5);
	outline: none;
}

.dashboard-switcher-sidebar__item.active {
	background: var(--color-primary-element-light, #e6f0fa);
}

.dashboard-switcher-sidebar__item.active .dashboard-switcher-sidebar__icon {
	color: var(--color-primary, #0082c9);
}

.dashboard-switcher-sidebar__icon {
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
}

.dashboard-switcher-sidebar__label {
	flex: 1 1 auto;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 14px;
}

.dashboard-switcher-sidebar__delete {
	display: none;
	background: transparent;
	border: 0;
	padding: 2px;
	border-radius: 3px;
	cursor: pointer;
	color: var(--color-text-maxcontrast, #757575);
	align-items: center;
	justify-content: center;
}

.dashboard-switcher-sidebar__item--personal:hover .dashboard-switcher-sidebar__delete,
.dashboard-switcher-sidebar__item--personal:focus-within .dashboard-switcher-sidebar__delete {
	display: inline-flex;
}

.dashboard-switcher-sidebar__delete:hover,
.dashboard-switcher-sidebar__delete:focus {
	background: var(--color-background-darker, #ececec);
	color: var(--color-error, #c0392b);
}

.dashboard-switcher-sidebar__item--create {
	color: var(--color-primary, #0082c9);
	font-weight: 500;
}

.dashboard-switcher-sidebar__item--create .dashboard-switcher-sidebar__icon {
	color: var(--color-primary, #0082c9);
}

.dashboard-switcher-sidebar__divider {
	border: 0;
	border-top: 1px solid var(--color-border, #e0e0e0);
	margin: 4px 0;
}
</style>
