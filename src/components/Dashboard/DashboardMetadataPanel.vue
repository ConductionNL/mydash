<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	DashboardMetadataPanel — capability `dashboard-metadata-fields`
	(REQ-MDFL-004, REQ-MDFL-005, REQ-MDFL-006)

	Per-dashboard form that renders one input per registered metadata
	field. The list of fields is loaded by the admin (REQ-MDFL-001) but
	every authenticated user can read & write the values for dashboards
	they own / share (REQ-MDFL-008 — owner check enforced on the server).

	Behaviour:
	  - On mount: fetch the field registry + the dashboard's current
	    metadata map.
	  - Inputs default to the persisted value when present.
	  - On save: PUT the full local map; the server validates each
	    value and returns the updated map (orphan fields silently
	    omitted — REQ-MDFL-004 graceful tolerance).
	  - Validation errors surface via the standard `showError` toast
	    triggered inside the store action.
-->

<template>
	<section class="dashboard-metadata-panel">
		<header class="dashboard-metadata-panel__header">
			<h3>{{ t('mydash', 'Dashboard metadata') }}</h3>
			<p v-if="!hasFields" class="dashboard-metadata-panel__empty">
				{{ t('mydash', 'No metadata fields are configured. Ask an administrator to add some.') }}
			</p>
		</header>

		<form
			v-if="hasFields"
			class="dashboard-metadata-panel__form"
			@submit.prevent="onSave">
			<div
				v-for="field in sortedFields"
				:key="field.id"
				class="dashboard-metadata-panel__row">
				<label
					:for="`mdfl-${field.id}`"
					class="dashboard-metadata-panel__label">
					{{ field.label }}<span
						v-if="field.required === 1"
						aria-hidden="true"
						class="dashboard-metadata-panel__required">*</span>
				</label>

				<select
					v-if="field.type === 'select'"
					:id="`mdfl-${field.id}`"
					v-model="localValues[field.key]"
					class="dashboard-metadata-panel__input">
					<option value="">
						{{ t('mydash', '— Choose —') }}
					</option>
					<option
						v-for="option in (field.options || [])"
						:key="option"
						:value="option">
						{{ option }}
					</option>
				</select>

				<select
					v-else-if="field.type === 'boolean'"
					:id="`mdfl-${field.id}`"
					v-model="localValues[field.key]"
					class="dashboard-metadata-panel__input">
					<option value="">
						{{ t('mydash', '— Choose —') }}
					</option>
					<option value="1">
						{{ t('mydash', 'Yes') }}
					</option>
					<option value="0">
						{{ t('mydash', 'No') }}
					</option>
				</select>

				<input
					v-else-if="field.type === 'date'"
					:id="`mdfl-${field.id}`"
					v-model="localValues[field.key]"
					type="date"
					class="dashboard-metadata-panel__input">

				<input
					v-else-if="field.type === 'number'"
					:id="`mdfl-${field.id}`"
					v-model="localValues[field.key]"
					type="number"
					step="any"
					class="dashboard-metadata-panel__input">

				<input
					v-else
					:id="`mdfl-${field.id}`"
					v-model="localValues[field.key]"
					type="text"
					class="dashboard-metadata-panel__input">
			</div>

			<div class="dashboard-metadata-panel__actions">
				<button
					type="submit"
					class="dashboard-metadata-panel__save"
					:disabled="saving">
					{{ saving ? t('mydash', 'Saving…') : t('mydash', 'Save metadata') }}
				</button>
			</div>
		</form>
	</section>
</template>

<script>
import { mapActions, mapState } from 'pinia'
import { translate as t } from '@nextcloud/l10n'

import { useDashboardStore } from '../../stores/dashboard.js'

export default {
	name: 'DashboardMetadataPanel',

	props: {
		dashboardUuid: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			localValues: {},
			saving: false,
		}
	},

	computed: {
		...mapState(useDashboardStore, ['metadataFields', 'metadataByDashboard']),

		/** @spec openspec/specs/dashboard-metadata-fields/spec.md */
		sortedFields() {
			return [...this.metadataFields].sort((a, b) => {
				const ao = Number(a.sortOrder || 0)
				const bo = Number(b.sortOrder || 0)
				return ao !== bo ? ao - bo : String(a.label).localeCompare(String(b.label))
			})
		},

		hasFields() {
			return this.sortedFields.length > 0
		},
	},

	watch: {
		dashboardUuid: {
			immediate: true,
			/** @spec openspec/specs/dashboard-metadata-fields/spec.md */
			handler() {
				this.bootstrap()
			},
		},
	},

	methods: {
		t,

		...mapActions(useDashboardStore, [
			'fetchMetadataFields',
			'fetchDashboardMetadata',
			'updateDashboardMetadata',
		]),

		/** @spec openspec/specs/dashboard-metadata-fields/spec.md */
		async bootstrap() {
			await this.fetchMetadataFields()
			if (!this.dashboardUuid) {
				return
			}
			const map = await this.fetchDashboardMetadata(this.dashboardUuid)
			this.localValues = { ...(map || {}) }
		},

		/** @spec openspec/specs/dashboard-metadata-fields/spec.md */
		async onSave() {
			if (this.saving || !this.dashboardUuid) {
				return
			}
			this.saving = true
			try {
				const updated = await this.updateDashboardMetadata(
					this.dashboardUuid,
					this.localValues,
				)
				if (updated) {
					this.localValues = { ...updated }
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.dashboard-metadata-panel {
	padding: 12px;
}

.dashboard-metadata-panel__row {
	display: flex;
	flex-direction: column;
	margin-bottom: 12px;
}

.dashboard-metadata-panel__label {
	font-weight: 600;
	margin-bottom: 4px;
}

.dashboard-metadata-panel__required {
	color: var(--color-error, #c00);
	margin-left: 2px;
}

.dashboard-metadata-panel__input {
	padding: 6px 8px;
}

.dashboard-metadata-panel__actions {
	margin-top: 12px;
}

.dashboard-metadata-panel__empty {
	color: var(--color-text-maxcontrast, #666);
}
</style>
