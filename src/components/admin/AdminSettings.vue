<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="mydash-admin">
		<CnSettingsSection
			:name="t('mydash', 'MyDash settings')"
			:description="t('mydash', 'Configure dashboard permissions and defaults')"
			doc-url="https://mydash.app">
			<!-- Global Settings -->
			<div class="mydash-admin__section">
				<h3>{{ t('mydash', 'Default settings') }}</h3>

				<div class="mydash-admin__field">
					<NcSelect
						v-model="settings.defaultPermissionLevel"
						:input-label="t('mydash', 'Default permission level')"
						:options="permissionOptions"
						label="label"
						track-by="id"
						:clearable="false"
						@input="saveSettings" />
				</div>

				<!-- REQ-ASET-003 (extended). The toggle is wired to
				     `PUT /api/admin/settings`. Toggling does NOT mutate
				     dashboard rows: existing personal dashboards remain
				     readable and editable; only NEW creations and forks
				     are blocked. The helper text below makes this clear
				     to admins so they don't fear data loss. -->
				<NcCheckboxRadioSwitch
					:checked="settings.allowUserDashboards"
					@update:checked="updateSetting('allowUserDashboards', $event)">
					{{ t('mydash', 'Allow users to create custom dashboards') }}
				</NcCheckboxRadioSwitch>
				<p class="mydash-admin__hint mydash-admin__hint--inline">
					{{ t('mydash', 'Disabling this only blocks creating new personal dashboards. Existing personal dashboards remain visible and editable.') }}
				</p>

				<NcCheckboxRadioSwitch
					:checked="settings.allowMultipleDashboards"
					@update:checked="updateSetting('allowMultipleDashboards', $event)">
					{{ t('mydash', 'Allow users to have multiple dashboards') }}
				</NcCheckboxRadioSwitch>

				<div class="mydash-admin__field">
					<NcSelect
						v-model="settings.defaultGridColumns"
						:input-label="t('mydash', 'Default grid columns')"
						:options="gridColumnOptions"
						:clearable="false"
						@input="saveSettings" />
				</div>
			</div>

			<!-- Template Management -->
			<div class="mydash-admin__section">
				<div class="mydash-admin__section-header">
					<h3>{{ t('mydash', 'Dashboard templates') }}</h3>
					<NcButton type="primary" @click="createTemplate">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('mydash', 'Create template') }}
					</NcButton>
				</div>

				<p class="mydash-admin__hint">
					{{ t('mydash', 'Create dashboard templates that will be applied to users based on their groups.') }}
				</p>

				<div v-if="templates.length === 0" class="mydash-admin__empty">
					<NcEmptyContent :description="t('mydash', 'No templates yet')">
						<template #icon>
							<ViewDashboard :size="48" />
						</template>
					</NcEmptyContent>
				</div>

				<div v-else class="mydash-admin__templates">
					<div
						v-for="template in templates"
						:key="template.id"
						class="mydash-admin__template">
						<div class="mydash-admin__template-info">
							<strong>{{ template.name }}</strong>
							<span v-if="template.isDefault" class="mydash-admin__badge">
								{{ t('mydash', 'Default') }}
							</span>
							<span class="mydash-admin__template-groups">
								{{ formatTargetGroups(template.targetGroups) }}
							</span>
						</div>
						<div class="mydash-admin__template-actions">
							<NcButton type="secondary" @click="editTemplate(template)">
								{{ t('mydash', 'Edit') }}
							</NcButton>
							<NcButton type="error" @click="deleteTemplate(template)">
								{{ t('mydash', 'Delete') }}
							</NcButton>
						</div>
					</div>
				</div>
			</div>

			<!-- Group-shared dashboards (REQ-DASH-015..017) -->
			<div class="mydash-admin__section">
				<div class="mydash-admin__section-header">
					<h3>{{ t('mydash', 'Group-shared dashboards') }}</h3>
				</div>

				<p class="mydash-admin__hint">
					{{ t('mydash', 'Promote a single dashboard per group as the default. Members of the group will land on it when they have no personal preference yet.') }}
				</p>

				<div v-if="loadingGroupDashboards" class="mydash-admin__hint">
					{{ t('mydash', 'Loading group dashboards…') }}
				</div>

				<div
					v-for="(rows, groupId) in groupSharedDashboards"
					:key="groupId"
					class="mydash-admin__group">
					<h4 class="mydash-admin__group-title">
						{{ groupId }}
					</h4>
					<div v-if="rows.length === 0" class="mydash-admin__hint">
						{{ t('mydash', 'No group-shared dashboards in this group yet.') }}
					</div>
					<div v-else class="mydash-admin__templates">
						<div
							v-for="dash in rows"
							:key="dash.uuid"
							class="mydash-admin__template">
							<div class="mydash-admin__template-info">
								<strong>{{ dash.name }}</strong>
								<span
									v-if="dash.isDefault === 1"
									class="mydash-admin__badge"
									data-test="group-default-badge">
									{{ t('mydash', 'Default') }}
								</span>
							</div>
							<div class="mydash-admin__template-actions">
								<NcButton
									v-if="dash.isDefault !== 1"
									type="secondary"
									data-test="set-group-default"
									:disabled="settingGroupDefault === dash.uuid"
									@click="setGroupDefault(groupId, dash.uuid)">
									{{ t('mydash', 'Set as default') }}
								</NcButton>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Info -->
			<div class="mydash-admin__section">
				<h3>{{ t('mydash', 'Setting as default app') }}</h3>
				<p>
					{{ t('mydash', 'To make MyDash the default app for users, go to Settings > Administration > Theming and select MyDash as the default app.') }}
				</p>
			</div>
		</CnSettingsSection>

		<!-- Template Editor Modal -->
		<NcModal
			v-if="editingTemplate"
			:name="editingTemplate.id ? t('mydash', 'Edit template') : t('mydash', 'Create template')"
			size="large"
			@close="closeTemplateEditor">
			<div class="mydash-admin__modal">
				<h2>{{ editingTemplate.id ? t('mydash', 'Edit template') : t('mydash', 'Create template') }}</h2>

				<div class="mydash-admin__field">
					<label>{{ t('mydash', 'Template name') }}</label>
					<NcTextField v-model="editingTemplate.name" :placeholder="t('mydash', 'My template')" />
				</div>

				<div class="mydash-admin__field">
					<label>{{ t('mydash', 'Description') }}</label>
					<NcTextField v-model="editingTemplate.description" :placeholder="t('mydash', 'Optional description')" />
				</div>

				<div class="mydash-admin__field">
					<label>{{ t('mydash', 'Target groups') }}</label>
					<NcSelectTags
						v-model="editingTemplate.targetGroups"
						:options="availableGroups"
						:multiple="true"
						:placeholder="t('mydash', 'Select groups (leave empty for all users)')" />
				</div>

				<div class="mydash-admin__field">
					<NcSelect
						v-model="editingTemplate.permissionLevel"
						:input-label="t('mydash', 'Permission level')"
						:options="permissionOptions"
						label="label"
						track-by="id"
						:clearable="false" />
				</div>

				<NcCheckboxRadioSwitch
					:checked="editingTemplate.isDefault"
					@update:checked="editingTemplate.isDefault = $event">
					{{ t('mydash', 'Set as default template') }}
				</NcCheckboxRadioSwitch>

				<div class="mydash-admin__modal-actions">
					<NcButton type="secondary" @click="closeTemplateEditor">
						{{ t('mydash', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" @click="saveTemplate">
						{{ t('mydash', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcSelect,
	NcSelectTags,
	NcTextField,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcModal,
} from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import { api } from '../../services/api.js'

export default {
	name: 'AdminSettings',

	components: {
		CnSettingsSection,
		NcButton,
		NcSelect,
		NcSelectTags,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcModal,
		Plus,
		ViewDashboard,
	},

	// REQ-INIT-004: read the initial-state snapshot the PHP admin form
	// pushes via `MyDashAdmin::getForm()`. Treated as a hint for the
	// initial render only — `loadData()` overwrites with the API truth.
	inject: {
		injectedAllGroups: { from: 'allGroups', default: () => [] },
		injectedConfiguredGroups: { from: 'configuredGroups', default: () => [] },
		allowUserDashboards: {
			from: 'allowUserDashboards',
			default: false,
		},
	},

	data() {
		return {
			loading: true,
			settings: {
				defaultPermissionLevel: { id: 'add_only', label: this.t('mydash', 'Add only') },
				// Default mirrors the spec — admins MUST opt in.
				allowUserDashboards: this.allowUserDashboards ?? false,
				allowMultipleDashboards: true,
				defaultGridColumns: 12,
			},
			templates: [],
			availableGroups: [],
			editingTemplate: null,
			permissionOptions: [
				{ id: 'view_only', label: this.t('mydash', 'View only') },
				{ id: 'add_only', label: this.t('mydash', 'Add only') },
				{ id: 'full', label: this.t('mydash', 'Full customization') },
			],
			gridColumnOptions: [6, 8, 12],
			// REQ-DASH-015..017 — group-shared dashboards listing for the
			// "Set as default" UI. Keyed by groupId, value is an array of
			// dashboard rows (each carrying `isDefault` 0|1).
			groupSharedDashboards: {},
			loadingGroupDashboards: false,
			// uuid currently being promoted (disables the row's button to
			// avoid double-clicks during the in-flight call).
			settingGroupDefault: null,
		}
	},

	async created() {
		await this.loadData()
		await this.loadGroupSharedDashboards()
	},

	methods: {
		async loadData() {
			this.loading = true
			try {
				const [settingsRes, templatesRes] = await Promise.all([
					api.getAdminSettings(),
					api.getAdminTemplates(),
				])

				if (settingsRes.data) {
					this.settings = {
						...this.settings,
						...settingsRes.data,
						defaultPermissionLevel: this.permissionOptions.find(
							p => p.id === settingsRes.data.defaultPermissionLevel,
						) || this.permissionOptions[1],
					}
				}

				this.templates = templatesRes.data || []

				// Load available groups
				// In a real app, you'd fetch this from OC.getGroups() or an API
				this.availableGroups = []
			} catch (error) {
				console.error('Failed to load admin data:', error)
			} finally {
				this.loading = false
			}
		},

		async saveSettings() {
			try {
				// REQ-ASET-002: the PUT /api/admin/settings endpoint accepts
				// the abbreviated camelCase parameter names — the long
				// camelCase forms returned by GET are NOT accepted on
				// write. Mismatched keys silently no-op (controller leaves
				// the matching arg null), which would have left the toggle
				// permanently stuck. REQ-ASET-003 explicitly demands the
				// admin can flip the flag at runtime.
				await api.updateAdminSettings({
					defaultPermLevel: this.settings.defaultPermissionLevel?.id,
					allowUserDash: this.settings.allowUserDashboards,
					allowMultiDash: this.settings.allowMultipleDashboards,
					defaultGridCols: this.settings.defaultGridColumns,
				})
			} catch (error) {
				console.error('Failed to save settings:', error)
			}
		},

		updateSetting(key, value) {
			this.settings[key] = value
			this.saveSettings()
		},

		createTemplate() {
			this.editingTemplate = {
				id: null,
				name: '',
				description: '',
				targetGroups: [],
				permissionLevel: this.permissionOptions[1],
				isDefault: false,
			}
		},

		editTemplate(template) {
			this.editingTemplate = {
				...template,
				permissionLevel: this.permissionOptions.find(
					p => p.id === template.permissionLevel,
				) || this.permissionOptions[1],
			}
		},

		closeTemplateEditor() {
			this.editingTemplate = null
		},

		async saveTemplate() {
			try {
				const data = {
					name: this.editingTemplate.name,
					description: this.editingTemplate.description,
					targetGroups: this.editingTemplate.targetGroups,
					permissionLevel: this.editingTemplate.permissionLevel?.id,
					isDefault: this.editingTemplate.isDefault,
				}

				if (this.editingTemplate.id) {
					await api.updateAdminTemplate(this.editingTemplate.id, data)
				} else {
					await api.createAdminTemplate(data)
				}

				await this.loadData()
				this.closeTemplateEditor()
			} catch (error) {
				console.error('Failed to save template:', error)
			}
		},

		async deleteTemplate(template) {
			if (!confirm(this.t('mydash', 'Are you sure you want to delete this template?'))) {
				return
			}

			try {
				await api.deleteAdminTemplate(template.id)
				await this.loadData()
			} catch (error) {
				console.error('Failed to delete template:', error)
			}
		},

		formatTargetGroups(groups) {
			if (!groups || groups.length === 0) {
				return this.t('mydash', 'All users')
			}
			return groups.join(', ')
		},

		/**
		 * Resolve the list of group ids the admin curates: prefer the
		 * `configuredGroups` initial-state slot (curated order) and fall
		 * back to the synthetic `'default'` group so the admin always sees
		 * at least one section. REQ-DASH-015.
		 *
		 * @return {string[]} Group ids to render.
		 */
		resolveAdminGroupIds() {
			const configured = Array.isArray(this.injectedConfiguredGroups)
				? this.injectedConfiguredGroups
				: []
			if (configured.length > 0) {
				return configured.includes('default')
					? configured
					: ['default', ...configured]
			}
			return ['default']
		},

		/**
		 * Fetch group-shared dashboards for every curated group via
		 * `GET /api/dashboards/group/{groupId}` (REQ-DASH-014). Errors
		 * for one group MUST NOT abort the others — a missing group is
		 * common (e.g. the admin removed it but the order was kept).
		 *
		 * @return {Promise<void>}
		 */
		async loadGroupSharedDashboards() {
			this.loadingGroupDashboards = true
			const groupIds = this.resolveAdminGroupIds()
			const next = {}
			await Promise.all(
				groupIds.map(async (groupId) => {
					try {
						const response = await api.listGroupDashboards(groupId)
						next[groupId] = Array.isArray(response.data) ? response.data : []
					} catch (e) {
						console.warn(`Failed to load group dashboards for ${groupId}:`, e)
						next[groupId] = []
					}
				}),
			)
			this.groupSharedDashboards = next
			this.loadingGroupDashboards = false
		},

		/**
		 * Promote a group-shared dashboard to the group default
		 * (REQ-DASH-015). Optimistically flips the in-memory rows
		 * (target → 1, every other row in the same group → 0) before the
		 * API call; on 4xx/5xx restores the snapshot so the badge never
		 * lies.
		 *
		 * @param {string} groupId The group id from the row context.
		 * @param {string} uuid The dashboard uuid to promote.
		 * @return {Promise<void>}
		 */
		async setGroupDefault(groupId, uuid) {
			const rows = this.groupSharedDashboards[groupId] || []
			// Snapshot prior `isDefault` values for rollback.
			const snapshot = rows.map(d => ({ uuid: d.uuid, isDefault: d.isDefault }))
			// Optimistic update.
			this.groupSharedDashboards = {
				...this.groupSharedDashboards,
				[groupId]: rows.map(d => ({
					...d,
					isDefault: d.uuid === uuid ? 1 : 0,
				})),
			}
			this.settingGroupDefault = uuid
			try {
				await api.setGroupDashboardDefault(groupId, uuid)
			} catch (error) {
				// Roll back to the snapshot. Surface the failure via the
				// console; the parent page already wires Nextcloud
				// notifications for axios errors at the global level.
				this.groupSharedDashboards = {
					...this.groupSharedDashboards,
					[groupId]: rows.map((d) => {
						const prev = snapshot.find(s => s.uuid === d.uuid)
						return prev ? { ...d, isDefault: prev.isDefault } : d
					}),
				}
				console.error(
					this.t('mydash', 'Failed to set the group default dashboard'),
					error,
				)
			} finally {
				this.settingGroupDefault = null
			}
		},
	},
}
</script>

<style scoped>
.mydash-admin {
	max-width: 800px;
}

.mydash-admin__section {
	margin-bottom: 32px;
	padding-bottom: 32px;
	border-bottom: 1px solid var(--color-border);
}

.mydash-admin__section h3 {
	margin: 0 0 16px;
}

.mydash-admin__section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.mydash-admin__section-header h3 {
	margin: 0;
}

.mydash-admin__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.mydash-admin__field {
	margin-bottom: 16px;
}

.mydash-admin__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.mydash-admin__templates {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.mydash-admin__template {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.mydash-admin__template-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.mydash-admin__template-groups {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.mydash-admin__template-actions {
	display: flex;
	gap: 8px;
}

.mydash-admin__badge {
	display: inline-block;
	padding: 2px 8px;
	background: var(--color-primary-element);
	color: var(--color-primary-text);
	border-radius: var(--border-radius-pill);
	font-size: 12px;
}

.mydash-admin__empty {
	padding: 48px 0;
}

.mydash-admin__modal {
	padding: 24px;
}

.mydash-admin__modal h2 {
	margin: 0 0 24px;
}

.mydash-admin__group {
	margin-bottom: 24px;
}

.mydash-admin__group-title {
	margin: 16px 0 8px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.mydash-admin__modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}
</style>
