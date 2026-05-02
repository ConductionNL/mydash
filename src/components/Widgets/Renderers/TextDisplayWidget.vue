<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="text-display-widget" :style="wrapperStyle">
		<div
			v-if="hasText"
			class="text-display-widget__content"
			:style="contentStyle"
			v-html="sanitizedHtml" /><!-- eslint-disable-line vue/no-v-html -->
		<span
			v-else
			class="text-display-widget__placeholder"
			:style="contentStyle">
			{{ placeholderText }}
		</span>
	</div>
</template>

<script>
import DOMPurify from 'dompurify'

/**
 * TextDisplayWidget renders user-authored multi-line text inside a dashboard
 * cell. Content is passed through DOMPurify before injection via `v-html` so
 * common formatting tags (`<b>`, `<i>`, `<a>`, `<br>`, `<p>`, `<ul>`, `<li>`)
 * survive while XSS vectors (`<script>`, `on*` attributes, `javascript:`
 * URLs) are stripped.
 *
 * Persisted shape (REQ-TXT-001..005): `{type: 'text', content: {text,
 * fontSize, color, backgroundColor, textAlign}}`. Defaults: `fontSize='14px'`,
 * `color='var(--color-main-text)'`, `backgroundColor='transparent'`,
 * `textAlign='left'`. Empty/whitespace `text` shows a localised italic
 * placeholder so the cell stays a visible drop target.
 */
export default {
	name: 'TextDisplayWidget',

	props: {
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

		sanitizedHtml() {
			// DOMPurify default config strips <script>, <style>, <link>,
			// on* event attributes and javascript: URLs. We keep the
			// default config explicitly — tighter overrides would block
			// the `<a href>` and `<b>`/`<i>` formatting authors expect.
			return DOMPurify.sanitize(this.text)
		},

		placeholderText() {
			return t('mydash', 'No text content')
		},

		fontSize() {
			return this.content?.fontSize || '14px'
		},

		color() {
			return this.content?.color || 'var(--color-main-text)'
		},

		backgroundColor() {
			return this.content?.backgroundColor || 'transparent'
		},

		textAlign() {
			return this.content?.textAlign || 'left'
		},

		wrapperStyle() {
			return {
				width: '100%',
				height: '100%',
				padding: '16px',
				display: 'flex',
				'align-items': 'center',
				'justify-content': 'center',
				overflow: 'auto',
				'background-color': this.backgroundColor,
			}
		},

		contentStyle() {
			const base = {
				'font-size': this.fontSize,
				'text-align': this.textAlign,
				color: this.color,
				width: '100%',
				'overflow-wrap': 'break-word',
			}
			if (!this.hasText) {
				base['font-style'] = 'italic'
				base.color = 'var(--color-text-maxcontrast)'
			}
			return base
		},
	},
}
</script>

<style scoped>
.text-display-widget {
	width: 100%;
	height: 100%;
}

.text-display-widget__content,
.text-display-widget__placeholder {
	/* Safety net (REQ-TXT-005) — ensures long URLs / words inside the
	   sanitised HTML wrap rather than overflowing horizontally. */
	overflow-wrap: break-word;
	word-wrap: break-word;
	max-width: 100%;
}
</style>
