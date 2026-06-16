<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="quicklinks-form">
		<div class="quicklinks-form__settings">
			<NcSelect
				:value="iconSize"
				:options="iconSizeOptions"
				:input-label="t('mydash', 'Icon Size')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateOption('iconSize', $event)" />

			<NcSelect
				:value="iconShape"
				:options="iconShapeOptions"
				:input-label="t('mydash', 'Icon Shape')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateOption('iconShape', $event)" />

			<label class="quicklinks-form__checkbox-label">
				<input
					type="checkbox"
					:checked="showLabels"
					@change="updateOption('showLabels', $event.target.checked)">
				{{ t('mydash', 'Show Labels') }}
			</label>

			<NcSelect
				:value="labelPosition"
				:options="labelPositionOptions"
				:input-label="t('mydash', 'Label Position')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				:disabled="!showLabels"
				@input="updateOption('labelPosition', $event)" />

			<NcSelect
				:value="columns"
				:options="columnsOptions"
				:input-label="t('mydash', 'Columns')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateOption('columns', $event)" />

			<NcSelect
				:value="tileBackgroundStyle"
				:options="tileBackgroundOptions"
				:input-label="t('mydash', 'Tile Background')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateOption('tileBackgroundStyle', $event)" />

			<NcSelect
				:value="hoverEffect"
				:options="hoverEffectOptions"
				:input-label="t('mydash', 'Hover Effect')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateOption('hoverEffect', $event)" />
		</div>

		<div class="quicklinks-form__links">
			<table class="quicklinks-form__table">
				<thead>
					<tr>
						<th>{{ t('mydash', 'Label') }}</th>
						<th>{{ t('mydash', 'URL') }}</th>
						<th>{{ t('mydash', 'Icon') }}</th>
						<th v-if="showColorColumn">
							{{ t('mydash', 'Color (optional)') }}
						</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(link, index) in links"
						:key="index"
						class="quicklinks-form__row">
						<td>
							<input
								v-model="link.label"
								type="text"
								class="quicklinks-form__input"
								:placeholder="t('mydash', 'Label')"
								@input="onContentChange">
						</td>
						<td>
							<input
								v-model="link.url"
								type="text"
								class="quicklinks-form__input"
								:class="{ 'quicklinks-form__input--invalid': !isLinkUrlValid(link) }"
								:placeholder="'https://...'"
								@input="onContentChange">
							<small
								v-if="!isLinkUrlValid(link)"
								class="quicklinks-form__inline-error">
								{{ t('mydash', 'Invalid URL') }}
							</small>
						</td>
						<td>
							<input
								v-model="link.icon"
								type="text"
								class="quicklinks-form__input"
								:placeholder="t('mydash', 'Icon')"
								@input="onContentChange">
						</td>
						<td v-if="showColorColumn">
							<input
								type="color"
								:value="link.color || '#0070c0'"
								class="quicklinks-form__color"
								@input="onLinkColor(index, $event.target.value)">
						</td>
						<td>
							<button
								type="button"
								class="quicklinks-form__row-action"
								:aria-label="t('mydash', 'Delete link')"
								@click="removeLink(index)">
								&times;
							</button>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="quicklinks-form__row-actions">
				<button
					type="button"
					class="quicklinks-form__add"
					@click="addLink">
					+ {{ t('mydash', 'Add link') }}
				</button>
			</div>

			<details class="quicklinks-form__bulk">
				<summary>{{ t('mydash', 'Paste CSV (label,url)') }}</summary>
				<textarea
					v-model="csvDraft"
					class="quicklinks-form__bulk-input"
					rows="4"
					:placeholder="'Docs,https://docs.example.com\nFiles,/apps/files'" />
				<button
					type="button"
					class="quicklinks-form__bulk-apply"
					@click="applyBulkAdd">
					{{ t('mydash', 'Add link') }}
				</button>
			</details>
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@conduction/nextcloud-vue'
import { sanitiseUrl, validateUrl } from '../../../composables/useUrlSanitiser.js'

const DEFAULT_CONTENT = Object.freeze({
	links: [],
	iconSize: 'medium',
	iconShape: 'rounded',
	showLabels: true,
	labelPosition: 'below',
	columns: 'auto',
	tileBackgroundStyle: 'transparent',
	hoverEffect: 'lift',
})

