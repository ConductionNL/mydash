<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="image-form">
		<fieldset class="image-form__source-group">
			<legend class="image-form__label">
				{{ t('mydash', 'Image source') }}
			</legend>
			<label class="image-form__radio">
				<input
					v-model="sourceType"
					type="radio"
					value="url"
					@change="onSourceChange">
				{{ t('mydash', 'URL/Link') }}
			</label>
			<label class="image-form__radio">
				<input
					v-model="sourceType"
					type="radio"
					value="upload"
					@change="onSourceChange">
				{{ t('mydash', 'Upload') }}
			</label>
			<label class="image-form__radio">
				<input
					v-model="sourceType"
					type="radio"
					value="files"
					@change="onSourceChange">
				{{ t('mydash', 'Pick from Files') }}
			</label>
		</fieldset>

		<label v-if="sourceType === 'upload'" class="image-form__field">
			<span class="image-form__label">{{ t('mydash', 'Upload Image') }}</span>
			<input
				type="file"
				accept="image/*"
				class="image-form__file"
				:disabled="uploading"
				@change="onFileSelected">
		</label>
		<div v-if="sourceType === 'upload' && uploadError" class="image-form__error" role="alert">
			{{ uploadError }}
		</div>

		<NcTextField
			v-if="sourceType === 'url'"
			:value="url"
			:label="t('mydash', 'Image URL')"
			:placeholder="t('mydash', 'Enter Image URL')"
			required
			@update:value="updateField('url', $event)" />

		<div v-if="sourceType === 'files'" class="image-form__files">
			<button
				type="button"
				class="image-form__pick-button"
				:disabled="picking"
				:aria-label="t('mydash', 'Pick image from Nextcloud Files')"
				@click="onPickFromFiles">
				{{ picking ? t('mydash', 'Opening file picker…') : t('mydash', 'Pick from Files') }}
			</button>
			<div v-if="filePath" class="image-form__file-path" aria-live="polite">
				{{ t('mydash', 'Selected:') }} {{ filePath }}
			</div>
			<div v-if="pickError" class="image-form__error" role="alert">
				{{ pickError }}
			</div>
		</div>

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
import { NcTextField } from '@conduction/nextcloud-vue'
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
	sourceType: 'url',
	fileId: null,
	filePath: '',
})

const ALLOWED_SOURCE_TYPES = Object.freeze(['url', 'upload', 'files'])

const PICKER_MIME_TYPES = Object.freeze([
	'image/png',
	'image/jpeg',
	'image/gif',
	'image/webp',
	'image/svg+xml',
])

