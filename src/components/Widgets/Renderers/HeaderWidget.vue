<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="header-widget" :style="wrapperStyle">
		<div
			v-if="hasOverlay"
			class="header-widget__overlay"
			:style="overlayStyle"
			aria-hidden="true" />
		<div class="header-widget__content" :style="contentStyle">
			<h2 v-if="hasTitle" class="header-widget__title" :style="textStyle">
				{{ title }}
			</h2>
			<p v-if="hasSubtitle" class="header-widget__subtitle" :style="textStyle">
				{{ subtitle }}
			</p>
			<a
				v-if="hasCta"
				:href="ctaUrl"
				:class="ctaClasses"
				:target="ctaTarget"
				:rel="ctaRel"
				:aria-label="ctaAriaLabel">
				{{ ctaLabel }}
			</a>
		</div>
		<img
			v-if="hasBackgroundImage"
			class="header-widget__probe"
			:src="backgroundImageUrl"
			alt=""
			aria-hidden="true"
			@error="onImageError">
	</div>
</template>

<script>
const ALLOWED_OVERLAY_MODES = ['none', 'tint', 'gradient-bottom']
const ALLOWED_HEIGHTS = ['small', 'medium', 'large', 'xlarge']
const ALLOWED_TEXT_ALIGN = ['left', 'center', 'right']
const ALLOWED_VERTICAL_ALIGN = ['top', 'middle', 'bottom']
const ALLOWED_CTA_STYLES = ['primary', 'secondary', 'ghost']

const HEIGHT_PIXELS = Object.freeze({
	small: 120,
	medium: 200,
	large: 320,
	xlarge: 480,
})

const VERTICAL_ALIGN_FLEX = Object.freeze({
	top: 'flex-start',
	middle: 'center',
	bottom: 'flex-end',
})

/**
 * HeaderWidget — full-width banner widget for MyDash dashboards. Renders
 * a configurable header with title, optional subtitle, optional background
 * image (REQ-HDR-003), optional color overlay (REQ-HDR-004), and optional
 * call-to-action button (REQ-HDR-010).
 *
 * Image source precedence (REQ-HDR-003): `backgroundImageFileId` takes
 * priority over `backgroundImageUrl`. When the image fails to load
 * (404, timeout, blocked) the renderer silently falls back to the solid
 * `backgroundColor` (REQ-HDR-007); no error UI is shown.
 *
 * Height presets (REQ-HDR-009) map to fixed pixel values:
 *   small=120px, medium=200px, large=320px, xlarge=480px.
 *
 * Title is rendered as `<h2>`, subtitle as `<p>`, CTA as an `<a>` element
 * — all semantic HTML for screen-reader and keyboard accessibility
 * (REQ-HDR-008, REQ-HDR-011). External CTA URLs (http/https) open in a
 * new tab with `rel="noopener noreferrer"`; relative or anchor URLs stay
 * in the current tab.
 */
