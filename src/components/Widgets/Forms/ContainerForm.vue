<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="container-form">
		<p class="container-form__hint">
			{{ t('mydash', 'A container holds a sub-grid of child widgets. Add child widgets via the container’s own grid once it is on the dashboard.') }}
		</p>

		<label class="container-form__color-label">
			{{ t('mydash', 'Background') }}
			<input
				type="color"
				:value="backgroundColorValue"
				class="container-form__color"
				@input="updateField('backgroundColor', $event.target.value)">
		</label>

		<NcSelect
			:value="padding"
			:options="paddingOptions"
			:input-label="t('mydash', 'Padding')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('padding', $event)" />

		<NcTextField
			:value="title"
			:label="t('mydash', 'Title (optional)')"
			:placeholder="t('mydash', 'Title (optional)')"
			@update:value="updateField('title', $event)" />
	</div>
</template>

<script>
import { NcTextField, NcSelect } from '@conduction/nextcloud-vue'

const PADDING_VALUES = Object.freeze(['none', 'small', 'medium', 'large'])

const DEFAULT_CONTENT = Object.freeze({
	placements: [],
	backgroundColor: 'transparent',
	padding: 'medium',
	title: '',
})

/**
 * ContainerForm — sub-form for creating or editing a `container` widget
 * placement (REQ-CONT-007).
 *
 * Three optional controls:
 *   - backgroundColor — colour picker (NcColorPicker stand-in via native
 *     <input type="color">) with a 'transparent' default
 *   - padding — none / small / medium / large preset (NcSelect)
 *   - title — optional string (NcTextField); empty means no title rendered
 *
 * The form deliberately does NOT manage the container's children —
 * children are added, removed, moved, and resized via the inner grid's
 * own affordances when the container is rendered in edit mode
 * (REQ-CONT-005). `validate()` always returns an empty array because
 * every field is optional.
 */
export default {
	name: 'ContainerForm',

	components: {
		NcTextField,
		NcSelect,
	},

	props: {
		/**
		 * The placement being edited, or `null` in create mode. Pre-fills
		 * every control from `editingWidget.content` per REQ-WDG-010.
		 */
		editingWidget: {
			type: Object,
			default: null,
		},
		/**
		 * Initial content values — used when not editing and the parent
		 * (AddWidgetModal) supplies registry defaults.
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
			placements: Array.isArray(initial.placements) ? initial.placements : [],
			backgroundColor: typeof initial.backgroundColor === 'string'
				? initial.backgroundColor
				: DEFAULT_CONTENT.backgroundColor,
			padding: PADDING_VALUES.includes(initial.padding)
				? initial.padding
				: DEFAULT_CONTENT.padding,
			title: typeof initial.title === 'string'
				? initial.title
				: DEFAULT_CONTENT.title,
		}
	},

	computed: {
		paddingOptions() {
			return [
				{ value: 'none', label: t('mydash', 'None') },
				{ value: 'small', label: t('mydash', 'Small') },
				{ value: 'medium', label: t('mydash', 'Medium') },
				{ value: 'large', label: t('mydash', 'Large') },
			]
		},

		/**
		 * The native <input type="color"> requires a 6-char hex value
		 * even when the form's stored value is `'transparent'` or empty.
		 * Map sentinel values to a neutral default for the swatch
		 * without overwriting the form's own state.
		 *
		 * @return {string}
		 */
		backgroundColorValue() {
			const value = this.backgroundColor
			if (typeof value !== 'string' || value === '' || value === 'transparent') {
				return '#ffffff'
			}
			return value
		},

		assembledContent() {
			return {
				placements: this.placements,
				backgroundColor: this.backgroundColor,
				padding: this.padding,
				title: this.title,
			}
		},
	},

	methods: {
		/**
		 * Set a field and notify parent via `update:content`.
		 *
		 * @param {string} field one of: backgroundColor, padding, title
		 * @param {string} value new value
		 */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Returns a list of error strings; empty array means valid.
		 * Every container field is optional, so this always returns [].
		 *
		 * @return {string[]} validation errors (always empty)
		 */
		validate() {
			return []
		},
	},
}
</script>

<style scoped>
.container-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.container-form__hint {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.container-form__color-label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	font-size: 14px;
}

.container-form__color {
	width: 48px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
}
</style>
