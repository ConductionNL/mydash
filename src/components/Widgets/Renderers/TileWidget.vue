<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="tile-widget-renderer"
		:style="rootStyle">
		<a
			:href="resolvedHref"
			class="tile-widget-renderer__link"
			:target="resolvedTarget"
			:rel="resolvedRel"
			:tabindex="isInEditMode ? -1 : 0"
			:aria-disabled="isInEditMode ? 'true' : 'false'"
			:style="linkStyle"
			@click="onClick">
			<span class="tile-widget-renderer__icon" aria-hidden="true">
				<svg
					v-if="iconType === 'svg'"
					class="tile-widget-renderer__svg"
					:style="{ fill: textColor }"
					viewBox="0 0 24 24">
					<path :d="icon" />
				</svg>
				<span
					v-else-if="iconType === 'class'"
					:class="['icon', icon]"
					class="tile-widget-renderer__icon-class" />
				<img
					v-else-if="iconType === 'url'"
					:src="icon"
					alt=""
					class="tile-widget-renderer__icon-img">
				<span
					v-else-if="iconType === 'emoji'"
					class="tile-widget-renderer__emoji">
					{{ icon }}
				</span>
			</span>
			<span class="tile-widget-renderer__title" :style="{ color: textColor }">
				{{ title }}
			</span>
		</a>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

/**
 * TileWidget — registry-driven renderer for the unified `tile` widget type
 * (REQ-WDG-022 / REQ-TILE-PLACEMENT). Renders a clickable card with an
 * icon, title, and configurable colours that dispatches navigation based
 * on the `linkType` discriminator.
 *
 * Dual-shape compatibility (REQ-TILE-PLACEMENT): the renderer reads from
 * BOTH the new inline `placement.content.{title, icon, ...}` shape AND
 * the legacy flat `placement.tileTitle / tileIcon / ...` columns so that
 * dashboards holding tile placements created via the deprecated
 * `oc_mydash_tiles` flow continue to render without a migration step.
 *
 * Click is suppressed while the surrounding dashboard is in admin/edit
 * mode, mirroring the close-discipline rules applied across the other
 * registry-driven widgets (REQ-WDG-014 / REQ-LBN-001 scenario "Click in
 * edit mode is suppressed").
 */
