<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div v-if="tile"
		class="tile-widget"
		:data-tile-id="tile.id"
		:style="{
			'--tile-bg-color': tile.backgroundColor || '#0082c9',
			'--tile-text-color': tile.textColor || '#ffffff'
		}">
		<!-- Edit button in edit mode -->
		<button
			v-if="editMode"
			class="tile-widget__edit"
			:aria-label="t('mydash', 'Edit tile')"
			@click.prevent="$emit('edit')">
			<span class="icon-settings" />
		</button>

		<a
			:href="tileUrl"
			class="tile-widget__link"
			:target="tile.linkType === 'url' ? '_blank' : '_self'"
			rel="noopener noreferrer">
			<!-- SVG icon -->
			<svg
				v-if="tile.iconType === 'svg'"
				class="tile-widget__icon"
				:style="{ fill: tile.textColor || '#ffffff' }"
				viewBox="0 0 24 24">
				<path :d="tile.icon" />
			</svg>
			<!-- Icon class or emoji or URL -->
			<div v-else class="tile-widget__icon">
				<span v-if="tile.iconType === 'class'" :class="['icon', tile.icon]" />
				<img v-else-if="tile.iconType === 'url'" :src="tile.icon" :alt="t('mydash', 'Icon')">
				<span v-else-if="tile.iconType === 'emoji'" class="tile-widget__emoji">{{ tile.icon }}</span>
			</div>
			<div
				class="tile-widget__title"
				:style="{
					color: tile.textColor || '#ffffff',
					'--title-color': tile.textColor || '#ffffff'
				}">
				{{ tile.title }}
			</div>
		</a>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'TileWidget',

	props: {
		tile: {
			type: Object,
			required: true,
		},
		editMode: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		tileUrl() {
			if (this.tile.linkType === 'app') {
				return generateUrl('/apps/' + this.tile.linkValue)
			}
			return this.tile.linkValue
		},
	},

	mounted() {
		console.log('[TileWidget] Mounted with tile:', JSON.stringify({
			id: this.tile?.id,
			title: this.tile?.title,
			backgroundColor: this.tile?.backgroundColor,
			textColor: this.tile?.textColor,
			icon: this.tile?.icon?.substring(0, 30),
			iconType: this.tile?.iconType,
		}, null, 2))
	},
}
</script>

<style scoped>
.tile-widget {
	height: 100%;
	width: 100%;
	position: absolute;
	top: 0;
	left: 0;
	border-radius: var(--border-radius-large);
	overflow: hidden;
	background-color: var(--tile-bg-color);
}

.tile-widget__link {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	width: 100%;
	text-decoration: none;
	padding: 20px;
	gap: 12px;
	transition: opacity var(--animation-quick) ease;
	background-color: var(--tile-bg-color);
	color: var(--tile-text-color);
}

.tile-widget__link:hover {
	opacity: 0.9;
}

.tile-widget__icon {
	font-size: 64px;
	width: 64px;
	height: 64px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

/* Nextcloud icon classes need the icon class wrapper and white filter */
.tile-widget__icon span.icon {
	display: inline-block;
	width: 64px;
	height: 64px;
	background-size: 64px;
	filter: brightness(0) invert(1);
}

.tile-widget__icon img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}

.tile-widget__emoji {
	font-size: 64px;
}

.tile-widget__title {
	font-size: 18px;
	font-weight: 700;
	text-align: center;
	word-break: break-word;
	line-height: 1.3;
	color: var(--tile-text-color);
}

.tile-widget__edit {
	position: absolute;
	top: 8px;
	right: 8px;
	width: 32px;
	height: 32px;
	border: none;
	border-radius: 50%;
	background: rgba(0, 0, 0, 0.5);
	cursor: pointer;
	z-index: 10;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background var(--animation-quick) ease;
}

.tile-widget__edit:hover {
	background: rgba(0, 0, 0, 0.7);
}

.tile-widget__edit .icon-settings {
	filter: brightness(0) invert(1);
	background-size: 20px;
	width: 20px;
	height: 20px;
}
</style>
