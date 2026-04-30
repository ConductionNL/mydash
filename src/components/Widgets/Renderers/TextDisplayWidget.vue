<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="text-display-widget" :style="wrapperStyle">
		<div
			v-if="hasContent"
			class="text-display-widget__content"
			v-html="sanitizedHtml" /><!-- eslint-disable-line vue/no-v-html — sanitised via DOMPurify per REQ-TXT-001 -->
		<span
			v-else
			class="text-display-widget__placeholder">
			{{ placeholderText }}
		</span>
	</div>
</template>

<script>
import DOMPurify from 'dompurify'

/**
 * TextDisplayWidget
 *
 * Renders user-authored text inside a dashboard cell. Supports inline HTML
 * (sanitised via DOMPurify) so authors can use <b>, <i>, <a>, <br>, <p>, <ul>,
 * <li> for light formatting. Inline style controls (font size, colour,
 * background, alignment) are applied to the wrapper element with theme-aware
 * fallbacks (REQ-TXT-001..005).
 */
export default {
	name: 'TextDisplayWidget',

	props: {
		/**
		 * Persisted widget content. Shape:
		 *   { text, fontSize, color, backgroundColor, textAlign }
		 * Any field may be missing or empty — the renderer falls back to
		 * theme-aware defaults per REQ-TXT-002.
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

		hasContent() {
			return this.text.trim() !== ''
		},

		sanitizedHtml() {
			// Deliberate use of v-html — sanitised via DOMPurify (see REQ-TXT-001).
			// DOMPurify default config strips <script>, on* attributes, and
			// javascript: URLs while preserving safe formatting tags.
			return DOMPurify.sanitize(this.text)
		},

		fontSize() {
			const value = this.content?.fontSize
			return value && String(value).trim() !== '' ? value : '14px'
		},

		color() {
			const value = this.content?.color
			return value && String(value).trim() !== '' ? value : 'var(--color-main-text)'
		},

		backgroundColor() {
			const value = this.content?.backgroundColor
			return value && String(value).trim() !== '' ? value : 'transparent'
		},

		textAlign() {
			const value = this.content?.textAlign
			const allowed = ['left', 'center', 'right', 'justify']
			return allowed.includes(value) ? value : 'left'
		},

		wrapperStyle() {
			return {
				fontSize: this.fontSize,
				color: this.color,
				backgroundColor: this.backgroundColor,
				textAlign: this.textAlign,
			}
		},

		placeholderText() {
			// `t` is provided as a Nextcloud global at runtime. Tests stub it.
			if (typeof t === 'function') {
				return t('mydash', 'No text content')
			}
			return 'No text content'
		},
	},
}
</script>

<style scoped>
.text-display-widget {
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	justify-content: center;
	width: 100%;
	height: 100%;
	padding: 16px;
	overflow: auto;
}

.text-display-widget__content {
	width: 100%;
	white-space: pre-wrap;
	word-break: break-word;
}

.text-display-widget__placeholder {
	display: block;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}
</style>