export default {
	name: 'TileWidget',

	props: {
		/**
		 * Persisted widget content. New placements use the standard
		 * widget-content shape: `{title, icon, iconType,
		 * backgroundColor, textColor, linkType, linkValue}`.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * The full placement record. Used as the legacy fallback so the
		 * renderer can pull `placement.tileTitle / tileIcon / ...`
		 * fields from rows created via the deprecated reusable-tile
		 * flow when `content` is empty.
		 */
		placement: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Whether the current user is an admin. Combined with `canEdit`
		 * to suppress click handlers in edit mode.
		 */
		isAdmin: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether the surrounding dashboard shell is in edit mode.
		 * Suppresses click handlers when both this and `isAdmin` are
		 * true.
		 */
		canEdit: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		/**
		 * Merged data view: prefer the new `content.{...}` shape, fall
		 * back to the legacy flat `placement.tile*` columns. Returning a
		 * single normalised object keeps every other computed clean.
		 *
		 * @return {object} normalised tile data
		 */
		data() {
			const c = this.content || {}
			const p = this.placement || {}
			return {
				title: c.title ?? p.tileTitle ?? '',
				icon: c.icon ?? p.tileIcon ?? '',
				iconType: c.iconType ?? p.tileIconType ?? 'class',
				backgroundColor: c.backgroundColor ?? p.tileBackgroundColor ?? '#3b82f6',
				textColor: c.textColor ?? p.tileTextColor ?? '#ffffff',
				linkType: c.linkType ?? p.tileLinkType ?? 'app',
				linkValue: c.linkValue ?? p.tileLinkValue ?? '',
			}
		},

		/** @spec openspec/specs/tiles/spec.md */
		title() {
			return this.data.title
		},

		/** @spec openspec/specs/tiles/spec.md */
		icon() {
			return this.data.icon
		},

		/** @spec openspec/specs/tiles/spec.md */
		iconType() {
			return this.data.iconType
		},

		/** @spec openspec/specs/tiles/spec.md */
		backgroundColor() {
			return this.data.backgroundColor
		},

		/** @spec openspec/specs/tiles/spec.md */
		textColor() {
			return this.data.textColor
		},

		/** @spec openspec/specs/tiles/spec.md */
		linkType() {
			return this.data.linkType
		},

		/** @spec openspec/specs/tiles/spec.md */
		linkValue() {
			return this.data.linkValue
		},

		isInEditMode() {
			return this.isAdmin === true && this.canEdit === true
		},

		/**
		 * Resolved href for the anchor. App links resolve through
		 * `generateUrl()` so they respect Nextcloud's webroot; URL links
		 * pass through unchanged.
		 *
		 * @return {string} the resolved href
		 */
		/** @spec openspec/specs/tiles/spec.md */
		resolvedHref() {
			if (this.linkValue === '') {
				return '#'
			}
			if (this.linkType === 'app') {
				return generateUrl(this.linkValue)
			}
			return this.linkValue
		},

		/** @spec openspec/specs/tiles/spec.md */
		resolvedTarget() {
			return this.linkType === 'url' ? '_blank' : '_self'
		},

		/** @spec openspec/specs/tiles/spec.md */
		resolvedRel() {
			return this.linkType === 'url' ? 'noopener noreferrer' : null
		},

		/** @spec openspec/specs/tiles/spec.md */
		rootStyle() {
			return {
				'--tile-bg-color': this.backgroundColor,
				'--tile-text-color': this.textColor,
				backgroundColor: this.backgroundColor,
			}
		},

		/** @spec openspec/specs/tiles/spec.md */
		linkStyle() {
			return {
				backgroundColor: this.backgroundColor,
				color: this.textColor,
			}
		},
	},

	methods: {
		/**
		 * Click handler. Suppresses navigation entirely when the
		 * surrounding shell is in edit mode so configuring the widget
		 * cannot accidentally open the linked page (REQ-WDG-014).
		 *
		 * @param {Event} event the click event
		 */
		/** @spec openspec/specs/tiles/spec.md */
		onClick(event) {
			if (this.isInEditMode) {
				event.preventDefault()
				return
			}
			if (this.linkValue === '') {
				event.preventDefault()
				return
			}
			if (this.linkType === 'url') {
				event.preventDefault()
				window.open(this.linkValue, '_blank', 'noopener,noreferrer')

			}
			// `linkType === 'app'` falls through to the anchor's default
			// behaviour, which navigates to the resolved Nextcloud route.
		},
	},
}
</script>

<style scoped>
.tile-widget-renderer {
	width: 100%;
	height: 100%;
	overflow: hidden;
	border-radius: var(--border-radius, 6px);
	background-color: var(--tile-bg-color);
}

.tile-widget-renderer__link {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	width: 100%;
	padding: 16px;
	gap: 12px;
	text-decoration: none;
	transition: transform 0.2s ease, opacity 0.2s ease;
	color: var(--tile-text-color);
}

.tile-widget-renderer__link:hover {
	transform: scale(1.02);
	opacity: 0.95;
}

.tile-widget-renderer__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 48px;
	height: 48px;
	flex-shrink: 0;
}

.tile-widget-renderer__svg {
	width: 100%;
	height: 100%;
}

.tile-widget-renderer__icon-class {
	display: inline-block;
	width: 48px;
	height: 48px;
	background-size: 48px;
	filter: brightness(0) invert(1);
}

.tile-widget-renderer__icon-img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}

.tile-widget-renderer__emoji {
	font-size: 40px;
	line-height: 1;
}

.tile-widget-renderer__title {
	font-size: 16px;
	font-weight: 600;
	text-align: center;
	word-break: break-word;
	line-height: 1.3;
}
</style>
