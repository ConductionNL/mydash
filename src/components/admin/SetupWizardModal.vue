<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal
		:name="t('mydash', 'MyDash setup wizard')"
		size="large"
		@close="onClose">
		<div class="setup-wizard" data-test="setup-wizard">
			<header class="setup-wizard__header">
				<h2>{{ t('mydash', 'MyDash setup wizard') }}</h2>
				<p class="setup-wizard__counter" data-test="setup-wizard-counter">
					{{ t('mydash', 'Step {n} / {total}', { n: currentStep, total: totalSteps }) }}
				</p>
			</header>

			<section class="setup-wizard__body">
				<!-- Step 1 — Welcome -->
				<div v-if="currentStep === 1" class="setup-wizard__step">
					<h3>{{ t('mydash', 'Welcome') }}</h3>
					<p>
						{{ t('mydash', 'Configure your MyDash instance with storage, group ordering, demo data, admin roles, and footer settings.') }}
					</p>
				</div>

				<!-- Step 2 — Storage backend (REQ-WIZ-003) -->
				<div v-else-if="currentStep === 2" class="setup-wizard__step">
					<h3>{{ t('mydash', 'Storage backend') }}</h3>
					<p>{{ t('mydash', 'Choose how MyDash stores dashboard content.') }}</p>

					<label class="setup-wizard__radio">
						<input
							v-model="storage"
							type="radio"
							value="database"
							data-test="storage-database">
						<div>
							<strong>{{ t('mydash', 'Database (default)') }}</strong>
							<p class="setup-wizard__hint">
								{{ t('mydash', 'Store dashboard content in the MyDash database table.') }}
							</p>
						</div>
					</label>

					<label
						class="setup-wizard__radio"
						:class="{ 'setup-wizard__radio--disabled': !groupfolderAvailable }"
						:title="groupfolderAvailable ? '' : t('mydash', 'GroupFolder app is not installed. Install \'Nextcloud GroupFolders\' to use this option.')">
						<input
							v-model="storage"
							type="radio"
							value="groupfolder"
							:disabled="!groupfolderAvailable"
							data-test="storage-groupfolder">
						<div>
							<strong>{{ t('mydash', 'GroupFolder (recommended for org use)') }}</strong>
							<p class="setup-wizard__hint">
								{{ t('mydash', 'Store dashboard content in Nextcloud GroupFolders for collaborative access.') }}
							</p>
						</div>
					</label>
				</div>

				<!-- Step 3 — Group priority order (REQ-WIZ-004; embeds existing UI) -->
				<div v-else-if="currentStep === 3" class="setup-wizard__step">
					<h3>{{ t('mydash', 'Group priority order') }}</h3>
					<p>
						{{ t('mydash', 'Pick the order Nextcloud groups appear when MyDash routes users to a workspace.') }}
					</p>
					<GroupPriorityOrder :initial-active="[]" />
				</div>

				<!-- Step 4 — Demo data (REQ-WIZ-005; delivered by demo-data-showcases sibling). -->
				<div v-else-if="currentStep === 4" class="setup-wizard__step">
					<h3>{{ t('mydash', 'Demo data') }}</h3>
					<AdminDemoData data-test="demo-data-panel" />
				</div>

				<!-- Step 5 — Admin roles (REQ-WIZ-006; sibling capability pending) -->
				<!-- TODO: admin-roles integration — admin-roles capability is delivered through
				     AdminController role endpoints (POST/DELETE /api/admin/roles), not a Vue
				     panel; build a dedicated AdminRolesPanel.vue and swap the stub below. -->
				<div v-else-if="currentStep === 5" class="setup-wizard__step">
					<h3>{{ t('mydash', 'Admin roles') }}</h3>
					<p>
						{{ t('mydash', 'Assign the "Dashboard Admin" role to a Nextcloud group to delegate MyDash administration.') }}
					</p>
					<p class="setup-wizard__note" data-test="admin-roles-stub">
						{{ t('mydash', 'The admin-roles capability is not yet available; this step will activate once it ships.') }}
					</p>
				</div>

				<!-- Step 6 — Footer config (REQ-WIZ-007; sibling capability pending) -->
				<!-- TODO: footer-customization integration — footer is currently
				     surfaced through admin endpoints (FooterService + AdminSetting
				     KEY_FOOTER_*); build a FooterSettingsPanel.vue and swap the
				     stub below. -->
				<div v-else-if="currentStep === 6" class="setup-wizard__step">
					<h3>{{ t('mydash', 'Footer configuration') }}</h3>
					<p>
						{{ t('mydash', 'Customize the footer content and appearance for your intranet.') }}
					</p>
					<p class="setup-wizard__note" data-test="footer-stub">
						{{ t('mydash', 'The footer-customization capability is not yet available; this step will activate once it ships.') }}
					</p>
				</div>

				<!-- Step 7 — Done (REQ-WIZ-002 final step) -->
				<div v-else-if="currentStep === 7" class="setup-wizard__step">
					<h3>{{ t('mydash', 'All set') }}</h3>
					<p>
						{{ t('mydash', 'Your setup is complete. Click Finish to save changes and dismiss the setup banner.') }}
					</p>
				</div>
			</section>

			<footer class="setup-wizard__footer">
				<NcButton
					type="tertiary"
					:disabled="currentStep <= 1"
					data-test="setup-wizard-back"
					@click="onBack">
					{{ t('mydash', 'Back') }}
				</NcButton>
				<NcButton
					v-if="!isFinalStep"
					type="secondary"
					data-test="setup-wizard-skip"
					@click="onSkip">
					{{ t('mydash', 'Skip') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="loading"
					data-test="setup-wizard-next"
					@click="onNext">
					{{ isFinalStep ? t('mydash', 'Finish') : t('mydash', 'Next') }}
				</NcButton>
			</footer>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@conduction/nextcloud-vue'
import GroupPriorityOrder from './GroupPriorityOrder.vue'
import AdminDemoData from './AdminDemoData.vue'
import { api } from '../../services/api.js'

/**
 * SetupWizardModal — multi-step first-run wizard (REQ-WIZ-002).
 *
 * The shell owns navigation state; per-step bodies inline simple UI for
 * Welcome / Storage / Done and embed canonical sibling components for
 * Group order. Steps 4-6 render lightweight stubs because their sibling
 * capabilities (demo-data-showcases, admin-roles, footer-customization)
 * ship on parallel branches; the merge agent reconciles by replacing
 * the stub blocks with the real embeds.
 */
export default {
	name: 'SetupWizardModal',

	components: {
		AdminDemoData,
		GroupPriorityOrder,
		NcButton,
		NcModal,
	},

	emits: ['close', 'completed'],

	data() {
		return {
			currentStep: 1,
			totalSteps: 7,
			loading: false,
			storage: 'database',
			groupfolderAvailable: false,
			completed: false,
		}
	},

	computed: {
		isFinalStep() {
			return this.currentStep === this.totalSteps
		},
	},

	async created() {
		await this.loadState()
	},

	methods: {
		async loadState() {
			try {
				const { data } = await api.getSetupWizardState()
				if (data) {
					this.storage = data.contentStorage || 'database'
					this.groupfolderAvailable = !!data.groupfolderAvailable
					if (data.currentRecommendedStep && !data.complete) {
						this.currentStep = Math.max(1, Math.min(data.currentRecommendedStep, this.totalSteps))
					}
				}
			} catch (error) {
				// REQ-WIZ-008: a failed state read MUST NOT block the wizard.
				// Default to Step 1 with a Database default.
				console.warn('Failed to load wizard state', error)
			}
		},

		async onNext() {
			if (this.loading) {
				return
			}
			this.loading = true
			try {
				if (this.currentStep === 2) {
					await api.setSetupWizardStorage(this.storage)
				}

				if (this.isFinalStep) {
					await api.completeSetupWizard()
					this.completed = true
					this.$emit('completed')
					this.$emit('close')
					return
				}
				this.currentStep += 1
			} catch (error) {
				console.error('Wizard step failed', error)
			} finally {
				this.loading = false
			}
		},

		onBack() {
			if (this.currentStep > 1) {
				this.currentStep -= 1
			}
		},

		onSkip() {
			if (!this.isFinalStep) {
				this.currentStep += 1
			}
		},

		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.setup-wizard {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 24px;
	min-height: 360px;
}

.setup-wizard__header {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}

.setup-wizard__header h2 {
	margin: 0;
}

.setup-wizard__counter {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 14px;
}

.setup-wizard__body {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.setup-wizard__step h3 {
	margin: 0 0 8px;
}

.setup-wizard__radio {
	display: flex;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
}

.setup-wizard__radio--disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.setup-wizard__hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
	font-size: 13px;
}

.setup-wizard__note {
	padding: 12px;
	background: var(--color-background-dark);
	border-left: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.setup-wizard__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}
</style>
