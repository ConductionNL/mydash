<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcActions
		:aria-label="t('mydash', 'Dashboard menu')"
		:force-menu="true"
		placement="bottom-end"
		type="secondary">
		<template #icon>
			<Cog :size="20" />
		</template>

		<!-- "Create dashboard…" was removed from this menu — the left
		     sidebar's "+" affordance is the only entry point now (the
		     redundant cog-menu entry was confusing alongside it). The
		     `create-dashboard` emit is preserved on the component contract
		     in case any host wires programmatic creation, but the menu no
		     longer surfaces it. -->
		<NcActionButton
			v-if="canEdit"
			:close-after-click="true"
			@click="$emit('toggle-edit')">
			<template #icon>
				<ContentSave v-if="isEditMode" :size="20" />
				<Pencil v-else :size="20" />
			</template>
			{{ isEditMode ? t('mydash', 'Save dashboard') : t('mydash', 'Edit dashboard') }}
		</NcActionButton>
		<NcActionButton
			v-if="isActiveOwner && activeDashboardId"
			:close-after-click="true"
			@click="$emit('open-config')">
			<template #icon>
				<Tune :size="20" />
			</template>
			{{ t('mydash', 'Dashboard configuration…') }}
		</NcActionButton>
		<!--
			REQ-WDG-022 / unified-add-widget-flow: the standalone "Add tile…"
			and "Add widget…" entries were removed in favour of the single
			unified picker below. Tile is now a registry-driven widget type
			alongside label/text/image/link/etc. Custom widget types come
			from widgetRegistry.js — REQ-WDG-014. Only shown when the
			registry has at least one type with a usable form, so the menu
			never offers an option that opens an empty modal.
		-->
		<NcActionButton
			v-if="canEdit && hasCustomWidgetTypes"
			:close-after-click="true"
			@click="$emit('add-custom-widget')">
			<template #icon>
				<ShapePolygonPlus :size="20" />
			</template>
			{{ t('mydash', 'Add custom widget…') }}
		</NcActionButton>

		<!-- "Documentation" was removed from this menu — the left
		     sidebar footer now hosts the only Documentation link (next to
		     the Sendent + Conduction "Powered by" logos). -->
	</NcActions>
</template>

<script>
import {
	NcActions,
	NcActionButton,
} from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

import Cog from 'vue-material-design-icons/Cog.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import ShapePolygonPlus from 'vue-material-design-icons/ShapePolygonPlus.vue'

import { listWidgetTypes } from '../constants/widgetRegistry.js'

export default {
	name: 'DashboardConfigMenu',

	components: {
		NcActions,
		NcActionButton,
		Cog,
		Pencil,
		ContentSave,
		Tune,
		ShapePolygonPlus,
	},

	// REQ-INIT-004: read the typed initial-state snapshot via root
	// `provide` (set in `src/main.js`). Default `false` keeps the gating
	// safe even if the value is missing — REQ-ASET-003 says the secure
	// default is "block creation".
	inject: {
		allowUserDashboards: {
			from: 'allowUserDashboards',
			default: false,
		},
	},

	props: {
		activeDashboardId: {
			type: [Number, String],
			default: null,
		},
		isEditMode: {
			type: Boolean,
			default: false,
		},
		canEdit: {
			type: Boolean,
			default: true,
		},
		isActiveOwner: {
			type: Boolean,
			default: true,
		},
	},

	emits: [
		'toggle-edit',
		'open-config',
		'add-custom-widget',
	],

	computed: {
		/**
		 * Whether the registry has at least one custom widget type with a
		 * usable form. Hides the "Add custom widget…" entry when no
		 * per-type sub-form is registered yet (REQ-WDG-014 — the menu is
		 * registry-driven and never offers an option that would open an
		 * empty modal).
		 *
		 * @return {boolean}
		 */
		hasCustomWidgetTypes() {
			return listWidgetTypes().length > 0
		},
	},

	methods: {
		t,
	},
}
</script>
