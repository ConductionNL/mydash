<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="link-button-form">
		<NcSelect
			:value="displayMode"
			:options="displayModeOptions"
			:input-label="t('mydash', 'Display Mode')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateDisplayMode($event)" />

		<!-- Single-button mode (REQ-LBN-006) — also rendered in list mode
		     to define the widget-level theme colours that apply to every
		     list item that does not override them. -->
		<template v-if="!isListMode">
			<NcTextField
				:value="label"
				:label="t('mydash', 'Label')"
				:placeholder="t('mydash', 'Label')"
				required
				@update:value="updateField('label', $event)" />

			<NcSelect
				:value="actionType"
				:options="actionTypeOptions"
				:input-label="t('mydash', 'Action Type')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateField('actionType', $event)" />

			<NcTextField
				:value="url"
				:label="t('mydash', 'URL')"
				:placeholder="urlPlaceholder"
				required
				@update:value="updateField('url', $event)" />

			<NcTextField
				:value="icon"
				:label="t('mydash', 'Upload Icon (optional)')"
				:placeholder="t('mydash', 'Icon')"
				@update:value="updateField('icon', $event)" />
		</template>

		<!-- Background + text colours apply to single-button mode AND
		     to list-mode items that do not declare their own colours. -->
		<label class="link-button-form__color-label">
			{{ t('mydash', 'Background Color') }}
			<input
				type="color"
				:value="backgroundColor || '#0070c0'"
				class="link-button-form__color"
				@input="updateField('backgroundColor', $event.target.value)">
		</label>

		<label class="link-button-form__color-label">
			{{ t('mydash', 'Text Color') }}
			<input
				type="color"
				:value="textColor || '#ffffff'"
				class="link-button-form__color"
				@input="updateField('textColor', $event.target.value)">
		</label>

		<!-- List-mode editor (REQ-LBLM-006). -->
		<template v-if="isListMode">
			<NcSelect
				:value="listOrientation"
				:options="orientationOptions"
				:input-label="t('mydash', 'List Orientation')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateField('listOrientation', $event)" />

			<NcSelect
				:value="listItemGap"
				:options="gapOptions"
				:input-label="t('mydash', 'List Item Spacing')"
				:reduce="(option) => option.value"
				label="label"
				:clearable="false"
				@input="updateField('listItemGap', $event)" />

			<div class="link-button-form__list-editor">
				<h4 class="link-button-form__list-title">
					{{ t('mydash', 'Links') }}
				</h4>
				<p
					v-if="links.length === 0"
					class="link-button-form__list-empty">
					{{ t('mydash', 'No links yet. Click "Add link" to add one.') }}
				</p>
				<ul v-else class="link-button-form__link-list">
					<li
						v-for="(link, index) in links"
						:key="`row-${index}`"
						class="link-button-form__link-row"
						:class="{ 'link-button-form__link-row--invalid': isLinkInvalid(link) }">
						<button
							type="button"
							class="link-button-form__row-handle"
							:disabled="index === 0"
							:aria-label="t('mydash', 'Move link up')"
							@click="moveLinkUp(index)">
							{{ '↑' }}
						</button>
						<button
							type="button"
							class="link-button-form__row-handle"
							:disabled="index === links.length - 1"
							:aria-label="t('mydash', 'Move link down')"
							@click="moveLinkDown(index)">
							{{ '↓' }}
						</button>
						<div class="link-button-form__row-fields">
							<NcTextField
								:value="link.label"
								:label="t('mydash', 'Label')"
								:placeholder="t('mydash', 'Label')"
								required
								@update:value="updateLinkField(index, 'label', $event)" />
							<NcSelect
								:value="link.actionType"
								:options="actionTypeOptions"
								:input-label="t('mydash', 'Action Type')"
								:reduce="(option) => option.value"
								label="label"
								:clearable="false"
								@input="updateLinkField(index, 'actionType', $event)" />
							<NcTextField
								:value="link.url"
								:label="t('mydash', 'URL')"
								:placeholder="urlPlaceholderFor(link.actionType)"
								required
								@update:value="updateLinkField(index, 'url', $event)" />
							<NcTextField
								:value="link.icon"
								:label="t('mydash', 'Icon (optional)')"
								:placeholder="t('mydash', 'Icon')"
								@update:value="updateLinkField(index, 'icon', $event)" />
							<NcTextField
								v-if="link.actionType === 'createFile'"
								:value="link.value"
								:label="t('mydash', 'File Extension')"
								:placeholder="'docx'"
								@update:value="updateLinkField(index, 'value', $event)" />
						</div>
						<button
							type="button"
							class="link-button-form__row-remove"
							:aria-label="t('mydash', 'Remove link')"
							@click="removeLink(index)">
							{{ t('mydash', 'Remove') }}
						</button>
					</li>
				</ul>
				<button
					type="button"
					class="link-button-form__add-link"
					:disabled="links.length >= MAX_LINKS"
					@click="addLink">
					{{ t('mydash', 'Add link') }}
				</button>
				<p
					v-if="links.length >= MAX_LINKS"
					class="link-button-form__list-hint">
					{{ t('mydash', 'Maximum of 20 links per list widget') }}
				</p>
			</div>
		</template>
	</div>
