<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="text-display-form">
		<div class="text-display-form__field">
			<label :for="textareaId" class="text-display-form__label">
				{{ tt('Text') }}
			</label>
			<textarea
				:id="textareaId"
				v-model="form.text"
				class="text-display-form__textarea"
				rows="4"
				@input="emitUpdate" />
		</div>

		<div class="text-display-form__field">
			<label :for="fontSizeId" class="text-display-form__label">
				{{ tt('Font Size') }}
			</label>
			<input
				:id="fontSizeId"
				v-model="form.fontSize"
				type="text"
				class="text-display-form__input"
				placeholder="14px"
				@input="emitUpdate">
		</div>

		<div class="text-display-form__field">
			<label :for="colorId" class="text-display-form__label">
				{{ tt('Text Color') }}
			</label>
			<input
				:id="colorId"
				v-model="form.color"
				type="color"
				class="text-display-form__color"
				@input="emitUpdate">
		</div>

		<div class="text-display-form__field">
			<label :for="bgColorId" class="text-display-form__label">
				{{ tt('Background Color') }}
			</label>
			<input
				:id="bgColorId"
				v-model="form.backgroundColor"
				type="color"
				class="text-display-form__color"
				@input="emitUpdate">
		</div>

		<div class="text-display-form__field">
			<label :for="alignmentId" class="text-display-form__label">
				{{ tt('Alignment') }}
			</label>
			<select
				:id="alignmentId"
				v-model="form.textAlign"
				class="text-display-form__select"
				@change="emitUpdate">
				<option value="left">
					{{ tt('Left') }}
				</option>
				<option value="center">
					{{ tt('Center') }}
				</option>
				<option value="right">
					{{ tt('Right') }}
				</option>
				<option value="justify">
					{{ tt('Justify') }}
				</option>
			</select>
		</div>
	</div>
</template>

<script>
/**
 * TextDisplayForm
 *
 * Sub-form for AddWidgetModal that authors the persisted `content` blob for a
 * `text` widget. Pre-fills from `editingWidget.content` on mount, emits
 * `update:content` reactively, and exposes a `validate()` method matching the
 * existing modal contract (REQ-TXT-004).
 */

const DEFAULTS = {
	text: '',
	fontSize: '14px',
	color: '',
	backgroundColor: '',
	textAlign: 'left',
}

let uidCounter = 0

export default {
	name: 'TextDisplayForm',

	props: {
		editingWidget: {
			type: Object,
			default: null,
		},
	},

	emits: ['update:content'],

	data() {
		return {
			uid: ++uidCounter,
			form: { ...DEFAULTS },
		}
	},

	computed: {
		textareaId() {
			return `text-display-form-text-${this.uid}`
		},
		fontSizeId() {
			return `text-display-form-fontsize-${this.uid}`
		},
		colorId() {
			return `text-display-form-color-${this.uid}`
		},
		bgColorId() {
			return `text-display-form-bgcolor-${this.uid}`
		},
		alignmentId() {
			return `text-display-form-alignment-${this.uid}`
		},
	},

	mounted() {
		const content = this.editingWidget?.content || {}
		this.form = {
			text: typeof content.text === 'string' ? content.text : DEFAULTS.text,
			fontSize: typeof content.fontSize === 'string' && content.fontSize !== ''
				? content.fontSize
				: DEFAULTS.fontSize,
			color: typeof content.color === 'string' ? content.color : DEFAULTS.color,
			backgroundColor: typeof content.backgroundColor === 'string'
				? content.backgroundColor
				: DEFAULTS.backgroundColor,
			textAlign: ['left', 'center', 'right', 'justify'].includes(content.textAlign)
				? content.textAlign
				: DEFAULTS.textAlign,
		}
	},

	methods: {
		tt(key) {
			if (typeof t === 'function') {
				return t('mydash', key)
			}
			return key
		},

		emitUpdate() {
			this.$emit('update:content', { ...this.form })
		},

		/**
		 * Validate the form. Returns an array of localised error strings.
		 * Empty array means the form is valid (REQ-TXT-004).
		 *
		 * @return {string[]} array of error messages
		 */
		validate() {
			if (!this.form.text || this.form.text.trim() === '') {
				return [this.tt('Text is required')]
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
}

.text-display-form__label {
	font-weight: bold;
}

.text-display-form__textarea {
	width: 100%;
	font-family: inherit;
	resize: vertical;
}

.text-display-form__input,
.text-display-form__select {
	width: 100%;
}

.text-display-form__color {
	width: 64px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
}
</style>
