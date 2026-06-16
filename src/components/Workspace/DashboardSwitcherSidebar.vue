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

	A dedicated "Add Dashboard" card button (NcButton outline) renders
	below the personal dashboards list — still inside the scroll container,
	NOT inside the footer — when `allowUserDashboards === true`. Clicking
	it emits `update:open(false)` then `create-dashboard()` (REQ-SWITCH-008).
	(REQ-SWITCH-005's inline create row was removed in favour of this
	card; same emit contract is preserved.)

	A persistent SidebarFooter renders below the scroll container with
	`position: sticky; bottom: 0` so it remains visible while the
	dashboards list scrolls (REQ-SWITCH-009). The footer hosts the
	"Powered by Sendent / Conduction" brand attribution and the
	Documentation link previously surfaced in the gear menu (moved here
	by `runtime-shell-trim`).

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
			<!--
				Wave3.6 moved the per-dashboard actions out of the header
				cog and into a per-row cog (rendered next to each <li>),
				so each dashboard's Edit / Configure / Add widget / Delete
				operate on that specific row regardless of which dashboard
				is currently active. The header keeps only the X close
				now.
			-->
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
						<span
							v-if="isDefaultDashboard(dashboard)"
							class="dashboard-switcher-sidebar__default-marker"
							:title="t('mydash', 'Default dashboard — opens automatically when you visit MyDash')"
							:aria-label="t('mydash', 'Default dashboard')">
							<Star :size="16" />
						</span>
						<span class="dashboard-switcher-sidebar__label">{{ dashboard.name }}</span>
						<DashboardRowActions
							:dashboard="dashboard"
							source="group"
							:can-edit="canEdit"
							:default-uuid="defaultUuid"
							:is-edit-mode="isEditMode"
							:active-dashboard-id="activeDashboardId"
							@toggle-edit="onRowToggleEdit(dashboard, 'group')"
							@open-config="onRowOpenConfig(dashboard, 'group')"
							@add-custom-widget="onRowAddCustomWidget(dashboard, 'group')"
							@delete="onRowDelete(dashboard, 'group')"
							@set-default="onRowSetDefault(dashboard, 'group')" />
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
						<span
							v-if="isDefaultDashboard(dashboard)"
							class="dashboard-switcher-sidebar__default-marker"
							:title="t('mydash', 'Default dashboard — opens automatically when you visit MyDash')"
							:aria-label="t('mydash', 'Default dashboard')">
							<Star :size="16" />
						</span>
						<span class="dashboard-switcher-sidebar__label">{{ dashboard.name }}</span>
						<DashboardRowActions
							:dashboard="dashboard"
							source="default"
							:can-edit="canEdit"
							:default-uuid="defaultUuid"
							:is-edit-mode="isEditMode"
							:active-dashboard-id="activeDashboardId"
							@toggle-edit="onRowToggleEdit(dashboard, 'default')"
							@open-config="onRowOpenConfig(dashboard, 'default')"
							@add-custom-widget="onRowAddCustomWidget(dashboard, 'default')"
							@delete="onRowDelete(dashboard, 'default')"
							@set-default="onRowSetDefault(dashboard, 'default')" />
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
						<span
							v-if="isDefaultDashboard(dashboard)"
							class="dashboard-switcher-sidebar__default-marker"
							:title="t('mydash', 'Default dashboard — opens automatically when you visit MyDash')"
							:aria-label="t('mydash', 'Default dashboard')">
							<Star :size="16" />
						</span>
						<span class="dashboard-switcher-sidebar__label">{{ dashboard.name }}</span>
						<DashboardRowActions
							:dashboard="dashboard"
							source="user"
							:can-edit="canEdit"
							:default-uuid="defaultUuid"
							:is-edit-mode="isEditMode"
							:active-dashboard-id="activeDashboardId"
							@toggle-edit="onRowToggleEdit(dashboard, 'user')"
							@open-config="onRowOpenConfig(dashboard, 'user')"
							@add-custom-widget="onRowAddCustomWidget(dashboard, 'user')"
							@delete="onRowDelete(dashboard, 'user')"
							@set-default="onRowSetDefault(dashboard, 'user')" />
					</li>
				</ul>

				<!--
					REQ-SWITCH-008: dedicated "Add Dashboard" card button. Lives
					BELOW the personal list (not inside the <ul>), still inside
					the scroll container, gated on `allowUserDashboards`. Same
					emit contract as the removed inline row.
				-->
				<div
					v-if="allowUserDashboards"
					class="dashboard-switcher-sidebar__add-dashboard-card">
					<NcButton
						type="outline"
						wide
						data-action="create"
						:aria-label="t('mydash', 'Add dashboard')"
						@click="onCreate">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('mydash', 'Add dashboard') }}
					</NcButton>
				</div>
			</section>
		</div>

		<!--
			REQ-SWITCH-009: persistent footer pinned to the sidebar bottom via
			`position: sticky; bottom: 0`. Stays visible while the dashboards
			list above scrolls.
		-->
		<SidebarFooter class="dashboard-switcher-sidebar__footer" />
	</aside>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcButton } from '@conduction/nextcloud-vue'

