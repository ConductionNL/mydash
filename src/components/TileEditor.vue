<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal
		:show.sync="isOpen"
		size="normal"
		@close="$emit('close')">
		<div class="tile-editor">
			<h2>{{ tile ? t('mydash', 'Edit Tile') : t('mydash', 'Create Tile') }}</h2>

			<div class="tile-editor__preview">
				<div
					class="tile-preview"
					:style="{
						backgroundColor: form.backgroundColor,
						color: form.textColor
					}">
					<svg
						class="tile-preview__icon"
						:style="{ fill: form.textColor }"
						viewBox="0 0 24 24">
						<path :d="iconPath" />
					</svg>
					<span class="tile-preview__title">{{ form.title }}</span>
				</div>
			</div>

			<div class="tile-editor__form">
				<NcTextField
					:value.sync="form.title"
					:label="t('mydash', 'Title')"
					:placeholder="t('mydash', 'Enter tile title')"
					required />

				<NcSelect
					v-model="selectedIcon"
					:options="iconOptions"
					:input-label="t('mydash', 'Icon')"
					label="label"
					label-outside>
					<template #selected-option="{ label }">
						<div class="icon-option">
							<img v-if="selectedIcon.type === 'nldesign'"
								class="icon-option__preview"
								:src="selectedIcon.icon"
								:alt="label">
							<svg v-else class="icon-option__preview" viewBox="0 0 24 24">
								<path :d="selectedIcon.icon" />
							</svg>
							<span class="icon-option__label">{{ label }}</span>
						</div>
					</template>
					<template #option="option">
						<div class="icon-option">
							<img v-if="option.type === 'nldesign'"
								class="icon-option__preview"
								:src="option.icon"
								:alt="option.label">
							<svg v-else class="icon-option__preview" viewBox="0 0 24 24">
								<path :d="option.icon" />
							</svg>
							<span class="icon-option__label">{{ option.label }}</span>
						</div>
					</template>
				</NcSelect>

				<div class="form-row">
					<div class="form-row__item">
						<label>{{ t('mydash', 'Background Color') }}</label>
						<NcColorPicker
							:value.sync="form.backgroundColor"
							@input="form.backgroundColor = $event">
							<NcButton
								type="tertiary"
								:aria-label="t('mydash', 'Pick background color')">
								<template #icon>
									<div
										class="color-preview"
										:style="{ backgroundColor: form.backgroundColor }" />
								</template>
								{{ form.backgroundColor }}
							</NcButton>
						</NcColorPicker>
					</div>

					<div class="form-row__item">
						<label>{{ t('mydash', 'Text Color') }}</label>
						<NcColorPicker
							:value.sync="form.textColor"
							@input="form.textColor = $event">
							<NcButton
								type="tertiary"
								:aria-label="t('mydash', 'Pick text color')">
								<template #icon>
									<div
										class="color-preview"
										:style="{ backgroundColor: form.textColor }" />
								</template>
								{{ form.textColor }}
							</NcButton>
						</NcColorPicker>
					</div>
				</div>

				<NcTextField
					:value.sync="form.linkValue"
					:label="t('mydash', 'URL')"
					:placeholder="t('mydash', 'https://example.com or /apps/files')"
					type="text" />

				<div class="tile-editor__actions">
					<NcButton
						v-if="tile"
						type="error"
						@click="$emit('delete')">
						{{ t('mydash', 'Delete') }}
					</NcButton>
					<div class="tile-editor__actions-right">
						<NcButton @click="$emit('close')">
							{{ t('mydash', 'Cancel') }}
						</NcButton>
						<NcButton type="primary" @click="saveTile">
							{{ t('mydash', 'Save') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField, NcSelect, NcColorPicker } from '@conduction/nextcloud-vue'
import {
	mdiFile,
	mdiFolder,
	mdiCalendar,
	mdiAccount,
	mdiEmail,
	mdiBriefcase,
	mdiLink,
	mdiHome,
	mdiAccountCircle,
	mdiAccountGroup,
	mdiCog,
	mdiImage,
	mdiVideo,
	mdiMusic,
	mdiStar,
	mdiHeart,
	mdiCheck,
	mdiTag,
	mdiComment,
	mdiShare,
	mdiMagnify,
	mdiDownload,
	mdiUpload,
	mdiChartLine,
	mdiConnection,
} from '@mdi/js'

export default {
	name: 'TileEditor',

	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcSelect,
		NcColorPicker,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		tile: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'save', 'delete'],

	data() {
		return {
			form: {
				title: '',
				icon: 'link',
				iconType: 'svg',
				backgroundColor: '#0082c9',
				textColor: '#ffffff',
				linkType: 'url',
				linkValue: '',
			},
			iconOptions: [
				{ id: 'file', label: this.t('mydash', 'Files'), icon: mdiFile },
				{ id: 'folder', label: this.t('mydash', 'Folder'), icon: mdiFolder },
				{ id: 'calendar', label: this.t('mydash', 'Calendar'), icon: mdiCalendar },
				{ id: 'contacts', label: this.t('mydash', 'Contacts'), icon: mdiAccount },
				{ id: 'mail', label: this.t('mydash', 'Mail'), icon: mdiEmail },
				{ id: 'office', label: this.t('mydash', 'Office'), icon: mdiBriefcase },
				{ id: 'link', label: this.t('mydash', 'Link'), icon: mdiLink },
				{ id: 'home', label: this.t('mydash', 'Home'), icon: mdiHome },
				{ id: 'user', label: this.t('mydash', 'User'), icon: mdiAccountCircle },
				{ id: 'group', label: this.t('mydash', 'Group'), icon: mdiAccountGroup },
				{ id: 'settings', label: this.t('mydash', 'Settings'), icon: mdiCog },
				{ id: 'picture', label: this.t('mydash', 'Picture'), icon: mdiImage },
				{ id: 'video', label: this.t('mydash', 'Video'), icon: mdiVideo },
				{ id: 'audio', label: this.t('mydash', 'Audio'), icon: mdiMusic },
				{ id: 'star', label: this.t('mydash', 'Star'), icon: mdiStar },
				{ id: 'favorite', label: this.t('mydash', 'Favorite'), icon: mdiHeart },
				{ id: 'checkmark', label: this.t('mydash', 'Checkmark'), icon: mdiCheck },
				{ id: 'tag', label: this.t('mydash', 'Tag'), icon: mdiTag },
				{ id: 'comment', label: this.t('mydash', 'Comment'), icon: mdiComment },
				{ id: 'share', label: this.t('mydash', 'Share'), icon: mdiShare },
				{ id: 'search', label: this.t('mydash', 'Search'), icon: mdiMagnify },
				{ id: 'download', label: this.t('mydash', 'Download'), icon: mdiDownload },
				{ id: 'upload', label: this.t('mydash', 'Upload'), icon: mdiUpload },
				{ id: 'monitoring', label: this.t('mydash', 'Monitoring'), icon: mdiChartLine },
				{ id: 'integration', label: this.t('mydash', 'Integration'), icon: mdiConnection },
				// NlDesign Icons
				{ id: 'nl-airplane', label: this.t('mydash', 'Airplane'), icon: this.getNlDesignIconUrl('Airplane'), type: 'nldesign' },
				{ id: 'nl-bell', label: this.t('mydash', 'Bell'), icon: this.getNlDesignIconUrl('Bell'), type: 'nldesign' },
				{ id: 'nl-bike', label: this.t('mydash', 'Bike'), icon: this.getNlDesignIconUrl('Bike'), type: 'nldesign' },
				{ id: 'nl-building', label: this.t('mydash', 'Building'), icon: this.getNlDesignIconUrl('Building'), type: 'nldesign' },
				{ id: 'nl-bus', label: this.t('mydash', 'Bus'), icon: this.getNlDesignIconUrl('Bus'), type: 'nldesign' },
				{ id: 'nl-cake', label: this.t('mydash', 'Cake'), icon: this.getNlDesignIconUrl('Cake'), type: 'nldesign' },
				{ id: 'nl-calendar', label: this.t('mydash', 'Calendar'), icon: this.getNlDesignIconUrl('Calendar'), type: 'nldesign' },
				{ id: 'nl-camera', label: this.t('mydash', 'Camera'), icon: this.getNlDesignIconUrl('Camera'), type: 'nldesign' },
				{ id: 'nl-car', label: this.t('mydash', 'Car'), icon: this.getNlDesignIconUrl('Car'), type: 'nldesign' },
				{ id: 'nl-certificate', label: this.t('mydash', 'Certificate'), icon: this.getNlDesignIconUrl('Certificate'), type: 'nldesign' },
				{ id: 'nl-clock', label: this.t('mydash', 'Clock'), icon: this.getNlDesignIconUrl('Clock'), type: 'nldesign' },
				{ id: 'nl-cogwheel', label: this.t('mydash', 'Cogwheel'), icon: this.getNlDesignIconUrl('Cogwheel'), type: 'nldesign' },
				{ id: 'nl-document', label: this.t('mydash', 'Document'), icon: this.getNlDesignIconUrl('Document'), type: 'nldesign' },
				{ id: 'nl-earth', label: this.t('mydash', 'Earth'), icon: this.getNlDesignIconUrl('Earth'), type: 'nldesign' },
				{ id: 'nl-euro', label: this.t('mydash', 'Euro'), icon: this.getNlDesignIconUrl('Euro'), type: 'nldesign' },
				{ id: 'nl-flower', label: this.t('mydash', 'Flower'), icon: this.getNlDesignIconUrl('Flower'), type: 'nldesign' },
				{ id: 'nl-folder', label: this.t('mydash', 'Folder'), icon: this.getNlDesignIconUrl('Folder'), type: 'nldesign' },
				{ id: 'nl-heart', label: this.t('mydash', 'Heart'), icon: this.getNlDesignIconUrl('Heart'), type: 'nldesign' },
				{ id: 'nl-house', label: this.t('mydash', 'House'), icon: this.getNlDesignIconUrl('House'), type: 'nldesign' },
				{ id: 'nl-image', label: this.t('mydash', 'Image'), icon: this.getNlDesignIconUrl('Image'), type: 'nldesign' },
				{ id: 'nl-lightbulb', label: this.t('mydash', 'Light Bulb'), icon: this.getNlDesignIconUrl('LightBulb'), type: 'nldesign' },
				{ id: 'nl-lightning', label: this.t('mydash', 'Lightning'), icon: this.getNlDesignIconUrl('Lightning'), type: 'nldesign' },
				{ id: 'nl-mail', label: this.t('mydash', 'Mail'), icon: this.getNlDesignIconUrl('Mail'), type: 'nldesign' },
				{ id: 'nl-map', label: this.t('mydash', 'Map'), icon: this.getNlDesignIconUrl('Map'), type: 'nldesign' },
				{ id: 'nl-megaphone', label: this.t('mydash', 'Megaphone'), icon: this.getNlDesignIconUrl('Megaphone'), type: 'nldesign' },
				{ id: 'nl-monument', label: this.t('mydash', 'Monument'), icon: this.getNlDesignIconUrl('Monument'), type: 'nldesign' },
				{ id: 'nl-park', label: this.t('mydash', 'Park'), icon: this.getNlDesignIconUrl('Park'), type: 'nldesign' },
				{ id: 'nl-parking', label: this.t('mydash', 'Parking'), icon: this.getNlDesignIconUrl('Parking'), type: 'nldesign' },
				{ id: 'nl-person', label: this.t('mydash', 'Person'), icon: this.getNlDesignIconUrl('Person'), type: 'nldesign' },
				{ id: 'nl-phone', label: this.t('mydash', 'Phone'), icon: this.getNlDesignIconUrl('Phone'), type: 'nldesign' },
				{ id: 'nl-search', label: this.t('mydash', 'Search'), icon: this.getNlDesignIconUrl('Search'), type: 'nldesign' },
				{ id: 'nl-star', label: this.t('mydash', 'Star'), icon: this.getNlDesignIconUrl('Star'), type: 'nldesign' },
				{ id: 'nl-tree', label: this.t('mydash', 'Tree'), icon: this.getNlDesignIconUrl('Tree'), type: 'nldesign' },
				{ id: 'nl-wallet', label: this.t('mydash', 'Wallet'), icon: this.getNlDesignIconUrl('Wallet'), type: 'nldesign' },
			],
		}
	},

	computed: {
		isOpen: {
			get() {
				return this.open
			},
			set(value) {
				if (!value) {
					this.$emit('close')
				}
			},
		},
		selectedIcon: {
			get() {
				const option = this.iconOptions.find(opt => opt.id === this.form.icon)
				return option || this.iconOptions.find(opt => opt.id === 'link')
			},
			set(value) {
				this.form.icon = value.id
			},
		},
		iconPath() {
			return this.selectedIcon.icon
		},
	},

	watch: {
		tile: {
			immediate: true,
			handler(newTile) {
				if (newTile) {
					this.form = {
						...newTile,
						iconType: newTile.iconType || 'class',
					}
				} else {
					this.resetForm()
				}
			},
		},
	},

	methods: {
		getNlDesignIconUrl(iconName) {
			// Generate URL for NlDesign icons
			return `${window.location.origin}/apps/nldesign/img/icons/${iconName}.svg`
		},

		resetForm() {
			this.form = {
				title: '',
				icon: 'link',
				iconType: 'svg',
				backgroundColor: '#0082c9',
				textColor: '#ffffff',
				linkType: 'url',
				linkValue: '',
			}
		},

		saveTile() {
			// Convert icon ID to the actual SVG path for the API
			const tileData = {
				...this.form,
				icon: this.iconPath,
			}
			this.$emit('save', tileData)
		},
	},
}
</script>

