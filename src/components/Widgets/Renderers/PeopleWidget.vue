<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="people-widget" :class="layoutClass">
		<header class="people-widget__header">
			<input
				v-model="search"
				type="search"
				class="people-widget__search"
				:placeholder="t('mydash', 'Search by name or email…')">
			<button
				type="button"
				class="people-widget__refresh"
				:title="t('mydash', 'Refresh')"
				:aria-label="t('mydash', 'Refresh')"
				@click="forceRefresh">
				&#x21bb;
			</button>
		</header>

		<div v-if="loading && users.length === 0" class="people-widget__state">
			{{ t('mydash', 'Loading…') }}
		</div>

		<div v-else-if="error" class="people-widget__state people-widget__state--error">
			<p>{{ t('mydash', 'Failed to load users') }}</p>
			<button type="button" class="people-widget__retry" @click="forceRefresh">
				{{ t('mydash', 'Retry') }}
			</button>
		</div>

		<div v-else-if="filteredUsers.length === 0" class="people-widget__state">
			{{ search ? t('mydash', 'No users match your search') : t('mydash', 'No matching users.') }}
		</div>

		<div
			v-else
			class="people-widget__items"
			:style="gridStyle"
			role="list">
			<a
				v-for="user in filteredUsers"
				:key="user.uid"
				:href="profileUrl(user.uid)"
				class="people-widget__item"
				role="listitem">
				<img
					:src="user.avatarUrl"
					:width="avatarSize"
					:height="avatarSize"
					:alt="t('mydash', 'Avatar of {name}', { name: user.displayName })"
					class="people-widget__avatar">

				<div class="people-widget__meta">
					<strong class="people-widget__name">{{ user.displayName }}</strong>
					<span v-if="layout !== 'grid' && user.role" class="people-widget__role">
						{{ user.role }}
					</span>
					<span v-if="layout !== 'grid' && (user.organisation || user.department)" class="people-widget__org">
						{{ user.organisation || user.department }}
					</span>
					<span v-if="layout !== 'grid' && user.email" class="people-widget__email">
						{{ user.email }}
					</span>
					<span v-if="showBirthdayBadge(user)" class="people-widget__birthday">
						{{ formatBirthdayBadge(user) }}
					</span>
				</div>
			</a>
		</div>

		<footer v-if="hasMore && !error" class="people-widget__footer">
			<button
				type="button"
				class="people-widget__load-more"
				:disabled="loading"
				@click="loadMore">
				{{ loading ? t('mydash', 'Loading…') : t('mydash', 'Load more') }}
			</button>
		</footer>
	</div>
</template>

<script>
const CACHE_TTL_MS = 60 * 1000

const DEFAULT_CONTENT = Object.freeze({
	layout: 'grid',
	filters: [],
	excludeDisabled: true,
	showBirthdays: true,
	birthdayWindowDays: 7,
	sortBy: 'displayName',
	columns: 3,
})

/**
 * PeopleWidget — renders a paginated, group-filterable user directory
 * (capability `people-widget`, REQ-PPL-001..012).
 *
 * Three layout modes (`card`, `grid`, `list`) backed by the same item
 * markup; CSS modifiers tune avatar size and column count. Search is a
 * client-side substring filter on the current page (REQ-PPL-011).
 *
 * Pagination is offset-based and matches the backend service contract
 * (REQ-PPL-003): each "Load more" tap appends one page of size
 * {@link PAGE_SIZE} starting at the current `users.length`.
 *
 * Caching: results are kept in an in-memory map keyed on the JSON-encoded
 * filter shape for {@link CACHE_TTL_MS}; the toolbar refresh button
 * clears the cache and refetches (REQ-PPL-012).
 *
 * Click-through (REQ-PPL-010): each item is rendered as an `<a>` linking
 * to `/u/{uid}` so the browser does the navigation natively (same tab,
 * keyboard accessible by default).
 */
