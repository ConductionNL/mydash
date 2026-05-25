<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - FeedTokenManager
  -
  - Self-contained card surface that lets an end user opt-in to the
  - per-user RSS / Atom feed (REQ-FEED-001, REQ-FEED-008), rotate the
  - token (REQ-FEED-002), or soft-revoke it (REQ-FEED-003). Wraps the
  - three /api/feed/token endpoints and exposes a copy-to-clipboard
  - action for the absolute feed URL returned by the backend.
  -->

<template>
	<div class="mydash-feed-token">
		<h3 class="mydash-feed-token__title">
			{{ t('mydash', 'RSS / Atom feed') }}
		</h3>
		<p class="mydash-feed-token__description">
			{{ t('mydash', 'Your personal RSS feed of accessible dashboards.') }}
		</p>

		<div v-if="loading" class="mydash-feed-token__loading">
			{{ t('mydash', 'Loading…') }}
		</div>

		<div v-else-if="hasToken" class="mydash-feed-token__active">
			<div class="mydash-feed-token__url-row">
				<input
					ref="urlInput"
					type="text"
					readonly
					class="mydash-feed-token__url"
					:value="feedUrl">
				<button
					type="button"
					class="mydash-feed-token__copy"
					@click="copyUrl">
					{{ t('mydash', 'Copy feed URL') }}
				</button>
			</div>
			<p class="mydash-feed-token__warning">
				{{ t('mydash', 'Treat this URL as a password — anyone with the link can read your dashboards.') }}
			</p>
			<div class="mydash-feed-token__actions">
				<button type="button" @click="regenerate">
					{{ t('mydash', 'Regenerate feed token') }}
				</button>
				<button type="button" class="mydash-feed-token__revoke" @click="revoke">
					{{ t('mydash', 'Revoke feed token') }}
				</button>
			</div>
		</div>

		<div v-else class="mydash-feed-token__inactive">
			<p>{{ t('mydash', 'No feed token issued yet.') }}</p>
			<button type="button" @click="enable">
				{{ t('mydash', 'Generate feed token') }}
			</button>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { api } from '../services/api.js'

export default {
	name: 'FeedTokenManager',

	data() {
		return {
			loading: false,
			feedUrl: '',
			feedToken: '',
		}
	},

	computed: {
		hasToken() {
			return this.feedUrl !== '' && this.feedToken !== ''
		},
	},

	methods: {
		t,

		/** @spec openspec/specs/dashboard-rss-feeds/spec.md */
		async enable() {
			this.loading = true
			try {
				const { data } = await api.getFeedToken()
				this.applyToken(data)
			} catch (error) {
				showError(t('mydash', 'Generate feed token'))
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/dashboard-rss-feeds/spec.md */
		async regenerate() {
			this.loading = true
			try {
				const { data } = await api.regenerateFeedToken()
				this.applyToken(data)
			} catch (error) {
				showError(t('mydash', 'Regenerate feed token'))
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/dashboard-rss-feeds/spec.md */
		async revoke() {
			this.loading = true
			try {
				await api.revokeFeedToken()
				this.feedUrl = ''
				this.feedToken = ''
			} catch (error) {
				showError(t('mydash', 'Revoke feed token'))
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/dashboard-rss-feeds/spec.md */
		applyToken(payload) {
			if (!payload) {
				return
			}
			this.feedToken = payload.token || ''
			this.feedUrl = payload.url || ''
		},

		/** @spec openspec/specs/dashboard-rss-feeds/spec.md */
		async copyUrl() {
			if (!this.feedUrl) {
				return
			}
			try {
				await navigator.clipboard.writeText(this.feedUrl)
				showSuccess(t('mydash', 'Feed URL copied to clipboard'))
			} catch (error) {
				if (this.$refs.urlInput) {
					this.$refs.urlInput.select()
				}
				showError(t('mydash', 'Copy feed URL'))
			}
		},
	},
}
</script>

<style scoped>
.mydash-feed-token {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.mydash-feed-token__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.mydash-feed-token__description {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.mydash-feed-token__url-row {
	display: flex;
	gap: 8px;
}

.mydash-feed-token__url {
	flex: 1;
	font-family: monospace;
	font-size: 12px;
	padding: 6px 8px;
}

.mydash-feed-token__warning {
	color: var(--color-warning);
	margin: 0;
	font-size: 12px;
}

.mydash-feed-token__actions {
	display: flex;
	gap: 8px;
}

.mydash-feed-token__revoke {
	color: var(--color-error);
}
</style>
