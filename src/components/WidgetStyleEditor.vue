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

				<div v-if="localStyle.backgroundColor" class="style-editor__row">
					<label class="style-editor__label">{{ t('mydash', 'Opacity') }}</label>
					<input
						v-model.number="localStyle.backgroundOpacity"
						type="range"
						min="0"
						max="1"
						step="0.1"
						class="style-editor__slider">
					<span class="style-editor__value">{{ Math.round(localStyle.backgroundOpacity * 100) }}%</span>
				</div>
			</div>

			<!-- Border settings -->
			<div class="style-editor__section">
				<h3 class="style-editor__section-title">
					{{ t('mydash', 'Border') }}
				</h3>

				<div class="style-editor__row">
					<label class="style-editor__label">{{ t('mydash', 'Style') }}</label>
					<NcSelect
						v-model="localStyle.borderStyle"
						:options="borderStyleOptions"
						:clearable="false" />
				</div>

				<template v-if="localStyle.borderStyle !== 'none'">
					<div class="style-editor__row">
						<label class="style-editor__label">{{ t('mydash', 'Color') }}</label>
						<NcColorPicker v-model="localStyle.borderColor">
							<NcButton type="secondary">
								<template #icon>
									<div
										class="style-editor__color-preview"
										:style="{ backgroundColor: localStyle.borderColor }" />
								</template>
								{{ localStyle.borderColor || t('mydash', 'Default') }}
							</NcButton>
						</NcColorPicker>
					</div>

					<div class="style-editor__row">
						<label class="style-editor__label">{{ t('mydash', 'Width') }}</label>
						<input
							v-model.number="localStyle.borderWidth"
							type="number"
							min="1"
							max="10"
							class="style-editor__input">
						<span class="style-editor__unit">px</span>
					</div>
				</template>

				<div class="style-editor__row">
					<label class="style-editor__label">{{ t('mydash', 'Radius') }}</label>
					<input
						v-model.number="localStyle.borderRadius"
						type="range"
						min="0"
						max="24"
						class="style-editor__slider">
					<span class="style-editor__value">{{ localStyle.borderRadius }}px</span>
				</div>
			</div>

			<!-- Padding settings -->
			<div class="style-editor__section">
				<h3 class="style-editor__section-title">
					{{ t('mydash', 'Padding') }}
				</h3>

				<div class="style-editor__padding-grid">
					<div class="style-editor__padding-row">
						<label>{{ t('mydash', 'Top') }}</label>
						<input v-model.number="localStyle.padding.top" type="number" min="0" max="48">
					</div>
					<div class="style-editor__padding-row">
						<label>{{ t('mydash', 'Right') }}</label>
						<input v-model.number="localStyle.padding.right" type="number" min="0" max="48">
					</div>
					<div class="style-editor__padding-row">
						<label>{{ t('mydash', 'Bottom') }}</label>
						<input v-model.number="localStyle.padding.bottom" type="number" min="0" max="48">
					</div>
					<div class="style-editor__padding-row">
						<label>{{ t('mydash', 'Left') }}</label>
						<input v-model.number="localStyle.padding.left" type="number" min="0" max="48">
					</div>
				</div>
			</div>

			<!-- Actions -->
			<div class="style-editor__actions">
				<NcButton type="secondary" @click="resetStyle">
					{{ t('mydash', 'Reset') }}
				</NcButton>
				<NcButton type="primary" @click="saveStyle">
					{{ t('mydash', 'Save') }}
				</NcButton>
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

const defaultStyle = {
	showTitle: true,
	customTitle: '',
	backgroundColor: '',
	backgroundOpacity: 1,
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

	emits: ['close', 'update'],

	data() {
		return {
			localStyle: { ...defaultStyle },
			borderStyleOptions: [
				{ id: 'none', label: this.t('mydash', 'None') },
				{ id: 'solid', label: this.t('mydash', 'Solid') },
				{ id: 'dashed', label: this.t('mydash', 'Dashed') },
				{ id: 'dotted', label: this.t('mydash', 'Dotted') },
			],
		}
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
		loadStyle() {
			const styleConfig = this.placement.styleConfig || {}
			this.localStyle = {
				showTitle: this.placement.showTitle !== false,
				customTitle: this.placement.customTitle || '',
				backgroundColor: styleConfig.backgroundColor || '',
				backgroundOpacity: styleConfig.backgroundOpacity ?? 1,
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
				backgroundOpacity: this.localStyle.backgroundOpacity,
				borderStyle: this.localStyle.borderStyle,
				borderColor: this.localStyle.borderColor || null,
				borderWidth: this.localStyle.borderWidth,
				borderRadius: this.localStyle.borderRadius,
				padding: { ...this.localStyle.padding },
			}

			this.$emit('update', this.placement.id, {
				showTitle: this.localStyle.showTitle,
				customTitle: this.localStyle.customTitle || null,
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
	color: var(--color-text-lighter);
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
	color: var(--color-text-lighter);
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
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}
</style>
