<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="video-form">
		<NcTextField
			:value="videoUrl"
			:label="t('mydash', 'Video URL')"
			placeholder="https://www.youtube.com/watch?v=..."
			required
			@update:value="onUrlInput" />

		<div v-if="detectedSource" class="video-form__hint">
			{{ detectedSourceLabel }}
		</div>
		<div v-else-if="hasUrl" class="video-form__error" role="alert">
			{{ t('mydash', 'Invalid video URL or domain not allowed.') }}
		</div>

		<NcTextField
			v-if="sourceType === 'nc-file'"
			:value="String(fileId || '')"
			:label="t('mydash', 'Nextcloud File ID')"
			placeholder="12345"
			@update:value="onFileIdInput" />

		<label class="video-form__field">
			<span class="video-form__label">{{ t('mydash', 'Aspect Ratio') }}</span>
			<select
				v-model="aspectRatio"
				class="video-form__select"
				@change="updateField('aspectRatio', aspectRatio)">
				<option v-for="opt in aspectRatioOptions" :key="opt.value" :value="opt.value">
					{{ opt.label }}
				</option>
			</select>
		</label>

		<label class="video-form__checkbox">
			<input
				type="checkbox"
				:checked="autoplay"
				@change="onAutoplayToggle($event.target.checked)">
			{{ t('mydash', 'Autoplay') }}
		</label>
		<div v-if="autoplay" class="video-form__hint">
			{{ t('mydash', 'Autoplay requires muting') }}
		</div>

		<label class="video-form__checkbox">
			<input
				type="checkbox"
				:checked="muted"
				:disabled="autoplay"
				@change="updateField('muted', $event.target.checked)">
			{{ t('mydash', 'Muted') }}
		</label>

		<label class="video-form__checkbox">
			<input
				type="checkbox"
				:checked="loop"
				@change="updateField('loop', $event.target.checked)">
			{{ t('mydash', 'Loop') }}
		</label>

		<label class="video-form__checkbox">
			<input
				type="checkbox"
				:checked="controls"
				@change="updateField('controls', $event.target.checked)">
			{{ t('mydash', 'Show controls') }}
		</label>

		<NcTextField
			:value="posterUrl"
			:label="t('mydash', 'Poster Image URL (optional)')"
			placeholder="https://example.com/poster.jpg"
			@update:value="updateField('posterUrl', $event)" />
	</div>
</template>

<script>
import { NcTextField } from '@conduction/nextcloud-vue'
import { detectVideoSource, normalizeEmbedUrl } from '../../../utils/videoUrlParser.js'

const VALID_ASPECT_RATIOS = ['16:9', '4:3', '1:1', '9:16']

const DEFAULT_CONTENT = Object.freeze({
	sourceType: null,
	videoUrl: '',
	fileId: null,
	autoplay: false,
	muted: true,
	loop: false,
	controls: true,
	aspectRatio: '16:9',
	posterUrl: '',
})

/**
 * VideoForm — sub-form mounted inside the AddWidgetModal when the user is
 * creating or editing a `video` placement.
 *
 * The form uses the shared `videoUrlParser` helper to detect the platform
 * (YouTube, Vimeo, PeerTube, Nextcloud Files) from the user's URL and to
 * pre-compute a canonical embed URL for hosted platforms. The detected
 * source type is reflected in the inline hint so the user gets immediate
 * feedback that the URL was recognised. Detection is purely client-side
 * UX; the server is the authoritative parser at save time and re-runs the
 * normalisation against the admin allow-list.
 *
 * Controls:
 *   - Video URL — primary text input. Detection runs on every change.
 *   - Nextcloud File ID — visible only when `sourceType === 'nc-file'`.
 *   - Aspect Ratio select — `16:9 | 4:3 | 1:1 | 9:16`, default `16:9`.
 *   - Autoplay / Muted / Loop / Show controls checkboxes. Autoplay forces
 *     muted=true (REQ-VID-008) and disables the muted checkbox.
 *   - Poster Image URL — optional, applied to HTML5 video tag only.
 *
 * `validate()` returns `[t('mydash', 'Video URL is required')]` when the
 * primary URL field is empty and `[t('mydash', 'Invalid video URL or
 * domain not allowed.')]` when the URL does not parse to a known source.
 */
