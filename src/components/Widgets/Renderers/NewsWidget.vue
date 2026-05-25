<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="news-widget" :class="layoutClass">
		<div v-if="failedCount > 0" class="news-widget__badge" :title="failedTooltip">
			{{ failedBadgeLabel }}
		</div>

		<div v-if="loading" class="news-widget__state news-widget__state--loading">
			{{ t('mydash', 'Loading news…') }}
		</div>

		<div v-else-if="hasError" class="news-widget__state news-widget__state--error">
			{{ t('mydash', 'Unable to load news. Please try again later.') }}
		</div>

		<div v-else-if="items.length === 0" class="news-widget__state news-widget__state--empty">
			{{ t('mydash', 'No news yet — try adding feeds in the widget settings') }}
		</div>

		<ul v-else class="news-widget__list">
			<li v-for="item in items" :key="item.guid" class="news-widget__item">
				<a
					v-if="item.link"
					:href="item.link"
					target="_blank"
					rel="noopener noreferrer"
					class="news-widget__item-link"
					@click="onItemClick($event, item)">
					<img
						v-if="showThumbnails && item.thumbnailUrl"
						class="news-widget__thumb"
						:src="item.thumbnailUrl"
						:alt="''">
					<div class="news-widget__body">
						<h4 class="news-widget__title">{{ item.title }}</h4>
						<p
							v-if="showSummary && item.summary"
							class="news-widget__summary"
							v-html="formattedSummary(item)" />
						<div class="news-widget__meta">
							<span class="news-widget__source">{{ item.sourceTitle }}</span>
							<span class="news-widget__date">{{ formatDate(item.pubDate) }}</span>
						</div>
					</div>
				</a>
				<div v-else class="news-widget__item-link news-widget__item-link--inert">
					<img
						v-if="showThumbnails && item.thumbnailUrl"
						class="news-widget__thumb"
						:src="item.thumbnailUrl"
						:alt="''">
					<div class="news-widget__body">
						<h4 class="news-widget__title">
							{{ item.title }}
						</h4>
						<p
							v-if="showSummary && item.summary"
							class="news-widget__summary"
							v-html="formattedSummary(item)" />
						<div class="news-widget__meta">
							<span class="news-widget__source">{{ item.sourceTitle }}</span>
							<span class="news-widget__date">{{ formatDate(item.pubDate) }}</span>
						</div>
					</div>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const ALLOWED_LAYOUTS = ['list', 'grid', 'carousel']
const DEFAULT_LAYOUT = 'list'
const DEFAULT_LIMIT = 10

/**
 * NewsWidget — renders merged RSS/Atom feed items returned by
 * `GET /api/widgets/news/{placementId}/items`. Three layout modes are
 * supported (list, grid, carousel) — switched purely via a CSS class
 * on the wrapper. The backend response shape is:
 *
 *   {
 *     items: [{guid, title, summary, link, pubDate, sourceUrl, sourceTitle, thumbnailUrl}],
 *     feedsFailed: <int>,
 *     failedUrls: [<string>, …]
 *   }
 *
 * REQ-NEWS-008/010: a single feed failure does NOT break the widget;
 * successful sources still render and the failure count surfaces in
 * a top-right badge with a tooltip listing the failed URLs.
 *
 * REQ-NEWS-011: item links open in a new tab with `noopener,noreferrer`.
 * The `<a>` tag carries the rel attributes natively so middle-click /
 * cmd-click also stay safe; the JavaScript handler is a defence-in-depth
 * `window.open` for environments that intercept anchor clicks.
 */
