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
					:label="t('mydash', 'Icon')"
					label-outside>
					<template #selected-option="{ label }">
						<div class="icon-option">
							<svg class="icon-option__preview" viewBox="0 0 24 24">
								<path :d="selectedIcon.icon" />
							</svg>
							<span class="icon-option__label">{{ label }}</span>
						</div>
					</template>
					<template #option="option">
						<div class="icon-option">
							<svg class="icon-option__preview" viewBox="0 0 24 24">
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
							<NcButton type="tertiary">
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
							<NcButton type="tertiary">
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
					<NcButton @click="$emit('close')">
						{{ t('mydash', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" @click="saveTile">
						{{ t('mydash', 'Save') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField, NcSelect, NcColorPicker } from '@nextcloud/vue'
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

	emits: ['close', 'save'],

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
				icon: this.iconPath
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
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
	padding-top: 20px;
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
