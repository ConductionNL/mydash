<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="calendar-widget">
		<header class="calendar-widget__header">
			<span class="calendar-widget__title">{{ headerTitle }}</span>
			<div class="calendar-widget__modes">
				<button
					v-for="mode in viewModes"
					:key="mode"
					type="button"
					class="calendar-widget__mode-btn"
					:class="{ 'is-active': activeMode === mode }"
					@click="setMode(mode)">
					{{ modeLabel(mode) }}
				</button>
			</div>
		</header>

		<div class="calendar-widget__body">
			<div v-if="loading" class="calendar-widget__loading">
				{{ t('mydash', 'Loading calendars…') }}
			</div>

			<div v-else-if="error" class="calendar-widget__error">
				<p>{{ t('mydash', 'Failed to load events') }}</p>
				<button type="button" class="calendar-widget__retry" @click="fetchEvents">
					{{ t('mydash', 'Retry') }}
				</button>
			</div>

			<div v-else-if="!hasSources" class="calendar-widget__empty">
				{{ t('mydash', 'No calendars configured') }}
			</div>

			<div v-else-if="events.length === 0 && activeMode === 'agenda'" class="calendar-widget__empty">
				{{ emptyMessage }}
			</div>

			<!-- Month view: 7-column grid grouped by week. -->
			<div v-else-if="activeMode === 'month'" class="calendar-widget__month">
				<div
					v-for="(weekday, idx) in weekdayHeaders"
					:key="'wh-' + idx"
					class="calendar-widget__month-header">
					{{ weekday }}
				</div>
				<div
					v-for="day in monthGrid"
					:key="day.iso"
					class="calendar-widget__month-cell"
					:class="{ 'is-today': day.isToday, 'is-other-month': day.isOtherMonth }">
					<span class="calendar-widget__month-day">{{ day.dayNum }}</span>
					<ul v-if="day.events.length" class="calendar-widget__month-events">
						<li
							v-for="event in day.events.slice(0, 3)"
							:key="event.uid + '-' + event.start"
							class="calendar-widget__month-event"
							:title="event.title"
							:style="eventStyle(event)">
							{{ event.title }}
						</li>
						<li
							v-if="day.events.length > 3"
							class="calendar-widget__month-overflow">
							+{{ day.events.length - 3 }}
						</li>
					</ul>
				</div>
			</div>

			<!-- Week view: 7 columns per day. -->
			<div v-else-if="activeMode === 'week'" class="calendar-widget__week">
				<div
					v-for="day in weekDays"
					:key="day.iso"
					class="calendar-widget__week-col"
					:class="{ 'is-today': day.isToday }">
					<header class="calendar-widget__week-day">
						<span class="calendar-widget__week-name">{{ day.weekday }}</span>
						<span class="calendar-widget__week-num">{{ day.dayNum }}</span>
					</header>
					<ul v-if="day.events.length" class="calendar-widget__week-events">
						<li
							v-for="event in day.events"
							:key="event.uid + '-' + event.start"
							class="calendar-widget__week-event"
							:style="eventStyle(event)">
							<span class="calendar-widget__week-time">{{ formatTime(event) }}</span>
							<span class="calendar-widget__week-title">{{ event.title }}</span>
						</li>
					</ul>
					<p v-else class="calendar-widget__week-empty">
						—
					</p>
				</div>
			</div>

			<!-- Agenda view: chronological list grouped by day. -->
			<ul v-else class="calendar-widget__agenda">
				<template v-for="group in agendaGroups">
					<li :key="'h-' + group.iso" class="calendar-widget__agenda-header">
						{{ group.label }}
					</li>
					<li
						v-for="event in group.events"
						:key="event.uid + '-' + event.start"
						class="calendar-widget__agenda-row"
						:style="eventStyle(event)">
						<span class="calendar-widget__agenda-time">{{ formatTime(event) }}</span>
						<span class="calendar-widget__agenda-title">{{ event.title }}</span>
						<span v-if="event.calendarName" class="calendar-widget__agenda-cal">
							{{ event.calendarName }}
						</span>
					</li>
				</template>
			</ul>

			<p v-if="failures.length" class="calendar-widget__failures">
				{{ failureSummary }}
			</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const VIEW_MODES = ['month', 'week', 'agenda']

