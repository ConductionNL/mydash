<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="links-widget" :class="layoutClass" :style="rootStyle">
		<div v-if="!hasVisibleSections" class="links-widget__empty">
			{{ emptyStateText }}
		</div>
		<template v-else>
			<section
				v-for="(section, sIdx) in visibleSections"
				:key="sIdx"
				class="links-widget__section">
				<h3
					v-if="showSectionTitles && section.title"
					class="links-widget__section-title">
					{{ section.title }}
				</h3>
				<div class="links-widget__items">
					<a
						v-for="(link, lIdx) in section.links"
						:key="lIdx"
						class="links-widget__link"
						:class="`links-widget__link--${linkLayout}`"
						:href="safeHref(link.url)"
						:target="openInNewTab ? '_blank' : '_self'"
						:rel="relAttr(link.url)"
						:title="linkLayout === 'icon-only' ? link.label : null"
						@click="onLinkClick($event, link.url)">
						<span
							v-if="linkLayout !== 'inline-no-icon' || resolveIconType(link.icon) !== null"
							class="links-widget__icon-wrap"
							:style="iconWrapStyle">
							<img
								v-if="resolveIconType(link.icon) === 'img'"
								:src="link.icon"
								:width="iconPx"
								:height="iconPx"
								alt="">
							<IconRenderer
								v-else
								:name="link.icon || ''"
								:size="iconPx" />
						</span>
						<span v-if="linkLayout !== 'icon-only'" class="links-widget__label">
							{{ link.label }}
						</span>
						<span
							v-if="linkLayout === 'card' && showLinkDescriptions && link.description"
							class="links-widget__description">
							{{ link.description }}
						</span>
					</a>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import IconRenderer from '../../Dashboard/IconRenderer.vue'
import { isCustomIconUrl } from '../../../constants/dashboardIcons.js'

const VALID_LAYOUTS = Object.freeze(['card', 'inline', 'icon-only'])
const VALID_SIZES = Object.freeze(['small', 'medium', 'large'])
const ICON_PX = Object.freeze({ small: 24, medium: 40, large: 64 })

/**
 * LinksWidget — multi-column grid of curated link cards organised into named
 * sections (capability `links-widget`).
 *
 * Renders three layout modes (REQ-LNKS-005):
 *   - `card` → icon + label + optional description
 *   - `inline` → flat row of icon + label (description hidden)
 *   - `icon-only` → grid of icons with hover tooltip
 *
 * REQ-LNKS-003: empty sections are filtered at render time but retained in
 * config so the user can refill them later.
 *
 * REQ-LNKS-004: icon resolution precedence — empty/unknown → generic icon,
 * URL-shaped → `<img>`, otherwise registry name → `IconRenderer`.
 *
 * REQ-LNKS-006: CSS Grid with configurable column count (1–6, default 3).
 *
 * REQ-LNKS-009: empty state when no sections have any links.
 *
 * REQ-LNKS-010: external URLs use `rel="noopener noreferrer"`; internal
 * (relative) URLs preserve `window.opener`. Edit-mode click is suppressed
 * so configuring the widget cannot accidentally navigate.
 */
