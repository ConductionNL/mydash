<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="label-widget" :style="wrapperStyle">
		<span class="label-widget__text" :style="spanStyle">{{ displayText }}</span>
	</div>
</template>

<script>
/**
 * LabelWidget
 *
 * Renders a short, single-line, plain-text heading inside a dashboard cell.
 * Unlike TextDisplayWidget, this renderer NEVER uses `v-html` — `text` is
 * always rendered as a literal string via Vue interpolation, eliminating the
 * XSS surface entirely (REQ-LBL-001).
 *
 * Defaults per REQ-LBL-002: 16px / bold / centred / theme-aware text colour /
 * transparent background. Long single words wrap via `overflow-wrap`
 * (REQ-LBL-003). Empty text shows the localised `Label` placeholder so the
 * widget remains visible during editing (REQ-LBL-004).
 */
export default {
	name: 'LabelWidget',

	props: {
		/**
		 * Persisted widget content. Shape:
		 *   { text, fontSize, color, backgroundColor, fontWeight, textAlign }
		 * Any field may be missing or empty — the renderer falls back to
		 * theme-aware defaults per REQ-LBL-002.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		text() {
			return typeof this.content?.text === 'string' ? this.content.text : ''
		},

		hasText() {
			return this.text.trim() !== ''
		},

		displayText() {
			if (this.hasText) {
				return this.text
			}
			// Localised fallback (REQ-LBL-004). `t` is provided as a Nextcloud
			// global at runtime; tests stub it.
			if (typeof t === 'function') {
				return t('mydash', 'Label')
			}
			return 'Label'
		},

		fontSize() {
			const value = this.content?.fontSize
			return value && String(value).trim() !== '' ? value : '16px'
		},

		color() {
			const value = this.content?.color
			return value && String(value).trim() !== '' ? value : 'var(--color-main-text)'
		},

		backgroundColor() {
			const value = this.content?.backgroundColor
			return value && String(value).trim() !== '' ? value : 'transparent'
		},

		fontWeight() {
			const value = this.content?.fontWeight
			const allowed = ['normal', 'bold', '600', '700', '800']
			return allowed.includes(String(value)) ? String(value) : 'bold'
		},

		textAlign() {
			const value = this.content?.textAlign
			const allowed = ['left', 'center', 'right']
			return allowed.includes(value) ? value : 'center'
		},

		wrapperStyle() {
			return {
				width: '100%',
				height: '100%',
				padding: '12px',
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'center',
				backgroundColor: this.backgroundColor,
				boxSizing: 'border-box',
			}
		},

		spanStyle() {
			return {
				fontSize: this.fontSize,
				fontWeight: this.fontWeight,
				textAlign: this.textAlign,
				color: this.color,
				overflowWrap: 'break-word',
			}
		},
	},
}
</script>

<style scoped>
.label-widget {
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	padding: 12px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.label-widget__text {
	display: inline-block;
	max-width: 100%;
	/* Safety net: keep long single words wrapping inside the cell even if the
	   inline style above gets overridden (REQ-LBL-003). */
	overflow-wrap: break-word;
	word-break: break-word;
}
</style>
