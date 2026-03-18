<!--
  - SPDX-FileCopyrightText: 2024 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal
		v-if="open"
		:name="t('mydash', 'Widget style')"
		size="normal"
		@close="$emit('close')">
		<div class="style-editor">
			<h2 class="style-editor__title">
				{{ t('mydash', 'Customize widget') }}
			</h2>

			<!-- Title settings -->
			<div class="style-editor__section">
				<h3 class="style-editor__section-title">
					{{ t('mydash', 'Title') }}
				</h3>

				<NcCheckboxRadioSwitch
					:checked="localStyle.showTitle"
					@update:checked="localStyle.showTitle = $event">
					{{ t('mydash', 'Show title') }}
				</NcCheckboxRadioSwitch>

				<NcTextField
					v-if="localStyle.showTitle"
					v-model="localStyle.customTitle"
					:label="t('mydash', 'Custom title')"
					:placeholder="placement.widget?.title || t('mydash', 'Widget title')" />
			</div>

			<!-- Background settings -->
			<div class="style-editor__section">
				<h3 class="style-editor__section-title">
					{{ t('mydash', 'Background') }}
				</h3>

				<div class="style-editor__row">
					<label class="style-editor__label">{{ t('mydash', 'Color') }}</label>
					<NcColorPicker v-model="localStyle.backgroundColor">
						<NcButton type="secondary">
							<template #icon>
								<div
									class="style-editor__color-preview"
									:style="{ backgroundColor: localStyle.backgroundColor }" />
							</template>
							{{ localStyle.backgroundColor || t('mydash', 'Default') }}
						</NcButton>
					</NcColorPicker>
				</div>
			</div>

			<!-- Icon settings -->
			<div class="style-editor__section">
				<h3 class="style-editor__section-title">
					{{ t('mydash', 'Icon') }}
				</h3>

				<NcSelect
					v-model="selectedIcon"
					:options="iconOptions"
					:label="t('mydash', 'Icon')"
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
			</div>

			<!-- Actions -->
			<div class="style-editor__actions">
				<NcButton
					v-if="!placement.isCompulsory"
					type="error"
					@click="$emit('delete')">
					{{ t('mydash', 'Delete') }}
				</NcButton>
				<div class="style-editor__actions-right">
					<NcButton type="secondary" @click="resetStyle">
						{{ t('mydash', 'Reset') }}
					</NcButton>
					<NcButton type="primary" @click="saveStyle">
						{{ t('mydash', 'Save') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
	NcButton,
	NcTextField,
	NcSelect,
	NcColorPicker,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
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

const defaultStyle = {
	showTitle: true,
	customTitle: '',
	customIcon: '',
	backgroundColor: '',
	borderStyle: 'none',
	borderColor: '',
	borderWidth: 1,
	borderRadius: 12,
	padding: { top: 0, right: 0, bottom: 0, left: 0 },
}

export default {
	name: 'WidgetStyleEditor',

	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcSelect,
		NcColorPicker,
		NcCheckboxRadioSwitch,
	},

	props: {
		placement: {
			type: Object,
			required: true,
		},
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'update', 'delete'],

	data() {
		return {
			localStyle: { ...defaultStyle },
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
		selectedIcon: {
			get() {
				const option = this.iconOptions.find(opt => opt.icon === this.localStyle.customIcon)
				return option || this.iconOptions[0]
			},
			set(value) {
				this.localStyle.customIcon = value.icon
			},
		},
	},

	watch: {
		placement: {
			immediate: true,
			handler(newPlacement) {
				if (newPlacement) {
					this.loadStyle()
				}
			},
		},
	},

	methods: {
		getNlDesignIconUrl(iconName) {
			// Generate URL for NlDesign icons
			return `${window.location.origin}/apps/nldesign/img/icons/${iconName}.svg`
		},

		loadStyle() {
			const styleConfig = this.placement.styleConfig || {}
			this.localStyle = {
				showTitle: this.placement.showTitle !== false,
				customTitle: this.placement.customTitle || '',
				customIcon: this.placement.customIcon || '',
				backgroundColor: styleConfig.backgroundColor || '',
				borderStyle: styleConfig.borderStyle || 'none',
				borderColor: styleConfig.borderColor || '',
				borderWidth: styleConfig.borderWidth || 1,
				borderRadius: styleConfig.borderRadius ?? 12,
				padding: {
					top: styleConfig.padding?.top || 0,
					right: styleConfig.padding?.right || 0,
					bottom: styleConfig.padding?.bottom || 0,
					left: styleConfig.padding?.left || 0,
				},
			}
		},

		resetStyle() {
			this.localStyle = { ...defaultStyle, padding: { ...defaultStyle.padding } }
		},

		saveStyle() {
			const styleConfig = {
				backgroundColor: this.localStyle.backgroundColor || null,
				borderStyle: this.localStyle.borderStyle,
				borderColor: this.localStyle.borderColor || null,
				borderWidth: this.localStyle.borderWidth,
				borderRadius: this.localStyle.borderRadius,
				padding: { ...this.localStyle.padding },
			}

			this.$emit('update', this.placement.id, {
				showTitle: this.localStyle.showTitle,
				customTitle: this.localStyle.customTitle || null,
				customIcon: this.localStyle.customIcon || null,
				styleConfig,
			})
		},
	},
}
</script>

<style scoped>
.style-editor {
	padding: 24px;
}

.style-editor__title {
	font-size: 20px;
	font-weight: 600;
	margin: 0 0 24px;
}

.style-editor__section {
	margin-bottom: 24px;
	padding-bottom: 24px;
	border-bottom: 1px solid var(--color-border);
}

.style-editor__section:last-of-type {
	border-bottom: none;
}

.style-editor__section-title {
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 16px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}

.style-editor__row {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
}

.style-editor__label {
	width: 80px;
	flex-shrink: 0;
}

.style-editor__slider {
	flex: 1;
}

.style-editor__input {
	width: 60px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.style-editor__value,
.style-editor__unit {
	width: 40px;
	text-align: right;
	color: var(--color-text-maxcontrast);
}

.style-editor__color-preview {
	width: 20px;
	height: 20px;
	border-radius: 4px;
	border: 1px solid var(--color-border);
}

.style-editor__padding-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.style-editor__padding-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.style-editor__padding-row label {
	width: 50px;
}

.style-editor__padding-row input {
	width: 60px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.style-editor__actions {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	margin-top: 24px;
}

.style-editor__actions-right {
	display: flex;
	gap: 12px;
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
