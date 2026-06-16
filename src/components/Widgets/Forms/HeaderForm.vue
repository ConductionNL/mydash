<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="header-form">
		<NcTextField
			:value="title"
			:label="t('mydash', 'Title')"
			:placeholder="t('mydash', 'Header title')"
			required
			@update:value="updateField('title', $event)" />

		<NcTextField
			:value="subtitle"
			:label="t('mydash', 'Subtitle (optional)')"
			:placeholder="t('mydash', 'Optional subtitle')"
			@update:value="updateField('subtitle', $event)" />

		<label class="header-form__field">
			<span class="header-form__label">{{ t('mydash', 'Upload Background Image') }}</span>
			<input
				type="file"
				accept="image/*"
				class="header-form__file"
				:disabled="uploading"
				@change="onFileSelected">
		</label>
		<div v-if="uploadError" class="header-form__error" role="alert">
			{{ uploadError }}
		</div>

		<NcTextField
			:value="backgroundImageUrl"
			:label="t('mydash', 'Or enter Background Image URL')"
			placeholder="https://example.com/banner.jpg"
			@update:value="updateField('backgroundImageUrl', $event)" />

		<label class="header-form__color-label">
			{{ t('mydash', 'Background Color') }}
			<input
				type="color"
				:value="backgroundColor || '#0070c0'"
				class="header-form__color"
				@input="updateField('backgroundColor', $event.target.value)">
		</label>

		<label class="header-form__field">
			<span class="header-form__label">{{ t('mydash', 'Overlay Mode') }}</span>
			<select
				v-model="overlayMode"
				class="header-form__select"
				@change="updateField('overlayMode', overlayMode)">
				<option v-for="opt in overlayModeOptions" :key="opt.value" :value="opt.value">
					{{ opt.label }}
				</option>
			</select>
		</label>

		<label v-if="overlayMode !== 'none'" class="header-form__color-label">
			{{ t('mydash', 'Overlay Color') }}
			<input
				type="color"
				:value="overlayColor || '#000000'"
				class="header-form__color"
				@input="updateField('overlayColor', $event.target.value)">
		</label>

		<label v-if="overlayMode === 'tint'" class="header-form__field">
			<span class="header-form__label">
				{{ t('mydash', 'Overlay Opacity') }} ({{ overlayOpacity.toFixed(2) }})
			</span>
			<input
				type="range"
				min="0"
				max="1"
				step="0.05"
				:value="overlayOpacity"
				class="header-form__range"
				@input="updateField('overlayOpacity', parseFloat($event.target.value))">
		</label>

		<label class="header-form__color-label">
			{{ t('mydash', 'Text Color') }}
			<input
				type="color"
				:value="textColor || '#ffffff'"
				class="header-form__color"
				@input="updateField('textColor', $event.target.value)">
		</label>

		<label class="header-form__field">
			<span class="header-form__label">{{ t('mydash', 'Text Alignment') }}</span>
			<select
				v-model="textAlign"
				class="header-form__select"
				@change="updateField('textAlign', textAlign)">
				<option v-for="opt in textAlignOptions" :key="opt.value" :value="opt.value">
					{{ opt.label }}
				</option>
			</select>
		</label>

		<label class="header-form__field">
			<span class="header-form__label">{{ t('mydash', 'Vertical Alignment') }}</span>
			<select
				v-model="verticalAlign"
				class="header-form__select"
				@change="updateField('verticalAlign', verticalAlign)">
				<option v-for="opt in verticalAlignOptions" :key="opt.value" :value="opt.value">
					{{ opt.label }}
				</option>
			</select>
		</label>

		<label class="header-form__field">
			<span class="header-form__label">{{ t('mydash', 'Height') }}</span>
			<select
				v-model="height"
				class="header-form__select"
				@change="updateField('height', height)">
				<option v-for="opt in heightOptions" :key="opt.value" :value="opt.value">
					{{ opt.label }}
				</option>
			</select>
		</label>

		<fieldset class="header-form__fieldset">
			<legend class="header-form__legend">
				{{ t('mydash', 'Call-to-Action Button (optional)') }}
			</legend>

			<NcTextField
				:value="ctaLabel"
				:label="t('mydash', 'Button Text')"
				:placeholder="t('mydash', 'Sign up')"
				@update:value="updateCta('label', $event)" />

			<NcTextField
				:value="ctaUrl"
				:label="t('mydash', 'Target URL')"
				placeholder="https://..."
				@update:value="updateCta('url', $event)" />

			<label class="header-form__field">
				<span class="header-form__label">{{ t('mydash', 'Button Style') }}</span>
				<select
					v-model="ctaStyle"
					class="header-form__select"
					@change="updateCta('style', ctaStyle)">
					<option v-for="opt in ctaStyleOptions" :key="opt.value" :value="opt.value">
						{{ opt.label }}
					</option>
				</select>
			</label>
		</fieldset>
	</div>
</template>

