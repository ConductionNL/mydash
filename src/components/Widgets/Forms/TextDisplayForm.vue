<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="text-display-form">
		<NcSelect
			:value="modeOption"
			:options="modeOptions"
			:input-label="t('mydash', 'Content type')"
			:clearable="false"
			label="label"
			@input="onModeChange" />

		<template v-if="!tableMode">
			<NcSelect
				:value="contentMode"
				:options="contentModeOptions"
				:input-label="t('mydash', 'Mode')"
				:clearable="false"
				:reduce="option => option.value"
				label="label"
				class="text-display-form__mode"
				@input="updateField('contentMode', $event)" />

			<label class="text-display-form__field">
				{{ t('mydash', 'Text') }}
				<textarea
					:value="text"
					:placeholder="modePlaceholder"
					class="text-display-form__textarea"
					rows="4"
					required
					@input="updateField('text', $event.target.value)" />
			</label>

			<NcTextField
				:value="fontSize"
				:label="t('mydash', 'Font Size')"
				placeholder="14px"
				@update:value="updateField('fontSize', $event)" />

			<label class="text-display-form__color-label">
				{{ t('mydash', 'Text Color') }}
				<input
					type="color"
					:value="color || '#000000'"
					class="text-display-form__color"
					@input="updateField('color', $event.target.value)">
			</label>

			<label class="text-display-form__color-label">
				{{ t('mydash', 'Background Color') }}
				<input
					type="color"
					:value="backgroundColor || '#ffffff'"
					class="text-display-form__color"
					@input="updateField('backgroundColor', $event.target.value)">
			</label>

			<NcSelect
				:value="textAlign"
				:options="textAlignOptions"
				:input-label="t('mydash', 'Alignment')"
				:clearable="false"
				@input="updateField('textAlign', $event)" />
		</template>

		<template v-else>
			<TextTableEditor
				:value="tableData"
				@input="onTableDataChange" />
		</template>
	</div>
</template>

<script>
import { NcTextField, NcSelect } from '@conduction/nextcloud-vue'
import TextTableEditor from './TextTableEditor.vue'
import { emptyTable, validateTable } from '../../../utils/textTable.js'

const DEFAULT_CONTENT = Object.freeze({
	text: '',
	fontSize: '14px',
	color: '',
	backgroundColor: '',
	textAlign: 'left',
	// New widgets default to 'markdown' (REQ-TXMD-001 / REQ-TXMD-005);
	// existing widgets without the field render in legacy 'html' mode.
	contentMode: 'markdown',
	tableMode: false,
	tableData: null,
})

const VALID_CONTENT_MODES = Object.freeze(['html', 'markdown'])

/**
 * TextDisplayForm is the sub-form for AddWidgetModal when the user is
 * creating or editing a `text` widget placement.
 *
 * Exposes the controls described in REQ-TXT-004 (textarea, font size input,
 * two colour pickers, alignment select) and REQ-TXMD-004 (Mode toggle for
 * HTML / Markdown). Validation method `validate()` returns
 * `[t('mydash', 'Text is required')]` when text is empty or whitespace-only —
 * matching the AddWidgetModal sub-form contract.
 *
 * Switching modes never mutates the text content (REQ-TXMD-004 scenario
 * "Toggling mode preserves text content"); only the parsing branch in the
 * renderer changes on next render.
 *
 * REQ-TBLE-002: a top-level "Content type" picker switches between text
 * mode (the original controls + Markdown/HTML toggle) and table mode (a
 * `TextTableEditor` sub-component editing `content.tableData`). The legacy
 * `text` field is preserved across mode switches so toggling back doesn't
 * lose the user's markdown / plain text. `validate()` defers to
 * `validateTable()` from `utils/textTable.js` when `tableMode` is on.
 */