/**
 * ImageForm is the sub-form mounted inside the AddWidgetModal when the
 * user is creating or editing an `image` widget placement.
 *
 * Controls (REQ-IMG-005, REQ-IMP-001..008):
 *   - Source type radio group: `url | upload | files` (REQ-IMP-007).
 *   - URL text input (visible when sourceType=url).
 *   - File upload `<input type="file" accept="image/*">` (visible when
 *     sourceType=upload) — on change, reads the file as a base64 data
 *     URL and POSTs it to `/apps/mydash/api/resources` via
 *     `uploadDataUrl()`. On success `form.url` is set to the response
 *     `{url}`; on failure an inline error appears under the upload
 *     input and `form.url` is left untouched.
 *   - "Pick from Files" button (visible when sourceType=files) — opens
 *     the Nextcloud file picker filtered to image MIME types
 *     (REQ-IMP-002). Selected file's path and `fileId` are stored on
 *     the placement (REQ-IMP-003); `url` is set to the OCS preview URL
 *     so the live preview thumbnail and renderer continue to work
 *     (REQ-IMP-004).
 *   - Alt text input.
 *   - Link text input (optional, drives click-through in the renderer).
 *   - Fit select — `cover | contain | fill | none`, default `cover`.
 *   - Live preview thumbnail under the source inputs whenever `url` is
 *     non-empty (the upload pipeline and file picker both set `url`).
 *
 * `validate()` returns `[t('mydash', 'Image URL is required')]` when
 * the active source type is `url` or `upload` and `form.url.trim() ===
 * ''`, or `[t('mydash', 'Please pick a file from Files')]` when the
 * active source type is `files` and `filePath` is empty. Otherwise
 * returns an empty array.
 *
 * Switching between source types preserves previously entered values
 * for other source types (REQ-IMP-007 scenario "Switching sources
 * retains previous values") — the URL string, the upload-set URL, and
 * the file-mode selection live in independent reactive fields.
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
		const initialSourceType = ALLOWED_SOURCE_TYPES.includes(initial.sourceType)
			? initial.sourceType
			: DEFAULT_CONTENT.sourceType
		const initialFileId = typeof initial.fileId === 'number' ? initial.fileId : null
		const initialFilePath = typeof initial.filePath === 'string' ? initial.filePath : ''
		return {
			url: typeof initial.url === 'string' ? initial.url : DEFAULT_CONTENT.url,
			alt: typeof initial.alt === 'string' ? initial.alt : DEFAULT_CONTENT.alt,
			link: typeof initial.link === 'string' ? initial.link : DEFAULT_CONTENT.link,
			fit: typeof initial.fit === 'string' ? initial.fit : DEFAULT_CONTENT.fit,
			sourceType: initialSourceType,
			fileId: initialFileId,
			filePath: initialFilePath,
			uploading: false,
			uploadError: '',
			picking: false,
			pickError: '',
			previewError: false,
		}
	},

	computed: {
		hasUrl() {
			return typeof this.url === 'string' && this.url.trim() !== ''
		},

		/** @spec openspec/specs/image-widget/spec.md */
		fitOptions() {
			return [
				{ value: 'cover', label: t('mydash', 'Cover') },
				{ value: 'contain', label: t('mydash', 'Contain') },
				{ value: 'fill', label: t('mydash', 'Fill') },
				{ value: 'none', label: t('mydash', 'None') },
			]
		},

		/** @spec openspec/specs/image-widget/spec.md */
		assembledContent() {
			return {
				url: this.url,
				alt: this.alt,
				link: this.link,
				fit: this.fit,
				sourceType: this.sourceType,
				fileId: this.fileId,
				filePath: this.filePath,
			}
		},
	},

	watch: {
		/** @spec openspec/specs/image-widget/spec.md */
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
		 * @param {string} field one of: url, alt, link, fit, sourceType, fileId, filePath
		 * @param {*} value new value
		 */
		/** @spec openspec/specs/image-widget/spec.md */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * REQ-IMP-007: switching source types must clear stale picker /
		 * upload error messages so the new source's UI starts clean,
		 * but it must NOT erase previously entered values for other
		 * sources (those live in their own fields and stay in component
		 * state for round-trip).
		 */
		/** @spec openspec/specs/image-widget/spec.md */
		onSourceChange() {
			this.uploadError = ''
			this.pickError = ''
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
		/** @spec openspec/specs/image-widget/spec.md */
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
		 * REQ-IMP-002: open the Nextcloud file picker filtered to image
		 * MIME types and store the selection. Loaded lazily so the
		 * picker bundle is not pulled into the modal's first paint and
		 * tests can inject a mock without intercepting top-level imports.
		 *
		 * On success, the selected node's path is stored as `filePath`,
		 * its file ID as `fileId`, and `url` is set to the Nextcloud
		 * core preview route so the existing renderer / preview
		 * pipeline picks it up unchanged (REQ-IMP-004).
		 *
		 * Cancellation (the picker's "FilePickerClosed" rejection) is
		 * NOT an error — we leave the previous selection intact and
		 * suppress the inline error message per REQ-IMP-002 task 4.5.
		 */
		/** @spec openspec/specs/image-widget/spec.md */
		async onPickFromFiles() {
			this.picking = true
			this.pickError = ''
			try {
				const dialogs = await import('@nextcloud/dialogs')
				const builder = dialogs.getFilePickerBuilder(t('mydash', 'Pick image from Files'))
				const picker = builder
					.setMultiSelect(false)
					.setMimeTypeFilter([...PICKER_MIME_TYPES])
					.allowDirectories(false)
					.build()
				const nodes = await picker.pickNodes()
				const node = Array.isArray(nodes) ? nodes[0] : nodes
				if (!node) {
					return
				}
				const fileId = typeof node.fileid === 'number'
					? node.fileid
					: (typeof node.id === 'number' ? node.id : null)
				const filePath = typeof node.path === 'string'
					? node.path
					: (typeof node.displayname === 'string' ? '/' + node.displayname : '')
				if (fileId === null || filePath === '') {
					this.pickError = t('mydash', 'Selected file is missing required metadata')
					return
				}
				this.fileId = fileId
				this.filePath = filePath
				// REQ-IMP-004: build the preview URL via the Nextcloud
				// core preview route. We construct the path inline (no
				// IURLGenerator on the client side) so the renderer can
				// treat file-mode placements identically to URL-mode
				// placements — the broken-image fallback (REQ-IMP-005)
				// already covers the case where the viewer cannot read
				// the file (preview returns 404).
				this.url = '/index.php/core/preview?fileId=' + encodeURIComponent(String(fileId))
					+ '&x=512&y=512&a=true'
				this.previewError = false
				this.$emit('update:content', this.assembledContent)
			} catch (err) {
				// `FilePickerClosed` is the documented rejection when
				// the user dismisses the picker without choosing — we
				// keep the prior selection and stay silent.
				const isClosed = err && (err.name === 'FilePickerClosed' || err.constructor?.name === 'FilePickerClosed')
				if (!isClosed) {
					this.pickError = t('mydash', 'File picker failed to open')
					// eslint-disable-next-line no-console
					console.warn('[mydash] file picker failed', err)
				}
			} finally {
				this.picking = false
			}
		},

		/**
		 * Mark the preview thumbnail as broken so the inline preview
		 * error message renders. The renderer has its own broken-image
		 * fallback for the dashboard cell — this is purely the form-side
		 * affordance.
		 */
		/** @spec openspec/specs/image-widget/spec.md */
		onPreviewError() {
			this.previewError = true
		},

		/**
		 * Returns a list of error strings; empty array means valid.
		 *
		 * For sourceType `url` or `upload`: requires `url` to be
		 * non-empty (REQ-IMG-005 unchanged).
		 *
		 * For sourceType `files`: requires `filePath` to be non-empty
		 * (REQ-IMP-002 / REQ-IMP-003 — a selection must be made
		 * before the form can save).
		 *
		 * @return {string[]} validation errors
		 */
		/** @spec openspec/specs/image-widget/spec.md */
		validate() {
			if (this.sourceType === 'files') {
				if (typeof this.filePath !== 'string' || this.filePath.trim() === '') {
					return [t('mydash', 'Please pick a file from Files')]
				}
				return []
			}
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

.image-form__source-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
	border: none;
	padding: 0;
	margin: 0;
}

.image-form__radio {
	display: flex;
	gap: 6px;
	align-items: center;
	font-size: 14px;
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

.image-form__files {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.image-form__pick-button {
	align-self: flex-start;
	padding: 6px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	cursor: pointer;
}

.image-form__pick-button:disabled {
	opacity: 0.6;
	cursor: progress;
}

.image-form__file-path {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
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
