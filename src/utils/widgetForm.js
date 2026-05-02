/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { widgetRegistry } from '../constants/widgetRegistry.js'

/**
 * Return a fresh form state (defaults) for the given widget type.
 * The returned object always contains a `type` field.
 *
 * @param {string} type the widget type discriminator (e.g. 'text')
 * @return {object} a shallow-cloned defaults object with `type` set
 */
export function resetForm(type) {
	const entry = widgetRegistry[type]
	const defaults = entry ? { ...entry.defaults } : {}
	return { type, ...defaults }
}

/**
 * Deep-merge `editingWidget.content` into `form` for the given editing widget.
 * Returns a new plain object — does NOT mutate `form` in place.
 *
 * @param {object} form the current form state
 * @param {object} editingWidget the widget being edited (must have `.type` and `.content`)
 * @return {object} merged form state
 */
export function loadEditingWidget(form, editingWidget) {
	if (!editingWidget) {
		return { ...form }
	}

	const type = editingWidget.type || form.type
	const content = editingWidget.content || {}

	// Start from registry defaults so we always have a complete shape, then
	// overlay the editing widget's persisted content.
	const entry = widgetRegistry[type]
	const base = entry ? { ...entry.defaults } : {}

	return {
		type,
		...base,
		...content,
	}
}

/**
 * Assemble the submit payload from the form, keeping only the fields that
 * belong to the selected type (strips cross-type leakage).
 *
 * @param {string} type the currently selected widget type
 * @param {object} form the current raw form state
 * @return {{type: string, content: object}} the clean submit payload
 */
export function assembleContent(type, form) {
	const entry = widgetRegistry[type]
	if (!entry) {
		// Unknown type — return empty content rather than leaking everything.
		return { type, content: {} }
	}

	// Only keep keys that appear in this type's defaults.
	const allowedKeys = Object.keys(entry.defaults)
	const content = {}
	for (const key of allowedKeys) {
		if (Object.prototype.hasOwnProperty.call(form, key)) {
			content[key] = form[key]
		}
	}

	return { type, content }
}