</template>

<script>
import { NcTextField, NcSelect } from '@conduction/nextcloud-vue'

const ACTION_TYPES = Object.freeze({
	EXTERNAL: 'external',
	INTERNAL: 'internal',
	CREATE_FILE: 'createFile',
})

const DISPLAY_MODES = Object.freeze({
	BUTTON: 'button',
	LIST: 'list',
})

const ORIENTATIONS = Object.freeze({
	VERTICAL: 'vertical',
	HORIZONTAL: 'horizontal',
})

const GAPS = Object.freeze({
	COMPACT: 'compact',
	NORMAL: 'normal',
	SPACIOUS: 'spacious',
})

const MAX_LINKS = 20

const DEFAULT_LINK = Object.freeze({
	label: '',
	url: '',
	icon: '',
	actionType: ACTION_TYPES.EXTERNAL,
	value: '',
})

const DEFAULT_CONTENT = Object.freeze({
	label: '',
	url: '',
	icon: '',
	actionType: ACTION_TYPES.EXTERNAL,
	backgroundColor: '',
	textColor: '',
	displayMode: DISPLAY_MODES.BUTTON,
	listOrientation: ORIENTATIONS.VERTICAL,
	listItemGap: GAPS.NORMAL,
	links: [],
})

/**
 * LinkButtonForm — sub-form for the AddWidgetModal when the user is
 * creating or editing a `link` placement (REQ-LBN-006, REQ-LBLM-006).
 *
 * In `displayMode = 'button'` (default) the form exposes the original
 * six fields: `label`, `actionType`, `url`, `icon`, `backgroundColor`,
 * `textColor`. The `url` placeholder swaps with `actionType`
 * (`https://...`, `action-id`, `docx`). `validate()` requires both
 * `label` AND `url` non-empty and returns a non-empty error array
 * otherwise. The form pre-fills from `editingWidget.content` when
 * editing an existing widget.
 *
 * In `displayMode = 'list'` (REQ-LBLM-006) the single-link fields are
 * hidden in favour of a list editor: orientation + spacing selects, an
 * inline list of link rows (each row a stripped-down single-link form),
 * plus add/remove/reorder controls. The widget-level background and
 * text colour fields remain visible because they apply uniformly to
 * every list item that does not override them. `validate()` requires a
 * non-empty `links` array AND non-empty `label`+`url` on every entry.
 *
 * Backward compatibility (REQ-LBLM-009):
 *  - Placements without a `displayMode` field are treated as button mode.
 *  - When the user toggles a button-mode placement to list mode, the
 *    first link entry is auto-populated from the legacy single-link
 *    fields if they are present.
 */
