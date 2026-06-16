<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="people-form">
		<NcSelect
			:value="layout"
			:options="layoutOptions"
			:input-label="t('mydash', 'Layout')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('layout', $event)" />

		<NcSelect
			:value="sortBy"
			:options="sortByOptions"
			:input-label="t('mydash', 'Sort by')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('sortBy', $event)" />

		<label class="people-form__field">
			{{ t('mydash', 'Group filter (comma-separated, blank for all)') }}
			<input
				type="text"
				class="people-form__input"
				:value="groupFilterText"
				:placeholder="t('mydash', 'e.g. management, product')"
				@input="updateGroupFilter($event.target.value)">
		</label>

		<label class="people-form__field people-form__field--inline">
			<input
				type="checkbox"
				:checked="excludeDisabled"
				@change="updateField('excludeDisabled', $event.target.checked)">
			{{ t('mydash', 'Exclude disabled users') }}
		</label>

		<label class="people-form__field people-form__field--inline">
			<input
				type="checkbox"
				:checked="showBirthdays"
				@change="updateField('showBirthdays', $event.target.checked)">
			{{ t('mydash', 'Show birthdays') }}
		</label>

		<label v-if="showBirthdays" class="people-form__field">
			{{ t('mydash', 'Birthday window (days, 0–30)') }}
			<input
				type="number"
				class="people-form__input"
				min="0"
				max="30"
				:value="birthdayWindowDays"
				@input="updateBirthdayWindow($event.target.value)">
			<span v-if="birthdayWindowError" class="people-form__error">
				{{ birthdayWindowError }}
			</span>
		</label>

		<label v-if="layout !== 'list'" class="people-form__field">
			{{ t('mydash', 'Columns') }}
			<select
				:value="columns"
				class="people-form__input"
				@change="updateField('columns', Number($event.target.value))">
				<option :value="2">
					2
				</option>
				<option :value="3">
					3
				</option>
				<option :value="4">
					4
				</option>
			</select>
		</label>
	</div>
</template>

<script>
import { NcSelect } from '@conduction/nextcloud-vue'

const DEFAULT_CONTENT = Object.freeze({
	layout: 'grid',
	selectionMode: 'filter',
	selectedUsers: [],
	filters: [],
	filterOperator: 'AND',
	excludeDisabled: true,
	showBirthdays: true,
	birthdayWindowDays: 7,
	sortBy: 'displayName',
	columns: 3,
	showFields: {
		displayName: true,
		role: true,
		organisation: true,
		email: true,
		phone: true,
		avatar: true,
		birthdate: true,
	},
})

/**
 * PeopleForm — sub-form for the AddWidgetModal when the user is creating
 * or editing a `people` placement (REQ-PPL-002).
 *
 * Exposes the most-used config keys: layout, sort, group filter, exclude
 * disabled, show birthdays + window, and columns. Less common knobs
 * (`selectionMode`, `selectedUsers`, `filterOperator`, `showFields`) keep
 * their defaults via the registry's `DEFAULT_CONTENT` and travel through
 * `assembledContent()` untouched, so callers that already saved those
 * keys keep them.
 *
 * Group filter is persisted as the canonical filters array shape from the
 * spec: `[{fieldName: 'group', operator: 'in', values: [...]}]`. The
 * comma-separated input is purely a user-facing convenience.
 *
 * `validate()` enforces REQ-PPL-002's `birthdayWindowDays` 0..30 range.
 */
