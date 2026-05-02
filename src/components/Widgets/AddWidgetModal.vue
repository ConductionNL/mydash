<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal
		v-if="show"
		size="normal"
		:name="modalTitle"
		@close="onCancel">
		<div
			class="add-widget-modal"
			role="dialog"
			:aria-labelledby="titleId"
			aria-modal="true">
			<h2 :id="titleId" class="add-widget-modal__title">
				{{ modalTitle }}
			</h2>

			<!-- Type selector: shown only in pure-create mode (no preselected
			     type, no editing widget). REQ-WDG-010. -->
			<div v-if="showTypeSelect" class="add-widget-modal__type">
				<label class="add-widget-modal__type-label" :for="typeSelectId">
					{{ t('mydash', 'Widget type') }}
				</label>
				<select
					:id="typeSelectId"
					v-model="state.type"
					class="add-widget-modal__type-select"
					@change="onTypeSwitch">
					<option
						v-for="type in availableTypes"
						:key="type"
						:value="type">
						{{ typeDisplayName(type) }}
					</option>
				</select>
			</div>

			<!-- Active per-type sub-form. Driven by `<component :is>` from the
			     widget registry; sub-forms expose `validate()` and either an
			     `assembledContent` getter or `@update:content` events. -->
			<div v-if="activeSubFormComponent" class="add-widget-modal__form">
				<component
					:is="activeSubFormComponent"
					ref="activeSubForm"
					:key="state.type"
					:editing-widget="state.editingWidget"
					:value="state.content"
					@update:content="onContentUpdate" />
			</div>
			<div v-else class="add-widget-modal__empty">
				{{ t('mydash', 'No widget types available') }}
			</div>

			<!-- Action buttons. REQ-WDG-013 close discipline: cancel emits
			     close, never submit. Submit button is disabled while the
			     active sub-form's validate() returns errors (REQ-WDG-012). -->
			<div class="add-widget-modal__actions">
				<NcButton type="tertiary" @click="onCancel">
					{{ t('mydash', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!isValid"
					:title="firstError || ''"
					@click="onSubmit">
					{{ submitLabel }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

import {
	listWidgetTypes,
	getWidgetTypeEntry,
} from '../../constants/widgetRegistry.js'
import { useWidgetForm } from '../../composables/useWidgetForm.js'

let titleIdCounter = 0
let selectIdCounter = 0

/**
 * AddWidgetModal — unified host for both "add a custom widget" and "edit a
 * custom widget" flows. The modal does NO API work itself; it emits
 * `submit({type, content})` for the parent to persist. Per-type fields live
 * in sub-form components owned by their respective widget capabilities and
 * registered in `widgetRegistry.js`.
 *
 * Props:
 *  - `show` (bool): toggles visibility. Going `false → true` triggers
 *    `resetForm()` (or `loadEditingWidget()` when `editingWidget` is set).
 *  - `preselectedType` (string|null): when set, the type `<select>` is
 *    hidden and the form opens directly on this type (toolbar deep-links).
 *  - `editingWidget` (object|null): when set, the modal opens in edit mode;
 *    the type select is hidden (placement type is immutable) and the
 *    sub-form is pre-filled from `editingWidget.content`. The action button
 *    reads `t('Save')` instead of `t('Add')` and the title reads
 *    `t('Edit Widget')` instead of `t('Add Widget')`.
 *
 * Emits:
 *  - `close`: cancel button, backdrop click, or Esc key.
 *  - `submit`: `{type, content}` payload for the parent to send to the API.
 */
export default {
	name: 'AddWidgetModal',

	components: {
		NcModal,
		NcButton,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		preselectedType: {
			type: String,
			default: null,
		},
		editingWidget: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'submit'],

	setup() {
		// One composable instance per modal mount. The composable owns the
		// type/content/editingWidget reactive state shared with sub-forms.
		const form = useWidgetForm()
		return { form }
	},

	data() {
		return {
			// Re-validation tick: the modal needs `isValid` to recompute
			// every time the active sub-form's input changes. Sub-forms
			// emit `update:content` on every keystroke; we bump this
			// counter in the handler so the computed re-runs.
			validationTick: 0,
			titleId: `add-widget-modal-title-${++titleIdCounter}`,
			typeSelectId: `add-widget-modal-type-${++selectIdCounter}`,
		}
	},

	computed: {
		state() {
			return this.form.state
		},

		/**
		 * Type keys the picker should offer. Filters out registry entries
		 * with no `form` component (i.e. types whose owning per-widget
		 * proposal hasn't shipped its sub-form yet). REQ-WDG-014.
		 *
		 * @return {string[]}
		 */
		availableTypes() {
			return listWidgetTypes()
		},

		/**
		 * Hide the type select in edit mode (placement type is immutable)
		 * or when the caller pre-selected a type.
		 *
		 * @return {boolean}
		 */
		showTypeSelect() {
			return !this.editingWidget && !this.preselectedType
		},

		/**
		 * The Vue component reference to mount via `<component :is>`.
		 * Returns `null` when the active type is unknown OR has no form
		 * registered yet (defensive — the user shouldn't be able to pick
		 * such a type via `availableTypes`, but a stale `preselectedType`
		 * could still drive us here).
		 *
		 * @return {object|null}
		 */
		activeSubFormComponent() {
			const entry = getWidgetTypeEntry(this.state.type)
			return entry?.form || null
		},

		/**
		 * Modal heading text — flips between Add/Edit based on whether
		 * an existing placement is being edited.
		 *
		 * @return {string}
		 */
		modalTitle() {
			return this.editingWidget
				? t('mydash', 'Edit Widget')
				: t('mydash', 'Add Widget')
		},

		/**
		 * Action button label — flips between Add/Save based on edit mode.
		 *
		 * @return {string}
		 */
		submitLabel() {
			return this.editingWidget ? t('mydash', 'Save') : t('mydash', 'Add')
		},

		/**
		 * Validation gate. `validationTick` keeps Vue's dependency tracker
		 * aware that this computed should re-run on every form input.
		 *
		 * @return {string[]}
		 */
		validationErrors() {
			// touch the tick so Vue tracks it as a dependency
			// eslint-disable-next-line no-unused-expressions
			this.validationTick
			return this.form.validate(this.$refs.activeSubForm)
		},

		isValid() {
			return this.validationErrors.length === 0
		},

		firstError() {
			const err = this.validationErrors[0]
			// Hide the internal "no active form" sentinel from the user UI.
			return err && err !== '__no-active-form__' ? err : ''
		},
	},

	watch: {
		show(isOpen) {
			if (isOpen) {
				this.openLifecycle()
			}
		},
		editingWidget: {
			immediate: false,
			handler(widget) {
				if (this.show && widget) {
					this.form.loadEditingWidget(widget)
				}
			},
		},
		preselectedType(type) {
			if (this.show && type && !this.editingWidget) {
				this.form.resetForm(type)
			}
		},
	},

	created() {
		// Seed state synchronously before the first render so the
		// `v-if="activeSubFormComponent"` path resolves to the right
		// sub-form on initial mount (otherwise the modal flashes the
		// "No widget types available" empty state for one tick).
		if (this.show) {
			this.openLifecycle()
		}
	},

	mounted() {
		document.addEventListener('keydown', this.onKeydown)
	},

	beforeDestroy() {
		document.removeEventListener('keydown', this.onKeydown)
	},

	methods: {
		t,

		/**
		 * Initialise form state when the modal opens. Edit mode pre-fills
		 * from `editingWidget`; create mode resets to the preselected type
		 * (toolbar invocation) or to the first available registered type.
		 */
		openLifecycle() {
			if (this.editingWidget) {
				this.form.loadEditingWidget(this.editingWidget)
				return
			}
			const initialType = this.preselectedType
				|| this.availableTypes[0]
				|| ''
			this.form.resetForm(initialType)
			this.validationTick++
		},

		/**
		 * Handle a `<select>` change: swap the active sub-form and reset
		 * its state to defaults. REQ-WDG-010 — switching type discards
		 * any in-progress field input (explicit trade-off, see proposal).
		 */
		onTypeSwitch() {
			this.form.resetForm(this.state.type)
			this.validationTick++
		},

		/**
		 * Sub-forms emit `update:content` on every keystroke. We mirror
		 * the payload into the composable so `assembleContent()` can fall
		 * back to it for sub-forms without an `assembledContent` getter,
		 * AND bump the validation tick so the action button enables/
		 * disables reactively on input. REQ-WDG-012.
		 *
		 * @param {object} content the sub-form's current content payload
		 */
		onContentUpdate(content) {
			this.state.content = { ...content }
			this.validationTick++
		},

		/**
		 * Cancel button / backdrop / NcModal `close` event. REQ-WDG-013 —
		 * close is non-destructive; it does not emit submit.
		 */
		onCancel() {
			this.$emit('close')
		},

		/**
		 * Esc-key listener. NcModal handles its own Esc dismissal in
		 * normal usage, but we register this fallback so the modal works
		 * even when NcModal's internal handler is suppressed by a parent
		 * (e.g. inside a focus-trap). REQ-WDG-013.
		 *
		 * @param {KeyboardEvent} event the keydown event
		 */
		onKeydown(event) {
			if (this.show && event.key === 'Escape') {
				this.$emit('close')
			}
		},

		/**
		 * Build the `{type, content}` payload via the composable's
		 * `assembleContent()` and emit it. The modal performs no API
		 * calls — the parent (Views.vue) does. REQ-WDG-010.
		 */
		onSubmit() {
			if (!this.isValid) {
				return
			}
			const payload = this.form.assembleContent(this.$refs.activeSubForm)
			this.$emit('submit', payload)
		},

		/**
		 * Look up the human-readable name for a registry type. Falls back
		 * to the type key itself when the registry entry is missing.
		 *
		 * @param {string} type the registry type key
		 * @return {string}
		 */
		typeDisplayName(type) {
			const entry = getWidgetTypeEntry(type)
			return entry?.displayName || type
		},
	},
}
</script>

<style scoped>
.add-widget-modal {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-height: 80vh;
	min-width: 320px;
}

.add-widget-modal__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.add-widget-modal__type {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.add-widget-modal__type-label {
	font-size: 14px;
	font-weight: 500;
}

.add-widget-modal__type-select {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.add-widget-modal__form {
	overflow-y: auto;
	flex: 1;
}

.add-widget-modal__empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.add-widget-modal__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}
</style>
