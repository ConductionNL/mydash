/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import TextDisplayWidget from '../components/Widgets/Renderers/TextDisplayWidget.vue'
import TextDisplayForm from '../components/Widgets/Forms/TextDisplayForm.vue'
import LabelWidget from '../components/Widgets/Renderers/LabelWidget.vue'
import LabelForm from '../components/Widgets/Forms/LabelForm.vue'
import ImageWidget from '../components/Widgets/Renderers/ImageWidget.vue'
import ImageForm from '../components/Widgets/Forms/ImageForm.vue'
import NcDashboardWidget from '../components/Widgets/Renderers/NcDashboardWidget.vue'
import NcDashboardForm from '../components/Widgets/Forms/NcDashboardForm.vue'
import LinkButtonWidget from '../components/Widgets/Renderers/LinkButtonWidget.vue'
import LinkButtonForm from '../components/Widgets/Forms/LinkButtonForm.vue'

/**
 * Localised label helper. `t` is provided as a Nextcloud global at runtime;
 * outside that context (e.g. unit tests, build-time evaluation) we fall back
 * to the raw English string.
 *
 * @param {string} key the translation key
 * @return {string} localised value
 */
function tt(key) {
	if (typeof t === 'function') {
		return t('mydash', key)
	}
	return key
}

/**
 * Built-in widget type registry.
 *
 * Each entry describes one selectable widget type for AddWidgetModal. The
 * registry is intentionally minimal at this stage — it carries only the
 * `text` widget type owned by the `text-display-widget` capability. The
 * follow-up `widget-add-edit-modal` capability will evolve this registry to
 * cover additional built-in widget types and wire it into the modal.
 *
 * Shape of an entry:
 *   {
 *     type:      string,    // discriminator stored in placement.styleConfig.type
 *     label:     string,    // localised label shown in the type-picker
 *     component: Component, // renderer
 *     form:      Component, // sub-form for AddWidgetModal
 *     defaults:  object,    // initial `content` blob for new placements
 *   }
 */
export const widgetRegistry = {
	text: {
		type: 'text',
		label: tt('Text'),
		component: TextDisplayWidget,
		form: TextDisplayForm,
		defaults: {
			text: '',
			fontSize: '14px',
			color: '',
			backgroundColor: '',
			textAlign: 'left',
		},
	},
	label: {
		type: 'label',
		label: tt('Label'),
		component: LabelWidget,
		form: LabelForm,
		defaults: {
			text: '',
			fontSize: '16px',
			color: '',
			backgroundColor: '',
			fontWeight: 'bold',
			textAlign: 'center',
		},
	},
	image: {
		type: 'image',
		label: tt('Image'),
		component: ImageWidget,
		form: ImageForm,
		defaults: {
			url: '',
			alt: '',
			link: '',
			fit: 'cover',
		},
	},
	'nc-widget': {
		type: 'nc-widget',
		label: tt('Nextcloud Widget'),
		component: NcDashboardWidget,
		form: NcDashboardForm,
		defaults: {
			widgetId: '',
			displayMode: 'vertical',
		},
	},
	link: {
		type: 'link',
		label: tt('Link Button'),
		component: LinkButtonWidget,
		form: LinkButtonForm,
		defaults: {
			label: '',
			url: '',
			icon: '',
			actionType: 'external',
			backgroundColor: '',
			textColor: '',
		},
	},
}

/**
 * Look up a widget registry entry by type discriminator.
 *
 * @param {string} type the widget type discriminator
 * @return {object|null} the registry entry or null when unknown
 */
export function getWidgetRegistryEntry(type) {
	return widgetRegistry[type] || null
}
