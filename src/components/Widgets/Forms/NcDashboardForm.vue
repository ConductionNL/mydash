<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="nc-dashboard-form">
		<!-- Widget picker -->
		<div class="nc-dashboard-form__field">
			<label :for="widgetSelectId" class="nc-dashboard-form__label">
				{{ tt('Select Widget') }}
			</label>
			<select
				:id="widgetSelectId"
				v-model="form.widgetId"
				class="nc-dashboard-form__select"
				@change="emitUpdate">
				<option value="">
					{{ tt('Choose a widget…') }}
				</option>
				<option
					v-for="w in availableWidgets"
					:key="w.id"
					:value="w.id">
					{{ w.title || w.id }}
				</option>
			</select>
		</div>

		<!-- Display mode picker -->
		<div class="nc-dashboard-form__field">
			<label :for="modeSelectId" class="nc-dashboard-form__label">
				{{ tt('Display Mode') }}
			</label>
			<select
				:id="modeSelectId"
				v-model="form.displayMode"
				class="nc-dashboard-form__select"
				@change="emitUpdate">
				<option value="vertical">
					{{ tt('Vertical (list)') }}
				</option>
				<option value="horizontal">
					{{ tt('Horizontal (cards)') }}
				</option>
			</select>
		</div>
	</div>
</template>

<script>
/**
 * NcDashboardForm
 *
 * Sub-form for AddWidgetModal that authors the persisted `content` blob for an
 * `nc-widget` widget. Provides a picker populated from the server-supplied
 * widget list (IManager::getWidgets() via initial state) and a display-mode
 * selector. Pre-fills from `editingWidget.content` on mount, emits
 * `update:content` reactively, and exposes a `validate()` method per
 * REQ-WDG-018.
 *
 * @spec openspec/changes/nc-dashboard-widget-proxy/specs/widgets/spec.md#req-wdg-018
 */

const DEFAULTS = {
	widgetId: '',
	displayMode: 'vertical',
}

let uidCounter = 0

export default {
	name: 'NcDashboardForm',

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
		widgetSelectId() {
			return `nc-dashboard-form-widget-${this.uid}`
		},

		modeSelectId() {
			return `nc-dashboard-form-mode-${this.uid}`
		},

		/**
		 * Available widgets from initial state. PHP may serialise a sequential
		 * array as a JSON object with numeric keys — normalise both shapes.
		 */
		availableWidgets() {
			const injected = (window.__initialState && window.__initialState.widgets)
				|| (window.OCA && window.OCA.MyDash && window.OCA.MyDash.initialState && window.OCA.MyDash.initialState.widgets)
				|| []
			return Array.isArray(injected) ? injected : Object.values(injected)
		},
	},

	mounted() {
		const content = this.editingWidget?.content || {}
		this.form = {
			widgetId: typeof content.widgetId === 'string' ? content.widgetId : DEFAULTS.widgetId,
			displayMode: ['vertical', 'horizontal'].includes(content.displayMode)
				? content.displayMode
				: DEFAULTS.displayMode,
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
		 * Empty array means the form is valid.
		 *
		 * @return {string[]} array of error messages
		 */
		validate() {
			if (!this.form.widgetId || this.form.widgetId.trim() === '') {
				return [this.tt('Select Widget')]
			}
			return []
		},
	},
}
</script>

<style scoped>
.nc-dashboard-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.nc-dashboard-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.nc-dashboard-form__label {
	font-weight: bold;
}

.nc-dashboard-form__select {
	width: 100%;
}
</style>
