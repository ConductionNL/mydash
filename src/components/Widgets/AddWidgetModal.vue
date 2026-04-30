<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		v-if="show"
		class="add-widget-modal__backdrop"
		role="presentation"
		@click.self="handleBackdropClick">
		<div
			:id="modalId"
			class="add-widget-modal"
			role="dialog"
			:aria-labelledby="titleId"
			:aria-modal="true">
			<!-- Header -->
			<div class="add-widget-modal__header">
				<h2 :id="titleId" class="add-widget-modal__title">
					{{ editMode ? tt('Edit Widget') : tt('Add Widget') }}
				</h2>
				<button
					class="add-widget-modal__close"
					:aria-label="tt('Close')"
					@click="handleClose">
					&times;
				</button>
			</div>

			<!-- Body -->
			<div class="add-widget-modal__body">
				<!-- Type selector — hidden in edit mode and when preselectedType is set -->
				<div v-if="showTypeSelector" class="add-widget-modal__field">
					<label :for="typeSelectorId" class="add-widget-modal__label">
						{{ tt('Type') }}
					</label>
					<select
						:id="typeSelectorId"
						v-model="activeType"
						class="add-widget-modal__select"
						@change="onTypeChange">
						<option
							v-for="(entry, typeKey) in widgetRegistry"
							:key="typeKey"
							:value="typeKey">
							{{ entry.label }}
						</option>
					</select>
				</div>

				<!-- Per-type sub-form -->
				<component
					:is="activeFormComponent"
					v-if="activeFormComponent"
					ref="activeFormRef"
					:editing-widget="editMode ? editingWidget : null"
					@update:content="onContentUpdate" />
			</div>

			<!-- Footer / action buttons -->
			<div class="add-widget-modal__footer">
				<button
					class="add-widget-modal__btn add-widget-modal__btn--secondary"
					@click="handleClose">
					{{ tt('Cancel') }}
				</button>
				<button
					class="add-widget-modal__btn add-widget-modal__btn--primary"
					:disabled="!isValid"
					:title="firstValidationError"
					@click="handleSubmit">
					{{ editMode ? tt('Save') : tt('Add') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
import { widgetRegistry } from '../../constants/widgetRegistry.js'
import { resetForm, loadEditingWidget, assembleContent } from '../../utils/widgetForm.js'

let uidCounter = 0

export default {
	name: 'AddWidgetModal',

	props: {
		/**
		 * Controls modal visibility.
		 */
		show: {
			type: Boolean,
			default: false,
		},

		/**
		 * System Nextcloud widgets list (passed to nc-widget form if needed).
		 */
		widgets: {
			type: Array,
			default: () => [],
		},

		/**
		 * When set, the type selector is hidden and this type is pre-selected.
		 */
		preselectedType: {
			type: String,
			default: null,
		},

		/**
		 * When set, the modal opens in edit mode pre-filled from this widget.
		 */
		editingWidget: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'submit'],

	data() {
		const uid = ++uidCounter
		const firstType = Object.keys(widgetRegistry)[0] || 'text'
		return {
			uid,
			widgetRegistry,
			activeType: firstType,
			// Reactive snapshot of the current form state (updated via onContentUpdate).
			formSnapshot: {},
			// Validation errors populated by revalidate() — kept reactive.
			formErrors: [''],
		}
	},

	computed: {
		modalId() {
			return `add-widget-modal-${this.uid}`
		},

		titleId() {
			return `add-widget-modal-title-${this.uid}`
		},

		typeSelectorId() {
			return `add-widget-modal-type-${this.uid}`
		},

		editMode() {
			return Boolean(this.editingWidget)
		},

		showTypeSelector() {
			return !this.preselectedType && !this.editMode
		},

		activeRegistryEntry() {
			return widgetRegistry[this.activeType] || null
		},

		activeFormComponent() {
			return this.activeRegistryEntry ? this.activeRegistryEntry.form : null
		},

		/**
		 * Submit is allowed only when formErrors is empty.
		 *
		 * @return {boolean}
		 */
		isValid() {
			return this.formErrors.length === 0
		},

		firstValidationError() {
			return this.formErrors[0] || ''
		},
	},

	watch: {
		/**
		 * React to show changes: reset form on open; restore editing state if needed.
		 *
		 * @param {boolean} newVal new visibility value
		 * @param {boolean} oldVal previous visibility value
		 */
		show(newVal, oldVal) {
			if (newVal && !oldVal) {
				this.initModal()
			}
		},

		/**
		 * React to editingWidget changes when modal is already open.
		 *
		 * @param {object|null} newVal updated editing widget value
		 */
		editingWidget(newVal) {
			if (this.show && newVal) {
				this.initModal()
			}
		},
	},

	mounted() {
		document.addEventListener('keydown', this.handleKeydown)
		if (this.show) {
			this.initModal()
		}
	},

	beforeDestroy() {
		document.removeEventListener('keydown', this.handleKeydown)
	},

	methods: {
		tt(key) {
			if (typeof t === 'function') {
				return t('mydash', key)
			}
			return key
		},

		/**
		 * Initialise modal state on open / re-open.
		 * Chooses type, then resets or pre-fills form.
		 */
		initModal() {
			if (this.editMode) {
				this.activeType = this.editingWidget.type || Object.keys(widgetRegistry)[0]
			} else if (this.preselectedType) {
				this.activeType = this.preselectedType
			} else {
				this.activeType = Object.keys(widgetRegistry)[0] || 'text'
			}

			// Reset form snapshot.
			this.formSnapshot = resetForm(this.activeType)

			if (this.editMode) {
				this.formSnapshot = loadEditingWidget(this.formSnapshot, this.editingWidget)
			}

			// Reset validation (start blocked until sub-form validates after mount).
			this.formErrors = ['']

			// After the sub-form mounts, do an initial validation pass.
			this.$nextTick(() => {
				this.revalidate()
			})
		},

		/**
		 * Called when the user changes the type selector.
		 * Resets form state to prevent cross-type field leakage.
		 */
		onTypeChange() {
			this.formSnapshot = resetForm(this.activeType)
			this.formErrors = ['']
			this.$nextTick(() => {
				this.revalidate()
			})
		},

		/**
		 * Called by the sub-form whenever its content changes.
		 * Updates the snapshot and triggers validation.
		 *
		 * @param {object} content updated content from sub-form
		 */
		onContentUpdate(content) {
			this.formSnapshot = { type: this.activeType, ...content }
			this.revalidate()
		},

		/**
		 * Call validate() on the active sub-form ref and store results in
		 * reactive `formErrors` so that `isValid` computed re-evaluates.
		 */
		revalidate() {
			const subForm = this.$refs.activeFormRef
			if (!subForm || typeof subForm.validate !== 'function') {
				// No validate method — treat as valid.
				this.formErrors = []
				return
			}
			const errors = subForm.validate()
			this.formErrors = Array.isArray(errors) ? errors : []
		},

		handleBackdropClick() {
			this.handleClose()
		},

		/**
		 * Global keydown listener for Escape while modal is visible.
		 *
		 * @param {KeyboardEvent} event the keyboard event
		 */
		handleKeydown(event) {
			if (this.show && event.key === 'Escape') {
				this.handleClose()
			}
		},

		handleClose() {
			this.$emit('close')
		},

		handleSubmit() {
			// Re-validate before submitting as a safety guard.
			this.revalidate()
			if (!this.isValid) {
				return
			}

			const payload = assembleContent(this.activeType, this.formSnapshot)
			this.$emit('submit', payload)
		},
	},
}
</script>

<style scoped>
.add-widget-modal__backdrop {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 2000;
}

.add-widget-modal {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
	width: min(480px, calc(100vw - 32px));
	max-height: calc(100vh - 64px);
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.add-widget-modal__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 20px 24px 16px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.add-widget-modal__title {
	font-size: 18px;
	font-weight: 600;
	margin: 0;
}

.add-widget-modal__close {
	background: none;
	border: none;
	font-size: 24px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	padding: 0 4px;
	line-height: 1;
}

.add-widget-modal__close:hover {
	color: var(--color-main-text);
}

.add-widget-modal__body {
	flex: 1;
	overflow-y: auto;
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.add-widget-modal__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.add-widget-modal__label {
	font-weight: bold;
	font-size: 14px;
}

.add-widget-modal__select {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.add-widget-modal__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding: 16px 24px 20px;
	border-top: 1px solid var(--color-border);
	flex-shrink: 0;
}

.add-widget-modal__btn {
	padding: 8px 16px;
	border-radius: var(--border-radius);
	border: 1px solid transparent;
	cursor: pointer;
	font-size: 14px;
	font-weight: 500;
	transition: background var(--animation-quick) ease;
}

.add-widget-modal__btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.add-widget-modal__btn--secondary {
	background: var(--color-background-hover);
	border-color: var(--color-border);
	color: var(--color-main-text);
}

.add-widget-modal__btn--secondary:hover:not(:disabled) {
	background: var(--color-border);
}

.add-widget-modal__btn--primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.add-widget-modal__btn--primary:hover:not(:disabled) {
	background: var(--color-primary-element-hover);
}
</style>
