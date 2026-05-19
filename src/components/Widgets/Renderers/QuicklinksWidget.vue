<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="quicklinks-widget"
		:class="rootClasses"
		:style="rootStyle">
		<div v-if="isEmpty" class="quicklinks-widget__empty" @click="onEmptyClick">
			<button
				type="button"
				class="quicklinks-widget__empty-button"
				:aria-label="t('mydash', 'Open Quicklinks settings')"
				@click.stop="onEmptyClick">
				<IconRenderer name="Cog" :size="32" />
			</button>
			<p class="quicklinks-widget__empty-text">
				{{ t('mydash', 'No quicklinks yet — click the gear icon to add some.') }}
			</p>
		</div>
		<ul v-else class="quicklinks-widget__list" :style="listStyle">
			<li
				v-for="(link, index) in renderedLinks"
				:key="`${link.url}-${index}`"
				class="quicklinks-widget__item">
				<a
					class="quicklinks-widget__link"
					:href="link.href"
					:target="link.target"
					:rel="link.rel"
					:aria-label="link.ariaLabel"
					:style="link.tileStyle"
					@click="onLinkClick($event, link)">
					<span
						class="quicklinks-widget__icon"
						:style="link.iconWrapperStyle">
						<img
							v-if="link.iconKind === 'image'"
							:src="link.icon"
							:alt="link.label || ''"
							:width="iconPx"
							:height="iconPx">
						<IconRenderer
							v-else
							:name="link.iconName"
							:size="iconPx" />
					</span>
					<span
						v-if="showLabels"
						class="quicklinks-widget__label"
						:class="labelClasses">
						{{ link.label }}
					</span>
				</a>
			</li>
		</ul>
	</div>
</template>

<script>
import IconRenderer from '../../Dashboard/IconRenderer.vue'
import { isCustomIconUrl } from '../../../constants/dashboardIcons.js'
import { isExternalUrl, validateUrl } from '../../../composables/useUrlSanitiser.js'

const SIZE_PX = Object.freeze({
	small: 32,
	medium: 48,
	large: 64,
	xlarge: 96,
})

const SHAPE_RADIUS = Object.freeze({
	square: '0',
	rounded: '8px',
	circle: '50%',
})

const ALLOWED_HOVER = Object.freeze(['lift', 'fade', 'border', 'none'])
const ALLOWED_TILE_BG = Object.freeze(['transparent', 'solid', 'gradient'])
const ALLOWED_LABEL_POSITIONS = Object.freeze(['below', 'overlay'])

/**
 * QuicklinksWidget — renders a flat icon-and-label grid for the
 * `quicklinks` widget type (REQ-QLNK-001 .. REQ-QLNK-011).
 *
 * The widget reads the persisted `content` blob and produces a list of
 * `<a>` elements arranged either as a flex-wrap row (`columns: 'auto'`)
 * or as a fixed CSS grid (`columns: 1..12`). Per-link click behaviour
 * matches link-button-widget: external URLs open in a new tab via
 * `window.open(..., '_blank', 'noopener,noreferrer')`; relative
 * Nextcloud URLs navigate in the same tab; the user can override the
 * decision per-link via the `openInNewTab` field. Click is fully
 * suppressed in admin/edit mode (`isAdmin === true && canEdit === true`).
 *
 * Icon resolution mirrors REQ-LBN-002: a custom URL renders as `<img>`,
 * a bare MDI name renders via `IconRenderer`, an empty value falls back
 * to the `Link` icon. Sizes (32/48/64/96 px) and shapes
 * (`0`/`8px`/`50%` radius) are widget-level settings applied uniformly.
 */
