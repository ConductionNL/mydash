<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="divider-widget" :style="wrapperStyle">
		<!-- REQ-DIV-003: line style — full-width horizontal rule with
		     configurable color, thickness, and border-style. -->
		<div
			v-if="style === 'line'"
			class="divider-widget__line"
			role="separator"
			aria-orientation="horizontal"
			:style="lineStyleObject" />

		<!-- REQ-DIV-004: whitespace style — transparent vertical spacer.
		     The role="separator" keeps it discoverable to AT as a
		     decorative divider rather than empty content. -->
		<div
			v-else-if="style === 'whitespace'"
			class="divider-widget__whitespace"
			role="separator"
			aria-orientation="horizontal"
			:style="whitespaceStyleObject" />

		<!-- REQ-DIV-005: heading-break — semantic h3 flanked by two lines.
		     The wrapper carries an aria-label so screen readers announce
		     "<text> divider" once instead of three separate elements. -->
		<div
			v-else-if="style === 'heading-break'"
			class="divider-widget__heading-break"
			role="separator"
			aria-orientation="horizontal"
			:aria-label="headingAriaLabel">
			<hr class="divider-widget__heading-line" :style="headingLineStyleObject" aria-hidden="true">
			<h3 class="divider-widget__heading-text">
				{{ headingText }}
			</h3>
			<hr class="divider-widget__heading-line" :style="headingLineStyleObject" aria-hidden="true">
		</div>
	</div>
</template>

<script>
/**
 * DividerWidget renders a lightweight visual separator inside a dashboard
 * cell — a horizontal line, a whitespace spacer, or a centered heading
 * flanked by two lines. All rendering is fully client-side and the
 * widget makes zero API calls (REQ-DIV-009).
 *
 * Persisted shape: `{type: 'divider', content: {style, lineColor,
 * lineThickness, lineStyle, whitespaceSize, headingText}}`.
 *
 * Defaults (REQ-DIV-002): style='line', lineColor=null
 * (resolves to var(--color-border) via REQ-DIV-007), lineThickness=1,
 * lineStyle='solid', whitespaceSize='medium', headingText=''.
 *
 * Accessibility (REQ-DIV-003/004/005): line + whitespace get
 * role="separator" with no aria-label (decorative); heading-break wraps
 * its semantic h3 in a separator with an aria-label of
 * "<headingText> divider" for screen-reader clarity.
 *
 * Print (REQ-DIV-008): the scoped CSS deliberately contains NO
 * `display: none` rules under `@media print`, guaranteeing dividers
 * remain visible on printed dashboards.
 */

const WHITESPACE_SIZES = Object.freeze({
	small: '16px',
	medium: '32px',
	large: '64px',
	xlarge: '128px',
})

export default {
	name: 'DividerWidget',

	props: {
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		style() {
			const value = this.content?.style
			if (value === 'whitespace' || value === 'heading-break' || value === 'line') {
				return value
			}
			return 'line'
		},

		lineColor() {
			// REQ-DIV-007: explicit color overrides the theme variable.
			const color = this.content?.lineColor
			if (typeof color === 'string' && color.trim() !== '') {
				return color
			}
			return 'var(--color-border)'
		},

		lineThickness() {
			const raw = Number(this.content?.lineThickness)
			if (!Number.isFinite(raw) || raw < 1) {
				return 1
			}
			// REQ-DIV-002 line config bounds 1..8.
			return Math.min(8, Math.max(1, Math.floor(raw)))
		},

		lineStyle() {
			const value = this.content?.lineStyle
			if (value === 'dashed' || value === 'dotted' || value === 'solid') {
				return value
			}
			return 'solid'
		},

		whitespaceSize() {
			const value = this.content?.whitespaceSize
			if (Object.prototype.hasOwnProperty.call(WHITESPACE_SIZES, value)) {
				return value
			}
			return 'medium'
		},

		whitespaceHeight() {
			return WHITESPACE_SIZES[this.whitespaceSize]
		},

		headingText() {
			const value = this.content?.headingText
			return typeof value === 'string' && value.trim() !== ''
				? value
				: t('mydash', 'Section')
		},

		headingAriaLabel() {
			return t('mydash', '{heading} divider', { heading: this.headingText })
		},

		wrapperStyle() {
			return {
				width: '100%',
				height: '100%',
				display: 'flex',
				'align-items': 'center',
				'justify-content': 'center',
			}
		},

		lineStyleObject() {
			// border-bottom keeps the element height equal to thickness so
			// the surrounding flex layout centres the divider exactly.
			return {
				width: '100%',
				'border-bottom-width': `${this.lineThickness}px`,
				'border-bottom-style': this.lineStyle,
				'border-bottom-color': this.lineColor,
			}
		},

		whitespaceStyleObject() {
			return {
				width: '100%',
				height: this.whitespaceHeight,
				'background-color': 'transparent',
			}
		},

		headingLineStyleObject() {
			return {
				flex: '1 1 auto',
				'border-top-width': `${this.lineThickness}px`,
				'border-top-style': this.lineStyle,
				'border-top-color': this.lineColor,
				margin: '0',
			}
		},
	},
}
</script>

<style scoped>
.divider-widget {
	width: 100%;
	height: 100%;
}

.divider-widget__line {
	border-bottom: 1px solid var(--color-border);
}

.divider-widget__heading-break {
	display: flex;
	align-items: center;
	gap: 1rem;
	width: 100%;
}

.divider-widget__heading-line {
	border: 0;
	border-top: 1px solid var(--color-border);
}

.divider-widget__heading-text {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
	white-space: nowrap;
}

/*
 * REQ-DIV-008: dividers MUST remain visible when printed. We deliberately
 * keep this @media print block free of any `display: none` rules; the
 * styles below merely tighten contrast for paper output. If future global
 * print stylesheets ever try to hide widget chrome, the explicit
 * `display: flex` here re-asserts visibility.
 */
@media print {
	.divider-widget,
	.divider-widget__line,
	.divider-widget__whitespace,
	.divider-widget__heading-break {
		display: flex;
	}

	.divider-widget__line,
	.divider-widget__heading-line {
		border-color: #000;
	}
}
</style>
