<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="image-widget"
		:style="wrapperStyle"
		@click="handleClick">
		<img
			v-if="hasUrl"
			:src="url"
			:alt="alt"
			class="image-widget__img"
			@error="onImageError">
		<div
			v-else
			class="image-widget__placeholder">
			<svg
				viewBox="0 0 24 24"
				width="48"
				height="48"
				fill="currentColor">
				<path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
			</svg>
			<p class="image-widget__placeholder-text">
				{{ placeholderText }}
			</p>
		</div>
	</div>
</template>

<script>
/**
 * ImageWidget
 *
 * Renders an image inside a dashboard cell with configurable object-fit behaviour.
 * Supports click-through link navigation, empty-URL placeholder, and broken-image
 * fallback (REQ-IMG-001..004).
 */
export default {
	name: 'ImageWidget',

	props: {
		/**
		 * Persisted widget content. Shape:
		 *   { url, alt, link, fit }
		 * Any field may be missing or empty — the renderer falls back to
		 * sensible defaults per the spec.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Image URL. If empty, renders placeholder.
		 */
		url: {
			type: String,
			default: '',
		},

		/**
		 * Alt text for accessibility.
		 */
		alt: {
			type: String,
			default: '',
		},

		/**
		 * Optional click-through link. When set, cell becomes clickable.
		 */
		link: {
			type: String,
			default: '',
		},

		/**
		 * CSS object-fit value. Validator restricts to valid enum values
		 * with fallback to 'cover' on unknown input (REQ-IMG-001).
		 */
		fit: {
			type: String,
			default: 'cover',
			validator(value) {
				if (!['cover', 'contain', 'fill', 'none'].includes(value)) {
					console.warn(`Invalid fit value: '${value}'. Falling back to 'cover'.`)
					return true // Vue validators pass through; the computed property falls back
				}
				return true
			},
		},
	},

	data() {
		return {
			showError: false,
		}
	},

	computed: {
		// Prefer prop values if provided, else fall back to content blob
		resolvedUrl() {
			return this.url || (this.content?.url || '')
		},

		resolvedAlt() {
			return this.alt || (this.content?.alt || '')
		},

		resolvedLink() {
			return this.link || (this.content?.link || '')
		},

		resolvedFit() {
			const value = this.fit || this.content?.fit || 'cover'
			const allowed = ['cover', 'contain', 'fill', 'none']
			return allowed.includes(value) ? value : 'cover'
		},

		hasUrl() {
			return this.resolvedUrl.trim() !== '' && !this.showError
		},

		wrapperStyle() {
			return {
				cursor: this.resolvedLink ? 'pointer' : 'default',
				overflow: 'hidden',
			}
		},

		placeholderText() {
			if (this.showError) {
				if (typeof t === 'function') {
					return t('mydash', 'Image failed to load')
				}
				return 'Image failed to load'
			}
			if (typeof t === 'function') {
				return t('mydash', 'No image')
			}
			return 'No image'
		},
	},

	methods: {
		onImageError() {
			this.showError = true
		},

		handleClick() {
			if (this.resolvedLink && this.resolvedLink.trim() !== '') {
				window.open(this.resolvedLink, '_blank', 'noopener,noreferrer')
			}
		},
	},
}
</script>

<style scoped>
.image-widget {
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	overflow: hidden;
}

.image-widget__img {
	width: 100%;
	height: 100%;
	object-fit: v-bind(resolvedFit);
}

.image-widget__placeholder {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	padding: 12px;
	box-sizing: border-box;
}

.image-widget__placeholder-text {
	margin: 0;
	font-size: 14px;
	text-align: center;
}
</style>