export default {
	name: 'QuicklinksWidget',

	components: { IconRenderer },

	props: {
		/**
		 * Persisted widget content. Shape: see REQ-QLNK-002.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Whether the current user is an admin. Combined with `canEdit`
		 * to suppress click handlers in edit mode (REQ-QLNK-009).
		 */
		isAdmin: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether the surrounding dashboard shell is in edit mode.
		 */
		canEdit: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['edit'],

	computed: {
		links() {
			const raw = this.content?.links
			if (!Array.isArray(raw)) {
				return []
			}
			return raw.filter((link) => link && typeof link === 'object')
		},

		isEmpty() {
			return this.links.length === 0
		},

		iconSize() {
			const declared = this.content?.iconSize
			return Object.prototype.hasOwnProperty.call(SIZE_PX, declared) ? declared : 'medium'
		},

		iconPx() {
			return SIZE_PX[this.iconSize]
		},

		iconShape() {
			const declared = this.content?.iconShape
			return Object.prototype.hasOwnProperty.call(SHAPE_RADIUS, declared) ? declared : 'rounded'
		},

		iconRadius() {
			return SHAPE_RADIUS[this.iconShape]
		},

		showLabels() {
			return this.content?.showLabels !== false
		},

		labelPosition() {
			const declared = this.content?.labelPosition
			return ALLOWED_LABEL_POSITIONS.includes(declared) ? declared : 'below'
		},

		columns() {
			const declared = this.content?.columns
			if (declared === 'auto' || declared === undefined || declared === null) {
				return 'auto'
			}
			const n = Number(declared)
			if (!Number.isInteger(n) || n < 1 || n > 12) {
				return 'auto'
			}
			return n
		},

		tileBackgroundStyle() {
			const declared = this.content?.tileBackgroundStyle
			return ALLOWED_TILE_BG.includes(declared) ? declared : 'transparent'
		},

		hoverEffect() {
			const declared = this.content?.hoverEffect
			return ALLOWED_HOVER.includes(declared) ? declared : 'lift'
		},

		isInEditMode() {
			return this.isAdmin === true && this.canEdit === true
		},

		labelClasses() {
			return [
				`quicklinks-widget__label--${this.labelPosition}`,
			]
		},

		rootClasses() {
			return [
				`quicklinks-widget--hover-${this.hoverEffect}`,
				`quicklinks-widget--bg-${this.tileBackgroundStyle}`,
				`quicklinks-widget--shape-${this.iconShape}`,
				{ 'quicklinks-widget--edit-mode': this.isInEditMode },
			]
		},

		rootStyle() {
			return {
				'--mydash-quicklinks-icon-size': `${this.iconPx}px`,
				'--mydash-quicklinks-icon-radius': this.iconRadius,
			}
		},

		listStyle() {
			if (this.columns === 'auto') {
				return {
					display: 'flex',
					'flex-wrap': 'wrap',
				}
			}
			return {
				display: 'grid',
				'grid-template-columns': `repeat(${this.columns}, 1fr)`,
			}
		},

		renderedLinks() {
			return this.links.map((link) => this.decorateLink(link))
		},
	},

	methods: {
		decorateLink(link) {
			const url = typeof link.url === 'string' ? link.url : ''
			const label = typeof link.label === 'string' ? link.label : ''
			const icon = typeof link.icon === 'string' ? link.icon : ''
			const color = typeof link.color === 'string' ? link.color : ''
			const isExternal = isExternalUrl(url)
			const opensNewTab = typeof link.openInNewTab === 'boolean'
				? link.openInNewTab
				: isExternal

			let iconKind = 'mdi'
			let iconName = icon
			if (icon === '') {
				iconName = 'Link'
			} else if (isCustomIconUrl(icon)) {
				iconKind = 'image'
			}

			const showSolid = this.tileBackgroundStyle === 'solid'
			const tileColor = showSolid && color !== '' ? color : ''

			const ariaLabel = this.computeAriaLabel(url, label)

			return {
				url,
				label,
				icon,
				iconKind,
				iconName,
				color,
				href: url || '#',
				target: opensNewTab ? '_blank' : '_self',
				rel: opensNewTab ? 'noopener noreferrer' : null,
				ariaLabel,
				opensNewTab,
				isExternal,
				iconWrapperStyle: this.computeIconWrapperStyle(tileColor),
				tileStyle: {},
			}
		},

		computeIconWrapperStyle(tileColor) {
			const style = {
				width: `${this.iconPx}px`,
				height: `${this.iconPx}px`,
				'border-radius': this.iconRadius,
			}
			if (this.tileBackgroundStyle === 'solid') {
				style['background-color'] = tileColor !== '' ? tileColor : 'var(--color-background-hover)'
			} else if (this.tileBackgroundStyle === 'gradient') {
				style['background-image'] = 'linear-gradient(135deg, var(--color-primary-light), var(--color-background-hover))'
			}
			return style
		},

		computeAriaLabel(url, label) {
			if (this.showLabels && this.labelPosition === 'below' && label !== '') {
				return label
			}
			const host = this.extractHostname(url)
			if (host !== '') {
				return `${t('mydash', 'Link to')} ${host}`
			}
			return label !== '' ? label : t('mydash', 'Link')
		},

		extractHostname(url) {
			if (typeof url !== 'string' || url === '') {
				return ''
			}
			try {
				if (url.startsWith('/')) {
					return ''
				}
				const parsed = new URL(url)
				return parsed.hostname
			} catch (_err) {
				return ''
			}
		},

		onLinkClick(event, link) {
			if (this.isInEditMode) {
				event.preventDefault()
				return
			}
			if (!validateUrl(link.url)) {
				event.preventDefault()
				return
			}
			// External URLs (or explicit openInNewTab) — let the browser
			// handle the `target=_blank` attribute. We additionally invoke
			// `window.open` so the test suite can assert on the call and
			// so the click reliably opens a new tab even when middleware
			// has stripped the attribute.
			if (link.opensNewTab) {
				event.preventDefault()
				window.open(link.url, '_blank', 'noopener,noreferrer')
			}
			// Relative Nextcloud URL — let the browser perform a same-tab
			// navigation by following the `<a>`'s href. No preventDefault.
		},

		onEmptyClick() {
			this.$emit('edit')
		},
	},
}
</script>

<style scoped>
.quicklinks-widget {
	width: 100%;
	height: 100%;
	padding: 8px;
	box-sizing: border-box;
	overflow: auto;
}

.quicklinks-widget__list {
	gap: 12px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.quicklinks-widget__item {
	list-style: none;
}

.quicklinks-widget__link {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: flex-start;
	gap: 4px;
	padding: 6px;
	color: var(--color-main-text);
	text-decoration: none;
	border-radius: var(--border-radius, 4px);
	transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.15s ease,
		outline 0.1s ease;
	position: relative;
	cursor: pointer;
}

.quicklinks-widget__link:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.quicklinks-widget__icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
}

.quicklinks-widget__icon img {
	max-width: 100%;
	max-height: 100%;
	display: block;
}

.quicklinks-widget__label {
	font-size: 12px;
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--color-main-text);
}

