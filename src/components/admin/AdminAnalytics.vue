<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<section class="mydash-analytics">
		<header class="mydash-analytics__header">
			<h3>{{ t('mydash', 'View analytics') }}</h3>
			<p class="mydash-analytics__hint">
				{{ t('mydash', 'Aggregate, privacy-preserving view counts per dashboard. Unique-viewer dedup uses a daily-rotating salted hash; no user identifiers are stored.') }}
			</p>
		</header>

		<!-- Period selector -->
		<div class="mydash-analytics__field">
			<label for="mydash-analytics-period" class="mydash-analytics__label">
				{{ t('mydash', 'Period') }}
			</label>
			<select
				id="mydash-analytics-period"
				v-model="period"
				class="mydash-analytics__select"
				@change="reload">
				<option value="7d">
					{{ t('mydash', 'Last 7 days') }}
				</option>
				<option value="30d">
					{{ t('mydash', 'Last 30 days') }}
				</option>
				<option value="90d">
					{{ t('mydash', 'Last 90 days') }}
				</option>
			</select>
		</div>

		<div v-if="loading" class="mydash-analytics__loading">
			{{ t('mydash', 'Loading analytics…') }}
		</div>

		<div v-else-if="error" class="mydash-analytics__error">
			{{ error }}
		</div>

		<template v-else>
			<!-- Instance summary -->
			<div class="mydash-analytics__summary">
				<div class="mydash-analytics__metric">
					<div class="mydash-analytics__metric-label">
						{{ t('mydash', 'Total views') }}
					</div>
					<div class="mydash-analytics__metric-value">
						{{ summary.totalViewCount }}
					</div>
				</div>
				<div class="mydash-analytics__metric">
					<div class="mydash-analytics__metric-label">
						{{ t('mydash', 'Unique viewers') }}
					</div>
					<div class="mydash-analytics__metric-value">
						{{ summary.totalUniqueViewers }}
					</div>
				</div>
				<div class="mydash-analytics__metric">
					<div class="mydash-analytics__metric-label">
						{{ t('mydash', 'Dashboards seen') }}
					</div>
					<div class="mydash-analytics__metric-value">
						{{ summary.dashboardCount }}
					</div>
				</div>
			</div>

			<!-- Top dashboards table -->
			<h4 class="mydash-analytics__subheader">
				{{ t('mydash', 'Top dashboards') }}
			</h4>
			<table v-if="topDashboards.length" class="mydash-analytics__table">
				<thead>
					<tr>
						<th>{{ t('mydash', 'Dashboard') }}</th>
						<th class="mydash-analytics__num">
							{{ t('mydash', 'Views') }}
						</th>
						<th class="mydash-analytics__num">
							{{ t('mydash', 'Unique viewers') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in topDashboards" :key="row.dashboardUuid">
						<td>{{ row.name || row.dashboardUuid }}</td>
						<td class="mydash-analytics__num">
							{{ row.viewCount }}
						</td>
						<td class="mydash-analytics__num">
							{{ row.uniqueViewerCount }}
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="mydash-analytics__hint">
				{{ t('mydash', 'No view events recorded yet for this period.') }}
			</p>

			<!-- Export CSV button -->
			<div class="mydash-analytics__actions">
				<button
					type="button"
					class="mydash-analytics__button"
					:disabled="exporting"
					@click="exportCsv">
					{{ exporting
						? t('mydash', 'Exporting…')
						: t('mydash', 'Export analytics (CSV)') }}
				</button>
			</div>
		</template>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { api } from '../../services/api.js'

export default {
	name: 'AdminAnalytics',
	data() {
		return {
			period: '30d',
			loading: false,
			exporting: false,
			error: null,
			summary: {
				totalViewCount: 0,
				totalUniqueViewers: 0,
				dashboardCount: 0,
				period: '30d',
				top5: [],
			},
			topDashboards: [],
		}
	},
	created() {
		this.reload()
	},
	methods: {
		t,
		/**
		 * REQ-ANLT-006 / REQ-ANLT-008 — fetch the summary + top 10
		 * dashboards for the currently selected period.
		 *
		 * @return {Promise<void>}
		 */
		async reload() {
			this.loading = true
			this.error = null
			try {
				const [summaryResp, topResp] = await Promise.all([
					api.getAnalyticsInstanceSummary(this.period),
					api.getAnalyticsTopDashboards(this.period, 10),
				])
				this.summary = summaryResp.data || {
					totalViewCount: 0,
					totalUniqueViewers: 0,
					dashboardCount: 0,
					period: this.period,
					top5: [],
				}
				this.topDashboards = Array.isArray(topResp.data)
					? topResp.data
					: []
			} catch (e) {
				console.error('Failed to load analytics:', e)
				this.error = t(
					'mydash',
					'Failed to load analytics data. Please try again.',
				)
			} finally {
				this.loading = false
			}
		},
		/**
		 * REQ-ANLT-010 — trigger a CSV export download of the
		 * currently selected period.
		 *
		 * @return {Promise<void>}
		 */
		async exportCsv() {
			this.exporting = true
			try {
				const response = await api.getAnalyticsCsvExport(this.period)
				const blob = new Blob(
					[response.data],
					{ type: 'text/csv' },
				)
				const url = URL.createObjectURL(blob)
				const today = new Date().toISOString().slice(0, 10)
				const a = document.createElement('a')
				a.href = url
				a.download = `dashboard-analytics-${today}.csv`
				document.body.appendChild(a)
				a.click()
				document.body.removeChild(a)
				URL.revokeObjectURL(url)
			} catch (e) {
				console.error('Failed to export analytics CSV:', e)
				this.error = t(
					'mydash',
					'Failed to export analytics CSV. Please try again.',
				)
			} finally {
				this.exporting = false
			}
		},
	},
}
</script>

<style scoped>
.mydash-analytics {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.mydash-analytics__hint {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin: 4px 0 12px;
}

.mydash-analytics__field {
	margin: 12px 0;
	display: flex;
	align-items: center;
	gap: 8px;
}

.mydash-analytics__label {
	font-weight: 600;
}

.mydash-analytics__select {
	min-width: 160px;
}

.mydash-analytics__summary {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	margin: 16px 0;
}

.mydash-analytics__metric {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 12px 16px;
	min-width: 140px;
}

.mydash-analytics__metric-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.mydash-analytics__metric-value {
	font-size: 20px;
	font-weight: 700;
	margin-top: 4px;
}

.mydash-analytics__subheader {
	margin-top: 16px;
}

.mydash-analytics__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 8px;
}

.mydash-analytics__table th,
.mydash-analytics__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.mydash-analytics__num {
	text-align: right;
}

.mydash-analytics__actions {
	margin-top: 16px;
}

.mydash-analytics__button {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border: none;
	border-radius: var(--border-radius);
	padding: 8px 14px;
	cursor: pointer;
}

.mydash-analytics__button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.mydash-analytics__loading,
.mydash-analytics__error {
	margin: 16px 0;
}

.mydash-analytics__error {
	color: var(--color-error);
}
</style>
