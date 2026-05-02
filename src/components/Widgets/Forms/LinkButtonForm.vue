<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="link-button-form">
		<NcTextField
			:value="label"
			:label="t('mydash', 'Label')"
			:placeholder="t('mydash', 'Label')"
			required
			@update:value="updateField('label', $event)" />

		<NcSelect
			:value="actionType"
			:options="actionTypeOptions"
			:input-label="t('mydash', 'Action Type')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('actionType', $event)" />

		<NcTextField
			:value="url"
			:label="t('mydash', 'URL')"
			:placeholder="urlPlaceholder"
			required
			@update:value="updateField('url', $event)" />

		<NcTextField
			:value="icon"
			:label="t('mydash', 'Upload Icon (optional)')"
			:placeholder="t('mydash', 'Icon')"
			@update:value="updateField('icon', $event)" />

		<label class="link-button-form__color-label">
			{{ t('mydash', 'Background Color') }}
			<input
				type="color"
				:value="backgroundColor || '#0070c0'"
				class="link-button-form__color"
				@input="updateField('backgroundColor', $event.target.value)">
		</label>

		<label class="link-button-form__color-label">
			{{ t('mydash', 'Text Color') }}
			<input
				type="color"
				:value="textColor || '#ffffff'"
				class="link-button-form__color"
				@input="updateField('textColor', $event.target.value)">
		</label>
	</div>
</template>

<script>
import { NcTextField, NcSelect } from '@nextcloud/vue'

const ACTION_TYPES = Object.freeze({
	EXTERNAL: 'external',
	INTERNAL: 'internal',
	CREATE_FILE: 'createFile',
})

const DEFAULT_CONTENT = Object.freeze({
	label: '',
	url: '',
	icon: '',
	actionType: ACTION_TYPES.EXTERNAL,
	backgroundColor: '',
	textColor: '',
})

/**
 * LinkButtonForm — sub-form for the AddWidgetModal when the user is
 * creating or editing a `link` placement (REQ-LBN-006).
 *
 * Six fields: `label`, `actionType`, `url`, `icon`, `backgroundColor`,
 * `textColor`. The `url` placeholder swaps with `actionType`
 * (`https://...`, `action-id`, `docx`). `validate()` requires both
 * `label` AND `url` non-empty and returns a non-empty error array
 * otherwise. The form pre-fills from `editingWidget.content` when
 * editing an existing widget.
 */
export default {
	name: 'LinkButtonForm',

	components: {
		NcTextField,
		NcSelect,
	},

	props: {
		/**
		 * The placement being edited, or `null` in create mode.
		 */
		editingWidget: {
			type: Object,
			default: null,
		},
		/**
		 * Initial content values — used when not editing and the
		 * parent supplies registry defaults.
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
			label: initial.label ?? DEFAULT_CONTENT.label,
			url: initial.url ?? DEFAULT_CONTENT.url,
			icon: initial.icon ?? DEFAULT_CONTENT.icon,
			actionType: initial.actionType ?? DEFAULT_CONTENT.actionType,
			backgroundColor: initial.backgroundColor ?? DEFAULT_CONTENT.backgroundColor,
			textColor: initial.textColor ?? DEFAULT_CONTENT.textColor,
		}
	},

	computed: {
		actionTypeOptions() {
			return [
				{ value: ACTION_TYPES.EXTERNAL, label: t('mydash', 'External Link') },
				{ value: ACTION_TYPES.INTERNAL, label: t('mydash', 'Internal Function') },
				{ value: ACTION_TYPES.CREATE_FILE, label: t('mydash', 'Create File') },
			]
		},

		urlPlaceholder() {
			switch (this.actionType) {
			case ACTION_TYPES.INTERNAL:
				return 'action-id'
			case ACTION_TYPES.CREATE_FILE:
				return 'docx'
			case ACTION_TYPES.EXTERNAL:
			default:
				return 'https://...'
			}
		},

		assembledContent() {
			return {
				label: this.label,
				url: this.url,
				icon: this.icon,
				actionType: this.actionType,
				backgroundColor: this.backgroundColor,
				textColor: this.textColor,
			}
		},
	},

	methods: {
		/**
		 * Set a field and notify the parent.
		 *
		 * @param {string} field one of: label, url, icon, actionType, backgroundColor, textColor
		 * @param {string} value new value
		 */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * REQ-LBN-006: validate() requires both `label` AND `url`
		 * non-empty and returns a non-empty error array otherwise.
		 *
		 * @return {string[]} validation errors
		 */
		validate() {
			const errors = []
			if (typeof this.label !== 'string' || this.label.trim() === '') {
				errors.push(t('mydash', 'Label is required'))
			}
			if (typeof this.url !== 'string' || this.url.trim() === '') {
				errors.push(t('mydash', 'URL is required'))
			}
			return errors
		},
	},
}
</script>

<style scoped>
.link-button-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.link-button-form__color-label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	font-size: 14px;
}

.link-button-form__color {
	width: 48px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
}
</style>
