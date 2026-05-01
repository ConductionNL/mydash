<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="mydash-admin__section">
		<h3>{{ t('mydash', 'Role-based widget permissions') }}</h3>
		<p class="mydash-admin__hint">
			{{ t('mydash', 'Restrict which widgets each Nextcloud group can add to their dashboard. Empty list = full catalogue (legacy).') }}
		</p>

		<div v-if="store.error" class="mydash-admin__error" role="alert">
			{{ store.error }}
		</div>

		<NcEmptyContent
			v-if="!store.loading && store.permissions.length === 0"
			:name="t('mydash', 'No role permissions configured')"
			:description="t('mydash', 'Add a role-permission row to start filtering the widget catalogue per Nextcloud group.')">
			<template #icon>
				<AccountGroup :size="40" />
			</template>
		</NcEmptyContent>

		<div v-else class="mydash-admin__role-list">
			<div v-for="row in store.permissions"
				:key="row.id"
				class="mydash-admin__role-row">
				<div class="mydash-admin__role-meta">
					<strong>{{ row.name }}</strong>
					<span class="mydash-admin__role-group">{{ row.groupId }}</span>
				</div>
				<div class="mydash-admin__role-widgets">
					<span v-for="wid in row.allowedWidgets"
						:key="wid"
						class="mydash-admin__chip">
						{{ wid }}
					</span>
					<span v-for="wid in row.deniedWidgets"
						:key="`d-${wid}`"
						class="mydash-admin__chip mydash-admin__chip--denied">
						{{ wid }}
					</span>
				</div>
				<div class="mydash-admin__role-actions">
					<NcButton type="tertiary"
						:aria-label="t('mydash', 'Edit')"
						@click="openEdit(row)">
						<template #icon>
							<Pencil :size="20" />
						</template>
					</NcButton>
					<NcButton type="tertiary"
						:aria-label="t('mydash', 'Delete')"
						@click="confirmDelete(row)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>

		<NcButton type="primary" @click="openCreate">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('mydash', 'Add role permission') }}
		</NcButton>

		<NcModal v-if="showEditor" @close="closeEditor">
			<div class="mydash-admin__editor">
				<h3>{{ editorRow.id ? t('mydash', 'Edit role permission') : t('mydash', 'Add role permission') }}</h3>
				<NcTextField :value.sync="editorRow.name"
					:label="t('mydash', 'Name')"
					required />
				<NcTextField :value.sync="editorRow.groupId"
					:label="t('mydash', 'Nextcloud group ID')"
					required
					:disabled="!!editorRow.id" />
				<NcTextField :value.sync="editorRow.description"
					:label="t('mydash', 'Description (optional)')" />
				<NcTextField :value.sync="allowedWidgetsCsv"
					:label="t('mydash', 'Allowed widget IDs (comma separated)')" />
				<NcTextField :value.sync="deniedWidgetsCsv"
					:label="t('mydash', 'Denied widget IDs (comma separated)')" />
				<div class="mydash-admin__editor-actions">
					<NcButton type="tertiary" @click="closeEditor">
						{{ t('mydash', 'Cancel') }}
					</NcButton>
					<NcButton type="primary"
						:disabled="store.saving || !editorRow.name || !editorRow.groupId"
						@click="save">
						{{ t('mydash', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcTextField,
	NcEmptyContent,
} from '@conduction/nextcloud-vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { useRoleFeaturePermissionStore } from '../../stores/roleFeaturePermissions.js'

export default {
	name: 'RolePermissionsSection',

	components: {
		NcButton,
		NcModal,
		NcTextField,
		NcEmptyContent,
		AccountGroup,
		Plus,
		Pencil,
		Delete,
	},

	setup() {
		const store = useRoleFeaturePermissionStore()
		return { store }
	},

	data() {
		return {
			showEditor: false,
			editorRow: this.emptyRow(),
			allowedWidgetsCsv: '',
			deniedWidgetsCsv: '',
		}
	},

	async mounted() {
		try {
			await this.store.loadPermissions()
		} catch (e) {
			console.error('Failed to load role permissions', e)
		}
	},

	methods: {
		emptyRow() {
			return {
				id: null,
				name: '',
				groupId: '',
				description: '',
				allowedWidgets: [],
				deniedWidgets: [],
				priorityWeights: {},
			}
		},
		openCreate() {
			this.editorRow = this.emptyRow()
			this.allowedWidgetsCsv = ''
			this.deniedWidgetsCsv = ''
			this.showEditor = true
		},
		openEdit(row) {
			this.editorRow = {
				...row,
				allowedWidgets: row.allowedWidgets ?? [],
				deniedWidgets: row.deniedWidgets ?? [],
				priorityWeights: row.priorityWeights ?? {},
			}
			this.allowedWidgetsCsv = (row.allowedWidgets ?? []).join(', ')
			this.deniedWidgetsCsv = (row.deniedWidgets ?? []).join(', ')
			this.showEditor = true
		},
		closeEditor() {
			this.showEditor = false
		},
		parseCsv(s) {
			return (s ?? '')
				.split(',')
				.map(x => x.trim())
				.filter(x => x.length > 0)
		},
		async save() {
			try {
				const payload = {
					name: this.editorRow.name,
					groupId: this.editorRow.groupId,
					description: this.editorRow.description || null,
					allowedWidgets: this.parseCsv(this.allowedWidgetsCsv),
					deniedWidgets: this.parseCsv(this.deniedWidgetsCsv),
					priorityWeights: this.editorRow.priorityWeights ?? {},
				}
				await this.store.savePermission(payload)
				this.closeEditor()
			} catch (e) {
				console.error('Failed to save role permission', e)
			}
		},
		async confirmDelete(row) {
			// eslint-disable-next-line no-alert
			if (!window.confirm(this.t('mydash', 'Delete role permission for "{group}"?', { group: row.groupId }))) {
				return
			}
			try {
				await this.store.deletePermission(row.id)
			} catch (e) {
				console.error('Failed to delete role permission', e)
			}
		},
	},
}
</script>

<style scoped>
.mydash-admin__hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 var(--default-grid-baseline) 0;
}
.mydash-admin__error {
	background: var(--color-error);
	color: var(--color-primary-element-text);
	padding: var(--default-grid-baseline);
	border-radius: var(--border-radius);
	margin-bottom: var(--default-grid-baseline);
}
.mydash-admin__role-list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin-bottom: var(--default-grid-baseline);
}
.mydash-admin__role-row {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}
.mydash-admin__role-meta {
	display: flex;
	flex-direction: column;
	min-width: 200px;
}
.mydash-admin__role-group {
	color: var(--color-text-maxcontrast);
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85em;
}
.mydash-admin__role-widgets {
	flex: 1;
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}
.mydash-admin__chip {
	background: var(--color-background-hover);
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 0.85em;
}
.mydash-admin__chip--denied {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}
.mydash-admin__role-actions {
	display: flex;
	gap: 4px;
}
.mydash-admin__editor {
	padding: calc(var(--default-grid-baseline) * 2);
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	min-width: 480px;
}
.mydash-admin__editor-actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}
</style>
