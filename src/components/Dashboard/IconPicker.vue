<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	IconPicker — capability `custom-icon-upload-pattern` (REQ-ICON-008..009)

	Combined select + file-upload picker for the `icon` field following the
	dashboards.icon convention. Lets users pick from a built-in registry OR
	upload a custom image URL, both updating the same v-model value.

	- Built-in select emits the registry key string (e.g. 'Star')
	- File upload reads as data URL, POSTs to resource-uploads endpoint,
	  emits the returned URL string
	- 24×24 preview via IconRenderer
	- Inline error display on upload failure (preserves previous value)

	Uses v-model per Vue 2 convention: value prop + input event.
-->

<template>
	<div class="icon-picker">
		<div class="icon-picker__preview">
			<IconRenderer
				:name="value"
				:alt="null"
				:size="24" />
		</div>

		<select
			:value="value"
			class="icon-picker__select"
			@change="selectIcon">
			<option :value="null" disabled>
				{{ tt('Select icon...') }}
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
				type="file"
				accept="image/*"
				class="icon-picker__file-input"
				@change="handleFileSelect">
			<span class="icon-picker__upload-button">
				{{ tt('Upload icon') }}
			</span>
		</label>

		<p v-if="uploadError" class="icon-picker__error">
			{{ uploadError }}
		</p>
	</div>
</template>

<script>
import { DASHBOARD_ICONS } from '../../constants/dashboardIcons.js'
import { uploadDataUrl } from '../../services/resourceService.js'
import IconRenderer from './IconRenderer.vue'

export default {
	name: 'IconPicker',

	components: {
		IconRenderer,
	},

	props: {
		/**
		 * The current icon value: either a registry key, a URL, or null.
		 * Uses Vue 2 v-model convention (value prop + input event).
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
		}
	},

	methods: {
		tt(key) {
			if (typeof t === 'function') {
				return t('mydash', key)
			}
			return key
		},

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
			const reader = new FileReader()

			reader.onload = async (e) => {
				try {
					const dataUrl = e.target.result
					const response = await uploadDataUrl(dataUrl)
					this.$emit('input', response.url)
					// Reset file input
					event.target.value = ''
				} catch (error) {
					this.uploadError = this.tt('Failed to upload icon')
					console.error('Icon upload failed:', error)
					// Reset file input
					event.target.value = ''
				}
			}

			reader.onerror = () => {
				this.uploadError = this.tt('Failed to upload icon')
				// Reset file input
				event.target.value = ''
			}

			reader.readAsDataURL(file)
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
	background-color: var(--color-background-secondary);
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
	background-color: var(--color-background-secondary);
	font-size: 14px;
	transition: background-color 0.2s;
}

.icon-picker__upload-label:hover .icon-picker__upload-button {
	background-color: var(--color-background-tertiary);
}

.icon-picker__error {
	margin: 0;
	padding: 4px 8px;
	font-size: 12px;
	color: var(--color-error);
	background-color: rgba(192, 0, 0, 0.1);
	border-radius: 2px;
}
</style>
