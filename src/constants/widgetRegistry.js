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
 * Renderer + Form Vue components — no other wiring is required. The registry
 * tolerates entries with `form: null` (renderer only) — `listWidgetTypes()`
 * filters those out so the AddWidgetModal type picker only shows types the
 * user can actually configure. Per-widget proposals that haven't yet shipped
 * their sub-form should not appear in the picker.
 *
 * REQ-LBL-007: The widget type `label` MUST be registered with a renderer
 * reference to `LabelWidget.vue`, a form reference to `LabelForm.vue`, and a
 * `defaultContent` of `{text:'', fontSize:'16px', color:'',
 * backgroundColor:'', fontWeight:'bold', textAlign:'center'}`.
 *
 * REQ-TXT-005 / REQ-TXT-001..004: The widget type `text` MUST be registered
 * with a renderer reference to `TextDisplayWidget.vue`, a form reference to
 * `TextDisplayForm.vue`, and a `defaultContent` of `{text:'',
 * fontSize:'14px', color:'', backgroundColor:'', textAlign:'left'}`.
 *
 * REQ-WDG-014: The set of supported widget types MUST come from this single
 * registry. Toolbar dropdown, modal type selector, and grid renderer all
 * consult `listWidgetTypes()` / `getWidgetTypeEntry()`.
 */

import LabelWidget from '../components/Widgets/Renderers/LabelWidget.vue'
import LabelForm from '../components/Widgets/Forms/LabelForm.vue'
import TextDisplayWidget from '../components/Widgets/Renderers/TextDisplayWidget.vue'
import TextDisplayForm from '../components/Widgets/Forms/TextDisplayForm.vue'
import ImageWidget from '../components/Widgets/Renderers/ImageWidget.vue'
import ImageForm from '../components/Widgets/Forms/ImageForm.vue'

/**
 * @typedef {object} WidgetRegistryEntry
 * @property {object} renderer Vue component reference for the dashboard grid
 * @property {object|null} form Vue component reference for the AddWidgetModal sub-form, or null if no form is registered yet
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
	text: {
		renderer: TextDisplayWidget,
		form: TextDisplayForm,
		defaultContent: {
			text: '',
			fontSize: '14px',
			color: '',
			backgroundColor: '',
			textAlign: 'left',
		},
		displayName: t('mydash', 'Text'),
		icon: 'FormatText',
	},
	image: {
		renderer: ImageWidget,
		form: ImageForm,
		defaultContent: {
			url: '',
			alt: '',
			link: '',
			fit: 'cover',
		},
		displayName: t('mydash', 'Image'),
		icon: 'Camera',
	},
}

/**
 * List every registered widget type that has a usable form component. The
 * AddWidgetModal type picker calls this; types without a `form` entry MUST
 * be excluded so the user is never offered a type they cannot configure.
 *
 * Per-widget proposals (text-display-widget, link-button-widget,
 * nc-dashboard-widget-proxy) each register their own form when they land —
 * until then those types are renderer-only and stay out of the picker.
 *
 * @return {string[]} list of registered type keys with a non-null form
 */
export function listWidgetTypes() {
	return Object.keys(widgetRegistry).filter(
		(type) => widgetRegistry[type] && widgetRegistry[type].form !== null && widgetRegistry[type].form !== undefined,
	)
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
