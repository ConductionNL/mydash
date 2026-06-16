<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal @close="$emit('close')">
		<div class="mydash-admin__editor">
			<h3>{{ row.id ? t('mydash', 'Edit role permission') : t('mydash', 'Add role permission') }}</h3>
			<NcTextField :value="row.name"
				:label="t('mydash', 'Name')"
				required
				@update:value="updateRow('name', $event)" />
			<NcTextField :value="row.groupId"
				:label="t('mydash', 'Nextcloud group ID')"
				required
				:disabled="!!row.id"
				@update:value="updateRow('groupId', $event)" />
			<NcTextField :value="row.description"
				:label="t('mydash', 'Description (optional)')"
				@update:value="updateRow('description', $event)" />
			<NcTextField :value="allowedWidgetsCsv"
				:label="t('mydash', 'Allowed widget IDs (comma separated)')"
				@update:value="$emit('update:allowedWidgetsCsv', $event)" />
			<NcTextField :value="deniedWidgetsCsv"
				:label="t('mydash', 'Denied widget IDs (comma separated)')"
				@update:value="$emit('update:deniedWidgetsCsv', $event)" />
			<div class="mydash-admin__editor-actions">
				<NcButton type="tertiary" @click="$emit('close')">
					{{ t('mydash', 'Cancel') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="saving || !row.name || !row.groupId"
					@click="$emit('save')">
					{{ t('mydash', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcTextField,
} from '@conduction/nextcloud-vue'

export default {
	name: 'RolePermissionEditorModal',

	components: {
		NcButton,
		NcModal,
		NcTextField,
	},

	props: {
		row: {
			type: Object,
			required: true,
		},
		allowedWidgetsCsv: {
			type: String,
			default: '',
		},
		deniedWidgetsCsv: {
			type: String,
			default: '',
		},
		saving: {
			type: Boolean,
			default: false,
		},
	},

	emits: [
		'close',
		'save',
		'update:row',
		'update:allowedWidgetsCsv',
		'update:deniedWidgetsCsv',
	],

	methods: {
		/** @spec openspec/specs/admin-roles/spec.md */
		updateRow(key, value) {
			this.$emit('update:row', { ...this.row, [key]: value })
		},
	},
}
</script>

<style scoped>
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
