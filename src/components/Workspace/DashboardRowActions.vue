<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	DashboardRowActions — wave3.6 per-row cog menu

	Lives at the right edge of every dashboard `<li>` in the sidebar.
	The 4 actions (Edit / Configure / Add custom widget / Delete) act on
	the row's own dashboard, not on whichever dashboard happens to be
	"active" — so the user can edit, configure, or delete any dashboard
	they have access to without having to switch first.

	Action gating:
	  - Edit dashboard         → only when `canEdit` is true (parent
	    flag derived from the active dashboard's permission level; for
	    other rows it remains advisory — clicking still emits the
	    event so the host can decide whether to switch + enter edit
	    mode or no-op).
	  - Dashboard configuration → only when the row's `dashboard.isOwner !== false`.
	  - Add custom widget       → only when `canEdit` is true.
	  - Delete dashboard        → only when the row's `dashboard.isOwner !== false`.

	Emits raw events; the host (`DashboardSwitcherSidebar.vue`) maps
	them to its `toggle-edit` / `open-config` / `add-custom-widget` /
	`delete-dashboard` outputs with the per-row dashboard payload.

	`@click.stop` is set on the cog trigger so opening the menu never
	fires the row's `@click` switch handler.
-->

<template>
	<NcActions
		:aria-label="t('mydash', 'Dashboard menu')"
		:force-menu="true"
		placement="bottom-end"
		type="tertiary-no-background"
		class="dashboard-row-actions"
		@click.native.stop>
		<template #icon>
			<Cog :size="18" />
		</template>
		<NcActionButton
			v-if="canEdit"
			:close-after-click="true"
			@click="$emit('toggle-edit')">
			<template #icon>
				<Pencil :size="20" />
			</template>
			{{ t('mydash', 'Edit dashboard') }}
		</NcActionButton>
		<NcActionButton
			v-if="isOwner"
			:close-after-click="true"
			@click="$emit('open-config')">
			<template #icon>
				<Tune :size="20" />
			</template>
			{{ t('mydash', 'Dashboard configuration…') }}
		</NcActionButton>
		<NcActionButton
			v-if="canEdit"
			:close-after-click="true"
			@click="$emit('add-custom-widget')">
			<template #icon>
				<ShapePolygonPlus :size="20" />
			</template>
			{{ t('mydash', 'Add custom widget…') }}
		</NcActionButton>
		<NcActionButton
			:close-after-click="true"
			@click="$emit('set-default')">
			<template #icon>
				<StarCheck v-if="isDefault" :size="20" />
				<Star v-else :size="20" />
			</template>
			{{ isDefault ? t('mydash', 'Default dashboard') : t('mydash', 'Set as default') }}
		</NcActionButton>
		<NcActionButton
			v-if="isOwner"
			:close-after-click="true"
			@click="$emit('delete')">
			<template #icon>
				<TrashCanOutline :size="20" />
			</template>
			{{ t('mydash', 'Delete dashboard') }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcActions, NcActionButton } from '@nextcloud/vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import ShapePolygonPlus from 'vue-material-design-icons/ShapePolygonPlus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarCheck from 'vue-material-design-icons/StarCheck.vue'

export default {
	name: 'DashboardRowActions',

	components: {
		NcActions,
		NcActionButton,
		Cog,
		Pencil,
		Tune,
		ShapePolygonPlus,
		TrashCanOutline,
		Star,
		StarCheck,
	},

	props: {
		dashboard: {
			type: Object,
			required: true,
		},
		source: {
			type: String,
			required: true,
			validator: v => ['group', 'default', 'user'].includes(v),
		},
		canEdit: {
			type: Boolean,
			default: false,
		},
		/*
		 * Wave3.7 — UUID of the user's pinned default dashboard, or
		 * empty/null when no pin is set. The host fetches it once on
		 * mount via `GET /api/dashboards/default` and forwards it here
		 * so the cog menu can render the "Set as default" entry as
		 * either a star (this row is NOT default) or a filled star
		 * (this row IS the default).
		 */
		defaultUuid: {
			type: String,
			default: '',
		},
	},

	emits: ['toggle-edit', 'open-config', 'add-custom-widget', 'delete', 'set-default'],

	computed: {
		/*
		 * Owner-gated actions (Configure, Delete) require the user to
		 * own this dashboard. The visibility API doesn't always set
		 * `isOwner` on every row, so we infer ownership from the
		 * `source` discriminator: rows under "MY DASHBOARDS" (user
		 * source) are inherently owned by the caller; group / default
		 * rows are owned by whoever created them, so we honour the
		 * explicit `dashboard.isOwner` flag when present and otherwise
		 * conservatively hide the destructive actions.
		 */
		isOwner() {
			if (this.source === 'user') {
				return true
			}
			return this.dashboard?.isOwner === true
		},

		/*
		 * Wave3.7 — true when THIS row's dashboard is the user's
		 * pinned default. Drives the cog entry's icon (star vs
		 * StarCheck) and label ("Set as default" vs "Default
		 * dashboard").
		 */
		isDefault() {
			const uuid = this.dashboard?.uuid
			return !!uuid && uuid === this.defaultUuid
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.dashboard-row-actions {
	margin-inline-start: auto;
	flex: 0 0 auto;
}
</style>
