<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	LinkButtonForm — sub-form for AddWidgetModal (REQ-LBN-006)

	Exposes six fields:
	  label         — required text
	  actionType    — select: external | internal | createFile
	  url           — required; placeholder swaps with actionType
	  icon          — optional via IconPicker
	  backgroundColor — optional colour picker
	  textColor       — optional colour picker

	Emits `update:content` on every change so the parent modal can track
	the live content blob. Exposes `validate()` which returns a non-empty
	error array when label or url is missing.

	Pre-fills from `editingWidget.content` on mount.
-->

<template>
	<div class="link-button-form">
		<!-- Label (required) -->
		<div class="link-button-form__field">
			<label :for="labelInputId" class="link-button-form__label">
				{{ tt('Label') }} <span aria-hidden="true">*</span>
			</label>
			<input
				:id="labelInputId"
				v-model="form.label"
				type="text"
				class="link-button-form__input"
				@input="emitUpdate">
		</div>

		<!-- Action type select -->
		<div class="link-button-form__field">
			<label :for="actionTypeSelectId" class="link-button-form__label">
				{{ tt('Action Type') }}
			</label>
			<select
				:id="actionTypeSelectId"
				v-model="form.actionType"
				class="link-button-form__select"
				@change="emitUpdate">
				<option value="external">
					{{ tt('External Link') }}
				</option>
				<option value="internal">
					{{ tt('Internal Function') }}
				</option>
				<option value="createFile">
					{{ tt('Create File') }}
				</option>
			</select>
		</div>

		<!-- URL / action-ID / extension (required) -->
		<div class="link-button-form__field">
			<label :for="urlInputId" class="link-button-form__label">
				{{ urlLabel }} <span aria-hidden="true">*</span>
			</label>
			<input
				:id="urlInputId"
				v-model="form.url"
				type="text"
				class="link-button-form__input"
				:placeholder="urlPlaceholder"
				@input="emitUpdate">
		</div>

		<!-- Icon picker (optional) -->
		<div class="link-button-form__field">
			<label class="link-button-form__label">
				{{ tt('Upload Icon (optional)') }}
			</label>
			<IconPicker
				:value="form.icon"
				@input="onIconChange" />
		</div>

		<!-- Background colour -->
		<div class="link-button-form__field link-button-form__field--row">
			<label :for="bgColorInputId" class="link-button-form__label">
				{{ tt('Background Color') }}
			</label>
			<input
				:id="bgColorInputId"
				v-model="form.backgroundColor"
				type="color"
				class="link-button-form__color"
				@input="emitUpdate">
		</div>

		<!-- Text colour -->
		<div class="link-button-form__field link-button-form__field--row">
			<label :for="textColorInputId" class="link-button-form__label">
				{{ tt('Text Color') }}
			</label>
			<input
				:id="textColorInputId"
				v-model="form.textColor"
				type="color"
				class="link-button-form__color"
				@input="emitUpdate">
		</div>

		<!-- Validation error summary -->
		<div
			v-if="errors.length > 0"
			class="link-button-form__errors"
			role="alert">
			<p
				v-for="(err, i) in errors"
				:key="i"
				class="link-button-form__error">
				{{ err }}
			</p>
		</div>
	</div>
</template>

<script>
import IconPicker from '../../Dashboard/IconPicker.vue'

const DEFAULTS = {
	label: '',
	actionType: 'external',
	url: '',
	icon: '',
	backgroundColor: '',
	textColor: '',
}

let uidCounter = 0

export default {
	name: 'LinkButtonForm',

	components: {
		IconPicker,
	},

	props: {
		/**
		 * The widget being edited, or null for a new widget.
		 * Pre-fills form when provided.
		 */
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
			errors: [],
		}
	},

	computed: {
		labelInputId() {
			return `lbf-label-${this.uid}`
		},

		actionTypeSelectId() {
			return `lbf-action-${this.uid}`
		},

		urlInputId() {
			return `lbf-url-${this.uid}`
		},

		bgColorInputId() {
			return `lbf-bg-${this.uid}`
		},

		textColorInputId() {
			return `lbf-tc-${this.uid}`
		},

		/** Label above the URL/ID/extension field, swaps with actionType. */
		urlLabel() {
			if (this.form.actionType === 'internal') {
				return this.tt('Internal Function')
			}

			if (this.form.actionType === 'createFile') {
				return this.tt('Create File')
			}

			return 'URL'
		},

		/** Placeholder for the url field, swaps with actionType (REQ-LBN-006). */
		urlPlaceholder() {
			if (this.form.actionType === 'internal') {
				return 'action-id'
			}

			if (this.form.actionType === 'createFile') {
				return 'docx'
			}

			return 'https://...'
		},
	},

	mounted() {
		const content = this.editingWidget?.content || {}
		this.form = {
			label: typeof content.label === 'string' ? content.label : DEFAULTS.label,
			actionType: ['external', 'internal', 'createFile'].includes(content.actionType)
				? content.actionType
				: DEFAULTS.actionType,
			url: typeof content.url === 'string' ? content.url : DEFAULTS.url,
			icon: typeof content.icon === 'string' ? content.icon : DEFAULTS.icon,
			backgroundColor: typeof content.backgroundColor === 'string'
				? content.backgroundColor
				: DEFAULTS.backgroundColor,
			textColor: typeof content.textColor === 'string'
				? content.textColor
				: DEFAULTS.textColor,
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

		onIconChange(value) {
			this.form.icon = value || ''
			this.emitUpdate()
		},

		/**
		 * Validate the form. Returns an array of localised error strings.
		 * Empty array means the form is valid (REQ-LBN-006).
		 *
		 * @return {string[]} Array of error messages.
		 */
		validate() {
			const errs = []

			if (!this.form.label || this.form.label.trim() === '') {
				errs.push(this.tt('Label text is required'))
			}

			if (!this.form.url || this.form.url.trim() === '') {
				errs.push(this.tt('Please enter a file name'))
			}

			this.errors = errs
			return errs
		},
	},
}
</script>

<style scoped>
.link-button-form {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.link-button-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.link-button-form__field--row {
	flex-direction: row;
	align-items: center;
	gap: 8px;
}

.link-button-form__label {
	font-weight: bold;
	font-size: 13px;
}

.link-button-form__input,
.link-button-form__select {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 14px;
	box-sizing: border-box;
}

.link-button-form__color {
	width: 40px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	cursor: pointer;
}

.link-button-form__errors {
	margin-top: 4px;
}

.link-button-form__error {
	margin: 0 0 4px;
	padding: 4px 8px;
	font-size: 12px;
	color: var(--color-error);
	background-color: rgba(192, 0, 0, 0.1);
	border-radius: 2px;
}
</style>
