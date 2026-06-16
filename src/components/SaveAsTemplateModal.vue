<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		v-if="visible"
		class="save-as-template-modal"
		role="dialog"
		:aria-label="t('mydash', 'Save as template')">
		<div class="save-as-template-modal__backdrop" @click="onCancel" />
		<div class="save-as-template-modal__panel">
			<h2 class="save-as-template-modal__title">
				{{ t('mydash', 'Save as template') }}
			</h2>
			<form @submit.prevent="onSubmit">
				<label class="save-as-template-modal__field">
					<span>{{ t('mydash', 'Template name') }}</span>
					<input
						v-model="form.name"
						type="text"
						required
						:placeholder="t('mydash', 'Template name')">
				</label>
				<label class="save-as-template-modal__field">
					<span>{{ t('mydash', 'Description') }}</span>
					<textarea
						v-model="form.description"
						rows="3"
						:placeholder="t('mydash', 'Describe the template purpose')" />
				</label>
				<label class="save-as-template-modal__field">
					<span>{{ t('mydash', 'Category') }}</span>
					<input
						v-model="form.category"
						type="text"
						:placeholder="t('mydash', 'e.g. marketing, engineering')">
				</label>
				<div class="save-as-template-modal__actions">
					<button type="button" @click="onCancel">
						{{ t('mydash', 'Cancel') }}
					</button>
					<button type="submit" :disabled="!canSubmit">
						{{ t('mydash', 'Save as template') }}
					</button>
				</div>
			</form>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'SaveAsTemplateModal',
	props: {
		visible: { type: Boolean, default: false },
		dashboardUuid: { type: String, default: '' },
		defaultName: { type: String, default: '' },
	},
	emits: ['cancel', 'submit'],
	data() {
		return {
			form: {
				name: this.defaultName,
				description: '',
				category: '',
				previewImage: '',
			},
		}
	},
	computed: {
		/** @spec openspec/specs/admin-templates/spec.md */
		canSubmit() {
			return String(this.form.name).trim().length > 0
		},
	},
	watch: {
		/** @spec openspec/specs/admin-templates/spec.md */
		defaultName(value) {
			if (!this.form.name) {
				this.form.name = value
			}
		},
	},
	methods: {
		t,
		/** @spec openspec/specs/admin-templates/spec.md */
		onCancel() {
			this.$emit('cancel')
		},
		/** @spec openspec/specs/admin-templates/spec.md */
		onSubmit() {
			if (!this.canSubmit) {
				return
			}
			this.$emit('submit', {
				dashboardUuid: this.dashboardUuid,
				metadata: { ...this.form },
			})
		},
	},
}
</script>

<style scoped>
.save-as-template-modal {
	position: fixed;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}

.save-as-template-modal__backdrop {
	position: absolute;
	inset: 0;
	background: rgba(0, 0, 0, 0.5);
}

.save-as-template-modal__panel {
	position: relative;
	background: var(--color-main-background, #fff);
	border-radius: var(--border-radius-large, 8px);
	padding: 20px;
	width: min(480px, 92vw);
	box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

.save-as-template-modal__title {
	margin: 0 0 16px;
}

.save-as-template-modal__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.save-as-template-modal__field input,
.save-as-template-modal__field textarea {
	padding: 6px 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
}

.save-as-template-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
