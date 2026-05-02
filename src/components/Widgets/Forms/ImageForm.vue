<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="image-form">
		<label class="image-form__field">
			<span class="image-form__label">{{ t('mydash', 'Upload Image') }}</span>
			<input
				type="file"
				accept="image/*"
				class="image-form__file"
				:disabled="uploading"
				@change="onFileSelected">
		</label>
		<div v-if="uploadError" class="image-form__error" role="alert">
			{{ uploadError }}
		</div>

		<NcTextField
			:value="url"
			:label="t('mydash', 'Or enter Image URL')"
			:placeholder="t('mydash', 'Or enter Image URL')"
			required
			@update:value="updateField('url', $event)" />

		<div v-if="hasUrl" class="image-form__preview-wrap">
			<img
				class="image-form__preview"
				:src="url"
				:alt="alt || t('mydash', 'Image')"
				@error="onPreviewError">
			<div v-if="previewError" class="image-form__preview-error">
				{{ t('mydash', 'Image failed to load') }}
			</div>
		</div>

		<NcTextField
			:value="alt"
			:label="t('mydash', 'Alt Text')"
			@update:value="updateField('alt', $event)" />

		<NcTextField
			:value="link"
			:label="t('mydash', 'Link (optional)')"
			placeholder="https://example.com"
			@update:value="updateField('link', $event)" />

		<label class="image-form__field">
			<span class="image-form__label">{{ t('mydash', 'Fit') }}</span>
			<select
				v-model="fit"
				class="image-form__select"
				@change="updateField('fit', fit)">
				<option v-for="opt in fitOptions" :key="opt.value" :value="opt.value">
					{{ opt.label }}
				</option>
			</select>
		</label>
	</div>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'
import {
	uploadDataUrl,
	readFileAsDataUrl,
	ResourceUploadError,
} from '../../../services/resourceService.js'

const DEFAULT_CONTENT = Object.freeze({
	url: '',
	alt: '',
	link: '',
	fit: 'cover',
})

/**
 * ImageForm is the sub-form mounted inside the AddWidgetModal when the
 * user is creating or editing an `image` widget placement.
 *
 * Controls (REQ-IMG-005):
 *   - File upload (`<input type="file" accept="image/*">`) — on change,
 *     read the file as a base64 data URL and POST it to
 *     `/apps/mydash/api/resources` via `uploadDataUrl()`. On success
 *     `form.url` is set to the response `{url}`; on failure an inline
 *     error appears under the upload input and `form.url` is left
 *     untouched.
 *   - URL text input — direct entry path (also written by the upload
 *     pipeline on success).
 *   - Alt text input.
 *   - Link text input (optional, drives click-through in the renderer).
 *   - Fit select — `cover | contain | fill | none`, default `cover`.
 *   - Live preview thumbnail under the URL input whenever `url` is
 *     non-empty.
 *
 * `validate()` returns `[t('mydash', 'Image URL is required')]` when
 * `form.url.trim() === ''`, otherwise an empty array.
 */
export default {
	name: 'ImageForm',

	components: {
		NcTextField,
	},

	props: {
		/**
		 * The placement being edited, or `null` in create mode.
		 * Pre-fills every control from `editingWidget.content`.
		 */
		editingWidget: {
			type: Object,
			default: null,
		},
		/**
		 * Initial content values — used when not editing and the parent
		 * supplies registry defaults.
		 */
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = (this.editingWidget && this.editingWidget.content) || this.value || {}
		return {
			url: typeof initial.url === 'string' ? initial.url : DEFAULT_CONTENT.url,
			alt: typeof initial.alt === 'string' ? initial.alt : DEFAULT_CONTENT.alt,
			link: typeof initial.link === 'string' ? initial.link : DEFAULT_CONTENT.link,
			fit: typeof initial.fit === 'string' ? initial.fit : DEFAULT_CONTENT.fit,
			uploading: false,
			uploadError: '',
			previewError: false,
		}
	},

	computed: {
		hasUrl() {
			return typeof this.url === 'string' && this.url.trim() !== ''
		},

		fitOptions() {
			return [
				{ value: 'cover', label: t('mydash', 'Cover') },
				{ value: 'contain', label: t('mydash', 'Contain') },
				{ value: 'fill', label: t('mydash', 'Fill') },
				{ value: 'none', label: t('mydash', 'None') },
			]
		},

		assembledContent() {
			return {
				url: this.url,
				alt: this.alt,
				link: this.link,
				fit: this.fit,
			}
		},
	},

	watch: {
		url() {
			// When the URL changes the preview must re-arm so a
			// previously broken URL does not permanently mask a freshly
			// chosen good one.
			this.previewError = false
		},
	},

	methods: {
		/**
		 * Set a field and notify parent so the modal can fall back to
		 * the composable's `state.content` when assembling the submit
		 * payload.
		 *
		 * @param {string} field one of: url, alt, link, fit
		 * @param {string} value new value
		 */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Handle the file input's `change` event: read the chosen file
		 * as a base64 data URL and POST it to the resource-uploads
		 * endpoint. On success set `url` from the response; on failure
		 * surface the inline error string and leave `url` unchanged.
		 *
		 * @param {Event} event the input change event
		 */
		async onFileSelected(event) {
			const target = event && event.target
			const file = target && target.files && target.files[0]
			if (!file) {
				return
			}
			this.uploading = true
			this.uploadError = ''
			try {
				const dataUrl = await readFileAsDataUrl(file)
				const result = await uploadDataUrl(dataUrl)
				this.updateField('url', result.url)
			} catch (err) {
				// Per spec we surface a single generic message — the
				// server already produced a stable code we could branch
				// on, but the proposal explicitly calls out one string.
				this.uploadError = t('mydash', 'Failed to upload image')
				if (err instanceof ResourceUploadError) {
					// Keep a console hint for admins debugging an
					// upload regression.
					// eslint-disable-next-line no-console
					console.warn('[mydash] image upload failed', err.code, err.message)
				}
			} finally {
				this.uploading = false
				// Reset the input so re-selecting the same file fires
				// the change event again.
				if (target) {
					target.value = ''
				}
			}
		},

		/**
		 * Mark the preview thumbnail as broken so the inline preview
		 * error message renders. The renderer has its own broken-image
		 * fallback for the dashboard cell — this is purely the form-side
		 * affordance.
		 */
		onPreviewError() {
			this.previewError = true
		},

		/**
		 * Returns a list of error strings; empty array means valid.
		 *
		 * @return {string[]} validation errors
		 */
		validate() {
			if (typeof this.url !== 'string' || this.url.trim() === '') {
				return [t('mydash', 'Image URL is required')]
			}
			return []
		},
	},
}
</script>

<style scoped>
.image-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.image-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 14px;
}

.image-form__label {
	font-weight: 500;
}

.image-form__file {
	font-size: 13px;
}

.image-form__select {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.image-form__error {
	color: var(--color-error);
	font-size: 13px;
}

.image-form__preview-wrap {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.image-form__preview {
	max-width: 100%;
	max-height: 160px;
	object-fit: contain;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.image-form__preview-error {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