export default {
	name: 'LinkButtonForm',

	components: {
		NcTextField,
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
		 * Initial content values — used when not editing and the
		 * parent supplies registry defaults.
		 */
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = this.editingWidget?.content || this.value || {}
		const declaredMode = initial.displayMode === DISPLAY_MODES.LIST
			? DISPLAY_MODES.LIST
			: DISPLAY_MODES.BUTTON
		const declaredOrientation = initial.listOrientation === ORIENTATIONS.HORIZONTAL
			? ORIENTATIONS.HORIZONTAL
			: ORIENTATIONS.VERTICAL
		const declaredGap = (initial.listItemGap === GAPS.COMPACT
			|| initial.listItemGap === GAPS.SPACIOUS)
			? initial.listItemGap
			: GAPS.NORMAL
		const declaredLinks = Array.isArray(initial.links)
			? initial.links.map((link) => normaliseLink(link))
			: []
		return {
			MAX_LINKS,
			label: initial.label ?? DEFAULT_CONTENT.label,
			url: initial.url ?? DEFAULT_CONTENT.url,
			icon: initial.icon ?? DEFAULT_CONTENT.icon,
			actionType: initial.actionType ?? DEFAULT_CONTENT.actionType,
			backgroundColor: initial.backgroundColor ?? DEFAULT_CONTENT.backgroundColor,
			textColor: initial.textColor ?? DEFAULT_CONTENT.textColor,
			displayMode: declaredMode,
			listOrientation: declaredOrientation,
			listItemGap: declaredGap,
			links: declaredLinks,
		}
	},

	computed: {
		isListMode() {
			return this.displayMode === DISPLAY_MODES.LIST
		},

		actionTypeOptions() {
			return [
				{ value: ACTION_TYPES.EXTERNAL, label: t('mydash', 'External Link') },
				{ value: ACTION_TYPES.INTERNAL, label: t('mydash', 'Internal Function') },
				{ value: ACTION_TYPES.CREATE_FILE, label: t('mydash', 'Create File') },
			]
		},

		displayModeOptions() {
			return [
				{ value: DISPLAY_MODES.BUTTON, label: t('mydash', 'Single button') },
				{ value: DISPLAY_MODES.LIST, label: t('mydash', 'List of links') },
			]
		},

		orientationOptions() {
			return [
				{ value: ORIENTATIONS.VERTICAL, label: t('mydash', 'Vertical (list)') },
				{ value: ORIENTATIONS.HORIZONTAL, label: t('mydash', 'Horizontal (cards)') },
			]
		},

		gapOptions() {
			return [
				{ value: GAPS.COMPACT, label: t('mydash', 'Compact') },
				{ value: GAPS.NORMAL, label: t('mydash', 'Normal') },
				{ value: GAPS.SPACIOUS, label: t('mydash', 'Spacious') },
			]
		},

		urlPlaceholder() {
			return this.urlPlaceholderFor(this.actionType)
		},

		assembledContent() {
			return {
				label: this.label,
				url: this.url,
				icon: this.icon,
				actionType: this.actionType,
				backgroundColor: this.backgroundColor,
				textColor: this.textColor,
				displayMode: this.displayMode,
				listOrientation: this.listOrientation,
				listItemGap: this.listItemGap,
				// Emit a clean, normalised links array so consumers can
				// rely on the schema shape regardless of internal mutations.
				links: this.links.map((link) => normaliseLink(link)),
			}
		},
	},

	methods: {
		urlPlaceholderFor(actionType) {
			switch (actionType) {
			case ACTION_TYPES.INTERNAL:
				return 'action-id'
			case ACTION_TYPES.CREATE_FILE:
				return 'docx'
			case ACTION_TYPES.EXTERNAL:
			default:
				return 'https://...'
			}
		},

		/**
		 * Set a top-level field and notify the parent.
		 *
		 * @param {string} field one of: label, url, icon, actionType, backgroundColor, textColor, listOrientation, listItemGap
		 * @param {string} value new value
		 * @return {void}
		 */
		updateField(field, value) {
			this[field] = value
			this.$emit('update:content', this.assembledContent)
		},

		/**
		 * REQ-LBLM-009: when toggling button → list, auto-populate the
		 * first link entry from the legacy single-link fields if the
		 * links array is empty. When toggling list → button the existing
		 * `links` array is preserved in storage but ignored at render
		 * time (per design D-open-1).
		 *
		 * @param {string} mode 'button' or 'list'
		 * @return {void}
		 */
		updateDisplayMode(mode) {
			const next = mode === DISPLAY_MODES.LIST ? DISPLAY_MODES.LIST : DISPLAY_MODES.BUTTON
			this.displayMode = next
			if (next === DISPLAY_MODES.LIST && this.links.length === 0) {
				const seed = (this.label !== '' || this.url !== '' || this.icon !== '')
					? normaliseLink({
						label: this.label,
						url: this.url,
						icon: this.icon,
						actionType: this.actionType,
					})
					: normaliseLink({ ...DEFAULT_LINK })
				this.links = [seed]
			}
			this.$emit('update:content', this.assembledContent)
		},

		updateLinkField(index, field, value) {
			if (index < 0 || index >= this.links.length) {
				return
			}
			const next = this.links.slice()
			next[index] = { ...next[index], [field]: value }
			this.links = next
			this.$emit('update:content', this.assembledContent)
		},

		addLink() {
			if (this.links.length >= MAX_LINKS) {
				return
			}
			this.links = [...this.links, normaliseLink({ ...DEFAULT_LINK })]
			this.$emit('update:content', this.assembledContent)
		},

		removeLink(index) {
			if (index < 0 || index >= this.links.length) {
				return
			}
			const next = this.links.slice()
			next.splice(index, 1)
			this.links = next
			this.$emit('update:content', this.assembledContent)
		},

		moveLinkUp(index) {
			if (index <= 0 || index >= this.links.length) {
				return
			}
			const next = this.links.slice()
			const tmp = next[index - 1]
			next[index - 1] = next[index]
			next[index] = tmp
			this.links = next
			this.$emit('update:content', this.assembledContent)
		},

		moveLinkDown(index) {
			if (index < 0 || index >= this.links.length - 1) {
				return
			}
			const next = this.links.slice()
			const tmp = next[index + 1]
			next[index + 1] = next[index]
			next[index] = tmp
			this.links = next
			this.$emit('update:content', this.assembledContent)
		},

		isLinkInvalid(link) {
			return (typeof link.label !== 'string' || link.label.trim() === '')
				|| (typeof link.url !== 'string' || link.url.trim() === '')
		},

		/**
		 * REQ-LBN-006 + REQ-LBLM-007: validate() requires either:
		 *  - button mode: `label` AND `url` non-empty
		 *  - list mode: a non-empty `links` array AND every entry has
		 *    non-empty `label` AND `url`
		 *
		 * @return {string[]} validation errors
		 */
		validate() {
			const errors = []
			if (this.isListMode) {
				if (!Array.isArray(this.links) || this.links.length === 0) {
					errors.push(t('mydash', 'At least one link is required for list mode'))
					return errors
				}
				let invalidCount = 0
				for (const link of this.links) {
					if (this.isLinkInvalid(link)) {
						invalidCount += 1
					}
				}
				if (invalidCount > 0) {
					errors.push(t('mydash', 'Each link requires a label and a URL'))
				}
				return errors
			}
			if (typeof this.label !== 'string' || this.label.trim() === '') {
				errors.push(t('mydash', 'Label is required'))
			}
			if (typeof this.url !== 'string' || this.url.trim() === '') {
				errors.push(t('mydash', 'URL is required'))
			}
			return errors
		},
	},
}

