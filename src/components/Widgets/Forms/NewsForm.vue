<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="news-form">
		<label class="news-form__label">
			{{ t('mydash', 'Feed URLs') }}
		</label>
		<div
			v-for="(url, index) in feedUrls"
			:key="`feed-${index}`"
			class="news-form__feed-row">
			<NcTextField
				:value="url"
				:label="t('mydash', 'Feed URL')"
				placeholder="https://example.com/feed.xml"
				@update:value="updateFeedUrl(index, $event)" />
			<button
				type="button"
				class="news-form__feed-remove"
				:aria-label="t('mydash', 'Remove feed')"
				@click="removeFeedUrl(index)">
				×
			</button>
		</div>
		<button
			type="button"
			class="news-form__feed-add"
			@click="addFeedUrl">
			+ {{ t('mydash', 'Add feed URL') }}
		</button>

		<NcSelect
			:value="layout"
			:options="layoutOptions"
			:input-label="t('mydash', 'Layout')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('layout', $event)" />

		<NcTextField
			:value="String(itemLimit)"
			type="number"
			:label="t('mydash', 'Item limit (1–50)')"
			@update:value="updateNumericField('itemLimit', $event, 1, 50)" />

		<NcCheckboxRadioSwitch
			:checked="showThumbnails"
			@update:checked="updateField('showThumbnails', $event)">
			{{ t('mydash', 'Show thumbnails') }}
		</NcCheckboxRadioSwitch>

		<NcCheckboxRadioSwitch
			:checked="showSummary"
			@update:checked="updateField('showSummary', $event)">
			{{ t('mydash', 'Show summary') }}
		</NcCheckboxRadioSwitch>

		<NcTextField
			:value="String(summaryMaxChars)"
			type="number"
			:label="t('mydash', 'Summary max characters')"
			@update:value="updateNumericField('summaryMaxChars', $event, 0, 5000)" />

		<NcSelect
			:value="dateFormat"
			:options="dateFormatOptions"
			:input-label="t('mydash', 'Date format')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('dateFormat', $event)" />

		<NcCheckboxRadioSwitch
			:checked="metadataFilterEnabled"
			@update:checked="toggleMetadataFilter">
			{{ t('mydash', 'Filter by dashboard metadata') }}
		</NcCheckboxRadioSwitch>

		<div v-if="metadataFilterEnabled" class="news-form__metadata">
			<NcTextField
				:value="metadataFieldKey"
				:label="t('mydash', 'Metadata field key')"
				placeholder="department"
				@update:value="updateMetadataField('fieldKey', $event)" />
			<NcTextField
				:value="metadataValue"
				:label="t('mydash', 'Metadata value to match')"
				placeholder="marketing"
				@update:value="updateMetadataField('value', $event)" />
		</div>
	</div>
</template>

<script>
import { NcTextField, NcSelect, NcCheckboxRadioSwitch } from '@conduction/nextcloud-vue'

const ALLOWED_LAYOUTS = Object.freeze(['list', 'grid', 'carousel'])

const DEFAULT_CONTENT = Object.freeze({
	feedUrls: [],
	layout: 'list',
	itemLimit: 10,
	showThumbnails: true,
	showSummary: true,
	summaryMaxChars: 200,
	dateFormat: 'relative',
	metadataFilter: null,
})

/**
 * NewsForm — sub-form for the AddWidgetModal when the user is creating
 * or editing a `news` placement (REQ-NEWS-002).
 *
 * Manages:
 *  - feed URL list (add/remove/edit) — must be HTTP(S) per validate()
 *  - layout enum (list / grid / carousel)
 *  - item limit (1-50)
 *  - show thumbnails / summary booleans
 *  - summary max characters
 *  - date format (relative / absolute)
 *  - optional metadata filter `{fieldKey, value}` for dashboard-aware
 *    filtering (REQ-NEWS-007)
 *
 * The form pre-fills from `editingWidget.content` on open and emits
 * `update:content` with the assembled payload on every keystroke.
 */
