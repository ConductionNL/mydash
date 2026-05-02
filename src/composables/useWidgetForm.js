/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * useWidgetForm — small Vue 2 composable shared by the AddWidgetModal host
 * and the per-type sub-form components. It owns the four state-management
 * helpers the modal needs while keeping the host template free of form
 * bookkeeping noise:
 *
 * - `resetForm(type)`: drop in a fresh copy of the registry's `defaultContent`
 *   for `type`, used when the user opens the modal in create mode or
 *   switches the type select mid-edit (REQ-WDG-010 — type switch resets
 *   form state, no cross-type leakage).
 * - `loadEditingWidget(widget)`: pre-fill state from an existing placement
 *   when the modal opens in edit mode (REQ-WDG-010 — edit mode pre-fills).
 * - `validate(activeSubFormRef)`: forwards to the active sub-form's
 *   `validate()` method (REQ-WDG-012). The composable does not own
 *   per-type validation logic — sub-forms do.
 * - `assembleContent(activeSubFormRef)`: ask the active sub-form for its
 *   current content payload, falling back to the composable's own state
 *   for sub-forms that drive the modal via `update:content` rather than
 *   exposing an `assembledContent` getter.
 *
 * The composable returns plain reactive refs (Vue 2 via `@vue/composition-api`
 * is NOT in this codebase, so we use Vue 2's `Vue.observable` for shared
 * reactivity — the AddWidgetModal binds `content` into its template via the
 * normal data() route).
 */

import Vue from 'vue'
import { getDefaultContent } from '../constants/widgetRegistry.js'

/**
 * Create a widget-form state container.
 *
 * @return {{
 *   state: {type: string, content: object, editingWidget: (object|null)},
 *   resetForm: (type: string) => void,
 *   loadEditingWidget: (widget: object) => void,
 *   validate: (subFormRef: any) => string[],
 *   assembleContent: (subFormRef: any) => {type: string, content: object},
 * }}
 */
export function useWidgetForm() {
	// Vue.observable wraps the object so any component that touches
	// state.content / state.type re-renders on change, the same way a
	// data() field would. We avoid pulling in @vue/composition-api just
	// for one ref.
	const state = Vue.observable({
		type: '',
		content: {},
		editingWidget: null,
	})

	/**
	 * Drop the form back to defaults for `type`. Called when the modal
	 * opens in create mode and on every type-switch (no cross-type leakage).
	 *
	 * @param {string} type registry key for the widget type
	 */
	function resetForm(type) {
		state.type = type
		state.content = getDefaultContent(type)
		state.editingWidget = null
	}

	/**
	 * Pre-fill state from an existing placement so the modal opens in edit
	 * mode with all fields populated.
	 *
	 * @param {object} widget the placement being edited; must expose `type` and `content`
	 */
	function loadEditingWidget(widget) {
		if (!widget) {
			return
		}
		state.type = widget.type || ''
		// Merge widget.content over the registry defaults so any field the
		// registry adds in a future version gets a sensible default even
		// when the persisted blob is missing it.
		const defaults = getDefaultContent(state.type)
		state.content = { ...defaults, ...(widget.content || {}) }
		state.editingWidget = widget
	}

	/**
	 * Ask the currently-mounted sub-form whether its inputs are valid.
	 * Returns an empty array when valid (REQ-WDG-012 contract). When the
	 * sub-form ref is missing or doesn't expose a `validate()` method we
	 * default to "invalid" so the modal stays in a safe state during
	 * transient swaps (e.g. between type-switch render and `nextTick`).
	 *
	 * @param {{validate?: () => string[]}|null|undefined} subFormRef the active sub-form Vue instance (via `<component :is ref="...">`)
	 * @return {string[]} validation error messages, empty when valid
	 */
	function validate(subFormRef) {
		if (!subFormRef || typeof subFormRef.validate !== 'function') {
			return ['__no-active-form__']
		}
		const errors = subFormRef.validate()
		return Array.isArray(errors) ? errors : []
	}

	/**
	 * Build the `{type, content}` payload the modal emits via `submit`.
	 *
	 * Sub-forms expose their content one of two ways:
	 *  1. Imperatively via an `assembledContent` computed (LabelForm pattern).
	 *  2. Reactively via `@update:content` events into `state.content`.
	 *
	 * We prefer (1) when present and fall back to (2) so per-widget proposals
	 * can pick whichever style fits their fields better.
	 *
	 * @param {{assembledContent?: object}|null|undefined} subFormRef the active sub-form Vue instance
	 * @return {{type: string, content: object}} payload for the `submit` event
	 */
	function assembleContent(subFormRef) {
		const content = subFormRef && subFormRef.assembledContent
			? { ...subFormRef.assembledContent }
			: { ...state.content }
		return { type: state.type, content }
	}

	return {
		state,
		resetForm,
		loadEditingWidget,
		validate,
		assembleContent,
	}
}