<script>
import { NcTextField } from '@conduction/nextcloud-vue'
import {
	uploadDataUrl,
	readFileAsDataUrl,
	ResourceUploadError,
} from '../../../services/resourceService.js'

const ALLOWED_OVERLAY_MODES = ['none', 'tint', 'gradient-bottom']
const ALLOWED_HEIGHTS = ['small', 'medium', 'large', 'xlarge']
const ALLOWED_TEXT_ALIGN = ['left', 'center', 'right']
const ALLOWED_VERTICAL_ALIGN = ['top', 'middle', 'bottom']
const ALLOWED_CTA_STYLES = ['primary', 'secondary', 'ghost']

const DEFAULT_CONTENT = Object.freeze({
	title: '',
	subtitle: '',
	backgroundImageUrl: '',
	backgroundImageFileId: null,
	backgroundColor: '',
	overlayMode: 'none',
	overlayColor: '',
	overlayOpacity: 0.4,
	textColor: '',
	textAlign: 'center',
	verticalAlign: 'middle',
	height: 'medium',
	cta: null,
})

/**
 * HeaderForm — sub-form for the AddWidgetModal when the user is creating
 * or editing a `header` placement (REQ-HDR-002).
 *
 * Controls cover every persisted field on the header widget content
 * blob: title, subtitle, background image (upload + URL), background
 * color, overlay mode/color/opacity, text color/alignment/vertical
 * alignment, height preset, and an optional call-to-action with label,
 * URL, and style.
 *
 * `validate()` requires `title` to be non-empty and any provided
 * `backgroundImageUrl` to be `http(s)` — invalid CTAs (label OR url
 * empty when the other is set) also produce an error so the user gets
 * an immediate hint instead of a half-baked button at render time.
 */
