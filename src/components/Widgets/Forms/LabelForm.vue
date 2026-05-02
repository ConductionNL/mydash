<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="label-form">
		<NcTextField
			:value="text"
			:label="t('mydash', 'Label text')"
			:placeholder="t('mydash', 'Label text')"
			required
			@update:value="updateField('text', $event)" />

		<NcTextField
			:value="fontSize"
			:label="t('mydash', 'Font size')"
			placeholder="16px"
			@update:value="updateField('fontSize', $event)" />

		<label class="label-form__color-label">
			{{ t('mydash', 'Color') }}
			<input
				type="color"
				:value="color || '#000000'"
				class="label-form__color"
				@input="updateField('color', $event.target.value)">
		</label>

		<label class="label-form__color-label">
			{{ t('mydash', 'Background color') }}
			<input
				type="color"
				:value="backgroundColor || '#ffffff'"
				class="label-form__color"
				@input="updateField('backgroundColor', $event.target.value)">
		</label>

		<NcSelect
			:value="fontWeight"
			:options="fontWeightOptions"
			:input-label="t('mydash', 'Font Weight')"
			:clearable="false"
			@input="updateField('fontWeight', $event)" />

		<NcSelect
			:value="textAlign"
			:options="textAlignOptions"
			:input-label="t('mydash', 'Alignment')"
			:clearable="false"
			@input="updateField('textAlign', $event)" />
	</div>
</template>

<script>
import { NcTextField, NcSelect } from '@nextcloud/vue'

const DEFAULT_CONTENT = Object.freeze({
	text: '',
	fontSize: '16px',
	color: '',
	backgroundColor: '',
	fontWeight: 'bold',
	textAlign: 'center',
})

/**
 * LabelForm is the sub-form for the AddWidgetModal when the user is creating
 * or editing a `label` widget placement.
 *
 * Exposes six controls per REQ-LBL-005 and a `validate()` method returning
 * `[t('mydash', 'Label text is required')]` when text is empty/whitespace.
 */
export default {
	name: 'LabelForm',

	components: {
		NcTextField,
		NcSelect,
	},

	props: {
		/**
		 * The placement being edited, or `null` in create mode.
		 * Pre-fills every control from `editingWidget.content` per REQ-LBL-005.
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
			text: initial.text ?? DEFAULT_CONTENT.text,
			fontSize: initial.fontSize ?? DEFAULT_CONTENT.fontSize,
			color: initial.color ?? DEFAULT_CONTENT.color,
			backgroundColor: initial.backgroundColor ?? DEFAULT_CONTENT.backgroundColor,
			fontWeight: initial.fontWeight ?? DEFAULT_CONTENT.fontWeight,
			textAlign: initial.textAlign ?? DEFAULT_CONTENT.textAlign,
		}
	},

	computed: {
		fontWeightOptions() {
			return ['normal', 'bold', '600', '700', '800']
		},

		textAlignOptions() {
			return ['left', 'center', 'right']
		},

		assembledContent() {
			return {
				text: this.text,
				fontSize: this.fontSize,
				color: this.color,
				backgroundColor: this.backgroundColor,
				fontWeight: this.fontWeight,
				textAlign: this.textAlign,
			}
		},
	},

	methods: {
		/**
		 * Set a field and notify parent.
		 *
		 * @param {string} field one of: text, fontSize, color, backgroundColor, fontWeight, textAlign
		 * @param {string} value new value
		 */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Returns a list of error strings; empty array means valid.
		 *
		 * @return {string[]} validation errors
		 */
		validate() {
			if (typeof this.text !== 'string' || this.text.trim() === '') {
				return [t('mydash', 'Label text is required')]
			}
			return []
		},
	},
}
</script>

<style scoped>
.label-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.label-form__color-label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	font-size: 14px;
}

.label-form__color {
	width: 48px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
}
</style>
