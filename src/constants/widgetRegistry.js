/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Widget registry — single source of truth for "what custom widget types
 * exist" on top of the Nextcloud-discovered widget set.
 *
 * Each entry maps a `type` string (the value persisted in
 * `oc_mydash_widget_placements`) to a `{renderer, form, defaultContent,
 * displayName, icon}` descriptor. The Add Widget modal consults this registry
 * to render the type picker and the per-type sub-form, and the dashboard
 * grid uses it to pick the right renderer for a placement.
 *
 * Adding a new widget type means adding an entry here plus the matching
 * Renderer + Form Vue components — no other wiring is required.
 *
 * REQ-LBL-007: The widget type `label` MUST be registered with a renderer
 * reference to `LabelWidget.vue`, a form reference to `LabelForm.vue`, and a
 * `defaultContent` of `{text:'', fontSize:'16px', color:'',
 * backgroundColor:'', fontWeight:'bold', textAlign:'center'}`.
 */

import LabelWidget from '../components/Widgets/Renderers/LabelWidget.vue'
import LabelForm from '../components/Widgets/Forms/LabelForm.vue'

/**
 * @typedef {object} WidgetRegistryEntry
 * @property {object} renderer Vue component reference for the dashboard grid
 * @property {object} form Vue component reference for the AddWidgetModal sub-form
 * @property {object} defaultContent Initial `content` payload for new placements
 * @property {string} displayName Human-readable type name for the type picker
 * @property {string} icon Material Design icon name used in the type picker
 */

/** @type {Record<string, WidgetRegistryEntry>} */
export const widgetRegistry = {
	label: {
		renderer: LabelWidget,
		form: LabelForm,
		defaultContent: {
			text: '',
			fontSize: '16px',
			color: '',
			backgroundColor: '',
			fontWeight: 'bold',
			textAlign: 'center',
		},
		displayName: t('mydash', 'Label'),
		icon: 'FormatTitle',
	},
}

/**
 * List every registered widget type — used by the AddWidgetModal type picker
 * to render selectable options distinct from the Nextcloud-discovered widget
 * set (REQ-LBL-007 second scenario).
 *
 * @return {string[]} list of registered type keys
 */
export function listWidgetTypes() {
	return Object.keys(widgetRegistry)
}

/**
 * Look up a widget type entry; returns null when the type is unknown so the
 * caller can fall back gracefully.
 *
 * @param {string} type the widget type key
 * @return {WidgetRegistryEntry|null} the registry entry or null
 */
export function getWidgetTypeEntry(type) {
	return widgetRegistry[type] || null
}

/**
 * Return the `defaultContent` blob for a registered type, or `{}` for unknown
 * types so the caller never has to null-check.
 *
 * @param {string} type the widget type key
 * @return {object} a fresh copy of the type's defaultContent
 */
export function getDefaultContent(type) {
	const entry = widgetRegistry[type]
	if (!entry) {
		return {}
	}
	// Return a shallow copy so callers can mutate freely without polluting
	// the registry's frozen-by-convention defaults.
	return { ...entry.defaultContent }
}