/**
 * Normalise a raw link entry into the canonical shape expected by the
 * renderer. Missing keys default to empty strings; the action type
 * falls back to `'external'` if not one of the three supported values.
 *
 * @param {object} raw the raw link entry (may be undefined or null)
 * @return {object} a normalised link entry
 */
function normaliseLink(raw) {
	const link = (raw !== null && typeof raw === 'object') ? raw : {}
	const declaredAction = link.actionType
	const actionType = (declaredAction === ACTION_TYPES.INTERNAL
		|| declaredAction === ACTION_TYPES.CREATE_FILE)
		? declaredAction
		: ACTION_TYPES.EXTERNAL
	return {
		label: typeof link.label === 'string' ? link.label : '',
		url: typeof link.url === 'string' ? link.url : '',
		icon: typeof link.icon === 'string' ? link.icon : '',
		actionType,
		value: typeof link.value === 'string' ? link.value : '',
	}
}
</script>

<style scoped>
.link-button-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.link-button-form__color-label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	font-size: 14px;
}

.link-button-form__color {
	width: 48px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
}

.link-button-form__list-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-hover);
}

.link-button-form__list-title {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
}

.link-button-form__list-empty,
.link-button-form__list-hint {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.link-button-form__link-list {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.link-button-form__link-row {
	display: grid;
	grid-template-columns: auto auto 1fr auto;
	align-items: start;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.link-button-form__link-row--invalid {
	border-color: var(--color-error, #d33);
	box-shadow: 0 0 0 1px var(--color-error, #d33) inset;
}

.link-button-form__row-handle {
	width: 28px;
	height: 28px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 14px;
}

.link-button-form__row-handle:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.link-button-form__row-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.link-button-form__row-remove {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 13px;
}

.link-button-form__add-link {
	align-self: flex-start;
	padding: 6px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-primary);
	color: var(--color-primary-text);
	cursor: pointer;
	font-size: 13px;
}

.link-button-form__add-link:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}
</style>
