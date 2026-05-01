<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal
		v-if="open"
		size="normal"
		:name="modalTitle"
		@close="$emit('close')">
		<div class="dashboard-config">
			<h2 class="dashboard-config__title">
				{{ modalTitle }}
			</h2>

			<div class="dashboard-config__field">
				<NcTextField
					:value="form.name"
					:label="t('mydash', 'Title')"
					:placeholder="t('mydash', 'My dashboard')"
					@update:value="form.name = $event" />
			</div>

			<div class="dashboard-config__field">
				<label class="dashboard-config__label" for="dashboard-config-description">
					{{ t('mydash', 'Description') }}
				</label>
				<textarea
					id="dashboard-config-description"
					v-model="form.description"
					class="dashboard-config__textarea"
					rows="3"
					:placeholder="t('mydash', 'What is this dashboard for?')" />
			</div>

			<div v-if="!isCreate && canManageShares" class="dashboard-config__field">
				<label class="dashboard-config__label">
					{{ t('mydash', 'Share with users and groups') }}
				</label>

				<NcSelect
					:value="null"
					:options="shareeOptions"
					:filterable="false"
					:loading="shareeLoading"
					:placeholder="t('mydash', 'Search users and groups…')"
					label="displayName"
					track-by="key"
					:clearable="false"
					@search="onShareeSearch"
					@input="onShareeSelected">
					<template #option="option">
						<span class="sharee-option">
							<AccountGroup v-if="option.shareType === 'group'" :size="18" />
							<Account v-else :size="18" />
							{{ option.displayName }}
						</span>
					</template>
				</NcSelect>

				<ul v-if="localShares.length > 0" class="dashboard-config__shares">
					<li
						v-for="(share, idx) in localShares"
						:key="`${share.shareType}:${share.shareWith}`"
						class="dashboard-config__share">
						<span class="dashboard-config__share-name">
							<AccountGroup v-if="share.shareType === 'group'" :size="18" />
							<Account v-else :size="18" />
							{{ share.displayName || share.shareWith }}
						</span>
						<NcSelect
							:value="permissionOptionFor(share.permissionLevel)"
							:options="permissionOptions"
							label="label"
							track-by="value"
							:clearable="false"
							class="dashboard-config__share-level"
							@input="onShareLevelChange(idx, $event)" />
						<NcButton
							type="tertiary"
							:aria-label="t('mydash', 'Remove share')"
							@click="onShareRemove(idx)">
							<template #icon>
								<Close :size="18" />
							</template>
						</NcButton>
					</li>
				</ul>
				<p v-else class="dashboard-config__hint">
					{{ t('mydash', 'Not shared with anyone yet.') }}
				</p>
				<p v-if="sharesDirty" class="dashboard-config__hint dashboard-config__hint--dirty">
					{{ t('mydash', 'Unsaved changes — click Save to apply.') }}
				</p>
			</div>

			<div class="dashboard-config__actions">
				<NcButton
					v-if="canDelete && !isCreate"
					type="error"
					:disabled="saving"
					@click="onDelete">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('mydash', 'Delete dashboard') }}
				</NcButton>
				<div class="dashboard-config__actions-right">
					<NcButton type="tertiary" :disabled="saving" @click="$emit('close')">
						{{ t('mydash', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="!canSave || saving" @click="onSave">
						<template #icon>
							<Plus v-if="isCreate" :size="20" />
							<ContentSave v-else :size="20" />
						</template>
						{{ primaryButtonLabel }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField, NcSelect } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

import Delete from 'vue-material-design-icons/Delete.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Account from 'vue-material-design-icons/Account.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'

import { api } from '../services/api.js'

const PERMISSION_OPTIONS = [
	{ value: 'view_only', label: 'View only' },
	{ value: 'add_only', label: 'Add only' },
	{ value: 'full', label: 'Full access' },
]

export default {
	name: 'DashboardConfigModal',

	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcSelect,
		Delete,
		ContentSave,
		Plus,
		Close,
		Account,
		AccountGroup,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		dashboard: {
			type: Object,
			default: null,
		},
		canDelete: {
			type: Boolean,
			default: false,
		},
		mode: {
			type: String,
			default: 'edit',
			validator: v => ['edit', 'create'].includes(v),
		},
	},

	emits: ['close', 'save', 'delete'],

	data() {
		return {
			form: {
				name: '',
				description: '',
			},
			saving: false,
			// Server snapshot of shares as last loaded; used to compute dirty state.
			serverShares: [],
			// Local in-progress edit list; mutations buffer here until Save.
			localShares: [],
			shareeOptions: [],
			shareeLoading: false,
			shareeSearchSeq: 0,
		}
	},

	computed: {
		isCreate() {
			return this.mode === 'create'
		},
		canManageShares() {
			// Only the owner can see / manage shares.
			return this.dashboard?.isOwner !== false && (this.dashboard?.id ?? null) !== null
		},
		modalTitle() {
			return this.isCreate
				? t('mydash', 'Create dashboard')
				: t('mydash', 'Dashboard configuration')
		},
		primaryButtonLabel() {
			if (this.saving) {
				return this.isCreate ? t('mydash', 'Creating…') : t('mydash', 'Saving…')
			}
			return this.isCreate ? t('mydash', 'Create') : t('mydash', 'Save')
		},
		permissionOptions() {
			return PERMISSION_OPTIONS.map(o => ({
				value: o.value,
				label: t('mydash', o.label),
			}))
		},
		selectedPermission: {
			get() {
				const level = this.dashboard?.permissionLevel || 'full'
				return this.permissionOptions.find(o => o.value === level) || this.permissionOptions[2]
			},
			set() {
				// Read-only — admin-managed.
			},
		},
		canSave() {
			return this.form.name.trim().length > 0
		},
		sharesDirty() {
			if (this.localShares.length !== this.serverShares.length) return true
			const key = s => `${s.shareType}:${s.shareWith}:${s.permissionLevel}`
			const a = [...this.localShares].map(key).sort()
			const b = [...this.serverShares].map(key).sort()
			return a.some((v, i) => v !== b[i])
		},
	},

	watch: {
		open: {
			immediate: true,
			handler(isOpen) {
				if (!isOpen) {
					this.serverShares = []
					this.localShares = []
					this.shareeOptions = []
					return
				}
				if (this.isCreate) {
					this.form.name = ''
					this.form.description = ''
				} else if (this.dashboard) {
					this.form.name = this.dashboard.name || ''
					this.form.description = this.dashboard.description || ''
					if (this.canManageShares) {
						this.loadShares()
					}
				}
			},
		},
	},

	methods: {
		t,
		permissionOptionFor(level) {
			return this.permissionOptions.find(o => o.value === level) || this.permissionOptions[0]
		},
		async loadShares() {
			try {
				const response = await api.listShares(this.dashboard.id)
				const fresh = response.data || []
				this.serverShares = fresh.map(s => ({ ...s }))
				this.localShares = fresh.map(s => ({ ...s }))
			} catch (error) {
				console.error('Failed to load shares:', error)
				this.serverShares = []
				this.localShares = []
			}
		},
		async onShareeSearch(query) {
			const trimmed = (query || '').trim()
			if (trimmed.length < 1) {
				this.shareeOptions = []
				return
			}
			const seq = ++this.shareeSearchSeq
			this.shareeLoading = true
			try {
				const response = await api.searchSharees(trimmed)
				if (seq !== this.shareeSearchSeq) return // stale result
				const users = (response.data?.users || []).map(u => ({
					key: `user:${u.id}`,
					shareType: 'user',
					id: u.id,
					displayName: u.displayName,
				}))
				const groups = (response.data?.groups || []).map(g => ({
					key: `group:${g.id}`,
					shareType: 'group',
					id: g.id,
					displayName: g.displayName,
				}))
				this.shareeOptions = [...users, ...groups]
			} catch (error) {
				console.error('Sharee search failed:', error)
				this.shareeOptions = []
			} finally {
				this.shareeLoading = false
			}
		},
		onShareeSelected(option) {
			if (!option) return
			// Buffer locally — do not write to server until Save.
			const exists = this.localShares.find(
				s => s.shareType === option.shareType && s.shareWith === option.id,
			)
			if (!exists) {
				this.localShares.push({
					shareType: option.shareType,
					shareWith: option.id,
					permissionLevel: 'view_only',
					displayName: option.displayName,
				})
			}
			this.shareeOptions = []
		},
		onShareLevelChange(idx, option) {
			if (!option) return
			const share = this.localShares[idx]
			if (!share || option.value === share.permissionLevel) return
			this.$set(this.localShares, idx, {
				...share,
				permissionLevel: option.value,
			})
		},
		onShareRemove(idx) {
			this.localShares.splice(idx, 1)
		},
		async onSave() {
			if (!this.canSave) return
			this.saving = true
			try {
				// Persist share changes first (REQ-SHARE-009 bulk replace) so
				// notifications fire before the modal closes. Skip on create
				// (no dashboard id yet) and when the user has no manage rights.
				if (!this.isCreate && this.canManageShares && this.sharesDirty) {
					try {
						await api.replaceShares(
							this.dashboard.id,
							this.localShares.map(s => ({
								shareType: s.shareType,
								shareWith: s.shareWith,
								permissionLevel: s.permissionLevel,
							})),
						)
					} catch (error) {
						console.error('Failed to replace shares:', error)
					}
				}
				await this.$emit('save', {
					id: this.dashboard?.id ?? null,
					name: this.form.name.trim(),
					description: this.form.description.trim(),
				})
			} finally {
				this.saving = false
			}
		},
		onDelete() {
			if (!this.canDelete) return
			this.$emit('delete', this.dashboard)
		},
	},
}
</script>

<style scoped>
.dashboard-config {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.dashboard-config__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.dashboard-config__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.dashboard-config__label {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.dashboard-config__textarea {
	width: 100%;
	resize: vertical;
	min-height: 80px;
	padding: 8px 12px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
	font-size: 14px;
}

.dashboard-config__textarea:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.dashboard-config__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.dashboard-config__hint--dirty {
	color: var(--color-warning, #c9a227);
	margin-top: 4px;
}

.dashboard-config__actions {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	margin-top: 8px;
}

.dashboard-config__actions-right {
	display: flex;
	gap: 8px;
	margin-left: auto;
}

.dashboard-config__shares {
	list-style: none;
	margin: 4px 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.dashboard-config__share {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.dashboard-config__share-name {
	flex: 1;
	min-width: 0;
	display: inline-flex;
	align-items: center;
	gap: 8px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dashboard-config__share-level {
	min-width: 140px;
}

.sharee-option {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}
</style>
