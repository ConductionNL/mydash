<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="mydash-export-import">
		<h3>{{ t('mydash', 'Backup, restore & migrate dashboards') }}</h3>

		<p class="mydash-export-import__hint">
			{{ t('mydash', 'Download a versioned ZIP archive of all dashboards in this MyDash instance, or upload a previously exported archive to restore or migrate it. The archive is portable across Nextcloud installations.') }}
		</p>

		<!-- Export controls -->
		<div class="mydash-export-import__row">
			<NcButton
				type="primary"
				:disabled="exporting"
				data-test="export-site-button"
				@click="exportSite">
				<template #icon>
					<Download :size="20" />
				</template>
				{{ exporting ? t('mydash', 'Exporting…') : t('mydash', 'Download all dashboards') }}
			</NcButton>
			<span v-if="exportError" class="mydash-export-import__error">
				{{ exportError }}
			</span>
		</div>

		<hr class="mydash-export-import__divider">

		<!-- Import controls -->
		<div class="mydash-export-import__row mydash-export-import__row--column">
			<label for="mydash-import-file" class="mydash-export-import__label">
				{{ t('mydash', 'Import a dashboard archive (.zip)') }}
			</label>
			<input
				id="mydash-import-file"
				ref="fileInput"
				type="file"
				accept=".zip,application/zip"
				data-test="import-file-input"
				@change="onFileSelected">

			<NcCheckboxRadioSwitch
				:checked="preserveUuids"
				@update:checked="preserveUuids = $event">
				{{ t('mydash', 'Preserve original dashboard UUIDs (fail on collision)') }}
			</NcCheckboxRadioSwitch>

			<NcButton
				type="primary"
				:disabled="!selectedFile || importing"
				data-test="import-submit"
				@click="runImport">
				<template #icon>
					<Upload :size="20" />
				</template>
				{{ importing ? t('mydash', 'Importing…') : t('mydash', 'Upload archive') }}
			</NcButton>

			<div v-if="importResult" class="mydash-export-import__result">
				<p>
					{{ t('mydash', 'Imported {imported} dashboards, skipped {skipped}.', {
						imported: importResult.importedDashboardCount,
						skipped: importResult.skippedDashboardCount,
					}) }}
				</p>
				<ul v-if="importResult.errors && importResult.errors.length > 0">
					<li v-for="(err, idx) in importResult.errors" :key="idx">
						{{ err.message || err.type }}
					</li>
				</ul>
			</div>

			<span v-if="importError" class="mydash-export-import__error">
				{{ importError }}
			</span>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch } from '@conduction/nextcloud-vue'
import Download from 'vue-material-design-icons/Download.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import { api } from '../../services/api.js'

export default {
	name: 'DashboardExportImport',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		Download,
		Upload,
	},

	data() {
		return {
			exporting: false,
			exportError: '',
			selectedFile: null,
			importing: false,
			importError: '',
			importResult: null,
			preserveUuids: false,
		}
	},

	methods: {
		/** @spec openspec/specs/dashboard-export-import/spec.md */
		async exportSite() {
			this.exporting = true
			this.exportError = ''
			try {
				const response = await api.exportDashboards({ scope: 'site' })
				this.downloadBlob(response.data, this.suggestedFilename())
			} catch (err) {
				this.exportError = this.t('mydash', 'Export failed. Please try again.')
				// eslint-disable-next-line no-console
				console.error('mydash export failed', err)
			} finally {
				this.exporting = false
			}
		},

		/** @spec openspec/specs/dashboard-export-import/spec.md */
		onFileSelected(event) {
			const files = event?.target?.files
			this.selectedFile = (files && files.length > 0) ? files[0] : null
			this.importResult = null
			this.importError = ''
		},

		/** @spec openspec/specs/dashboard-export-import/spec.md */
		async runImport() {
			if (!this.selectedFile) return
			this.importing = true
			this.importError = ''
			this.importResult = null
			try {
				const response = await api.importDashboards(this.selectedFile, {
					preserveUuids: this.preserveUuids,
				})
				this.importResult = response.data
			} catch (err) {
				const data = err?.response?.data
				if (data && Array.isArray(data.errors)) {
					this.importResult = data
				} else {
					this.importError = this.t('mydash', 'Import failed. Please try again.')
				}
				// eslint-disable-next-line no-console
				console.error('mydash import failed', err)
			} finally {
				this.importing = false
			}
		},

		/** @spec openspec/specs/dashboard-export-import/spec.md */
		downloadBlob(blob, filename) {
			const url = window.URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = filename
			document.body.appendChild(link)
			link.click()
			document.body.removeChild(link)
			window.URL.revokeObjectURL(url)
		},

		/** @spec openspec/specs/dashboard-export-import/spec.md */
		suggestedFilename() {
			const stamp = new Date().toISOString().replace(/[-:T.Z]/g, '').slice(0, 14)
			return `mydash-export-${stamp}.zip`
		},
	},
}
</script>

<style lang="scss" scoped>
.mydash-export-import {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;

	&__hint {
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 0.75rem;

		&--column {
			flex-direction: column;
			align-items: flex-start;
		}
	}

	&__label {
		font-weight: 600;
	}

	&__divider {
		border: none;
		border-top: 1px solid var(--color-border);
		margin: 0.5rem 0;
	}

	&__error {
		color: var(--color-error);
	}

	&__result {
		background: var(--color-background-hover);
		padding: 0.5rem 0.75rem;
		border-radius: var(--border-radius);
		width: 100%;

		ul {
			margin: 0.25rem 0 0 1.25rem;
			padding: 0;
		}
	}
}
</style>