export default {
	name: 'HeaderForm',

	components: {
		NcTextField,
	},

	props: {
		/** The placement being edited, or `null` in create mode. */
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
		const cta = (initial.cta && typeof initial.cta === 'object') ? initial.cta : null
		return {
			title: typeof initial.title === 'string' ? initial.title : DEFAULT_CONTENT.title,
			subtitle: typeof initial.subtitle === 'string' ? initial.subtitle : DEFAULT_CONTENT.subtitle,
			backgroundImageUrl: typeof initial.backgroundImageUrl === 'string'
				? initial.backgroundImageUrl
				: DEFAULT_CONTENT.backgroundImageUrl,
			backgroundImageFileId: (typeof initial.backgroundImageFileId === 'number')
				? initial.backgroundImageFileId
				: DEFAULT_CONTENT.backgroundImageFileId,
			backgroundColor: typeof initial.backgroundColor === 'string'
				? initial.backgroundColor
				: DEFAULT_CONTENT.backgroundColor,
			overlayMode: ALLOWED_OVERLAY_MODES.includes(initial.overlayMode)
				? initial.overlayMode
				: DEFAULT_CONTENT.overlayMode,
			overlayColor: typeof initial.overlayColor === 'string'
				? initial.overlayColor
				: DEFAULT_CONTENT.overlayColor,
			overlayOpacity: typeof initial.overlayOpacity === 'number'
				? initial.overlayOpacity
				: DEFAULT_CONTENT.overlayOpacity,
			textColor: typeof initial.textColor === 'string'
				? initial.textColor
				: DEFAULT_CONTENT.textColor,
			textAlign: ALLOWED_TEXT_ALIGN.includes(initial.textAlign)
				? initial.textAlign
				: DEFAULT_CONTENT.textAlign,
			verticalAlign: ALLOWED_VERTICAL_ALIGN.includes(initial.verticalAlign)
				? initial.verticalAlign
				: DEFAULT_CONTENT.verticalAlign,
			height: ALLOWED_HEIGHTS.includes(initial.height)
				? initial.height
				: DEFAULT_CONTENT.height,
			ctaLabel: cta && typeof cta.label === 'string' ? cta.label : '',
			ctaUrl: cta && typeof cta.url === 'string' ? cta.url : '',
			ctaStyle: (cta && ALLOWED_CTA_STYLES.includes(cta.style)) ? cta.style : 'primary',
			uploading: false,
			uploadError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/header-widget/spec.md */
		overlayModeOptions() {
			return [
				{ value: 'none', label: t('mydash', 'None') },
				{ value: 'tint', label: t('mydash', 'Tinted Overlay') },
				{ value: 'gradient-bottom', label: t('mydash', 'Gradient Bottom') },
			]
		},

		/** @spec openspec/specs/header-widget/spec.md */
		textAlignOptions() {
			return [
				{ value: 'left', label: t('mydash', 'Left') },
				{ value: 'center', label: t('mydash', 'Center') },
				{ value: 'right', label: t('mydash', 'Right') },
			]
		},

		/** @spec openspec/specs/header-widget/spec.md */
		verticalAlignOptions() {
			return [
				{ value: 'top', label: t('mydash', 'Top') },
				{ value: 'middle', label: t('mydash', 'Middle') },
				{ value: 'bottom', label: t('mydash', 'Bottom') },
			]
		},

		/** @spec openspec/specs/header-widget/spec.md */
		heightOptions() {
			return [
				{ value: 'small', label: t('mydash', 'Small (120px)') },
				{ value: 'medium', label: t('mydash', 'Medium (200px)') },
				{ value: 'large', label: t('mydash', 'Large (320px)') },
				{ value: 'xlarge', label: t('mydash', 'Extra Large (480px)') },
			]
		},

		/** @spec openspec/specs/header-widget/spec.md */
		ctaStyleOptions() {
			return [
				{ value: 'primary', label: t('mydash', 'Primary') },
				{ value: 'secondary', label: t('mydash', 'Secondary') },
				{ value: 'ghost', label: t('mydash', 'Ghost') },
			]
		},

		/** @spec openspec/specs/header-widget/spec.md */
		ctaPayload() {
			const label = typeof this.ctaLabel === 'string' ? this.ctaLabel.trim() : ''
			const url = typeof this.ctaUrl === 'string' ? this.ctaUrl.trim() : ''
			if (label === '' && url === '') {
				return null
			}
			return {
				label,
				url,
				style: this.ctaStyle,
			}
		},

		/** @spec openspec/specs/header-widget/spec.md */
		assembledContent() {
			return {
				title: this.title,
				subtitle: this.subtitle,
				backgroundImageUrl: this.backgroundImageUrl,
				backgroundImageFileId: this.backgroundImageFileId,
				backgroundColor: this.backgroundColor,
				overlayMode: this.overlayMode,
				overlayColor: this.overlayColor,
				overlayOpacity: this.overlayOpacity,
				textColor: this.textColor,
				textAlign: this.textAlign,
				verticalAlign: this.verticalAlign,
				height: this.height,
				cta: this.ctaPayload,
			}
		},
	},

	methods: {
		/**
		 * Set a top-level field and re-emit the assembled payload.
		 *
		 * @param {string} field one of the top-level keys
		 * @param {*} value new value
		 */
		/** @spec openspec/specs/header-widget/spec.md */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Set a CTA sub-field (label, url, style) and re-emit.
		 *
		 * @param {string} field one of: label, url, style
		 * @param {string} value new value
		 */
		/** @spec openspec/specs/header-widget/spec.md */
		updateCta(field, value) {
			if (field === 'label') {
				this.ctaLabel = value
			} else if (field === 'url') {
				this.ctaUrl = value
			} else if (field === 'style') {
				this.ctaStyle = value
			}
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * Upload a chosen file to the resource pipeline and bind the
		 * returned URL to `backgroundImageUrl`. On failure leave the URL
		 * untouched and surface a translated inline error.
		 *
		 * @param {Event} event the input change event
		 */
		/** @spec openspec/specs/header-widget/spec.md */
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
				this.updateField('backgroundImageUrl', result.url)
			} catch (err) {
				this.uploadError = t('mydash', 'Failed to upload image')
				if (err instanceof ResourceUploadError) {
					// eslint-disable-next-line no-console
					console.warn('[mydash] header background upload failed', err.code, err.message)
				}
			} finally {
				this.uploading = false
				if (target) {
					target.value = ''
				}
			}
		},

		/**
		 * Validate the form. Returns an array of human-readable error
		 * strings; empty array means valid.
		 *
		 * REQ-HDR-002: title is required.
		 * REQ-HDR-005: external image URLs MUST be HTTP/HTTPS.
		 * REQ-HDR-010: when CTA fields are partially filled, both label
		 * AND url MUST be present.
		 *
		 * @return {string[]} validation errors
		 */
		/** @spec openspec/specs/header-widget/spec.md */
		validate() {
			const errors = []
			if (typeof this.title !== 'string' || this.title.trim() === '') {
				errors.push(t('mydash', 'Title is required'))
			}
			if (typeof this.backgroundImageUrl === 'string'
				&& this.backgroundImageUrl.trim() !== ''
				&& /^https?:\/\//i.test(this.backgroundImageUrl.trim()) === false) {
				errors.push(t('mydash', 'Background image URL must be HTTP or HTTPS'))
			}
			const labelEmpty = typeof this.ctaLabel !== 'string' || this.ctaLabel.trim() === ''
			const urlEmpty = typeof this.ctaUrl !== 'string' || this.ctaUrl.trim() === ''
			if (labelEmpty !== urlEmpty) {
				errors.push(t('mydash', 'Call-to-Action requires both label and URL'))
			}
			return errors
		},
	},
}
</script>

<style scoped>
.header-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.header-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 14px;
}

.header-form__label {
	font-weight: 500;
}

.header-form__file {
	font-size: 13px;
}

.header-form__select {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.header-form__color-label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	font-size: 14px;
}

.header-form__color {
	width: 48px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
}

.header-form__range {
	width: 100%;
}

.header-form__error {
	color: var(--color-error);
	font-size: 13px;
}

.header-form__fieldset {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
}

.header-form__legend {
	font-size: 13px;
	font-weight: 600;
	padding: 0 4px;
}
</style>