<style scoped>
.tile-editor {
	padding: 20px;
}

.tile-editor h2 {
	margin-top: 0;
	margin-bottom: 20px;
}

.tile-editor__preview {
	display: flex;
	justify-content: center;
	margin-bottom: 30px;
}

.tile-preview {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	width: 120px;
	height: 120px;
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	padding: 12px;
	gap: 8px;
}

.tile-preview__icon {
	width: 48px;
	height: 48px;
	display: block;
}

.tile-preview__icon img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}

.tile-preview__title {
	font-size: 14px;
	font-weight: 600;
	text-align: center;
	word-break: break-word;
	line-height: 1.2;
}

.tile-editor__form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.form-row {
	display: flex;
	gap: 12px;
}

.form-row__item {
	flex: 1;
}

.form-row__item label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
	font-size: 14px;
}

.color-preview {
	width: 20px;
	height: 20px;
	border-radius: 4px;
	border: 1px solid var(--color-border);
}

.tile-editor__actions {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-top: 20px;
	padding-top: 20px;
}

.tile-editor__actions-right {
	display: flex;
	gap: 8px;
}

.icon-option {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 4px 0;
}

.icon-option__preview {
	width: 24px;
	height: 24px;
	display: block;
	flex-shrink: 0;
	fill: currentColor;
}

.icon-option__label {
	flex: 1;
}
</style>