export default {
	name: 'PeopleForm',

	components: {
		NcSelect,
	},

	props: {
		/**
		 * The placement being edited, or `null` in create mode. When set,
		 * pre-fills every field from `editingWidget.content`.
		 */
		editingWidget: {
			type: Object,
			default: null,
		},
		/**
		 * Initial content values, used in create mode when the parent
		 * supplies the registry default blob.
		 */
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = this.editingWidget?.content || this.value || {}
		const groupFilter = (initial.filters || [])
			.find((f) => f && f.fieldName === 'group')
		return {
			layout: initial.layout ?? DEFAULT_CONTENT.layout,
			selectionMode: initial.selectionMode ?? DEFAULT_CONTENT.selectionMode,
			selectedUsers: Array.isArray(initial.selectedUsers)
				? [...initial.selectedUsers]
				: [...DEFAULT_CONTENT.selectedUsers],
			filterOperator: initial.filterOperator ?? DEFAULT_CONTENT.filterOperator,
			excludeDisabled: typeof initial.excludeDisabled === 'boolean'
				? initial.excludeDisabled
				: DEFAULT_CONTENT.excludeDisabled,
			showBirthdays: typeof initial.showBirthdays === 'boolean'
				? initial.showBirthdays
				: DEFAULT_CONTENT.showBirthdays,
			birthdayWindowDays: typeof initial.birthdayWindowDays === 'number'
				? initial.birthdayWindowDays
				: DEFAULT_CONTENT.birthdayWindowDays,
			sortBy: initial.sortBy ?? DEFAULT_CONTENT.sortBy,
			columns: typeof initial.columns === 'number'
				? initial.columns
				: DEFAULT_CONTENT.columns,
			showFields: { ...DEFAULT_CONTENT.showFields, ...(initial.showFields || {}) },
			groupFilterValues: groupFilter && Array.isArray(groupFilter.values)
				? [...groupFilter.values]
				: [],
			birthdayWindowError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/people-widget/spec.md */
		layoutOptions() {
			return [
				{ value: 'card', label: t('mydash', 'Card') },
				{ value: 'grid', label: t('mydash', 'Grid') },
				{ value: 'list', label: t('mydash', 'List') },
			]
		},

		/** @spec openspec/specs/people-widget/spec.md */
		sortByOptions() {
			return [
				{ value: 'displayName', label: t('mydash', 'Display name') },
				{ value: 'group', label: t('mydash', 'Group') },
			]
		},

		/** @spec openspec/specs/people-widget/spec.md */
		groupFilterText() {
			return this.groupFilterValues.join(', ')
		},

		/** @spec openspec/specs/people-widget/spec.md */
		assembledFilters() {
			if (this.groupFilterValues.length === 0) {
				return []
			}
			return [{
				fieldName: 'group',
				operator: 'in',
				values: this.groupFilterValues,
			}]
		},

		/** @spec openspec/specs/people-widget/spec.md */
		assembledContent() {
			return {
				layout: this.layout,
				selectionMode: this.selectionMode,
				selectedUsers: this.selectedUsers,
				filters: this.assembledFilters,
				filterOperator: this.filterOperator,
				excludeDisabled: this.excludeDisabled,
				showBirthdays: this.showBirthdays,
				birthdayWindowDays: this.birthdayWindowDays,
				sortBy: this.sortBy,
				columns: this.columns,
				showFields: this.showFields,
			}
		},
	},

	methods: {
		/**
		 * Set a field and notify the parent.
		 *
		 * @param {string} field one of the form's reactive keys
		 * @param {*} value new value
		 */
		/** @spec openspec/specs/people-widget/spec.md */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Parse the comma-separated group input and re-emit the assembled
		 * content (so the persisted shape always matches the spec's
		 * `filters` array contract).
		 *
		 * @param {string} raw the textbox value
		 */
		/** @spec openspec/specs/people-widget/spec.md */
		updateGroupFilter(raw) {
			this.groupFilterValues = (raw || '')
				.split(',')
				.map((part) => part.trim())
				.filter((part) => part.length > 0)
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Validate + clamp the birthday window against the spec's 0..30
		 * range (REQ-PPL-002). Records a localised error message that the
		 * template surfaces inline.
		 *
		 * @param {string|number} raw the input value
		 */
		/** @spec openspec/specs/people-widget/spec.md */
		updateBirthdayWindow(raw) {
			const numeric = Number(raw)
			if (!Number.isFinite(numeric) || numeric < 0 || numeric > 30) {
				this.birthdayWindowError = t('mydash', 'Must be between 0 and 30')
				return
			}
			this.birthdayWindowError = ''
			this.birthdayWindowDays = Math.floor(numeric)
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * REQ-PPL-002: surface invalid `birthdayWindowDays` as a non-empty
		 * error array so AddWidgetModal blocks the save.
		 *
		 * @return {string[]} validation errors
		 */
		/** @spec openspec/specs/people-widget/spec.md */
		validate() {
			const errors = []
			if (typeof this.birthdayWindowDays !== 'number'
				|| this.birthdayWindowDays < 0
				|| this.birthdayWindowDays > 30) {
				errors.push(t('mydash', 'Birthday window must be between 0 and 30'))
			}
			if (!['card', 'grid', 'list'].includes(this.layout)) {
				errors.push(t('mydash', 'Invalid layout'))
			}
			if (!['displayName', 'group'].includes(this.sortBy)) {
				errors.push(t('mydash', 'Invalid sort key'))
			}
			return errors
		},
	},
}
</script>

<style scoped>
.people-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.people-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 14px;
}

.people-form__field--inline {
	flex-direction: row;
	align-items: center;
	gap: 8px;
}

.people-form__input {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}

.people-form__error {
	color: var(--color-error, #c00);
	font-size: 12px;
}
</style>
