<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="tile-card-wrapper">
		<a
			:href="tileUrl"
			class="tile-card"
			:style="{
				backgroundColor: tile.backgroundColor,
				color: tile.textColor
			}"
			:target="isExternalLink ? '_blank' : '_self'"
			rel="noopener noreferrer">
			<svg
				v-if="tile.iconType === 'svg'"
				class="tile-card__icon"
				:style="{ fill: tile.textColor }"
				viewBox="0 0 24 24">
				<path :d="tile.icon" />
			</svg>
			<div v-else class="tile-card__icon">
				<span v-if="tile.iconType === 'class'" :class="tile.icon" />
				<img v-else-if="tile.iconType === 'url'" :src="tile.icon" alt="Icon">
				<span v-else class="tile-card__emoji">{{ tile.icon }}</span>
			</div>
			<div class="tile-card__title">
				{{ tile.title }}
			</div>
		</a>

		<div v-if="editMode" class="tile-card__actions">
			<NcButton
				type="tertiary"
				@click="$emit('edit', tile)">
				<template #icon>
					<Pencil :size="20" />
				</template>
			</NcButton>
			<NcButton
				type="tertiary"
				@click="$emit('remove', tile.id)">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'TileCard',

	components: {
		NcButton,
		Pencil,
		Close,
	},

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

	emits: ['edit', 'remove'],

	computed: {
		tileUrl() {
			// If it's a relative path or starts with /apps/, keep it as is.
			// Otherwise, treat it as an external URL.
			const url = this.tile.linkValue
			if (!url) {
				return '#'
			}

			// If it starts with http:// or https://, it's an external URL.
			if (url.startsWith('http://') || url.startsWith('https://')) {
				return url
			}

			// If it starts with /, it's a relative path (likely /apps/something).
			if (url.startsWith('/')) {
				return generateUrl(url)
			}

			// Otherwise, assume it's an app name and generate the URL.
			return generateUrl('/apps/' + url)
		},
		isExternalLink() {
			const url = this.tile.linkValue || ''
			return url.startsWith('http://') || url.startsWith('https://')
		},
	},
}
</script>

<style scoped>
.tile-card-wrapper {
	position: relative;
	height: 100%;
}

.tile-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	padding: 16px;
	border-radius: var(--border-radius-large);
	box-shadow: 0 0 10px var(--color-box-shadow);
	text-decoration: none;
	transition: transform 0.2s ease, box-shadow 0.2s ease;
	gap: 12px;
	cursor: pointer;
}

.tile-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 16px var(--color-box-shadow);
}

.tile-card__icon {
	width: 48px;
	height: 48px;
	display: block;
}

.tile-card__icon img {
	width: 100%;
	height: 100%;
	object-fit: contain;
	filter: none;
}

.tile-card__emoji {
	filter: none !important;
	font-size: 48px;
}

.tile-card__title {
	font-size: 16px;
	font-weight: 600;
	text-align: center;
	word-break: break-word;
	line-height: 1.3;
}

.tile-card__actions {
	position: absolute;
	top: 8px;
	right: 8px;
	display: flex;
	gap: 4px;
	opacity: 0;
	transition: opacity 0.2s ease;
	z-index: 10;
}

.tile-card-wrapper:hover .tile-card__actions {
	opacity: 1;
}

.tile-card__actions :deep(.button-vue) {
	background: var(--color-main-background) !important;
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
</style>
