<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="files-form">
		<NcTextField
			:value="folderPath"
			:label="t('mydash', 'Folder path')"
			:placeholder="t('mydash', 'e.g. /Documents/Marketing')"
			required
			@update:value="updateField('folderPath', $event)" />

		<NcTextField
			:value="fileIdString"
			:label="t('mydash', 'Folder ID (optional, preferred)')"
			:placeholder="t('mydash', 'Numeric file id of the folder')"
			@update:value="updateFileId" />

		<NcSelect
			:value="viewMode"
			:options="viewModeOptions"
			:input-label="t('mydash', 'View mode')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('viewMode', $event)" />

		<NcSelect
			:value="sortBy"
			:options="sortByOptions"
			:input-label="t('mydash', 'Sort by')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('sortBy', $event)" />

		<label class="files-form__toggle">
			<input
				type="checkbox"
				:checked="sortDescending"
				@change="updateField('sortDescending', $event.target.checked)">
			{{ t('mydash', 'Sort descending') }}
		</label>

		<label class="files-form__toggle">
			<input
				type="checkbox"
				:checked="showThumbnails"
				@change="updateField('showThumbnails', $event.target.checked)">
			{{ t('mydash', 'Show thumbnails') }}
		</label>

		<NcTextField
			:value="mimeTypeFilterString"
			:label="t('mydash', 'MIME type filter (comma separated)')"
			:placeholder="t('mydash', 'e.g. image/*, application/pdf')"
			@update:value="updateMimeFilter" />

		<label class="files-form__toggle">
			<input
				type="checkbox"
				:checked="allowUpload"
				@change="updateField('allowUpload', $event.target.checked)">
			{{ t('mydash', 'Allow upload') }}
		</label>

		<label class="files-form__toggle">
			<input
				type="checkbox"
				:checked="allowDelete"
				@change="updateField('allowDelete', $event.target.checked)">
			{{ t('mydash', 'Allow delete') }}
		</label>
	</div>
</template>

<script>
import { NcTextField, NcSelect } from '@conduction/nextcloud-vue'

const VIEW_MODES = Object.freeze(['list', 'grid', 'tree'])
const SORT_FIELDS = Object.freeze(['name', 'modified', 'size', 'type'])

const DEFAULT_CONTENT = Object.freeze({
	folderPath: '',
	fileId: null,
	viewMode: 'list',
	showThumbnails: true,
	mimeTypeFilter: [],
	allowUpload: false,
	allowDelete: false,
	sortBy: 'name',
	sortDescending: false,
})

/**
 * FilesForm — sub-form for the AddWidgetModal when the user is
 * creating or editing a `files` placement (REQ-FLS-002).
 *
 * Nine fields: `folderPath`, `fileId`, `viewMode`, `showThumbnails`,
 * `mimeTypeFilter`, `allowUpload`, `allowDelete`, `sortBy`,
 * `sortDescending`. `validate()` requires either a non-empty
 * `folderPath` OR a numeric `fileId` so the placement always resolves
 * to a folder at view time.
 */
export default {
	name: 'FilesForm',

	components: {
		NcTextField,
		NcSelect,
	},

	props: {
		editingWidget: {
			type: Object,
			default: null,
		},
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = this.editingWidget?.content || this.value || {}
		return {
			folderPath: typeof initial.folderPath === 'string' ? initial.folderPath : DEFAULT_CONTENT.folderPath,
			fileId: this.coerceFileId(initial.fileId),
			viewMode: VIEW_MODES.includes(initial.viewMode) ? initial.viewMode : DEFAULT_CONTENT.viewMode,
			showThumbnails: typeof initial.showThumbnails === 'boolean' ? initial.showThumbnails : DEFAULT_CONTENT.showThumbnails,
			mimeTypeFilter: Array.isArray(initial.mimeTypeFilter) ? initial.mimeTypeFilter.filter((entry) => typeof entry === 'string') : [],
			allowUpload: initial.allowUpload === true,
			allowDelete: initial.allowDelete === true,
			sortBy: SORT_FIELDS.includes(initial.sortBy) ? initial.sortBy : DEFAULT_CONTENT.sortBy,
			sortDescending: initial.sortDescending === true,
		}
	},

	computed: {
		/** @spec openspec/specs/files-widget/spec.md */
		fileIdString() {
			return this.fileId === null || this.fileId === undefined ? '' : String(this.fileId)
		},

		/** @spec openspec/specs/files-widget/spec.md */
		mimeTypeFilterString() {
			return this.mimeTypeFilter.join(', ')
		},

		/** @spec openspec/specs/files-widget/spec.md */
		viewModeOptions() {
			return [
				{ value: 'list', label: t('mydash', 'List') },
				{ value: 'grid', label: t('mydash', 'Grid') },
				{ value: 'tree', label: t('mydash', 'Tree') },
			]
		},

		/** @spec openspec/specs/files-widget/spec.md */
		sortByOptions() {
			return [
				{ value: 'name', label: t('mydash', 'Name') },
				{ value: 'modified', label: t('mydash', 'Modified') },
				{ value: 'size', label: t('mydash', 'Size') },
				{ value: 'type', label: t('mydash', 'Type') },
			]
		},

		/** @spec openspec/specs/files-widget/spec.md */
		assembledContent() {
			return {
				folderPath: this.folderPath,
				fileId: this.fileId,
				viewMode: this.viewMode,
				showThumbnails: this.showThumbnails,
				mimeTypeFilter: [...this.mimeTypeFilter],
				allowUpload: this.allowUpload,
				allowDelete: this.allowDelete,
				sortBy: this.sortBy,
				sortDescending: this.sortDescending,
			}
		},
	},

	methods: {
		/** @spec openspec/specs/files-widget/spec.md */
		coerceFileId(raw) {
			if (raw === null || raw === undefined || raw === '') {
				return null
			}
			const num = Number(raw)
			if (!Number.isFinite(num) || num <= 0) {
				return null
			}
			return Math.floor(num)
		},

		/** @spec openspec/specs/files-widget/spec.md */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/** @spec openspec/specs/files-widget/spec.md */
		updateFileId(value) {
			this.fileId = this.coerceFileId(value)
			this.$emit('update:content', this.assembledContent)
		},

		/** @spec openspec/specs/files-widget/spec.md */
		updateMimeFilter(value) {
			const raw = String(value || '')
			this.mimeTypeFilter = raw
				.split(',')
				.map((entry) => entry.trim())
				.filter((entry) => entry !== '')
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * REQ-FLS-002: at least one of `folderPath` or `fileId` must
		 * be present so the placement always resolves to a folder at
		 * view time.
		 *
		 * @return {string[]} validation errors
		 */
		/** @spec openspec/specs/files-widget/spec.md */
		validate() {
			const errors = []
			const hasPath = typeof this.folderPath === 'string' && this.folderPath.trim() !== ''
			const hasFileId = typeof this.fileId === 'number' && this.fileId > 0
			if (!hasPath && !hasFileId) {
				errors.push(t('mydash', 'Folder path or folder id is required'))
			}
			return errors
		},
	},
}
</script>

<style scoped>
.files-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.files-form__toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
}
</style>