export default {
	name: 'TextDisplayForm',

	components: {
		NcTextField,
		NcSelect,
		TextTableEditor,
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
		// REQ-TXMD-004: existing widgets with no contentMode default to
		// 'html' to preserve their current rendering; new widgets — which
		// arrive via the registry default — get 'markdown'. The form
		// honours whatever mode is on the placement and only falls back
		// to 'html' for genuinely-legacy placements.
		const isEditingExisting = this.editingWidget != null
		const fallback = isEditingExisting ? 'html' : DEFAULT_CONTENT.contentMode
		const requested = initial.contentMode
		const contentMode = VALID_CONTENT_MODES.includes(requested)
			? requested
			: fallback
		return {
			text: initial.text ?? DEFAULT_CONTENT.text,
			fontSize: initial.fontSize ?? DEFAULT_CONTENT.fontSize,
			color: initial.color ?? DEFAULT_CONTENT.color,
			backgroundColor: initial.backgroundColor ?? DEFAULT_CONTENT.backgroundColor,
			textAlign: initial.textAlign ?? DEFAULT_CONTENT.textAlign,
			contentMode,
			tableMode: initial.tableMode === true,
			tableData: initial.tableData && typeof initial.tableData === 'object'
				? initial.tableData
				: null,
		}
	},

	computed: {
		/** @spec openspec/specs/text-display-widget/spec.md */
		textAlignOptions() {
			return ['left', 'center', 'right', 'justify']
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		contentModeOptions() {
			return [
				{ value: 'markdown', label: t('mydash', 'Markdown') },
				{ value: 'html', label: t('mydash', 'HTML') },
			]
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		modePlaceholder() {
			return this.contentMode === 'markdown'
				? t('mydash', 'Markdown — # heading, **bold**, *italic*, [link](url), - list')
				: t('mydash', 'HTML — <b>bold</b>, <i>italic</i>, <a href="…">link</a>')
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		modeOptions() {
			return [
				{ id: 'text', label: t('mydash', 'Text') },
				{ id: 'table', label: t('mydash', 'Table') },
			]
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		modeOption() {
			return this.modeOptions.find((o) => o.id === (this.tableMode ? 'table' : 'text'))
		},

		/** @spec openspec/specs/text-display-widget/spec.md */
		assembledContent() {
			return {
				text: this.text,
				fontSize: this.fontSize,
				color: this.color,
				backgroundColor: this.backgroundColor,
				textAlign: this.textAlign,
				contentMode: this.contentMode,
				tableMode: this.tableMode,
				tableData: this.tableData,
			}
		},
	},

	methods: {
		/**
		 * Set a field and notify parent.
		 *
		 * @param {string} field one of: text, fontSize, color, backgroundColor, textAlign, contentMode
		 * @param {string} value new value
		 */
		/** @spec openspec/specs/text-display-widget/spec.md */
		updateField(field, value) {
			if (field === 'contentMode' && !VALID_CONTENT_MODES.includes(value)) {
				// Ignore invalid mode writes — keeps the form aligned with
				// REQ-TXMD-005 ("invalid setting values are rejected").
				return
			}
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Switch between text and table content type. Initialises an empty
		 * 1×1 tableData on first switch to table mode (REQ-TBLE-002 minimal
		 * default). Preserves the original text so toggling back is lossless.
		 *
		 * @param {object} option the selected modeOptions item
		 */
		/** @spec openspec/specs/text-display-widget/spec.md */
		onModeChange(option) {
			const id = option?.id || 'text'
			this.tableMode = id === 'table'
			if (this.tableMode && !this.tableData) {
				this.tableData = emptyTable()
			}
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Receive the latest tableData from `TextTableEditor` and bubble up
		 * through the standard sub-form contract.
		 *
		 * @param {object} next the next tableData value
		 */
		/** @spec openspec/specs/text-display-widget/spec.md */
		onTableDataChange(next) {
			this.tableData = next
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Returns a list of error strings; empty array means valid.
		 *
		 * @return {string[]} validation errors
		 */
		/** @spec openspec/specs/text-display-widget/spec.md */
		validate() {
			if (this.tableMode) {
				return validateTable(this.tableData)
			}
			if (typeof this.text !== 'string' || this.text.trim() === '') {
				return [t('mydash', 'Text is required')]
			}
			return []
		},
	},
}
</script>

<style scoped>
.text-display-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.text-display-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 14px;
}

.text-display-form__textarea {
	width: 100%;
	min-height: 96px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	resize: vertical;
}

.text-display-form__color-label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	font-size: 14px;
}

.text-display-form__color {
	width: 48px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
}

.text-display-form__mode {
	width: 100%;
}
</style>