export default {
	name: 'HeaderWidget',

	props: {
		/**
		 * Persisted widget content. Shape:
		 * `{title, subtitle, backgroundImageUrl, backgroundImageFileId,
		 *   backgroundColor, overlayMode, overlayColor, overlayOpacity,
		 *   textColor, textAlign, verticalAlign, height, cta}`.
		 *
		 * All fields are optional except `title`. Unknown enum values
		 * collapse to documented defaults. The renderer never throws on
		 * malformed config — it falls back to a usable banner.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
		/** Reserved to match the renderer contract. */
		placement: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			// Set true once the background image fires a DOM `error` event
			// so subsequent renders fall back to `backgroundColor` only
			// (REQ-HDR-007). The probe `<img>` is invisible — its only
			// purpose is to detect load failure for the CSS background.
			imageFailed: false,
		}
	},

	computed: {
		/** @spec openspec/specs/header-widget/spec.md */
		title() {
			const value = this.content && this.content.title
			return typeof value === 'string' ? value : ''
		},

		/** @spec openspec/specs/header-widget/spec.md */
		subtitle() {
			const value = this.content && this.content.subtitle
			return typeof value === 'string' ? value : ''
		},

		hasTitle() {
			return this.title !== ''
		},

		hasSubtitle() {
			return this.subtitle !== ''
		},

		/** @spec openspec/specs/header-widget/spec.md */
		backgroundImageUrl() {
			// REQ-HDR-003: file ID takes precedence over URL.
			const fileId = this.content && this.content.backgroundImageFileId
			if (typeof fileId === 'number' && Number.isFinite(fileId) && fileId > 0) {
				return `/index.php/core/preview?fileId=${fileId}&x=1024&y=512&a=1`
			}
			const url = this.content && this.content.backgroundImageUrl
			if (typeof url === 'string' && /^https?:\/\//i.test(url)) {
				return url
			}
			return ''
		},

		hasBackgroundImage() {
			return this.backgroundImageUrl !== '' && this.imageFailed === false
		},

		/** @spec openspec/specs/header-widget/spec.md */
		backgroundColor() {
			const value = this.content && this.content.backgroundColor
			if (typeof value === 'string' && value !== '') {
				return value
			}
			return 'var(--color-primary, #0070c0)'
		},

		/** @spec openspec/specs/header-widget/spec.md */
		overlayMode() {
			const declared = this.content && this.content.overlayMode
			if (typeof declared === 'string' && ALLOWED_OVERLAY_MODES.includes(declared)) {
				return declared
			}
			// Default per REQ-HDR-002 / REQ-HDR-004: `tint` when image is
			// present, `none` otherwise.
			return this.hasBackgroundImage ? 'tint' : 'none'
		},

		hasOverlay() {
			return this.overlayMode !== 'none'
		},

		/** @spec openspec/specs/header-widget/spec.md */
		overlayColor() {
			const value = this.content && this.content.overlayColor
			if (typeof value === 'string' && value !== '') {
				return value
			}
			// Falls through to backgroundColor for tint, or black for
			// gradient — keeps a sensible default when no explicit overlay
			// color is set.
			const bg = this.content && this.content.backgroundColor
			return (typeof bg === 'string' && bg !== '') ? bg : '#000000'
		},

		/** @spec openspec/specs/header-widget/spec.md */
		overlayOpacity() {
			const raw = this.content && this.content.overlayOpacity
			const value = typeof raw === 'number' ? raw : Number.parseFloat(raw)
			if (Number.isFinite(value) === false) {
				return 0.4
			}
			if (value < 0) {
				return 0
			}
			if (value > 1) {
				return 1
			}
			return value
		},

		/** @spec openspec/specs/header-widget/spec.md */
		height() {
			const declared = this.content && this.content.height
			if (typeof declared === 'string' && ALLOWED_HEIGHTS.includes(declared)) {
				return declared
			}
			return 'medium'
		},

		/** @spec openspec/specs/header-widget/spec.md */
		heightPixels() {
			return HEIGHT_PIXELS[this.height]
		},

		/** @spec openspec/specs/header-widget/spec.md */
		textAlign() {
			const declared = this.content && this.content.textAlign
			if (typeof declared === 'string' && ALLOWED_TEXT_ALIGN.includes(declared)) {
				return declared
			}
			return 'center'
		},

		/** @spec openspec/specs/header-widget/spec.md */
		verticalAlign() {
			const declared = this.content && this.content.verticalAlign
			if (typeof declared === 'string' && ALLOWED_VERTICAL_ALIGN.includes(declared)) {
				return declared
			}
			return 'middle'
		},

		/** @spec openspec/specs/header-widget/spec.md */
		textColor() {
			const value = this.content && this.content.textColor
			if (typeof value === 'string' && value !== '') {
				return value
			}
			// Auto-contrast fallback: dark text on light bg, light text on
			// dark bg or when an image+overlay is present (REQ-HDR-008).
			if (this.hasBackgroundImage) {
				return '#ffffff'
			}
			return this.isLightColor(this.backgroundColor) ? '#000000' : '#ffffff'
		},

		/** @spec openspec/specs/header-widget/spec.md */
		wrapperStyle() {
			const style = {
				position: 'relative',
				width: '100%',
				height: `${this.heightPixels}px`,
				overflow: 'hidden',
				'background-color': this.backgroundColor,
			}
			if (this.hasBackgroundImage) {
				style['background-image'] = `url("${this.backgroundImageUrl}")`
				style['background-size'] = 'cover'
				style['background-position'] = 'center center'
				style['background-repeat'] = 'no-repeat'
			}
			return style
		},

		/** @spec openspec/specs/header-widget/spec.md */
		overlayStyle() {
			if (this.overlayMode === 'gradient-bottom') {
				return {
					position: 'absolute',
					inset: 0,
					'background-image': `linear-gradient(to bottom, transparent 50%, ${this.overlayColor} 100%)`,
					'pointer-events': 'none',
				}
			}
			// tint
			return {
				position: 'absolute',
				inset: 0,
				'background-color': this.overlayColor,
				opacity: String(this.overlayOpacity),
				'pointer-events': 'none',
			}
		},

		/** @spec openspec/specs/header-widget/spec.md */
		contentStyle() {
			return {
				position: 'relative',
				'z-index': 1,
				width: '100%',
				height: '100%',
				display: 'flex',
				'flex-direction': 'column',
				'align-items': this.flexAlignFromTextAlign,
				'justify-content': VERTICAL_ALIGN_FLEX[this.verticalAlign],
				padding: '16px 24px',
				'box-sizing': 'border-box',
				gap: '8px',
				'text-align': this.textAlign,
			}
		},

		/** @spec openspec/specs/header-widget/spec.md */
		flexAlignFromTextAlign() {
			if (this.textAlign === 'left') {
				return 'flex-start'
			}
			if (this.textAlign === 'right') {
				return 'flex-end'
			}
			return 'center'
		},

		/** @spec openspec/specs/header-widget/spec.md */
		textStyle() {
			return {
				color: this.textColor,
				margin: 0,
			}
		},

		/** @spec openspec/specs/header-widget/spec.md */
		cta() {
			const raw = this.content && this.content.cta
			if (raw === null || typeof raw !== 'object') {
				return null
			}
			const label = typeof raw.label === 'string' ? raw.label.trim() : ''
			const url = typeof raw.url === 'string' ? raw.url.trim() : ''
			if (label === '' || url === '') {
				return null
			}
			let style = raw.style
			if (typeof style !== 'string' || ALLOWED_CTA_STYLES.includes(style) === false) {
				style = 'primary'
			}
			return { label, url, style }
		},

		hasCta() {
			return this.cta !== null
		},

		/** @spec openspec/specs/header-widget/spec.md */
		ctaLabel() {
			return this.hasCta ? this.cta.label : ''
		},

		/** @spec openspec/specs/header-widget/spec.md */
		ctaUrl() {
			return this.hasCta ? this.cta.url : ''
		},

		/** @spec openspec/specs/header-widget/spec.md */
		ctaIsExternal() {
			return this.hasCta && /^https?:\/\//i.test(this.ctaUrl)
		},

		/** @spec openspec/specs/header-widget/spec.md */
		ctaTarget() {
			return this.ctaIsExternal ? '_blank' : null
		},

		/** @spec openspec/specs/header-widget/spec.md */
		ctaRel() {
			return this.ctaIsExternal ? 'noopener noreferrer' : null
		},

		/** @spec openspec/specs/header-widget/spec.md */
		ctaAriaLabel() {
			if (this.hasCta === false) {
				return null
			}
			if (this.ctaIsExternal) {
				return `${this.ctaLabel} (${t('mydash', 'opens in new tab')})`
			}
			return this.ctaLabel
		},

		/** @spec openspec/specs/header-widget/spec.md */
		ctaClasses() {
			if (this.hasCta === false) {
				return []
			}
			return [
				'header-widget__cta',
				`header-widget__cta--${this.cta.style}`,
			]
		},
	},

	watch: {
		/** @spec openspec/specs/header-widget/spec.md */
		backgroundImageUrl() {
			// Re-arm the probe so a previously failing URL can recover
			// when the user edits the placement.
			this.imageFailed = false
		},
	},

	methods: {
		/** @spec openspec/specs/header-widget/spec.md */
		onImageError() {
			this.imageFailed = true
		},

		/**
		 * Quick perceived-luminance check on a CSS hex color. Returns true
		 * for "light" (white text would be illegible), false for "dark".
		 * Non-hex colors collapse to false (assume dark) which is the
		 * safer default for arbitrary CSS variables.
		 *
		 * @param {string} color CSS color value
		 * @return {boolean} true when the color is perceived as light
		 */
		/** @spec openspec/specs/header-widget/spec.md */
		isLightColor(color) {
			if (typeof color !== 'string') {
				return false
			}
			const match = color.trim().match(/^#([0-9a-f]{6})$/i)
			if (!match) {
				return false
			}
			const hex = match[1]
			const r = Number.parseInt(hex.slice(0, 2), 16)
			const g = Number.parseInt(hex.slice(2, 4), 16)
			const b = Number.parseInt(hex.slice(4, 6), 16)
			// ITU-R BT.601 luma — fast and good enough for a contrast hint.
			const luma = (0.299 * r + 0.587 * g + 0.114 * b) / 255
			return luma > 0.6
		},
	},
}
</script>