import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Star from 'vue-material-design-icons/Star.vue'

import IconRenderer from '../Dashboard/IconRenderer.vue'
import SidebarFooter from './SidebarFooter.vue'
import DashboardRowActions from './DashboardRowActions.vue'

export default {
	name: 'DashboardSwitcherSidebar',

	components: {
		Close,
		Plus,
		Star,
		IconRenderer,
		NcButton,
		SidebarFooter,
		DashboardRowActions,
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
			/** @spec openspec/specs/dashboard-switcher/spec.md */
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
			/** @spec openspec/specs/dashboard-switcher/spec.md */
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
		 * When true, the personal section renders a dedicated "Add
		 * Dashboard" card button below the personal list (REQ-SWITCH-008);
		 * when false the card MUST NOT be in the DOM.
		 */
		allowUserDashboards: {
			type: Boolean,
			default: false,
		},

		/*
		 * `canEdit` flows down to each per-row cog (wave3.6) so the
		 * Edit / Add-custom-widget entries can hide for view-only
		 * users. Configure / Delete remain owner-gated per row via
		 * `dashboard.isOwner`. The wave3.3 `isEditMode` /
		 * `isActiveOwner` props were removed when the cog moved out
		 * of the header — per-row gating uses each row's own
		 * `dashboard.isOwner`, and Edit/Save toggle is implicit
		 * (the host enters edit mode after switching).
		 */
		canEdit: {
			type: Boolean,
			default: false,
		},

		/*
		 * Wave3.7 — UUID of the user's pinned default dashboard, or
		 * empty string when no pin is set. Forwarded to each
		 * DashboardRowActions so the cog menu can show "Set as default"
		 * vs "Default dashboard" with the right icon.
		 */
		defaultUuid: {
			type: String,
			default: '',
		},

		/*
		 * Wave3.9 — host's edit-mode boolean. Forwarded down so the
		 * cog button on the active row flips to "Save dashboard" +
		 * ContentSave icon while editing.
		 */
		isEditMode: {
			type: Boolean,
			default: false,
		},
	},

	emits: [
		'switch',
		'create-dashboard',
		'delete-dashboard',
		'update:open',
		// wave3.6 — per-row events. Each carries `(dashboard, source)`
		// so the host can switch to that dashboard and then apply the
		// action. The sidebar emits the raw event; the host owns the
		// switch-then-apply orchestration.
		'toggle-edit',
		'open-config',
		'add-custom-widget',
		// wave3.7 — `(dashboard, source)`. The host pins this row as
		// the user's default via `POST /api/dashboards/default`.
		'set-default',
	],

	computed: {
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		primaryGroupDashboards() {
			return this.groupDashboards.filter(d => d.source !== 'default')
		},

		/** @spec openspec/specs/dashboard-switcher/spec.md */
		defaultGroupDashboards() {
			return this.groupDashboards.filter(d => d.source === 'default')
		},

		/** @spec openspec/specs/dashboard-switcher/spec.md */
		primaryGroupHeading() {
			return this.groupName || t('mydash', 'Dashboards')
		},

		/**
		 * Personal section is rendered when there is at least one personal
		 * dashboard OR the user is allowed to create one (REQ-SWITCH-001).
		 */
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		showPersonalSection() {
			return this.userDashboards.length > 0 || this.allowUserDashboards === true
		},

		/**
		 * UUID of the row the resolver would land the user on at
		 * `/apps/mydash/`. Mirrors the backend's resolver precedence
		 * (steps 0..5) up to the group fallback so the star stays in
		 * sync with what the user will actually see when they navigate
		 * cold. Personal dashboards (step 6) are intentionally NOT
		 * starred when no explicit pin is set — starring an arbitrary
		 * personal dashboard the user never marked feels presumptuous.
		 *
		 * @return {string} UUID of the effective default, or '' when
		 *                  no pin/group default applies.
		 */
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		effectiveDefaultUuid() {
			if (this.defaultUuid) {
				return this.defaultUuid
			}
			const isDefaultFlag = (d) => Number(d?.isDefault) === 1
			// Step 2: primary-group with isDefault=1.
			const groupDefault = this.primaryGroupDashboards.find(isDefaultFlag)
			if (groupDefault?.uuid) {
				return groupDefault.uuid
			}
			// Step 3: default-group with isDefault=1.
			const defaultDefault = this.defaultGroupDashboards.find(isDefaultFlag)
			if (defaultDefault?.uuid) {
				return defaultDefault.uuid
			}
			// Step 4: first primary-group dashboard (no flag).
			if (this.primaryGroupDashboards[0]?.uuid) {
				return this.primaryGroupDashboards[0].uuid
			}
			// Step 5: first default-group dashboard (no flag).
			if (this.defaultGroupDashboards[0]?.uuid) {
				return this.defaultGroupDashboards[0].uuid
			}
			return ''
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
		/** @spec openspec/specs/dashboard-switcher/spec.md */
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
		 * Whether `dashboard` is the row the resolver would land the
		 * user on when they visit `/apps/mydash/`. The sidebar marks it
		 * with a star so the effective default is visible at a glance
		 * instead of buried in the cog menu.
		 *
		 * @param {object} dashboard Row payload from the parent.
		 * @return {boolean} True when the row is the effective default.
		 */
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		isDefaultDashboard(dashboard) {
			return Boolean(this.effectiveDefaultUuid)
				&& dashboard?.uuid === this.effectiveDefaultUuid
		},

		/**
		 * Click handler for a dashboard row. MUST emit `update:open(false)`
		 * BEFORE `switch(id, source)` so the parent can close the sidebar
		 * in the same tick (REQ-SWITCH-002).
		 *
		 * @param {string|number} id Dashboard id of the clicked row.
		 * @param {'group'|'default'|'user'} source Section the row was rendered in.
		 */
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		onSwitch(id, source) {
			this.$emit('update:open', false)
			this.$emit('switch', id, source)
		},

		/*
		 * Per-row action handlers (wave3.6). Each receives the row's
		 * dashboard payload + its source bucket; they wrap each cog
		 * action by first emitting `update:open(false)` so the
		 * sidebar collapses, THEN re-emitting the action upward with
		 * `(dashboard, source)` so the host can switch to that
		 * dashboard before applying the action.
		 */
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		onRowToggleEdit(dashboard, source) {
			this.$emit('toggle-edit', dashboard, source)
		},
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		onRowOpenConfig(dashboard, source) {
			this.$emit('open-config', dashboard, source)
		},
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		onRowAddCustomWidget(dashboard, source) {
			this.$emit('add-custom-widget', dashboard, source)
		},
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		onRowDelete(dashboard, source) {
			this.$emit('delete-dashboard', dashboard.id, source)
		},
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		onRowSetDefault(dashboard, source) {
			this.$emit('set-default', dashboard, source)
		},

		/**
		 * Click handler for the "Add Dashboard" card button. MUST emit
		 * `update:open(false)` BEFORE `create-dashboard()` (REQ-SWITCH-008).
		 */
		/** @spec openspec/specs/dashboard-switcher/spec.md */
		onCreate() {
			this.$emit('update:open', false)
			this.$emit('create-dashboard')
		},

		/** @spec openspec/specs/dashboard-switcher/spec.md */
		onCloseClick() {
			this.$emit('update:open', false)
		},

		/** @spec openspec/specs/dashboard-switcher/spec.md */
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

.dashboard-switcher-sidebar__icon {
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
}

.dashboard-switcher-sidebar__item.active .dashboard-switcher-sidebar__icon {
	color: var(--color-primary, #0082c9);
}

.dashboard-switcher-sidebar__default-marker {
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	color: var(--color-warning, #e9a800);
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

.dashboard-switcher-sidebar__delete:hover,
.dashboard-switcher-sidebar__delete:focus {
	background: var(--color-background-darker, #ececec);
	color: var(--color-error, #c0392b);
}

.dashboard-switcher-sidebar__item--personal:hover .dashboard-switcher-sidebar__delete,
.dashboard-switcher-sidebar__item--personal:focus-within .dashboard-switcher-sidebar__delete {
	display: inline-flex;
}

.dashboard-switcher-sidebar__add-dashboard-card {
	display: flex;
	padding: 8px 16px 4px 16px;
}

/*
	NcButton with `wide` already stretches to its container; this helper
	keeps the card visually aligned with the dashboard rows above.
 */
.dashboard-switcher-sidebar__add-dashboard-card :deep(.button-vue) {
	width: 100%;
}

.dashboard-switcher-sidebar__divider {
	border: 0;
	border-top: 1px solid var(--color-border, #e0e0e0);
	margin: 4px 0;
}

/*
	REQ-SWITCH-009: footer sticks to the bottom of the sidebar viewport
	while the dashboards list above scrolls. `position: sticky` together
	with the parent's `flex-direction: column` keeps the footer pinned
	without removing it from document flow (so the sidebar still has a
	deterministic height for the scroll container above).
 */
.dashboard-switcher-sidebar__footer {
	position: sticky;
	bottom: 0;
	flex: 0 0 auto;
}
</style>
