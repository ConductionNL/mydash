<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="divider-form">
		<p class="divider-form__hint">
			{{ t('mydash', 'Pick a divider style to break dashboard sections into logical groups.') }}
		</p>

		<NcSelect
			:value="style"
			:options="styleOptions"
			:input-label="t('mydash', 'Style')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('style', $event)" />

		<!-- REQ-DIV-002 line config: color, thickness, line-style. -->
		<template v-if="style === 'line'">
			<label class="divider-form__color-label">
				{{ t('mydash', 'Line color') }}
				<input
					type="color"
					:value="lineColor || '#cccccc'"
					class="divider-form__color"
					@input="updateField('lineColor', $event.target.value)">
			</label>

			<NcTextField
				type="number"
				:value="String(lineThickness)"
				:label="t('mydash', 'Thickness (pixels)')"
				placeholder="1"
				:min="1"
				:max="8"
				@update:value="updateThickness($event)" />

			<NcSelect
				:value="lineStyle"
				:options="lineStyleOptions"
				:input-label="t('mydash', 'Line style')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateField('lineStyle', $event)" />
		</template>

		<!-- REQ-DIV-002 whitespace config: size preset only. -->
		<template v-if="style === 'whitespace'">
			<NcSelect
				:value="whitespaceSize"
				:options="whitespaceSizeOptions"
				:input-label="t('mydash', 'Spacing size')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateField('whitespaceSize', $event)" />
		</template>

		<!-- REQ-DIV-002 heading-break config: required text + optional color/style. -->
		<template v-if="style === 'heading-break'">
			<NcTextField
				:value="headingText"
				:label="t('mydash', 'Heading text')"
				:placeholder="t('mydash', 'Section heading')"
				required
				@update:value="updateField('headingText', $event)" />

			<label class="divider-form__color-label">
				{{ t('mydash', 'Line color') }}
				<input
					type="color"
					:value="lineColor || '#cccccc'"
					class="divider-form__color"
					@input="updateField('lineColor', $event.target.value)">
			</label>

			<NcSelect
				:value="lineStyle"
				:options="lineStyleOptions"
				:input-label="t('mydash', 'Line style')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateField('lineStyle', $event)" />
		</template>
	</div>
</template>

<script>
import { NcTextField, NcSelect } from '@conduction/nextcloud-vue'

const STYLES = Object.freeze({
	LINE: 'line',
	WHITESPACE: 'whitespace',
	HEADING_BREAK: 'heading-break',
})

const DEFAULT_CONTENT = Object.freeze({
	style: STYLES.LINE,
	lineColor: '',
	lineThickness: 1,
	lineStyle: 'solid',
	whitespaceSize: 'medium',
	headingText: '',
})

/**
 * DividerForm — minimal sub-form for the AddWidgetModal when the user is
 * creating or editing a `divider` placement (REQ-DIV-002).
 *
 * The form deliberately exposes ONLY divider-specific config — no name,
 * icon, click target, or other standard widget fields — keeping the
 * editor as light as the widget itself. Visible fields depend on the
 * selected style:
 *
 * - line       → lineColor, lineThickness (1..8), lineStyle (solid/dashed/dotted)
 * - whitespace → whitespaceSize (small/medium/large/xlarge)
 * - heading-break → headingText (required), lineColor (optional), lineStyle (optional)
 *
 * `validate()` returns one error when `style === 'heading-break'` and
 * `headingText` is empty/whitespace, otherwise an empty array.
 */
export default {
	name: 'DividerForm',

	components: {
		NcTextField,
		NcSelect,
	},

	props: {
		/**
		 * The placement being edited, or `null` in create mode. Pre-fills
		 * every control from `editingWidget.content` when present.
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
			style: initial.style ?? DEFAULT_CONTENT.style,
			lineColor: initial.lineColor ?? DEFAULT_CONTENT.lineColor,
			lineThickness: typeof initial.lineThickness === 'number'
				? initial.lineThickness
				: DEFAULT_CONTENT.lineThickness,
			lineStyle: initial.lineStyle ?? DEFAULT_CONTENT.lineStyle,
			whitespaceSize: initial.whitespaceSize ?? DEFAULT_CONTENT.whitespaceSize,
			headingText: initial.headingText ?? DEFAULT_CONTENT.headingText,
		}
	},

	computed: {
		styleOptions() {
			return [
				{ value: STYLES.LINE, label: t('mydash', 'Horizontal line') },
				{ value: STYLES.WHITESPACE, label: t('mydash', 'Whitespace') },
				{ value: STYLES.HEADING_BREAK, label: t('mydash', 'Heading with lines') },
			]
		},

		lineStyleOptions() {
			return [
				{ value: 'solid', label: t('mydash', 'Solid') },
				{ value: 'dashed', label: t('mydash', 'Dashed') },
				{ value: 'dotted', label: t('mydash', 'Dotted') },
			]
		},

		whitespaceSizeOptions() {
			return [
				{ value: 'small', label: t('mydash', 'Small (16px)') },
				{ value: 'medium', label: t('mydash', 'Medium (32px)') },
				{ value: 'large', label: t('mydash', 'Large (64px)') },
				{ value: 'xlarge', label: t('mydash', 'Extra Large (128px)') },
			]
		},

		assembledContent() {
			return {
				style: this.style,
				lineColor: this.lineColor,
				lineThickness: this.lineThickness,
				lineStyle: this.lineStyle,
				whitespaceSize: this.whitespaceSize,
				headingText: this.headingText,
			}
		},
	},

	methods: {
		/**
		 * Set a field and notify parent via `update:content`.
		 *
		 * @param {string} field one of: style, lineColor, lineThickness,
		 *                        lineStyle, whitespaceSize, headingText
		 * @param {string|number} value new value
		 */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Coerce the thickness <input type="number"> string into a
		 * clamped integer (1..8) before pushing it through updateField.
		 *
		 * @param {string} raw raw value from the NcTextField number input
		 */
		updateThickness(raw) {
			const parsed = Number(raw)
			const clamped = Number.isFinite(parsed)
				? Math.min(8, Math.max(1, Math.floor(parsed)))
				: 1
			this.updateField('lineThickness', clamped)
		},

		/**
		 * Returns a list of error strings; empty array means valid.
		 * Heading-break requires non-empty `headingText`.
		 *
		 * @return {string[]} validation errors
		 */
		validate() {
			if (this.style === STYLES.HEADING_BREAK) {
				if (typeof this.headingText !== 'string' || this.headingText.trim() === '') {
					return [t('mydash', 'Heading text is required')]
				}
			}
			return []
		},
	},
}
</script>

<style scoped>
.divider-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.divider-form__hint {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.divider-form__color-label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	font-size: 14px;
}

.divider-form__color {
	width: 48px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
}
</style>
