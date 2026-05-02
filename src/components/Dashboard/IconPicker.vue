<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	IconPicker — capability `dashboard-icons` (REQ-ICON-008..009)

	Combined select + file-upload picker for any field that follows the
	dashboards.icon convention (see src/constants/dashboardIcons.js):

	  - Built-in `<select>` of registry options (REQ-ICON-003) emits the
	    registry key string, e.g. `'Star'`
	  - File-upload input reads the file as a data URL, POSTs it via
	    `uploadDataUrl()` (resource-uploads capability), and emits the
	    returned URL string
	  - 24×24 live preview rendered through `IconRenderer`
	  - On upload failure the previous v-model value is preserved and an
	    inline error message is surfaced

	Vue 2 v-model convention: `value` prop in, `input` event out.
-->

<template>
	<div class="icon-picker">
		<div class="icon-picker__preview">
			<IconRenderer
				:name="value"
				:size="24"
				:alt="t('mydash', 'Icon preview')" />
		</div>

		<select
			:value="builtInValue"
			class="icon-picker__select"
			:disabled="uploading"
			@change="selectIcon">
			<option value="" disabled>
				{{ t('mydash', 'Select icon…') }}
			</option>
			<option
				v-for="(_, name) in DASHBOARD_ICONS"
				:key="name"
				:value="name">
				{{ name }}
			</option>
		</select>

		<label class="icon-picker__upload-label">
			<input
				ref="fileInput"
				type="file"
				accept="image/*"
				class="icon-picker__file-input"
				:disabled="uploading"
				@change="handleFileSelect">
			<span class="icon-picker__upload-button">
				<span v-if="uploading">{{ t('mydash', 'Uploading…') }}</span>
				<span v-else>{{ t('mydash', 'Upload icon') }}</span>
			</span>
		</label>

		<p
			v-if="uploadError"
			class="icon-picker__error"
			role="alert">
			{{ uploadError }}
		</p>
	</div>
</template>

<script>
import { DASHBOARD_ICONS, isCustomIconUrl } from '../../constants/dashboardIcons.js'
import { uploadDataUrl, ResourceUploadError } from '../../services/resourceService.js'
import IconRenderer from './IconRenderer.vue'

export default {
	name: 'IconPicker',

	components: {
		IconRenderer,
	},

	props: {
		/**
		 * The current icon value: either a registry key, a URL, or
		 * null. Uses the Vue 2 v-model convention (value prop +
		 * `input` event).
		 */
		value: {
			type: String,
			default: null,
		},
	},

	emits: ['input'],

	data() {
		return {
			DASHBOARD_ICONS,
			uploadError: '',
			uploading: false,
		}
	},

	computed: {
		/**
		 * Show the registry value in the `<select>` only when v-model
		 * holds a registry key — when the user has uploaded a custom
		 * URL, leave the select on the disabled placeholder so it's
		 * obvious the upload has taken priority.
		 */
		builtInValue() {
			if (this.value && !isCustomIconUrl(this.value)) {
				return this.value
			}
			return ''
		},
	},

	methods: {
		selectIcon(event) {
			const selected = event.target.value
			this.uploadError = ''
			this.$emit('input', selected || null)
		},

		handleFileSelect(event) {
			const file = event.target.files?.[0]
			if (!file) {
				return
			}

			this.uploadError = ''
			this.uploading = true

			const reader = new FileReader()

			reader.onload = async (e) => {
				try {
					const dataUrl = e.target.result
					if (typeof dataUrl !== 'string') {
						throw new Error('FileReader did not return a data URL')
					}
					const response = await uploadDataUrl(dataUrl)
					this.$emit('input', response.url)
				} catch (err) {
					if (err instanceof ResourceUploadError && err.message) {
						this.uploadError = err.message
					} else {
						this.uploadError = t('mydash', 'Failed to upload icon')
					}
					console.error('Icon upload failed:', err)
				} finally {
					this.uploading = false
					this.resetFileInput()
				}
			}

			reader.onerror = () => {
				this.uploadError = t('mydash', 'Failed to upload icon')
				this.uploading = false
				this.resetFileInput()
			}

			reader.readAsDataURL(file)
		},

		resetFileInput() {
			if (this.$refs.fileInput) {
				this.$refs.fileInput.value = ''
			}
		},
	},
}
</script>

<style scoped>
.icon-picker {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.icon-picker__preview {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-background-hover);
}

.icon-picker__select {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 14px;
}

.icon-picker__upload-label {
	position: relative;
	display: inline-flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
}

.icon-picker__file-input {
	display: none;
}

.icon-picker__upload-button {
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-background-hover);
	font-size: 14px;
	transition: background-color 0.2s;
}

.icon-picker__upload-label:hover .icon-picker__upload-button {
	background-color: var(--color-background-dark);
}

.icon-picker__error {
	margin: 0;
	padding: 4px 8px;
	font-size: 12px;
	color: var(--color-error);
	background-color: var(--color-background-hover);
	border-radius: 2px;
}
</style>