export default {
	name: 'PeopleWidget',

	props: {
		/**
		 * Persisted widget content (see `DEFAULT_CONTENT` for shape).
		 */
		content: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	data() {
		return {
			users: [],
			total: 0,
			hasMore: false,
			loading: false,
			error: null,
			search: '',
			cacheKey: '',
			cacheStoredAt: 0,
		}
	},

	computed: {
		layout() {
			const value = this.content?.layout
			return ['card', 'grid', 'list'].includes(value) ? value : DEFAULT_CONTENT.layout
		},

		layoutClass() {
			return `people-widget--${this.layout}`
		},

		columns() {
			const raw = this.content?.columns
			if (typeof raw === 'number' && [2, 3, 4].includes(raw)) {
				return raw
			}
			return this.layout === 'grid' ? 4 : 3
		},

		gridStyle() {
			if (this.layout === 'list') {
				return {}
			}
			return { 'grid-template-columns': `repeat(${this.columns}, minmax(0, 1fr))` }
		},

		avatarSize() {
			switch (this.layout) {
			case 'card':
				return 80
			case 'list':
				return 44
			case 'grid':
			default:
				return 64
			}
		},

		showBirthdays() {
			return this.content?.showBirthdays !== false
		},

		birthdayWindowDays() {
			const value = this.content?.birthdayWindowDays
			if (typeof value === 'number' && value >= 0 && value <= 30) {
				return value
			}
			return DEFAULT_CONTENT.birthdayWindowDays
		},

		filteredUsers() {
			if (!this.search) {
				return this.users
			}
			const needle = this.search.toLowerCase()
			return this.users.filter((user) => {
				const name = (user.displayName || '').toLowerCase()
				const email = (user.email || '').toLowerCase()
				return name.includes(needle) || email.includes(needle)
			})
		},

		queryParams() {
			const filters = Array.isArray(this.content?.filters) ? this.content.filters : []
			return {
				filters: JSON.stringify(filters),
				excludeDisabled: this.content?.excludeDisabled === false ? 0 : 1,
				showBirthdays: this.content?.showBirthdays === false ? 0 : 1,
				sortBy: this.content?.sortBy || DEFAULT_CONTENT.sortBy,
			}
		},
	},

	watch: {
		queryParams: {
			handler() {
				this.cacheKey = ''
				this.cacheStoredAt = 0
				this.users = []
				this.total = 0
				this.hasMore = false
				this.fetchPage(0)
			},
			deep: true,
		},
	},

	mounted() {
		this.fetchPage(0)
	},

	methods: {
		profileUrl(uid) {
			return `/u/${encodeURIComponent(uid)}`
		},

		showBirthdayBadge(user) {
			if (!this.showBirthdays || !user.birthdate) {
				return false
			}
			const days = this.daysToBirthday(user.birthdate)
			if (days === null) {
				return false
			}
			return days >= 0 && days <= this.birthdayWindowDays
		},

		formatBirthdayBadge(user) {
			const days = this.daysToBirthday(user.birthdate)
			if (days === 0) {
				return t('mydash', '🎂 today')
			}
			return t('mydash', '🎂 in {n} days', { n: days })
		},

		daysToBirthday(iso) {
			if (typeof iso !== 'string' || !iso) {
				return null
			}
			const parts = iso.split('-')
			if (parts.length !== 3) {
				return null
			}
			const [, monthStr, dayStr] = parts
			const month = Number(monthStr)
			const day = Number(dayStr)
			if (!Number.isFinite(month) || !Number.isFinite(day)) {
				return null
			}
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			let candidate = new Date(today.getFullYear(), month - 1, day)
			if (candidate < today) {
				candidate = new Date(today.getFullYear() + 1, month - 1, day)
			}
			const diffMs = candidate.getTime() - today.getTime()
			return Math.round(diffMs / (24 * 60 * 60 * 1000))
		},

		async loadMore() {
			if (this.loading || !this.hasMore) {
				return
			}
			await this.fetchPage(this.users.length)
		},

		forceRefresh() {
			this.cacheKey = ''
			this.cacheStoredAt = 0
			this.users = []
			this.total = 0
			this.hasMore = false
			this.fetchPage(0)
		},

		async fetchPage(offset) {
			const cacheKey = JSON.stringify({ params: this.queryParams, offset })
			const fresh = this.cacheKey === cacheKey
				&& (Date.now() - this.cacheStoredAt) < CACHE_TTL_MS
			if (fresh && this.users.length > 0) {
				return
			}

			this.loading = true
			this.error = null
			try {
				const params = new URLSearchParams()
				Object.entries(this.queryParams).forEach(([key, value]) => {
					params.append(key, String(value))
				})
				params.append('limit', '50')
				params.append('offset', String(offset))

				// Lazy import @nextcloud/* helpers — keeps the renderer
				// out of the vitest css-no-op transform path for the
				// majority of tests that don't exercise the network call.
				const [{ default: axios }, { generateUrl }] = await Promise.all([
					import('@nextcloud/axios'),
					import('@nextcloud/router'),
				])

				const url = `${generateUrl('/apps/mydash/api/people')}?${params.toString()}`
				const response = await axios.get(url)
				const data = response?.data || {}

				const incoming = Array.isArray(data.users) ? data.users : []
				if (offset === 0) {
					this.users = incoming
				} else {
					this.users = this.users.concat(incoming)
				}
				this.total = typeof data.total === 'number' ? data.total : this.users.length
				this.hasMore = data.hasMore === true

				this.cacheKey = cacheKey
				this.cacheStoredAt = Date.now()
			} catch (err) {
				this.error = err
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.people-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
	gap: 8px;
	padding: 8px;
	overflow: hidden;
}

.people-widget__header {
	display: flex;
	gap: 6px;
	align-items: center;
	flex: 0 0 auto;
}

.people-widget__search {
	flex: 1 1 auto;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.people-widget__refresh {
	border: 1px solid var(--color-border);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 2px 8px;
	cursor: pointer;
	font-size: 14px;
}

.people-widget__items {
	flex: 1 1 auto;
	overflow-y: auto;
	display: grid;
	gap: 8px;
}

.people-widget--list .people-widget__items {
	display: flex;
	flex-direction: column;
}

.people-widget__item {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 6px;
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	text-decoration: none;
	border: 1px solid transparent;
}

.people-widget--card .people-widget__item {
	flex-direction: column;
	text-align: center;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	min-height: 200px;
}

.people-widget--grid .people-widget__item {
	flex-direction: column;
	text-align: center;
}

.people-widget__item:hover {
	background: var(--color-background-hover);
	border-color: var(--color-border);
}

.people-widget__avatar {
	border-radius: 50%;
	object-fit: cover;
	background: var(--color-background-darker);
}

.people-widget__meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.people-widget__name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 100%;
}

.people-widget__role,
.people-widget__org,
.people-widget__email {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.people-widget__birthday {
	font-size: 12px;
	color: var(--color-warning, #c4a000);
}

.people-widget__state {
	flex: 1 1 auto;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	padding: 16px;
	text-align: center;
}

.people-widget__state--error {
	flex-direction: column;
	gap: 8px;
}

.people-widget__retry,
.people-widget__load-more {
	padding: 4px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	cursor: pointer;
	font-size: 13px;
}

.people-widget__footer {
	flex: 0 0 auto;
	display: flex;
	justify-content: center;
	padding-top: 4px;
}
</style>