/**
 * QuicklinksForm — sub-form for the AddWidgetModal that lets the user
 * configure the `quicklinks` widget type (REQ-QLNK-002, REQ-QLNK-008).
 *
 * Layout:
 *  - top: dropdown grid for the eight widget-level enum / boolean fields
 *  - middle: editable table of links (label / URL / icon / color)
 *  - bottom: collapsible CSV bulk-add textarea
 *
 * `validate()` returns a non-empty error array when any link has an empty
 * or invalid URL (REQ-QLNK-008). `assembledContent` returns the full
 * persistence shape with sanitised URLs so a hostile paste (`javascript:`,
 * `data:`) is dropped before reaching the backend.
 */
export default {
	name: 'QuicklinksForm',

	components: {
		NcSelect,
	},

	props: {
		/**
		 * The placement being edited, or `null` in create mode.
		 */
		editingWidget: {
			type: Object,
			default: null,
		},
		/**
		 * Initial content values — used when not editing.
		 */
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT, links: [] }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = this.editingWidget?.content || this.value || {}
		const rawLinks = Array.isArray(initial.links) ? initial.links : []
		return {
			links: rawLinks.map((link) => ({
				label: typeof link?.label === 'string' ? link.label : '',
				url: typeof link?.url === 'string' ? link.url : '',
				icon: typeof link?.icon === 'string' ? link.icon : '',
				color: typeof link?.color === 'string' ? link.color : '',
				openInNewTab: typeof link?.openInNewTab === 'boolean' ? link.openInNewTab : undefined,
			})),
			iconSize: initial.iconSize ?? DEFAULT_CONTENT.iconSize,
			iconShape: initial.iconShape ?? DEFAULT_CONTENT.iconShape,
			showLabels: typeof initial.showLabels === 'boolean' ? initial.showLabels : DEFAULT_CONTENT.showLabels,
			labelPosition: initial.labelPosition ?? DEFAULT_CONTENT.labelPosition,
			columns: initial.columns ?? DEFAULT_CONTENT.columns,
			tileBackgroundStyle: initial.tileBackgroundStyle ?? DEFAULT_CONTENT.tileBackgroundStyle,
			hoverEffect: initial.hoverEffect ?? DEFAULT_CONTENT.hoverEffect,
			csvDraft: '',
		}
	},

	computed: {
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		iconSizeOptions() {
			return [
				{ value: 'small', label: t('mydash', 'Small') },
				{ value: 'medium', label: t('mydash', 'Medium') },
				{ value: 'large', label: t('mydash', 'Large') },
				{ value: 'xlarge', label: t('mydash', 'Extra Large') },
			]
		},
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		iconShapeOptions() {
			return [
				{ value: 'square', label: t('mydash', 'Square') },
				{ value: 'rounded', label: t('mydash', 'Rounded') },
				{ value: 'circle', label: t('mydash', 'Circle') },
			]
		},
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		labelPositionOptions() {
			return [
				{ value: 'below', label: t('mydash', 'Below') },
				{ value: 'overlay', label: t('mydash', 'Overlay') },
			]
		},
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		columnsOptions() {
			const list = [{ value: 'auto', label: t('mydash', 'Auto') }]
			for (let i = 1; i <= 12; i += 1) {
				list.push({ value: i, label: String(i) })
			}
			return list
		},
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		tileBackgroundOptions() {
			return [
				{ value: 'transparent', label: t('mydash', 'Transparent') },
				{ value: 'solid', label: t('mydash', 'Solid') },
				{ value: 'gradient', label: t('mydash', 'Gradient') },
			]
		},
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		hoverEffectOptions() {
			return [
				{ value: 'lift', label: t('mydash', 'Lift') },
				{ value: 'fade', label: t('mydash', 'Fade') },
				{ value: 'border', label: t('mydash', 'Border') },
				{ value: 'none', label: t('mydash', 'None') },
			]
		},
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		showColorColumn() {
			return this.tileBackgroundStyle === 'solid'
		},
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		assembledContent() {
			return {
				links: this.links.map((link) => {
					const out = {
						label: link.label,
						url: sanitiseUrl(link.url),
						icon: link.icon,
					}
					if (typeof link.color === 'string' && link.color !== '') {
						out.color = link.color
					}
					if (typeof link.openInNewTab === 'boolean') {
						out.openInNewTab = link.openInNewTab
					}
					return out
				}),
				iconSize: this.iconSize,
				iconShape: this.iconShape,
				showLabels: this.showLabels,
				labelPosition: this.labelPosition,
				columns: this.columns,
				tileBackgroundStyle: this.tileBackgroundStyle,
				hoverEffect: this.hoverEffect,
			}
		},
	},

	methods: {
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		updateOption(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/** @spec openspec/specs/quicklinks-widget/spec.md */
		onContentChange() {
			this.$emit('update:content', this.assembledContent)
		},

		/** @spec openspec/specs/quicklinks-widget/spec.md */
		onLinkColor(index, value) {
			if (this.links[index]) {
				this.$set(this.links, index, { ...this.links[index], color: value })
			}
			this.onContentChange()
		},

		/** @spec openspec/specs/quicklinks-widget/spec.md */
		addLink() {
			this.links.push({ label: '', url: '', icon: '', color: '' })
			this.onContentChange()
		},

		/** @spec openspec/specs/quicklinks-widget/spec.md */
		removeLink(index) {
			this.links.splice(index, 1)
			this.onContentChange()
		},

		/** @spec openspec/specs/quicklinks-widget/spec.md */
		isLinkUrlValid(link) {
			// Empty input is "neutral" while editing — only flag as invalid
			// once the user has typed something so the row stops blinking
			// red on freshly-added empty rows.
			if (typeof link?.url !== 'string' || link.url === '') {
				return true
			}
			return validateUrl(link.url)
		},

		/** @spec openspec/specs/quicklinks-widget/spec.md */
		applyBulkAdd() {
			const draft = (this.csvDraft || '').trim()
			if (draft === '') {
				return
			}
			const newLinks = this.parseCsv(draft)
			if (newLinks.length === 0) {
				return
			}
			this.links.push(...newLinks)
			this.csvDraft = ''
			this.onContentChange()
		},

		/** @spec openspec/specs/quicklinks-widget/spec.md */
		parseCsv(input) {
			const out = []
			const rows = input.split(/\r?\n/)
			for (const raw of rows) {
				const line = raw.trim()
				if (line === '') {
					continue
				}
				// Naive split — stops at the first comma so URLs containing
				// commas (rare but possible in query strings) end up in the
				// URL slot intact.
				const commaIdx = line.indexOf(',')
				if (commaIdx === -1) {
					continue
				}
				const label = line.slice(0, commaIdx).trim()
				const url = line.slice(commaIdx + 1).trim()
				if (label === '' || url === '') {
					continue
				}
				out.push({ label, url, icon: '', color: '' })
			}
			return out
		},

		/**
		 * REQ-QLNK-008: validate() returns a non-empty error array when
		 * any link has an empty or invalid URL.
		 *
		 * @return {string[]} validation errors
		 */
		/** @spec openspec/specs/quicklinks-widget/spec.md */
		validate() {
			const errors = []
			for (let i = 0; i < this.links.length; i += 1) {
				const link = this.links[i]
				if (typeof link?.url !== 'string' || link.url.trim() === '') {
					errors.push(t('mydash', 'Invalid URL in one or more links'))
					return errors
				}
				if (!validateUrl(link.url)) {
					errors.push(t('mydash', 'Invalid URL in one or more links'))
					return errors
				}
			}
			return errors
		},
	},
}
</script>

<style scoped>
.quicklinks-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.quicklinks-form__settings {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 12px;
}

.quicklinks-form__checkbox-label {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
}

.quicklinks-form__links {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.quicklinks-form__table {
	width: 100%;
	border-collapse: collapse;
}

.quicklinks-form__table th,
.quicklinks-form__table td {
	padding: 4px;
	font-size: 13px;
	text-align: left;
	vertical-align: top;
}

.quicklinks-form__input {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 13px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-sizing: border-box;
}

.quicklinks-form__input--invalid {
	border-color: var(--color-error, #d32f2f);
}

.quicklinks-form__inline-error {
	display: block;
	color: var(--color-error, #d32f2f);
	font-size: 11px;
	margin-top: 2px;
}

.quicklinks-form__color {
	width: 32px;
	height: 24px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
}

.quicklinks-form__row-action,
.quicklinks-form__add,
.quicklinks-form__bulk-apply {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	cursor: pointer;
	padding: 4px 10px;
	font-size: 13px;
}

.quicklinks-form__bulk {
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.quicklinks-form__bulk-input {
	width: 100%;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
	box-sizing: border-box;
	margin-bottom: 8px;
}
</style>