export default {
	name: 'NewsForm',

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
		const filter = initial.metadataFilter
		const hasFilter = filter !== null && typeof filter === 'object'
		return {
			feedUrls: Array.isArray(initial.feedUrls) ? [...initial.feedUrls] : [],
			layout: ALLOWED_LAYOUTS.includes(initial.layout) ? initial.layout : DEFAULT_CONTENT.layout,
			itemLimit: typeof initial.itemLimit === 'number' ? initial.itemLimit : DEFAULT_CONTENT.itemLimit,
			showThumbnails: initial.showThumbnails === undefined ? DEFAULT_CONTENT.showThumbnails : initial.showThumbnails === true,
			showSummary: initial.showSummary === undefined ? DEFAULT_CONTENT.showSummary : initial.showSummary === true,
			summaryMaxChars: typeof initial.summaryMaxChars === 'number' ? initial.summaryMaxChars : DEFAULT_CONTENT.summaryMaxChars,
			dateFormat: initial.dateFormat === 'absolute' ? 'absolute' : 'relative',
			metadataFilterEnabled: hasFilter,
			metadataFieldKey: hasFilter && typeof filter.fieldKey === 'string' ? filter.fieldKey : '',
			metadataValue: hasFilter && typeof filter.value === 'string' ? filter.value : '',
		}
	},

	computed: {
		/** @spec openspec/specs/news-widget/spec.md */
		layoutOptions() {
			return [
				{ value: 'list', label: t('mydash', 'List') },
				{ value: 'grid', label: t('mydash', 'Grid') },
				{ value: 'carousel', label: t('mydash', 'Carousel') },
			]
		},

		/** @spec openspec/specs/news-widget/spec.md */
		dateFormatOptions() {
			return [
				{ value: 'relative', label: t('mydash', 'Relative (2 hours ago)') },
				{ value: 'absolute', label: t('mydash', 'Absolute (2026-05-01 14:30)') },
			]
		},

		/** @spec openspec/specs/news-widget/spec.md */
		assembledContent() {
			const filter = this.metadataFilterEnabled
				? { fieldKey: this.metadataFieldKey, value: this.metadataValue }
				: null
			return {
				feedUrls: [...this.feedUrls],
				layout: this.layout,
				itemLimit: this.itemLimit,
				showThumbnails: this.showThumbnails,
				showSummary: this.showSummary,
				summaryMaxChars: this.summaryMaxChars,
				dateFormat: this.dateFormat,
				metadataFilter: filter,
			}
		},
	},

	methods: {
		/** @spec openspec/specs/news-widget/spec.md */
		emitUpdate() {
			this.$emit('update:content', this.assembledContent)
		},

		/** @spec openspec/specs/news-widget/spec.md */
		updateField(field, value) {
			this[field] = value
			this.emitUpdate()
		},

		/** @spec openspec/specs/news-widget/spec.md */
		updateNumericField(field, value, min, max) {
			const parsed = parseInt(value, 10)
			if (Number.isNaN(parsed)) {
				return
			}
			const clamped = Math.max(min, Math.min(max, parsed))
			this[field] = clamped
			this.emitUpdate()
		},

		/** @spec openspec/specs/news-widget/spec.md */
		updateFeedUrl(index, value) {
			this.$set(this.feedUrls, index, value)
			this.emitUpdate()
		},

		/** @spec openspec/specs/news-widget/spec.md */
		addFeedUrl() {
			this.feedUrls.push('')
			this.emitUpdate()
		},

		/** @spec openspec/specs/news-widget/spec.md */
		removeFeedUrl(index) {
			this.feedUrls.splice(index, 1)
			this.emitUpdate()
		},

		/** @spec openspec/specs/news-widget/spec.md */
		toggleMetadataFilter(enabled) {
			this.metadataFilterEnabled = enabled
			this.emitUpdate()
		},

		/** @spec openspec/specs/news-widget/spec.md */
		updateMetadataField(field, value) {
			if (field === 'fieldKey') {
				this.metadataFieldKey = value
			} else if (field === 'value') {
				this.metadataValue = value
			}
			this.emitUpdate()
		},

		/**
		 * REQ-NEWS-002: validate that every feed URL begins with http://
		 * or https://. Empty list is allowed (the placement just renders
		 * the empty state).
		 *
		 * @return {string[]} validation errors (empty when ok)
		 */
		/** @spec openspec/specs/news-widget/spec.md */
		validate() {
			const errors = []
			for (const url of this.feedUrls) {
				if (typeof url !== 'string' || url.trim() === '') {
					errors.push(t('mydash', 'Empty feed URL — remove it or fill it in'))
					continue
				}
				if (!/^https?:\/\//i.test(url.trim())) {
					errors.push(t('mydash', 'Feed URL must start with http:// or https://'))
				}
			}
			if (this.metadataFilterEnabled) {
				if (this.metadataFieldKey.trim() === '') {
					errors.push(t('mydash', 'Metadata field key is required when filter is enabled'))
				}
			}
			return errors
		},
	},
}
</script>

<style scoped>
.news-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.news-form__label {
	font-size: 13px;
	font-weight: 600;
}

.news-form__feed-row {
	display: flex;
	align-items: flex-end;
	gap: 6px;
}

.news-form__feed-remove {
	width: 32px;
	height: 32px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	cursor: pointer;
	font-size: 18px;
	line-height: 1;
}

.news-form__feed-add {
	align-self: flex-start;
	padding: 6px 12px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 13px;
}

.news-form__metadata {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-left: 16px;
	border-left: 2px solid var(--color-border);
}
</style>
