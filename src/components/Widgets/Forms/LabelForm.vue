<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="label-form">
		<div class="label-form__field">
			<label :for="textId" class="label-form__label">
				{{ tt('Text') }}
			</label>
			<input
				:id="textId"
				v-model="form.text"
				type="text"
				class="label-form__input"
				@input="emitUpdate">
		</div>

		<div class="label-form__field">
			<label :for="fontSizeId" class="label-form__label">
				{{ tt('Font Size') }}
			</label>
			<input
				:id="fontSizeId"
				v-model="form.fontSize"
				type="text"
				class="label-form__input"
				placeholder="16px"
				@input="emitUpdate">
		</div>

		<div class="label-form__field">
			<label :for="colorId" class="label-form__label">
				{{ tt('Text Color') }}
			</label>
			<input
				:id="colorId"
				v-model="form.color"
				type="color"
				class="label-form__color"
				@input="emitUpdate">
		</div>

		<div class="label-form__field">
			<label :for="bgColorId" class="label-form__label">
				{{ tt('Background Color') }}
			</label>
			<input
				:id="bgColorId"
				v-model="form.backgroundColor"
				type="color"
				class="label-form__color"
				@input="emitUpdate">
		</div>

		<div class="label-form__field">
			<label :for="fontWeightId" class="label-form__label">
				{{ tt('Font Weight') }}
			</label>
			<select
				:id="fontWeightId"
				v-model="form.fontWeight"
				class="label-form__select"
				@change="emitUpdate">
				<option value="normal">
					{{ tt('Normal') }}
				</option>
				<option value="bold">
					{{ tt('Bold') }}
				</option>
				<option value="600">
					600
				</option>
				<option value="700">
					700
				</option>
				<option value="800">
					800
				</option>
			</select>
		</div>

		<div class="label-form__field">
			<label :for="alignmentId" class="label-form__label">
				{{ tt('Alignment') }}
			</label>
			<select
				:id="alignmentId"
				v-model="form.textAlign"
				class="label-form__select"
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
			</select>
		</div>
	</div>
</template>

<script>
/**
 * LabelForm
 *
 * Sub-form for AddWidgetModal that authors the persisted `content` blob for a
 * `label` widget. Pre-fills from `editingWidget.content` on mount, emits
 * `update:content` reactively, and exposes a `validate()` method requiring
 * non-empty trimmed text per REQ-LBL-005.
 */

const ALLOWED_FONT_WEIGHT = ['normal', 'bold', '600', '700', '800']
const ALLOWED_TEXT_ALIGN = ['left', 'center', 'right']

const DEFAULTS = {
	text: '',
	fontSize: '16px',
	color: '',
	backgroundColor: '',
	fontWeight: 'bold',
	textAlign: 'center',
}

let uidCounter = 0

export default {
	name: 'LabelForm',

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
		textId() {
			return `label-form-text-${this.uid}`
		},
		fontSizeId() {
			return `label-form-fontsize-${this.uid}`
		},
		colorId() {
			return `label-form-color-${this.uid}`
		},
		bgColorId() {
			return `label-form-bgcolor-${this.uid}`
		},
		fontWeightId() {
			return `label-form-fontweight-${this.uid}`
		},
		alignmentId() {
			return `label-form-alignment-${this.uid}`
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
			fontWeight: ALLOWED_FONT_WEIGHT.includes(String(content.fontWeight))
				? String(content.fontWeight)
				: DEFAULTS.fontWeight,
			textAlign: ALLOWED_TEXT_ALIGN.includes(content.textAlign)
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
		 * Empty array means the form is valid (REQ-LBL-005).
		 *
		 * @return {string[]} array of error messages
		 */
		validate() {
			if (!this.form.text || this.form.text.trim() === '') {
				return [this.tt('Label text is required')]
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

.label-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.label-form__label {
	font-weight: bold;
}

.label-form__input,
.label-form__select {
	width: 100%;
}

.label-form__color {
	width: 64px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
}
</style>
