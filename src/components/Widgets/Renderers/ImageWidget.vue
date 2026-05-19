<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="image-widget"
		:style="wrapperStyle"
		@click="onClick">
		<img
			v-if="showImage"
			class="image-widget__img"
			:src="url"
			:alt="alt"
			:style="imgStyle"
			@error="onImageError">
		<div v-else class="image-widget__placeholder" :style="placeholderStyle">
			<Camera :size="48" :fill-color="placeholderColor" />
			<span class="image-widget__placeholder-label">{{ placeholderLabel }}</span>
		</div>
	</div>
</template>

<script>
import Camera from 'vue-material-design-icons/Camera.vue'

const ALLOWED_FITS = ['cover', 'contain', 'fill', 'none']
const DEFAULT_FIT = 'cover'

/**
 * ImageWidget renders a single image inside a dashboard cell. The
 * persisted shape is `{type: 'image', content: {url, alt, link, fit}}`
 * with `fit` restricted to `'cover' | 'contain' | 'fill' | 'none'` and
 * defaulting to `'cover'`.
 *
 * Three render branches:
 *   - `url` non-empty and not yet errored → `<img>` with `object-fit: <fit>`.
 *   - `url` empty → camera placeholder + `t('No image')`.
 *   - `<img>` `error` event fired → camera placeholder + `t('Image failed to load')`.
 *
 * Click-through: when `link` is non-empty the cell wrapper sets
 * `cursor: pointer` and a click opens the link via
 * `window.open(link, '_blank', 'noopener,noreferrer')`. When `link` is
 * empty the cursor stays default (deliberate UX choice — no misleading
 * clickable affordance) and clicks are no-ops.
 */
export default {
	name: 'ImageWidget',

	components: {
		Camera,
	},

	props: {
		/** Persisted content blob: `{url, alt, link, fit}`. */
		content: {
			type: Object,
			default: () => ({}),
		},
		/** Reserved for future use — kept to match the renderer contract. */
		placement: {
			type: Object,
			default: null,
		},
		/**
		 * REQ-IMG-001: object-fit value. Restricted to the four CSS values we
		 * support; an unknown value falls back to `'cover'` and triggers a
		 * Vue prop validator warning.
		 */
		fit: {
			type: String,
			default: undefined,
			validator(value) {
				if (value === undefined || value === null) {
					return true
				}
				return ALLOWED_FITS.includes(value)
			},
		},
	},

	data() {
		return {
			// Set to true once the `<img>` has fired the DOM `error`
			// event so subsequent renders show the placeholder + the
			// "Image failed to load" annotation (REQ-IMG-004).
			loadFailed: false,
		}
	},

	computed: {
		url() {
			const value = this.content && this.content.url
			return typeof value === 'string' ? value : ''
		},

		alt() {
			const value = this.content && this.content.alt
			return typeof value === 'string' ? value : ''
		},

		link() {
			const value = this.content && this.content.link
			return typeof value === 'string' ? value : ''
		},

		hasUrl() {
			return this.url.trim() !== ''
		},

		hasLink() {
			return this.link.trim() !== ''
		},

		showImage() {
			return this.hasUrl && this.loadFailed === false
		},

		/**
		 * Resolve the active `object-fit` value, preferring the prop
		 * (used by some test/integration call sites) and falling back
		 * to the persisted `content.fit`. Unknown / missing values
		 * collapse to `'cover'` per REQ-IMG-001.
		 *
		 * @return {string} one of cover, contain, fill, none
		 */
		resolvedFit() {
			let candidate = this.fit
			if (candidate === undefined || candidate === null) {
				candidate = this.content && this.content.fit
			}
			if (typeof candidate !== 'string' || ALLOWED_FITS.includes(candidate) === false) {
				return DEFAULT_FIT
			}
			return candidate
		},

		placeholderLabel() {
			return this.loadFailed
				? t('mydash', 'Image failed to load')
				: t('mydash', 'No image')
		},

		placeholderColor() {
			return 'var(--color-text-maxcontrast)'
		},

		wrapperStyle() {
			return {
				width: '100%',
				height: '100%',
				overflow: 'hidden',
				position: 'relative',
				cursor: this.hasLink ? 'pointer' : 'default',
			}
		},

		imgStyle() {
			return {
				width: '100%',
				height: '100%',
				display: 'block',
				'object-fit': this.resolvedFit,
			}
		},

		placeholderStyle() {
			return {
				width: '100%',
				height: '100%',
				display: 'flex',
				'flex-direction': 'column',
				'align-items': 'center',
				'justify-content': 'center',
				gap: '8px',
				color: this.placeholderColor,
			}
		},
	},

	watch: {
		// When the URL changes (for example after the user edits the
		// placement) we must re-arm the `<img>` so a previously failed
		// URL doesn't permanently lock the cell into the placeholder.
		url() {
			this.loadFailed = false
		},
	},

	methods: {
		/**
		 * REQ-IMG-004: swap to the placeholder + `Image failed to load`
		 * annotation when the `<img>` reports an error. We deliberately
		 * swallow the event here so no exception bubbles up into the
		 * GridStack grid layer (which would crash the whole dashboard).
		 */
		onImageError() {
			this.loadFailed = true
		},

		/**
		 * REQ-IMG-003: open `link` in a new tab on click when non-empty,
		 * no-op otherwise. We pass `noopener,noreferrer` so the opened
		 * page can never reach back into the dashboard via `window.opener`.
		 */
		onClick() {
			if (this.hasLink === false) {
				return
			}
			window.open(this.link, '_blank', 'noopener,noreferrer')
		},
	},
}
</script>

<style scoped>
.image-widget {
	width: 100%;
	height: 100%;
	overflow: hidden;
}

.image-widget__img {
	display: block;
}

.image-widget__placeholder {
	width: 100%;
	height: 100%;
}

.image-widget__placeholder-label {
	font-size: 13px;
}
</style>
