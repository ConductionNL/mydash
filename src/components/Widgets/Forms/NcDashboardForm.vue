<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="nc-dashboard-form">
		<label class="nc-dashboard-form__field">
			<span class="nc-dashboard-form__label">{{ t('mydash', 'Select Widget') }}</span>
			<select
				v-model="widgetId"
				class="nc-dashboard-form__select"
				required
				@change="emitContent">
				<option value="" disabled>
					{{ t('mydash', 'Choose a widget…') }}
				</option>
				<option
					v-for="opt in widgetOptions"
					:key="opt.id"
					:value="opt.id">
					{{ opt.title }}
				</option>
			</select>
		</label>

		<label class="nc-dashboard-form__field">
			<span class="nc-dashboard-form__label">{{ t('mydash', 'Display Mode') }}</span>
			<select
				v-model="displayMode"
				class="nc-dashboard-form__select"
				@change="emitContent">
				<option value="vertical">
					{{ t('mydash', 'Vertical (list)') }}
				</option>
				<option value="horizontal">
					{{ t('mydash', 'Horizontal (cards)') }}
				</option>
			</select>
		</label>
	</div>
</template>

<script>
const DEFAULT_CONTENT = Object.freeze({
	widgetId: '',
	displayMode: 'vertical',
})

/**
 * NcDashboardForm is the sub-form for the AddWidgetModal when the user is
 * creating or editing an `nc-widget` placement (REQ-WDG-018).
 *
 * Two controls:
 *  - **picker** — `<select>` populated from the `widgets` initial-state list
 *    (REQ-WDG-001 / REQ-INIT-002 / REQ-WDG-018 scenario "Form picker lists
 *    discovered widgets"). Validation requires a non-empty `widgetId`.
 *  - **display mode** — `vertical` (list) or `horizontal` (cards).
 *
 * Pre-fills both controls from `editingWidget.content` per REQ-WDG-018.
 */
export default {
	name: 'NcDashboardForm',

	inject: {
		widgetsCatalog: {
			from: 'widgets',
			default: () => [],
		},
	},

	props: {
		/**
		 * The placement being edited, or `null` in create mode.
		 * Pre-fills both controls from `editingWidget.content` per REQ-WDG-018.
		 */
		editingWidget: {
			type: Object,
			default: null,
		},
		/**
		 * Initial content values — used when not editing and the parent
		 * supplies registry defaults.
		 */
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = this.editingWidget?.content || this.value || {}
		return {
			widgetId: typeof initial.widgetId === 'string' ? initial.widgetId : DEFAULT_CONTENT.widgetId,
			displayMode: initial.displayMode === 'horizontal' ? 'horizontal' : 'vertical',
		}
	},

	computed: {
		widgetOptions() {
			// PHP can serialise sequential arrays as objects; normalise here
			// the same way the renderer does (tasks.md §2 defensive
			// normalisation).
			const list = Array.isArray(this.widgetsCatalog)
				? this.widgetsCatalog
				: (this.widgetsCatalog && typeof this.widgetsCatalog === 'object'
					? Object.values(this.widgetsCatalog)
					: [])
			return list
				.filter((w) => w && typeof w.id === 'string' && w.id !== '')
				.map((w) => ({ id: w.id, title: w.title || w.id }))
		},

		assembledContent() {
			return {
				widgetId: this.widgetId,
				displayMode: this.displayMode,
			}
		},
	},

	methods: {
		emitContent() {
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Returns a list of error strings; empty array means valid.
		 *
		 * @return {string[]} validation errors
		 */
		validate() {
			if (typeof this.widgetId !== 'string' || this.widgetId.trim() === '') {
				return [t('mydash', 'Choose a widget…')]
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
	font-size: 14px;
	font-weight: 500;
}

.nc-dashboard-form__select {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
</style>