.quicklinks-widget__label--below {
	display: block;
	text-align: center;
}

.quicklinks-widget__label--overlay {
	display: none;
	position: absolute;
	bottom: 0;
	left: 0;
	right: 0;
	padding: 2px 4px;
	background-color: rgba(0, 0, 0, 0.6);
	color: #fff;
	text-align: center;
	border-radius: 0 0 var(--border-radius, 4px) var(--border-radius, 4px);
}

.quicklinks-widget__link:hover .quicklinks-widget__label--overlay,
.quicklinks-widget__link:focus .quicklinks-widget__label--overlay {
	display: block;
}

/* Hover effects */
.quicklinks-widget--hover-lift .quicklinks-widget__link:hover {
	transform: translateY(-3px);
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.quicklinks-widget--hover-fade:hover .quicklinks-widget__link {
	opacity: 0.6;
}

.quicklinks-widget--hover-fade .quicklinks-widget__link:hover {
	opacity: 1;
}

.quicklinks-widget--hover-border .quicklinks-widget__link:hover {
	outline: 2px solid var(--color-primary);
	outline-offset: 1px;
}

.quicklinks-widget--hover-none .quicklinks-widget__link:hover {
	cursor: pointer;
}

.quicklinks-widget--edit-mode .quicklinks-widget__link {
	cursor: not-allowed;
}

.quicklinks-widget__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 12px;
	box-sizing: border-box;
}

.quicklinks-widget__empty-button {
	background: transparent;
	border: none;
	cursor: pointer;
	color: inherit;
	padding: 4px;
	border-radius: var(--border-radius, 4px);
}

.quicklinks-widget__empty-button:hover {
	background: var(--color-background-hover);
}

.quicklinks-widget__empty-text {
	margin: 0;
	font-size: 13px;
	text-align: center;
	max-width: 24em;
}
</style>