/**
 * CalendarWidget renders aggregated events from internal Nextcloud
 * calendars and external ICS feeds in three view modes (month, week,
 * agenda). Configuration lives in the placement's `content` payload —
 * see CalendarForm.vue and the calendar-widget capability for the
 * canonical shape (REQ-CAL-002, REQ-CAL-008).
 *
 * The component never renders untrusted content via v-html — all event
 * fields are surfaced through Vue interpolation, eliminating XSS via
 * malicious ICS feeds.
 */
export default {
	name: 'CalendarWidget',

	props: {
		/**
		 * Persisted placement content. Must contain at least one of
		 * `internalCalendars` or `externalIcsUrls` for events to load.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Placement id used for the events endpoint (when present). When
		 * absent — for example in standalone preview / story mode — the
		 * component renders empty-state UI without performing a fetch.
		 */
		placementId: {
			type: [Number, String],
			default: null,
		},
	},

	data() {
		const initialMode = VIEW_MODES.includes(this.content?.viewMode)
			? this.content.viewMode
			: 'agenda'
		return {
			activeMode: initialMode,
			events: [],
			failures: [],
			loading: false,
			error: null,
			today: new Date(),
		}
	},

	computed: {
		/** @spec openspec/specs/calendar-widget/spec.md */
		viewModes() {
			return VIEW_MODES
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		hasSources() {
			const internal = Array.isArray(this.content?.internalCalendars)
				? this.content.internalCalendars
				: []
			const external = Array.isArray(this.content?.externalIcsUrls)
				? this.content.externalIcsUrls
				: []
			return internal.length + external.length > 0
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		daysAhead() {
			const value = parseInt(this.content?.daysAhead ?? 14, 10)
			return Number.isFinite(value) && value > 0 ? value : 14
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		colorByCalendar() {
			return this.content?.colorByCalendar !== false
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		headerTitle() {
			return t('mydash', 'Calendar')
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		emptyMessage() {
			return t('mydash', 'No events in the next {N} days')
				.replace('{N}', String(this.daysAhead))
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		failureSummary() {
			const count = this.failures.length
			return t('mydash', '{N} calendar source(s) unavailable')
				.replace('{N}', String(count))
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		weekdayHeaders() {
			return [
				t('mydash', 'Sun'),
				t('mydash', 'Mon'),
				t('mydash', 'Tue'),
				t('mydash', 'Wed'),
				t('mydash', 'Thu'),
				t('mydash', 'Fri'),
				t('mydash', 'Sat'),
			]
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		monthRange() {
			const today = this.today
			const first = new Date(today.getFullYear(), today.getMonth(), 1)
			const last = new Date(today.getFullYear(), today.getMonth() + 1, 0)
			return { from: first, to: last }
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		weekRange() {
			const today = this.today
			const start = new Date(today)
			start.setDate(today.getDate() - today.getDay())
			start.setHours(0, 0, 0, 0)
			const end = new Date(start)
			end.setDate(start.getDate() + 6)
			end.setHours(23, 59, 59, 999)
			return { from: start, to: end }
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		monthGrid() {
			const { from, to } = this.monthRange
			const cells = []
			const gridStart = new Date(from)
			gridStart.setDate(from.getDate() - from.getDay())
			const totalDays = Math.ceil((to.getDate() + from.getDay()) / 7) * 7
			for (let i = 0; i < totalDays; i++) {
				const cellDate = new Date(gridStart)
				cellDate.setDate(gridStart.getDate() + i)
				const iso = this.toIsoDate(cellDate)
				cells.push({
					iso,
					dayNum: cellDate.getDate(),
					isToday: this.toIsoDate(this.today) === iso,
					isOtherMonth: cellDate.getMonth() !== from.getMonth(),
					events: this.eventsByDay[iso] || [],
				})
			}
			return cells
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		weekDays() {
			const { from } = this.weekRange
			const out = []
			for (let i = 0; i < 7; i++) {
				const day = new Date(from)
				day.setDate(from.getDate() + i)
				const iso = this.toIsoDate(day)
				out.push({
					iso,
					weekday: this.weekdayHeaders[day.getDay()],
					dayNum: day.getDate(),
					isToday: this.toIsoDate(this.today) === iso,
					events: this.eventsByDay[iso] || [],
				})
			}
			return out
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		eventsByDay() {
			const buckets = {}
			for (const event of this.events) {
				const iso = this.toIsoDate(this.parseDate(event.start))
				if (!buckets[iso]) {
					buckets[iso] = []
				}
				buckets[iso].push(event)
			}
			return buckets
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		agendaGroups() {
			const groups = []
			const seen = {}
			for (const event of this.events) {
				const date = this.parseDate(event.start)
				const iso = this.toIsoDate(date)
				if (!seen[iso]) {
					seen[iso] = { iso, label: this.formatDayHeader(date), events: [] }
					groups.push(seen[iso])
				}
				seen[iso].events.push(event)
			}
			return groups
		},
	},

	watch: {
		content: {
			deep: true,
			/** @spec openspec/specs/calendar-widget/spec.md */
			handler() {
				this.fetchEvents()
			},
		},
		/** @spec openspec/specs/calendar-widget/spec.md */
		activeMode() {
			this.fetchEvents()
		},
	},

	mounted() {
		this.fetchEvents()
	},

	methods: {
		t,

		/** @spec openspec/specs/calendar-widget/spec.md */
		setMode(mode) {
			if (VIEW_MODES.includes(mode)) {
				this.activeMode = mode
			}
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		modeLabel(mode) {
			if (mode === 'month') {
				return t('mydash', 'Month')
			}
			if (mode === 'week') {
				return t('mydash', 'Week')
			}
			return t('mydash', 'Agenda')
		},

		/**
		 * Compute the inclusive date window for the current mode.
		 *
		 * @return {{from: Date, to: Date}|null} the date window
		 */
		/** @spec openspec/specs/calendar-widget/spec.md */
		computeRange() {
			if (this.activeMode === 'month') {
				return this.monthRange
			}
			if (this.activeMode === 'week') {
				return this.weekRange
			}
			const from = new Date(this.today)
			from.setHours(0, 0, 0, 0)
			const to = new Date(from)
			to.setDate(from.getDate() + this.daysAhead)
			to.setHours(23, 59, 59, 999)
			return { from, to }
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		async fetchEvents() {
			if (!this.placementId || !this.hasSources) {
				this.events = []
				this.failures = []
				return
			}
			const { from, to } = this.computeRange()
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/mydash/api/widgets/calendar/{id}/events', {
					id: this.placementId,
				})
				const response = await axios.get(url, {
					params: { from: from.toISOString(), to: to.toISOString() },
				})
				const payload = response?.data ?? {}
				this.events = Array.isArray(payload.events) ? payload.events : []
				this.failures = Array.isArray(payload.failures) ? payload.failures : []
			} catch (err) {
				this.error = err
				this.events = []
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		parseDate(value) {
			if (!value) {
				return new Date()
			}
			const d = new Date(value)
			return Number.isNaN(d.getTime()) ? new Date() : d
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		toIsoDate(date) {
			const yyyy = date.getFullYear()
			const mm = String(date.getMonth() + 1).padStart(2, '0')
			const dd = String(date.getDate()).padStart(2, '0')
			return `${yyyy}-${mm}-${dd}`
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		formatTime(event) {
			if (event.allDay) {
				return t('mydash', 'All day')
			}
			const start = this.parseDate(event.start)
			const hh = String(start.getHours()).padStart(2, '0')
			const mm = String(start.getMinutes()).padStart(2, '0')
			return `${hh}:${mm}`
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		formatDayHeader(date) {
			const month = date.toLocaleString(undefined, { month: 'short' })
			return `${this.weekdayHeaders[date.getDay()]} ${date.getDate()} ${month}`
		},

		/** @spec openspec/specs/calendar-widget/spec.md */
		eventStyle(event) {
			if (!this.colorByCalendar) {
				return {}
			}
			const color = event.color
			if (typeof color === 'string' && color !== '') {
				return { 'border-left': `3px solid ${color}` }
			}
			return {}
		},
	},
}
</script>

<style scoped>
.calendar-widget {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	overflow: hidden;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.calendar-widget__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.calendar-widget__title {
	font-weight: 600;
}

.calendar-widget__modes {
	display: flex;
	gap: 4px;
}

.calendar-widget__mode-btn {
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
	border-radius: var(--border-radius);
	padding: 2px 8px;
	font-size: 12px;
	cursor: pointer;
}

.calendar-widget__mode-btn.is-active {
	background: var(--color-primary-element-light, var(--color-primary));
	color: var(--color-primary-element-text, #fff);
}

.calendar-widget__body {
	flex: 1;
	overflow: auto;
	padding: 8px 12px;
}

.calendar-widget__loading,
.calendar-widget__error,
.calendar-widget__empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.calendar-widget__retry {
	margin-top: 8px;
	padding: 4px 12px;
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	border-radius: var(--border-radius);
}

.calendar-widget__month {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: 2px;
}

.calendar-widget__month-header {
	font-size: 11px;
	font-weight: 600;
	text-align: center;
	padding: 4px 0;
	color: var(--color-text-maxcontrast);
}

.calendar-widget__month-cell {
	min-height: 60px;
	padding: 2px 4px;
	border: 1px solid var(--color-border);
	border-radius: 2px;
	background: var(--color-main-background);
}

.calendar-widget__month-cell.is-today {
	background: var(--color-background-hover);
}

.calendar-widget__month-cell.is-other-month {
	opacity: 0.45;
}

.calendar-widget__month-day {
	font-size: 11px;
	font-weight: 600;
}

.calendar-widget__month-events {
	list-style: none;
	margin: 2px 0 0;
	padding: 0;
}

.calendar-widget__month-event {
	font-size: 10px;
	padding: 1px 3px;
	margin-bottom: 1px;
	border-radius: 2px;
	background: var(--color-background-dark);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.calendar-widget__month-overflow {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
}

.calendar-widget__week {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: 4px;
}

.calendar-widget__week-col {
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 4px;
}

.calendar-widget__week-col.is-today {
	background: var(--color-background-hover);
}

.calendar-widget__week-day {
	display: flex;
	flex-direction: column;
	align-items: center;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 2px;
	margin-bottom: 4px;
}

.calendar-widget__week-name {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
}

.calendar-widget__week-num {
	font-size: 14px;
	font-weight: 600;
}

.calendar-widget__week-events {
	list-style: none;
	margin: 0;
	padding: 0;
}

.calendar-widget__week-event {
	font-size: 11px;
	padding: 2px 4px;
	margin-bottom: 2px;
	background: var(--color-background-dark);
	border-radius: 2px;
}

.calendar-widget__week-time {
	font-weight: 600;
	margin-right: 4px;
}

.calendar-widget__week-empty {
	font-size: 11px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.calendar-widget__agenda {
	list-style: none;
	margin: 0;
	padding: 0;
}

.calendar-widget__agenda-header {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-top: 8px;
	margin-bottom: 4px;
}

.calendar-widget__agenda-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 6px;
	margin-bottom: 2px;
	background: var(--color-background-hover);
	border-radius: 2px;
}

.calendar-widget__agenda-time {
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.calendar-widget__agenda-title {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.calendar-widget__agenda-cal {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.calendar-widget__failures {
	margin-top: 8px;
	padding: 4px 8px;
	font-size: 11px;
	color: var(--color-warning);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}
</style>
