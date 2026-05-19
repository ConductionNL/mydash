<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="calendar-form">
		<NcSelect
			:value="viewMode"
			:options="viewModeOptions"
			:input-label="t('mydash', 'View Mode')"
			:clearable="false"
			@input="updateField('viewMode', $event)" />

		<label class="calendar-form__field">
			<span class="calendar-form__label">{{ t('mydash', 'Internal Calendars (one principal URI per line)') }}</span>
			<textarea
				class="calendar-form__textarea"
				:value="internalCalendarsText"
				rows="3"
				@input="updateMulti('internalCalendars', $event.target.value)" />
		</label>

		<label class="calendar-form__field">
			<span class="calendar-form__label">{{ t('mydash', 'External ICS URLs (one per line)') }}</span>
			<textarea
				class="calendar-form__textarea"
				:value="externalIcsUrlsText"
				rows="3"
				placeholder="https://example.com/cal.ics"
				@input="updateMulti('externalIcsUrls', $event.target.value)" />
		</label>

		<NcTextField
			:value="String(daysAhead)"
			:label="t('mydash', 'Days Ahead')"
			placeholder="14"
			@update:value="updateNumber('daysAhead', $event)" />

		<NcCheckboxRadioSwitch
			:checked="colorByCalendar"
			@update:checked="updateField('colorByCalendar', $event)">
			{{ t('mydash', 'Color by Calendar') }}
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script>
import { NcTextField, NcSelect, NcCheckboxRadioSwitch } from '@conduction/nextcloud-vue'

const VIEW_MODES = ['month', 'week', 'agenda']

const DEFAULT_CONTENT = Object.freeze({
	internalCalendars: [],
	externalIcsUrls: [],
	viewMode: 'agenda',
	daysAhead: 14,
	colorByCalendar: true,
})

/**
 * CalendarForm is the AddWidgetModal sub-form for the `calendar` widget
 * type. Captures internal NC calendar principals, external ICS URLs,
 * the active view mode, days-ahead window for agenda mode, and the
 * color-by-calendar toggle (REQ-CAL-002, REQ-CAL-008).
 *
 * Validation requires at least one calendar source AND that every
 * external URL parses as `https://...`. Plain http and other schemes
 * are rejected here so they never reach the backend's SSRF guard
 * (REQ-CAL-006).
 */
export default {
	name: 'CalendarForm',

	components: {
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
	},

	props: {
		editingWidget: {
			type: Object,
			default: null,
		},
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = this.editingWidget?.content || this.value || {}
		return {
			internalCalendars: Array.isArray(initial.internalCalendars)
				? [...initial.internalCalendars]
				: [],
			externalIcsUrls: Array.isArray(initial.externalIcsUrls)
				? [...initial.externalIcsUrls]
				: [],
			viewMode: VIEW_MODES.includes(initial.viewMode) ? initial.viewMode : DEFAULT_CONTENT.viewMode,
			daysAhead: this.coerceNumber(initial.daysAhead, DEFAULT_CONTENT.daysAhead),
			colorByCalendar: initial.colorByCalendar !== false,
		}
	},

	computed: {
		viewModeOptions() {
			return VIEW_MODES
		},

		internalCalendarsText() {
			return this.internalCalendars.join('\n')
		},

		externalIcsUrlsText() {
			return this.externalIcsUrls.join('\n')
		},

		assembledContent() {
			return {
				internalCalendars: [...this.internalCalendars],
				externalIcsUrls: [...this.externalIcsUrls],
				viewMode: this.viewMode,
				daysAhead: this.daysAhead,
				colorByCalendar: this.colorByCalendar,
			}
		},
	},

	methods: {
		coerceNumber(value, fallback) {
			const num = parseInt(value, 10)
			return Number.isFinite(num) && num > 0 ? num : fallback
		},

		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		updateNumber(field, value) {
			this[field] = this.coerceNumber(value, DEFAULT_CONTENT[field])
			this.$emit('update:content', this.assembledContent)
		},

		updateMulti(field, raw) {
			const lines = String(raw || '')
				.split('\n')
				.map((line) => line.trim())
				.filter((line) => line !== '')
			this[field] = lines
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Validate the form. Requires at least one calendar source AND
		 * every external URL to parse as https://<host>...
		 *
		 * @return {string[]} error strings; empty array means valid.
		 */
		validate() {
			const errors = []

			if (this.internalCalendars.length === 0 && this.externalIcsUrls.length === 0) {
				errors.push(t('mydash', 'At least one calendar source is required'))
			}

			for (const url of this.externalIcsUrls) {
				if (!/^https:\/\/[^\s/]+/i.test(url)) {
					errors.push(t('mydash', 'External ICS URLs must start with https://'))
					break
				}
			}

			return errors
		},
	},
}
</script>

<style scoped>
.calendar-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.calendar-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 14px;
}

.calendar-form__label {
	font-weight: 500;
}

.calendar-form__textarea {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
	font-size: 13px;
	resize: vertical;
}
</style>
