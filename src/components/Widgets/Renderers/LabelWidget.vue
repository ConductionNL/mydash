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
 * LabelWidget renders a short, single-line, plain-text heading inside a
 * dashboard cell. Content is rendered via Vue interpolation only — never via
 * v-html — so embedded HTML in `text` MUST appear as literal characters and
 * the XSS surface is eliminated entirely.
 *
 * Persisted shape: `{type: 'label', content: {text, fontSize, color,
 * backgroundColor, fontWeight, textAlign}}`.
 *
 * Defaults (REQ-LBL-002): fontSize='16px', color='var(--color-main-text)',
 * backgroundColor='transparent', fontWeight='bold', textAlign='center'.
 */
export default {
	name: 'LabelWidget',

	props: {
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/** @spec openspec/specs/label-widget/spec.md */
		text() {
			return typeof this.content?.text === 'string' ? this.content.text : ''
		},

		hasText() {
			return this.text.trim() !== ''
		},

		/** @spec openspec/specs/label-widget/spec.md */
		displayText() {
			return this.hasText ? this.text : t('mydash', 'Label')
		},

		/** @spec openspec/specs/label-widget/spec.md */
		fontSize() {
			return this.content?.fontSize || '16px'
		},

		/** @spec openspec/specs/label-widget/spec.md */
		color() {
			return this.content?.color || 'var(--color-main-text)'
		},

		/** @spec openspec/specs/label-widget/spec.md */
		backgroundColor() {
			return this.content?.backgroundColor || 'transparent'
		},

		/** @spec openspec/specs/label-widget/spec.md */
		fontWeight() {
			return this.content?.fontWeight || 'bold'
		},

		/** @spec openspec/specs/label-widget/spec.md */
		textAlign() {
			return this.content?.textAlign || 'center'
		},

		/** @spec openspec/specs/label-widget/spec.md */
		wrapperStyle() {
			return {
				width: '100%',
				height: '100%',
				padding: '12px',
				display: 'flex',
				'align-items': 'center',
				'justify-content': 'center',
				'background-color': this.backgroundColor,
			}
		},

		/** @spec openspec/specs/label-widget/spec.md */
		spanStyle() {
			return {
				'font-size': this.fontSize,
				'font-weight': this.fontWeight,
				'text-align': this.textAlign,
				color: this.color,
				'overflow-wrap': 'break-word',
			}
		},
	},
}
</script>

<style scoped>
.label-widget {
	width: 100%;
	height: 100%;
}

.label-widget__text {
	/* Safety net (REQ-LBL-003) — keeps long single words wrapping even if
	   the inline overflow-wrap style is overridden by host styles. */
	overflow-wrap: break-word;
	word-wrap: break-word;
	max-width: 100%;
}
</style>