<style scoped>
.header-widget {
	width: 100%;
	height: 100%;
	min-height: 120px;
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
}

.header-widget__overlay {
	position: absolute;
	inset: 0;
}

.header-widget__content {
	position: relative;
	z-index: 1;
}

.header-widget__title {
	font-size: 28px;
	font-weight: 700;
	line-height: 1.2;
	margin: 0;
}

.header-widget__subtitle {
	font-size: 16px;
	line-height: 1.4;
	margin: 0;
	opacity: 0.95;
}

.header-widget__cta {
	display: inline-block;
	margin-top: 8px;
	padding: 10px 18px;
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
	font-weight: 600;
	text-decoration: none;
	cursor: pointer;
	transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.header-widget__cta:hover {
	transform: translateY(-1px);
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.header-widget__cta--primary {
	background-color: var(--color-primary, #0070c0);
	color: var(--color-primary-text, #ffffff);
	border: 1px solid var(--color-primary, #0070c0);
}

.header-widget__cta--secondary {
	background-color: var(--color-background-hover, rgba(255, 255, 255, 0.85));
	color: var(--color-main-text, #000000);
	border: 1px solid var(--color-border, #cccccc);
}

.header-widget__cta--ghost {
	background-color: transparent;
	color: inherit;
	border: 1px solid currentColor;
}

.header-widget__probe {
	position: absolute;
	width: 1px;
	height: 1px;
	opacity: 0;
	pointer-events: none;
	left: -9999px;
	top: -9999px;
}

@media print {
	.header-widget {
		print-color-adjust: exact;
		-webkit-print-color-adjust: exact;
	}
}
</style>
