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

		<NcActionCaption
			v-if="dashboards.length > 0"
			:name="t('mydash', 'Dashboards')" />
		<NcActionButton
			v-for="dashboard in dashboards"
			:key="`dash-${dashboard.id}`"
			:close-after-click="true"
			@click="$emit('switch-dashboard', dashboard.id)">
			<template #icon>
				<Check v-if="dashboard.id === activeDashboardId" :size="20" />
				<AccountGroup v-else-if="dashboard.isOwner === false" :size="20" />
				<ViewDashboard v-else :size="20" />
			</template>
			{{ dashboard.name }}{{ dashboard.isOwner === false ? ` (${t('mydash', 'shared by')} ${dashboard.sharedBy})` : '' }}
		</NcActionButton>
		<NcActionButton
			:close-after-click="true"
			@click="$emit('create-dashboard')">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('mydash', 'Create dashboard…') }}
		</NcActionButton>

		<NcActionSeparator />

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
		<NcActionButton
			v-if="canEdit"
			:close-after-click="true"
			@click="$emit('add-tile')">
			<template #icon>
				<ShapeRectanglePlus :size="20" />
			</template>
			{{ t('mydash', 'Add tile…') }}
		</NcActionButton>
		<NcActionButton
			v-if="canEdit"
			:close-after-click="true"
			@click="$emit('add-widget')">
			<template #icon>
				<ViewModule :size="20" />
			</template>
			{{ t('mydash', 'Add widget…') }}
		</NcActionButton>
		<!-- Custom widget types (label, text, image, link-button…) come from
		     widgetRegistry.js — REQ-WDG-014. Only shown when the registry has
		     at least one type with a usable form, so the menu never offers an
		     option that opens an empty modal. -->
		<NcActionButton
			v-if="canEdit && hasCustomWidgetTypes"
			:close-after-click="true"
			@click="$emit('add-custom-widget')">
			<template #icon>
				<ShapePolygonPlus :size="20" />
			</template>
			{{ t('mydash', 'Add custom widget…') }}
		</NcActionButton>

		<NcActionSeparator />

		<NcActionLink
			href="https://mydash.app"
			target="_blank"
			rel="noopener noreferrer">
			<template #icon>
				<BookOpenVariantOutline :size="20" />
			</template>
			{{ t('mydash', 'Documentation') }}
		</NcActionLink>

		<NcActionSeparator />

		<NcActionCaption class="dashboard-menu__brand-caption" :name="t('mydash', 'Powered by')" />
		<NcActionLink
			class="dashboard-menu__brand-link"
			href="https://sendent.com"
			target="_blank"
			rel="noopener noreferrer"
			aria-label="Sendent">
			<template #icon>
				<img :src="sendentLogo" alt="Sendent" class="dashboard-menu__brand-image">
			</template>
			<!-- Visible label is the logo image; this hidden text exists only
			     so NcActionLink renders the link properly. -->
			<span class="dashboard-menu__brand-sr-only">Sendent</span>
		</NcActionLink>
		<NcActionLink
			class="dashboard-menu__brand-link"
			href="https://conduction.nl"
			target="_blank"
			rel="noopener noreferrer"
			aria-label="Conduction">
			<template #icon>
				<img :src="conductionLogo" alt="Conduction" class="dashboard-menu__brand-image dashboard-menu__brand-image--invert">
			</template>
			<span class="dashboard-menu__brand-sr-only">Conduction</span>
		</NcActionLink>
	</NcActions>
</template>

<script>
import {
	NcActions,
	NcActionButton,
	NcActionCaption,
	NcActionLink,
	NcActionSeparator,
} from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'

import Cog from 'vue-material-design-icons/Cog.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import ViewModule from 'vue-material-design-icons/ViewModule.vue'
import ShapeRectanglePlus from 'vue-material-design-icons/ShapeRectanglePlus.vue'
import ShapePolygonPlus from 'vue-material-design-icons/ShapePolygonPlus.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'

import { listWidgetTypes } from '../constants/widgetRegistry.js'

export default {
	name: 'DashboardConfigMenu',

	components: {
		NcActions,
		NcActionButton,
		NcActionCaption,
		NcActionLink,
		NcActionSeparator,
		Cog,
		Check,
		Plus,
		Pencil,
		ContentSave,
		Tune,
		ViewDashboard,
		ViewModule,
		ShapeRectanglePlus,
		ShapePolygonPlus,
		BookOpenVariantOutline,
		AccountGroup,
	},

	props: {
		dashboards: {
			type: Array,
			default: () => [],
		},
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
		'switch-dashboard',
		'create-dashboard',
		'toggle-edit',
		'open-config',
		'add-tile',
		'add-widget',
		'add-custom-widget',
	],

	computed: {
		sendentLogo() {
			return generateFilePath('mydash', 'img', 'sendent-logo.png')
		},
		conductionLogo() {
			return generateFilePath('mydash', 'img', 'conduction-logo.png')
		},

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

<style scoped>
/* Center the "Powered by" caption above the brand logos.
   NcActionCaption renders a `display: flex` <li>, so text-align is ignored —
   we need justify-content for the inline text node. */
.dashboard-menu__brand-caption {
	justify-content: center;
	text-align: center;
	padding-left: 14px;
	padding-right: 14px;
}

/* Render each brand link as a full-width logo row, not the default
   icon-plus-text layout. */
.dashboard-menu__brand-link :deep(.action-link) {
	height: auto;
	padding: 10px 14px;
	justify-content: center;
}

.dashboard-menu__brand-link :deep(.action-link__icon),
.dashboard-menu__brand-link :deep(.action-button__icon) {
	width: 100%;
	height: auto;
	flex: 1 1 auto;
	display: flex;
	align-items: center;
	justify-content: center;
}

.dashboard-menu__brand-link :deep(.action-link__longtext-wrapper),
.dashboard-menu__brand-link :deep(.action-button__longtext-wrapper),
.dashboard-menu__brand-link :deep(.action-link__text),
.dashboard-menu__brand-link :deep(.action-button__text) {
	display: none;
}

.dashboard-menu__brand-link :deep(.dashboard-menu__brand-image) {
	max-width: 140px;
	max-height: 24px;
	width: auto;
	height: auto;
	object-fit: contain;
	opacity: 0.75;
	transition: opacity var(--animation-quick) ease;
}

.dashboard-menu__brand-link:hover :deep(.dashboard-menu__brand-image),
.dashboard-menu__brand-link:focus-within :deep(.dashboard-menu__brand-image) {
	opacity: 1;
}

/* Conduction logo is grayscale-on-transparent — force solid main-text color
   so it's visible on both light and dark themes. */
.dashboard-menu__brand-link :deep(.dashboard-menu__brand-image--invert) {
	filter: brightness(0) saturate(100%);
}

:global(body[data-themes*="dark"]) .dashboard-menu__brand-link :deep(.dashboard-menu__brand-image--invert) {
	filter: brightness(0) saturate(100%) invert(1);
}

.dashboard-menu__brand-sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}
</style>
