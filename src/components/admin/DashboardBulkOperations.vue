<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Bulk dashboard operations widget (REQ-BULK-010).
  -
  - Renders a multi-select table over the visible dashboards plus an
  - "Actions" dropdown that wires into the four bulk endpoints exposed by
  - {@link OCA\\MyDash\\Controller\\AdminBulkController}. Every action
  - supports a "Dry run (preview only)" toggle, surfaces a summary of the
  - per-uuid result envelope, and clears the selection on a successful
  - non-dry-run run (REQ-BULK-008).
  -->

<template>
	<div class="mydash-bulk-ops" data-test="bulk-ops">
		<h3>{{ t('mydash', 'Bulk dashboard operations') }}</h3>
		<p class="mydash-bulk-ops__hint">
			{{ t('mydash', 'Select multiple dashboards and apply an admin action to all of them at once. Every operation supports a dry-run preview.') }}
		</p>

		<div v-if="loading" class="mydash-bulk-ops__loading">
			{{ t('mydash', 'Loading dashboards…') }}
		</div>

		<table v-else class="mydash-bulk-ops__table" data-test="bulk-ops-table">
			<thead>
				<tr>
					<th class="mydash-bulk-ops__col-select">
						<input
							type="checkbox"
							:checked="allSelected"
							:indeterminate.prop="someSelected && !allSelected"
							data-test="bulk-ops-select-all"
							@change="toggleSelectAll">
					</th>
					<th>{{ t('mydash', 'Name') }}</th>
					<th>{{ t('mydash', 'Status') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="dash in dashboards"
					:key="dash.uuid"
					:class="{ 'mydash-bulk-ops__row--selected': selectedUuids.includes(dash.uuid) }">
					<td>
						<input
							type="checkbox"
							:value="dash.uuid"
							:checked="selectedUuids.includes(dash.uuid)"
							data-test="bulk-ops-row-select"
							@change="toggleRow(dash.uuid)">
					</td>
					<td>{{ dash.name }}</td>
					<td>{{ dash.publicationStatus || 'published' }}</td>
				</tr>
			</tbody>
		</table>

		<div class="mydash-bulk-ops__actions">
			<select
				v-model="selectedAction"
				:disabled="selectedUuids.length === 0"
				data-test="bulk-ops-action-select">
				<option value="">
					{{ t('mydash', 'Actions…') }}
				</option>
				<option value="delete">
					{{ t('mydash', 'Delete') }}
				</option>
				<option value="move">
					{{ t('mydash', 'Move to…') }}
				</option>
				<option value="status">
					{{ t('mydash', 'Set status') }}
				</option>
				<option value="reindex">
					{{ t('mydash', 'Reindex') }}
				</option>
			</select>
			<button
				:disabled="selectedUuids.length === 0 || selectedAction === '' || running"
				data-test="bulk-ops-run"
				@click="runAction">
				{{ running ? t('mydash', 'Working…') : t('mydash', 'Apply') }}
			</button>
		</div>

		<div v-if="modal" class="mydash-bulk-ops__modal" data-test="bulk-ops-modal">
			<div class="mydash-bulk-ops__modal-inner">
				<h4>{{ modal.title }}</h4>
				<p>{{ modal.message }}</p>

				<div v-if="modal.action === 'move'" class="mydash-bulk-ops__field">
					<label>{{ t('mydash', 'New parent UUID (leave empty for root)') }}</label>
					<input
						v-model="parentUuidInput"
						type="text"
						data-test="bulk-ops-parent-uuid">
				</div>

				<div v-if="modal.action === 'status'" class="mydash-bulk-ops__field">
					<label>{{ t('mydash', 'Publication status') }}</label>
					<select v-model="statusInput" data-test="bulk-ops-status-select">
						<option value="draft">
							{{ t('mydash', 'Draft') }}
						</option>
						<option value="published">
							{{ t('mydash', 'Published') }}
						</option>
						<option value="scheduled">
							{{ t('mydash', 'Scheduled') }}
						</option>
					</select>
					<input
						v-if="statusInput === 'scheduled'"
						v-model="publishAtInput"
						type="datetime-local"
						data-test="bulk-ops-publish-at">
				</div>

				<label class="mydash-bulk-ops__dryrun">
					<input
						v-model="dryRun"
						type="checkbox"
						data-test="bulk-ops-dryrun">
					{{ t('mydash', 'Dry run (preview only)') }}
				</label>

				<div class="mydash-bulk-ops__modal-actions">
					<button @click="cancelModal">
						{{ t('mydash', 'Cancel') }}
					</button>
					<button
						:disabled="running"
						data-test="bulk-ops-confirm"
						@click="confirmAction">
						{{ t('mydash', 'OK') }}
					</button>
				</div>
			</div>
		</div>

		<div v-if="resultSummary" class="mydash-bulk-ops__result" data-test="bulk-ops-result">
			<p v-if="resultSummary.dryRun">
				{{ t('mydash', 'PREVIEW: would change {count} dashboards.', { count: resultSummary.changed }) }}
			</p>
			<p v-else>
				{{ t('mydash', 'Changed {count} dashboards. Skipped {skipped}.', {
					count: resultSummary.changed,
					skipped: resultSummary.skipped,
				}) }}
			</p>
			<ul v-if="resultSummary.errors.length > 0">
				<li v-for="(err, idx) in resultSummary.errors" :key="idx">
					{{ err.uuid }}: {{ err.reason }}
				</li>
			</ul>
		</div>

		<div v-if="errorMessage" class="mydash-bulk-ops__error" data-test="bulk-ops-error">
			{{ errorMessage }}
		</div>
	</div>
</template>

<script>
import { api } from '../../services/api.js'

export default {
	name: 'DashboardBulkOperations',

	data() {
		return {
			dashboards: [],
			selectedUuids: [],
			selectedAction: '',
			loading: false,
			running: false,
			modal: null,
			parentUuidInput: '',
			statusInput: 'published',
			publishAtInput: '',
			dryRun: false,
			resultSummary: null,
			errorMessage: '',
		}
	},

	computed: {
		allSelected() {
			return this.dashboards.length > 0
				&& this.selectedUuids.length === this.dashboards.length
		},
		someSelected() {
			return this.selectedUuids.length > 0
		},
	},

	mounted() {
		this.loadDashboards()
	},

	methods: {
		async loadDashboards() {
			this.loading = true
			try {
				const { data } = await api.getVisibleDashboards()
				this.dashboards = Array.isArray(data) ? data : (data.dashboards || [])
			} catch (e) {
				this.errorMessage = t('mydash', 'Failed to load dashboards')
			} finally {
				this.loading = false
			}
		},

		toggleSelectAll(event) {
			if (event.target.checked) {
				this.selectedUuids = this.dashboards.map(d => d.uuid)
			} else {
				this.selectedUuids = []
			}
		},

		toggleRow(uuid) {
			const idx = this.selectedUuids.indexOf(uuid)
			if (idx === -1) {
				this.selectedUuids.push(uuid)
			} else {
				this.selectedUuids.splice(idx, 1)
			}
		},

		runAction() {
			this.errorMessage = ''
			this.resultSummary = null
			const titles = {
				delete: t('mydash', 'Delete dashboards?'),
				move: t('mydash', 'Move dashboards?'),
				status: t('mydash', 'Set publication status?'),
				reindex: t('mydash', 'Reindex dashboards for search?'),
			}
			const messages = {
				delete: t('mydash', 'About to delete {n} dashboards. This cannot be undone.', { n: this.selectedUuids.length }),
				move: t('mydash', 'About to re-parent {n} dashboards.', { n: this.selectedUuids.length }),
				status: t('mydash', 'About to update the publication status of {n} dashboards.', { n: this.selectedUuids.length }),
				reindex: t('mydash', 'About to reindex {n} dashboards for unified search.', { n: this.selectedUuids.length }),
			}
			this.modal = {
				action: this.selectedAction,
				title: titles[this.selectedAction],
				message: messages[this.selectedAction],
			}
		},

		cancelModal() {
			this.modal = null
		},

		async confirmAction() {
			this.running = true
			try {
				const opts = { dryRun: this.dryRun }
				let response
				if (this.modal.action === 'delete') {
					response = await api.bulkDeleteDashboards(this.selectedUuids, opts)
				} else if (this.modal.action === 'move') {
					const parent = this.parentUuidInput.trim() === '' ? null : this.parentUuidInput.trim()
					response = await api.bulkMoveDashboards(this.selectedUuids, parent, opts)
				} else if (this.modal.action === 'status') {
					const extra = { ...opts }
					if (this.statusInput === 'scheduled') {
						extra.publishAt = this.publishAtInput
					}
					response = await api.bulkStatusDashboards(this.selectedUuids, this.statusInput, extra)
				} else if (this.modal.action === 'reindex') {
					response = await api.bulkReindexDashboards(this.selectedUuids, opts)
				}

				const data = response?.data || {}
				const changed = data.deletedCount ?? data.movedCount ?? data.updatedCount ?? data.reindexedCount
					?? data.wouldDeleteCount ?? data.wouldMoveCount ?? data.wouldUpdateCount ?? data.wouldReindexCount
					?? 0
				const skipped = data.skippedCount ?? data.wouldSkipCount ?? 0
				this.resultSummary = {
					dryRun: !!data.dryRun,
					changed,
					skipped,
					errors: Array.isArray(data.errors) ? data.errors : [],
				}

				if (!data.dryRun) {
					this.selectedUuids = []
					this.loadDashboards()
				}
			} catch (e) {
				this.errorMessage = e?.response?.data?.error || t('mydash', 'Bulk operation failed')
			} finally {
				this.running = false
				this.modal = null
			}
		},
	},
}
</script>

<style scoped>
.mydash-bulk-ops {
	margin-top: 1rem;
}
.mydash-bulk-ops__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 0.75rem;
}
.mydash-bulk-ops__table th,
.mydash-bulk-ops__table td {
	padding: 0.25rem 0.5rem;
	text-align: left;
}
.mydash-bulk-ops__col-select {
	width: 2rem;
}
.mydash-bulk-ops__row--selected {
	background-color: var(--color-background-hover, #eee);
}
.mydash-bulk-ops__actions {
	display: flex;
	gap: 0.5rem;
	margin-bottom: 0.75rem;
}
.mydash-bulk-ops__modal {
	position: fixed;
	inset: 0;
	background-color: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}
.mydash-bulk-ops__modal-inner {
	background-color: var(--color-main-background, #fff);
	padding: 1rem;
	border-radius: 8px;
	min-width: 320px;
}
.mydash-bulk-ops__field {
	margin: 0.5rem 0;
}
.mydash-bulk-ops__dryrun {
	display: flex;
	gap: 0.5rem;
	align-items: center;
	margin: 0.5rem 0;
}
.mydash-bulk-ops__modal-actions {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-end;
}
.mydash-bulk-ops__error {
	color: var(--color-error, #c00);
}
</style>