export default {
	name: 'LinksWidget',

	components: {
		IconRenderer,
	},

	props: {
		/**
		 * Persisted widget content. Shape:
		 * `{sections, columns, linkLayout, iconSize, openInNewTab,
		 * showSectionTitles, showLinkDescriptions}`.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Whether the current user is an admin. Combined with `canEdit` to
		 * suppress click handlers in dashboard edit mode (REQ-LNKS-010).
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

	computed: {
		sections() {
			const raw = this.content?.sections
			return Array.isArray(raw) ? raw : []
		},

		columns() {
			const raw = Number(this.content?.columns)
			if (!Number.isFinite(raw)) {
				return 3
			}
			// REQ-LNKS-006: clamp to 1..6.
			return Math.max(1, Math.min(6, Math.round(raw)))
		},

		linkLayout() {
			const declared = this.content?.linkLayout
			return VALID_LAYOUTS.includes(declared) ? declared : 'card'
		},

		layoutClass() {
			return `links-widget--${this.linkLayout}`
		},

		iconSize() {
			const declared = this.content?.iconSize
			return VALID_SIZES.includes(declared) ? declared : 'medium'
		},

		iconPx() {
			return ICON_PX[this.iconSize]
		},

		openInNewTab() {
			const value = this.content?.openInNewTab
			return value === undefined ? true : Boolean(value)
		},

		showSectionTitles() {
			const value = this.content?.showSectionTitles
			return value === undefined ? true : Boolean(value)
		},

		showLinkDescriptions() {
			const value = this.content?.showLinkDescriptions
			return value === undefined ? true : Boolean(value)
		},

		visibleSections() {
			// REQ-LNKS-003: hide sections with zero links at render time;
			// retain them in config (handled by the form, not here).
			return this.sections
				.filter((section) => Array.isArray(section?.links) && section.links.length > 0)
				.map((section) => ({
					title: typeof section.title === 'string' ? section.title : '',
					links: section.links.filter((l) => l && typeof l.url === 'string' && l.url !== ''),
				}))
				.filter((section) => section.links.length > 0)
		},

		hasVisibleSections() {
			return this.visibleSections.length > 0
		},

		rootStyle() {
			return {
				'--links-widget-cols': this.columns,
			}
		},

		iconWrapStyle() {
			return {
				width: `${this.iconPx}px`,
				height: `${this.iconPx}px`,
			}
		},

		emptyStateText() {
			return t('mydash', 'No links yet — click the gear icon to add some.')
		},

		isInEditMode() {
			return this.isAdmin === true && this.canEdit === true
		},
	},

	methods: {
		/**
		 * REQ-LNKS-004 — icon resolution discriminator. Returns `'img'` when
		 * the icon field is a URL (custom upload or remote logo) and `null`
		 * otherwise so the template falls through to `<IconRenderer>` which
		 * tolerates empty/unknown registry names.
		 *
		 * @param {string} icon raw icon field from link config
		 * @return {string|null} `'img'` or `null`
		 */
		resolveIconType(icon) {
			return isCustomIconUrl(icon) ? 'img' : null
		},

		/**
		 * REQ-LNKS-007 — only allow http(s) and root-relative URLs to reach
		 * the rendered `<a href>`. Empty/invalid inputs yield `'#'` so the
		 * link element stays inert rather than navigating somewhere unsafe.
		 *
		 * @param {string} url raw URL from link config
		 * @return {string} sanitised URL or `'#'`
		 */
		safeHref(url) {
			if (typeof url !== 'string' || url === '') {
				return '#'
			}
			if (url.startsWith('/') && !url.includes('..')) {
				return url
			}
			if (url.startsWith('http://') || url.startsWith('https://')) {
				return url
			}
			return '#'
		},

		/**
		 * REQ-LNKS-010 — external URLs get `rel="noopener noreferrer"`;
		 * internal (root-relative) URLs intentionally omit `rel` so the new
		 * tab can talk back via `window.opener` for cross-tab messaging.
		 *
		 * @param {string} url raw URL from link config
		 * @return {string|null} rel attribute value or `null` to omit
		 */
		relAttr(url) {
			if (!this.openInNewTab) {
				return null
			}
			if (typeof url === 'string' && (url.startsWith('http://') || url.startsWith('https://'))) {
				return 'noopener noreferrer'
			}
			return null
		},

		/**
		 * REQ-LNKS-010 — suppress navigation in edit mode and on inert
		 * (sanitised) links. Outside edit mode the browser's native `<a>`
		 * navigation handles the actual click.
		 *
		 * @param {MouseEvent} event the click event
		 * @param {string} url raw URL from link config
		 */
		onLinkClick(event, url) {
			if (this.isInEditMode) {
				event.preventDefault()
				return
			}
			if (this.safeHref(url) === '#') {
				event.preventDefault()
			}
		},
	},
}
</script>

<style scoped>
.links-widget {
	width: 100%;
	height: 100%;
	overflow: auto;
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.links-widget__empty {
	margin: auto;
	color: var(--color-text-maxcontrast);
	text-align: center;
	font-size: 14px;
}

.links-widget__section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.links-widget__section-title {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-light, var(--color-main-text));
}

.links-widget__items {
	display: grid;
	grid-template-columns: repeat(var(--links-widget-cols, 3), 1fr);
	gap: 8px;
}

.links-widget--icon-only .links-widget__items {
	grid-template-columns: repeat(var(--links-widget-cols, 3), minmax(0, 1fr));
	gap: 12px;
	justify-items: center;
}

.links-widget__link {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-main-text);
	text-decoration: none;
	border-radius: var(--border-radius, 4px);
	padding: 6px 8px;
	transition: background-color 0.12s ease;
}

.links-widget__link:hover,
.links-widget__link:focus-visible {
	background-color: var(--color-background-hover);
	text-decoration: none;
}

.links-widget__link--card {
	flex-direction: column;
	align-items: flex-start;
	padding: 12px;
	border: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}

.links-widget__link--icon-only {
	justify-content: center;
	padding: 8px;
}

.links-widget__icon-wrap {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.links-widget__label {
	font-size: 14px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 100%;
}

.links-widget__link--card .links-widget__label {
	white-space: normal;
}

.links-widget__description {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

@media (max-width: 600px) {
	.links-widget__items {
		grid-template-columns: 1fr;
	}
}
</style>