export default {
	name: 'NewsWidget',

	props: {
		/** Persisted widget content blob (placement.styleConfig.content). */
		content: {
			type: Object,
			default: () => ({}),
		},
		/** Placement entity carrying the id we hit the API with. */
		placement: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			items: [],
			failedCount: 0,
			failedUrls: [],
			loading: false,
			hasError: false,
		}
	},

	computed: {
		/** @spec openspec/specs/news-widget/spec.md */
		feedUrls() {
			const value = this.content && this.content.feedUrls
			return Array.isArray(value) ? value : []
		},

		/** @spec openspec/specs/news-widget/spec.md */
		layout() {
			const value = this.content && this.content.layout
			if (typeof value !== 'string' || ALLOWED_LAYOUTS.includes(value) === false) {
				return DEFAULT_LAYOUT
			}
			return value
		},

		/** @spec openspec/specs/news-widget/spec.md */
		layoutClass() {
			return `news-widget--${this.layout}`
		},

		/** @spec openspec/specs/news-widget/spec.md */
		itemLimit() {
			const value = this.content && this.content.itemLimit
			if (typeof value !== 'number' || value < 1 || value > 50) {
				return DEFAULT_LIMIT
			}
			return value
		},

		/** @spec openspec/specs/news-widget/spec.md */
		showThumbnails() {
			const value = this.content && this.content.showThumbnails
			return value === undefined ? true : value === true
		},

		/** @spec openspec/specs/news-widget/spec.md */
		showSummary() {
			const value = this.content && this.content.showSummary
			return value === undefined ? true : value === true
		},

		/** @spec openspec/specs/news-widget/spec.md */
		summaryMaxChars() {
			const value = this.content && this.content.summaryMaxChars
			if (typeof value !== 'number' || value < 0) {
				return 200
			}
			return value
		},

		/** @spec openspec/specs/news-widget/spec.md */
		dateFormat() {
			const value = this.content && this.content.dateFormat
			return value === 'absolute' ? 'absolute' : 'relative'
		},

		/** @spec openspec/specs/news-widget/spec.md */
		placementId() {
			return this.placement && this.placement.id ? this.placement.id : null
		},

		/** @spec openspec/specs/news-widget/spec.md */
		failedBadgeLabel() {
			if (this.failedCount === 1) {
				return t('mydash', '1 feed failed')
			}
			return t('mydash', '{count} feeds failed').replace('{count}', String(this.failedCount))
		},

		/** @spec openspec/specs/news-widget/spec.md */
		failedTooltip() {
			if (this.failedUrls.length === 0) {
				return ''
			}
			return t('mydash', 'Failed: {urls}').replace('{urls}', this.failedUrls.join(', '))
		},
	},

	watch: {
		placementId: {
			immediate: true,
			/** @spec openspec/specs/news-widget/spec.md */
			handler(newId) {
				if (newId !== null && newId !== undefined) {
					this.loadItems()
				}
			},
		},
	},

	methods: {
		/** @spec openspec/specs/news-widget/spec.md */
		async loadItems() {
			if (this.placementId === null) {
				return
			}

			this.loading = true
			this.hasError = false

			try {
				const url = generateUrl('/apps/mydash/api/widgets/news/{placementId}/items', {
					placementId: this.placementId,
				})
				const response = await axios.get(url, { params: { limit: this.itemLimit } })
				const data = response && response.data ? response.data : {}
				this.items = Array.isArray(data.items) ? data.items : []
				this.failedCount = typeof data.feedsFailed === 'number' ? data.feedsFailed : 0
				this.failedUrls = Array.isArray(data.failedUrls) ? data.failedUrls : []
			} catch (err) {
				this.hasError = true
				this.items = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Defence-in-depth click handler. The native `<a target="_blank"
		 * rel="noopener noreferrer">` already opens safely; this handler
		 * exists so unit tests can assert the expected `window.open`
		 * contract without relying on jsdom's anchor-click behaviour.
		 *
		 * @param {Event}  event MouseEvent
		 * @param {object} item  the rendered news item
		 */
		/** @spec openspec/specs/news-widget/spec.md */
		onItemClick(event, item) {
			if (!item || !item.link) {
				return
			}
			// Keep the native anchor behaviour; the `window.open` below is
			// only needed when consumers preventDefault() the anchor.
			if (event && event.defaultPrevented) {
				window.open(item.link, '_blank', 'noopener,noreferrer')
			}
		},

		/**
		 * Truncate the (already server-sanitised) summary HTML to the
		 * placement's `summaryMaxChars` budget. Truncation operates on
		 * raw bytes; the frontend never re-sanitises.
		 *
		 * @param {object} item news item
		 * @return {string} truncated summary HTML
		 */
		/** @spec openspec/specs/news-widget/spec.md */
		formattedSummary(item) {
			const raw = typeof item.summary === 'string' ? item.summary : ''
			if (raw.length <= this.summaryMaxChars) {
				return raw
			}
			return raw.slice(0, this.summaryMaxChars) + '…'
		},

		/**
		 * Format ISO 8601 publication dates per the placement's
		 * `dateFormat` setting. `relative` produces "2 hours ago" style;
		 * `absolute` produces a locale-aware date+time string. Invalid
		 * input falls back to the empty string so the meta line stays
		 * visually intact.
		 *
		 * @param {string} pubDate ISO 8601 date string
		 * @return {string} formatted date label
		 */
		/** @spec openspec/specs/news-widget/spec.md */
		formatDate(pubDate) {
			if (typeof pubDate !== 'string' || pubDate === '') {
				return ''
			}
			const ts = Date.parse(pubDate)
			if (Number.isNaN(ts)) {
				return ''
			}
			if (this.dateFormat === 'absolute') {
				return new Date(ts).toISOString().slice(0, 16).replace('T', ' ')
			}
			const diffSeconds = Math.max(0, Math.floor((Date.now() - ts) / 1000))
			if (diffSeconds < 60) {
				return t('mydash', 'just now')
			}
			const diffMinutes = Math.floor(diffSeconds / 60)
			if (diffMinutes < 60) {
				return t('mydash', '{n} minutes ago').replace('{n}', String(diffMinutes))
			}
			const diffHours = Math.floor(diffMinutes / 60)
			if (diffHours < 24) {
				return t('mydash', '{n} hours ago').replace('{n}', String(diffHours))
			}
			const diffDays = Math.floor(diffHours / 24)
			return t('mydash', '{n} days ago').replace('{n}', String(diffDays))
		},
	},
}
</script>

<style scoped>
.news-widget {
	position: relative;
	width: 100%;
	height: 100%;
	overflow: hidden;
	display: flex;
	flex-direction: column;
}

.news-widget__badge {
	position: absolute;
	top: 6px;
	right: 6px;
	padding: 2px 8px;
	font-size: 11px;
	background: var(--color-warning, #ffcc00);
	color: var(--color-main-text, #000);
	border-radius: 12px;
	z-index: 2;
}

.news-widget__state {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.news-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	overflow: auto;
	flex: 1;
}

.news-widget--list .news-widget__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.news-widget--grid .news-widget__list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 12px;
}

.news-widget--carousel .news-widget__list {
	display: flex;
	flex-direction: row;
	gap: 12px;
	overflow-x: auto;
	overflow-y: hidden;
}

.news-widget--carousel .news-widget__item {
	flex: 0 0 220px;
}

.news-widget__item-link {
	display: flex;
	gap: 8px;
	padding: 8px;
	color: inherit;
	text-decoration: none;
	border-radius: var(--border-radius, 4px);
	transition: background-color 0.15s ease;
}

.news-widget__item-link:hover {
	background-color: var(--color-background-hover);
}

.news-widget__item-link--inert {
	cursor: default;
}

.news-widget--grid .news-widget__item-link,
.news-widget--carousel .news-widget__item-link {
	flex-direction: column;
}

.news-widget__thumb {
	width: 64px;
	height: 64px;
	object-fit: cover;
	border-radius: var(--border-radius, 4px);
	flex-shrink: 0;
}

.news-widget--grid .news-widget__thumb,
.news-widget--carousel .news-widget__thumb {
	width: 100%;
	height: 96px;
}

.news-widget__body {
	flex: 1;
	min-width: 0;
}

.news-widget__title {
	margin: 0 0 4px 0;
	font-size: 14px;
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.news-widget__summary {
	margin: 0 0 4px 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
}

.news-widget__meta {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}
</style>