export default {
	name: 'VideoForm',

	components: {
		NcTextField,
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
		const initial = (this.editingWidget && this.editingWidget.content) || this.value || {}
		return {
			videoUrl: typeof initial.videoUrl === 'string' ? initial.videoUrl : DEFAULT_CONTENT.videoUrl,
			sourceType: typeof initial.sourceType === 'string' ? initial.sourceType : DEFAULT_CONTENT.sourceType,
			fileId: typeof initial.fileId === 'number' ? initial.fileId : DEFAULT_CONTENT.fileId,
			autoplay: typeof initial.autoplay === 'boolean' ? initial.autoplay : DEFAULT_CONTENT.autoplay,
			muted: typeof initial.muted === 'boolean' ? initial.muted : DEFAULT_CONTENT.muted,
			loop: typeof initial.loop === 'boolean' ? initial.loop : DEFAULT_CONTENT.loop,
			controls: typeof initial.controls === 'boolean' ? initial.controls : DEFAULT_CONTENT.controls,
			aspectRatio: VALID_ASPECT_RATIOS.includes(initial.aspectRatio)
				? initial.aspectRatio
				: DEFAULT_CONTENT.aspectRatio,
			posterUrl: typeof initial.posterUrl === 'string' ? initial.posterUrl : DEFAULT_CONTENT.posterUrl,
		}
	},

	computed: {
		hasUrl() {
			return typeof this.videoUrl === 'string' && this.videoUrl.trim() !== ''
		},

		detectedSource() {
			if (!this.hasUrl) {
				return null
			}
			return detectVideoSource(this.videoUrl)
		},

		detectedSourceLabel() {
			switch (this.detectedSource) {
			case 'youtube':
				return t('mydash', 'Detected: YouTube')
			case 'vimeo':
				return t('mydash', 'Detected: Vimeo')
			case 'peertube':
				return t('mydash', 'Detected: PeerTube')
			case 'nc-file':
				return t('mydash', 'Detected: Nextcloud File')
			default:
				return ''
			}
		},

		aspectRatioOptions() {
			return [
				{ value: '16:9', label: '16:9' },
				{ value: '4:3', label: '4:3' },
				{ value: '1:1', label: '1:1' },
				{ value: '9:16', label: '9:16' },
			]
		},

		assembledContent() {
			return {
				sourceType: this.sourceType,
				videoUrl: this.normalizedVideoUrl,
				fileId: this.fileId,
				autoplay: this.autoplay,
				// REQ-VID-008: backend invariant — autoplay forces muted.
				muted: this.autoplay ? true : this.muted,
				loop: this.loop,
				controls: this.controls,
				aspectRatio: this.aspectRatio,
				posterUrl: this.posterUrl,
			}
		},

		/**
		 * The canonical embed URL we emit upward. For hosted platforms the
		 * shared parser produces the embed form; for `nc-file` we keep the
		 * raw input string as a transparency aid for the form (the backend
		 * will re-resolve via the file ID anyway).
		 *
		 * @return {string} embed URL or empty string
		 */
		normalizedVideoUrl() {
			if (!this.detectedSource || this.detectedSource === 'nc-file') {
				return this.videoUrl
			}
			return normalizeEmbedUrl(this.videoUrl, this.detectedSource) || this.videoUrl
		},
	},

	methods: {
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * URL changes drive source-type detection and trigger an
		 * `update:content` so the parent picks up both the new URL and
		 * the inferred sourceType in one event.
		 *
		 * @param {string} value the new URL
		 */
		onUrlInput(value) {
			this.videoUrl = value
			const detected = detectVideoSource(value)
			this.sourceType = detected
			this.$emit('update:content', this.assembledContent)
		},

		onFileIdInput(value) {
			const parsed = parseInt(String(value).trim(), 10)
			this.fileId = Number.isFinite(parsed) ? parsed : null
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * REQ-VID-008: autoplay coerces muted=true at the form level so the
		 * UX matches the backend invariant. The muted checkbox is disabled
		 * while autoplay is on.
		 *
		 * @param {boolean} checked the autoplay checkbox value
		 */
		onAutoplayToggle(checked) {
			this.autoplay = checked
			if (checked) {
				this.muted = true
			}
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * REQ-VID-011: validation requires a URL plus a recognised source.
		 *
		 * @return {string[]} validation errors
		 */
		validate() {
			const errors = []
			if (!this.hasUrl) {
				errors.push(t('mydash', 'Video URL is required'))
				return errors
			}
			if (this.detectedSource === null) {
				errors.push(t('mydash', 'Invalid video URL or domain not allowed.'))
				return errors
			}
			if (this.detectedSource === 'nc-file' && this.fileId === null) {
				errors.push(t('mydash', 'Nextcloud file ID is required'))
			}
			return errors
		},
	},
}
</script>

<style scoped>
.video-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.video-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 14px;
}

.video-form__label {
	font-weight: 500;
}

.video-form__select {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.video-form__checkbox {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
}

.video-form__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.video-form__error {
	color: var(--color-error);
	font-size: 13px;
}
</style>
