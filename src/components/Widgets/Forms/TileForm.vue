<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="tile-form">
		<NcTextField
			:value="title"
			:label="t('mydash', 'Title')"
			:placeholder="t('mydash', 'Tile title')"
			required
			@update:value="updateField('title', $event)" />

		<div class="tile-form__icon-row">
			<label class="tile-form__icon-label">
				{{ t('mydash', 'Icon') }}
			</label>
			<IconPicker
				:value="icon"
				@input="onIconChange" />
		</div>

		<NcSelect
			:value="iconType"
			:options="iconTypeOptions"
			:input-label="t('mydash', 'Icon type')"
			:clearable="false"
			@input="updateField('iconType', $event)" />

		<div class="tile-form__color-row">
			<label class="tile-form__color-label">
				{{ t('mydash', 'Background color') }}
				<input
					type="color"
					:value="backgroundColor || '#3b82f6'"
					class="tile-form__color"
					@input="updateField('backgroundColor', $event.target.value)">
			</label>

			<label class="tile-form__color-label">
				{{ t('mydash', 'Text color') }}
				<input
					type="color"
					:value="textColor || '#ffffff'"
					class="tile-form__color"
					@input="updateField('textColor', $event.target.value)">
			</label>
		</div>

		<NcSelect
			:value="linkType"
			:options="linkTypeOptions"
			:input-label="t('mydash', 'Link type')"
			:clearable="false"
			@input="updateField('linkType', $event)" />

		<NcTextField
			:value="linkValue"
			:label="linkValueLabel"
			:placeholder="linkValuePlaceholder"
			required
			@update:value="updateField('linkValue', $event)" />
	</div>
</template>

<script>
import { NcTextField, NcSelect } from '@conduction/nextcloud-vue'
import IconPicker from '../../Dashboard/IconPicker.vue'
import { isCustomIconUrl } from '../../../constants/dashboardIcons.js'

const DEFAULT_CONTENT = Object.freeze({
	title: '',
	icon: '',
	iconType: 'class',
	backgroundColor: '#3b82f6',
	textColor: '#ffffff',
	linkType: 'app',
	linkValue: '',
})

/**
 * TileForm — sub-form for the unified Add Custom Widget modal when the
 * user is creating or editing a `tile` widget placement (REQ-WDG-022).
 *
 * Collects the six fields the legacy standalone tile-creation modal
 * collected (title, icon + iconType discriminator, backgroundColor,
 * textColor, linkType, linkValue) and emits them via the standard
 * `update:content` event so the AddWidgetModal can save them inline on
 * the placement (REQ-TILE-PLACEMENT).
 *
 * The IconPicker (capability `dashboard-icons`) is wired to the `icon`
 * field; the picker's emitted value is either a registry key (treated as
 * iconType `class`) or an uploaded URL (treated as iconType `url`). The
 * `iconType` <select> remains visible so authors can override to `emoji`
 * or `svg` when persisting hand-authored values via the API or migration
 * scripts.
 */
export default {
	name: 'TileForm',

	components: {
		NcTextField,
		NcSelect,
		IconPicker,
	},

	props: {
		/**
		 * The placement being edited, or `null` in create mode.
		 * Pre-fills every control from `editingWidget.content`.
		 */
		editingWidget: {
			type: Object,
			default: null,
		},
		/**
		 * Initial content values — used when not editing and the parent
		 * supplies registry defaults.
		 */
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = this.editingWidget?.content || this.value || {}
		return {
			title: initial.title ?? DEFAULT_CONTENT.title,
			icon: initial.icon ?? DEFAULT_CONTENT.icon,
			iconType: initial.iconType ?? DEFAULT_CONTENT.iconType,
			backgroundColor: initial.backgroundColor ?? DEFAULT_CONTENT.backgroundColor,
			textColor: initial.textColor ?? DEFAULT_CONTENT.textColor,
			linkType: initial.linkType ?? DEFAULT_CONTENT.linkType,
			linkValue: initial.linkValue ?? DEFAULT_CONTENT.linkValue,
		}
	},

	computed: {
		/** @spec openspec/specs/tiles/spec.md */
		iconTypeOptions() {
			return ['class', 'url', 'emoji', 'svg']
		},

		/** @spec openspec/specs/tiles/spec.md */
		linkTypeOptions() {
			return ['app', 'url']
		},

		/** @spec openspec/specs/tiles/spec.md */
		linkValueLabel() {
			return this.linkType === 'app'
				? t('mydash', 'App route')
				: t('mydash', 'URL')
		},

		/** @spec openspec/specs/tiles/spec.md */
		linkValuePlaceholder() {
			return this.linkType === 'app'
				? '/apps/files'
				: 'https://example.com'
		},

		/** @spec openspec/specs/tiles/spec.md */
		assembledContent() {
			return {
				title: this.title,
				icon: this.icon,
				iconType: this.iconType,
				backgroundColor: this.backgroundColor,
				textColor: this.textColor,
				linkType: this.linkType,
				linkValue: this.linkValue,
			}
		},
	},

	methods: {
		/**
		 * Set a field and notify parent.
		 *
		 * @param {string} field one of: title, icon, iconType, backgroundColor, textColor, linkType, linkValue
		 * @param {string} value new value
		 */
		/** @spec openspec/specs/tiles/spec.md */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * IconPicker emits a single string. Adjust `iconType` based on
		 * whether the emitted value is a custom URL or a registry key so
		 * the renderer picks the correct branch.
		 *
		 * @param {string} value the emitted icon value
		 */
		/** @spec openspec/specs/tiles/spec.md */
		onIconChange(value) {
			this.icon = value || ''
			this.iconType = isCustomIconUrl(this.icon) ? 'url' : 'class'
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Returns a list of error strings; empty array means valid.
		 *
		 * @return {string[]} validation errors
		 */
		/** @spec openspec/specs/tiles/spec.md */
		validate() {
			const errors = []
			if (typeof this.title !== 'string' || this.title.trim() === '') {
				errors.push(t('mydash', 'Tile title is required'))
			}
			if (typeof this.linkValue !== 'string' || this.linkValue.trim() === '') {
				errors.push(t('mydash', 'Tile link target is required'))
			}
			return errors
		},
	},
}
</script>

<style scoped>
.tile-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.tile-form__icon-row {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.tile-form__icon-label {
	font-size: 14px;
	font-weight: 600;
}

.tile-form__color-row {
	display: flex;
	gap: 12px;
}

.tile-form__color-label {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	font-size: 14px;
}

.tile-form__color {
	width: 48px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
}
</style>
