<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="links-form">
		<!-- Sections editor (REQ-LNKS-008) -->
		<div class="links-form__sections">
			<div
				v-for="(section, sIdx) in sections"
				:key="sIdx"
				class="links-form__section">
				<div class="links-form__section-header">
					<input
						type="text"
						class="links-form__section-title"
						:value="section.title"
						:placeholder="t('mydash', 'Section title')"
						@input="updateSectionTitle(sIdx, $event.target.value)">
					<div class="links-form__section-actions">
						<button
							type="button"
							class="links-form__btn links-form__btn--small"
							:disabled="sIdx === 0"
							:title="t('mydash', 'Move up')"
							@click="moveSection(sIdx, -1)">
							↑
						</button>
						<button
							type="button"
							class="links-form__btn links-form__btn--small"
							:disabled="sIdx === sections.length - 1"
							:title="t('mydash', 'Move down')"
							@click="moveSection(sIdx, 1)">
							↓
						</button>
						<button
							type="button"
							class="links-form__btn links-form__btn--danger"
							:title="t('mydash', 'Delete section')"
							@click="deleteSection(sIdx)">
							×
						</button>
					</div>
				</div>

				<div class="links-form__links">
					<div
						v-for="(link, lIdx) in section.links"
						:key="lIdx"
						class="links-form__link-row"
						:class="{ 'links-form__link-row--invalid': linkErrors[sIdx] && linkErrors[sIdx][lIdx] }">
						<input
							type="text"
							class="links-form__input"
							:value="link.label"
							:placeholder="t('mydash', 'Label')"
							@input="updateLink(sIdx, lIdx, 'label', $event.target.value)">
						<input
							type="text"
							class="links-form__input"
							:value="link.url"
							:placeholder="t('mydash', 'URL')"
							@input="updateLink(sIdx, lIdx, 'url', $event.target.value)">
						<input
							type="text"
							class="links-form__input links-form__input--narrow"
							:value="link.icon"
							:placeholder="t('mydash', 'Icon (name or URL)')"
							@input="updateLink(sIdx, lIdx, 'icon', $event.target.value)">
						<input
							v-if="showLinkDescriptions"
							type="text"
							class="links-form__input"
							:value="link.description"
							:placeholder="t('mydash', 'Description (optional)')"
							@input="updateLink(sIdx, lIdx, 'description', $event.target.value)">
						<button
							type="button"
							class="links-form__btn links-form__btn--small"
							:disabled="lIdx === 0"
							:title="t('mydash', 'Move up')"
							@click="moveLink(sIdx, lIdx, -1)">
							↑
						</button>
						<button
							type="button"
							class="links-form__btn links-form__btn--small"
							:disabled="lIdx === section.links.length - 1"
							:title="t('mydash', 'Move down')"
							@click="moveLink(sIdx, lIdx, 1)">
							↓
						</button>
						<button
							type="button"
							class="links-form__btn links-form__btn--danger"
							:title="t('mydash', 'Delete link')"
							@click="deleteLink(sIdx, lIdx)">
							×
						</button>
					</div>
					<button
						type="button"
						class="links-form__btn links-form__btn--ghost"
						@click="addLink(sIdx)">
						+ {{ t('mydash', 'Add link') }}
					</button>
				</div>
			</div>
			<button
				type="button"
				class="links-form__btn links-form__btn--ghost"
				@click="addSection">
				+ {{ t('mydash', 'Add section') }}
			</button>
		</div>

		<!-- Layout options panel -->
		<div class="links-form__options">
			<label class="links-form__option">
				<span>{{ t('mydash', 'Columns') }}</span>
				<input
					type="number"
					min="1"
					max="6"
					:value="columns"
					class="links-form__input links-form__input--narrow"
					@input="updateOption('columns', clampColumns($event.target.value))">
			</label>

			<label class="links-form__option">
				<span>{{ t('mydash', 'Layout') }}</span>
				<select
					:value="linkLayout"
					class="links-form__input"
					@change="updateOption('linkLayout', $event.target.value)">
					<option value="card">{{ t('mydash', 'Card') }}</option>
					<option value="inline">{{ t('mydash', 'Inline') }}</option>
					<option value="icon-only">{{ t('mydash', 'Icon only') }}</option>
				</select>
			</label>

			<label class="links-form__option">
				<span>{{ t('mydash', 'Icon size') }}</span>
				<select
					:value="iconSize"
					class="links-form__input"
					@change="updateOption('iconSize', $event.target.value)">
					<option value="small">{{ t('mydash', 'Small (24 px)') }}</option>
					<option value="medium">{{ t('mydash', 'Medium (40 px)') }}</option>
					<option value="large">{{ t('mydash', 'Large (64 px)') }}</option>
				</select>
			</label>

			<label class="links-form__option links-form__option--checkbox">
				<input
					type="checkbox"
					:checked="openInNewTab"
					@change="updateOption('openInNewTab', $event.target.checked)">
				<span>{{ t('mydash', 'Open in new tab') }}</span>
			</label>

			<label class="links-form__option links-form__option--checkbox">
				<input
					type="checkbox"
					:checked="showSectionTitles"
					@change="updateOption('showSectionTitles', $event.target.checked)">
				<span>{{ t('mydash', 'Show section titles') }}</span>
			</label>

			<label class="links-form__option links-form__option--checkbox">
				<input
					type="checkbox"
					:checked="showLinkDescriptions"
					:disabled="linkLayout !== 'card'"
					@change="updateOption('showLinkDescriptions', $event.target.checked)">
				<span>{{ t('mydash', 'Show descriptions') }}</span>
			</label>
		</div>

		<p v-if="firstError" class="links-form__error">
			{{ firstError }}
		</p>
	</div>
</template>

<script>
const VALID_LAYOUTS = Object.freeze(['card', 'inline', 'icon-only'])
const VALID_SIZES = Object.freeze(['small', 'medium', 'large'])

const DEFAULT_CONTENT = Object.freeze({
	sections: [],
	columns: 3,
	linkLayout: 'card',
	iconSize: 'medium',
	openInNewTab: true,
	showSectionTitles: true,
	showLinkDescriptions: true,
})

function makeEmptyLink() {
	return { label: '', url: '', icon: '', description: '' }
}

function makeEmptySection() {
	return { title: '', links: [makeEmptyLink()] }
}

/**
 * REQ-LNKS-007 — URL sanitiser. Accepts http(s) and root-relative URLs
 * (without `..` traversal). Returns `null` for valid URLs and a
 * translated error string otherwise.
 *
 * @param {string} url candidate URL
 * @return {string|null} error message or `null` when valid
 */
function validateUrl(url) {
	if (typeof url !== 'string' || url.trim() === '') {
		return t('mydash', 'Link URL is required')
	}
	const trimmed = url.trim()
	if (trimmed.startsWith('/')) {
		if (trimmed.includes('..')) {
			return t('mydash', 'Invalid URL — use HTTP(S) or relative paths.')
		}
		return null
	}
	if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
		return null
	}
	return t('mydash', 'Invalid URL — use HTTP(S) or relative paths.')
}

/**
 * LinksForm — sub-form for the AddWidgetModal when the user is creating
 * or editing a `links` placement (capability `links-widget`).
 *
 * Edits sections + links inline (REQ-LNKS-008) plus the global layout
 * options (columns, linkLayout, iconSize, openInNewTab, showSectionTitles,
 * showLinkDescriptions). `validate()` runs URL sanitisation per
 * REQ-LNKS-007 and returns a non-empty error array when any link URL is
 * empty or unsafe; the AddWidgetModal disables the action button while
 * errors are present (REQ-WDG-012).
 *
 * The form intentionally uses up/down arrow buttons instead of HTML5
 * drag-and-drop — keyboard reorder is the accessibility-friendly default
 * and meets the design.md WCAG note.
 */
export default {
	name: 'LinksForm',

	props: {
		/**
		 * The placement being edited, or `null` in create mode.
		 */
		editingWidget: {
			type: Object,
			default: null,
		},
		/**
		 * Initial content values — used when not editing and the parent
		 * supplies registry defaults.
		 */
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = this.editingWidget?.content || this.value || {}
		return {
			sections: Array.isArray(initial.sections) && initial.sections.length > 0
				? this.cloneSections(initial.sections)
				: [],
			columns: this.clampColumns(initial.columns ?? DEFAULT_CONTENT.columns),
			linkLayout: VALID_LAYOUTS.includes(initial.linkLayout)
				? initial.linkLayout
				: DEFAULT_CONTENT.linkLayout,
			iconSize: VALID_SIZES.includes(initial.iconSize)
				? initial.iconSize
				: DEFAULT_CONTENT.iconSize,
			openInNewTab: initial.openInNewTab === undefined ? DEFAULT_CONTENT.openInNewTab : Boolean(initial.openInNewTab),
			showSectionTitles: initial.showSectionTitles === undefined ? DEFAULT_CONTENT.showSectionTitles : Boolean(initial.showSectionTitles),
			showLinkDescriptions: initial.showLinkDescriptions === undefined ? DEFAULT_CONTENT.showLinkDescriptions : Boolean(initial.showLinkDescriptions),
			linkErrors: [],
			firstError: '',
		}
	},

	computed: {
		assembledContent() {
			return {
				sections: this.cloneSections(this.sections),
				columns: this.columns,
				linkLayout: this.linkLayout,
				iconSize: this.iconSize,
				openInNewTab: this.openInNewTab,
				showSectionTitles: this.showSectionTitles,
				showLinkDescriptions: this.showLinkDescriptions,
			}
		},
	},

	methods: {
		cloneSections(sections) {
			return sections.map((section) => ({
				title: typeof section?.title === 'string' ? section.title : '',
				links: Array.isArray(section?.links)
					? section.links.map((link) => ({
						label: typeof link?.label === 'string' ? link.label : '',
						url: typeof link?.url === 'string' ? link.url : '',
						icon: typeof link?.icon === 'string' ? link.icon : '',
						description: typeof link?.description === 'string' ? link.description : '',
					}))
					: [],
			}))
		},

		clampColumns(value) {
			const num = Number(value)
			if (!Number.isFinite(num)) {
				return DEFAULT_CONTENT.columns
			}
			return Math.max(1, Math.min(6, Math.round(num)))
		},

		emitUpdate() {
			this.$emit('update:content', this.assembledContent)
		},

		addSection() {
			this.sections.push(makeEmptySection())
			this.emitUpdate()
		},

		deleteSection(index) {
			this.sections.splice(index, 1)
			this.emitUpdate()
		},

		moveSection(index, delta) {
			const target = index + delta
			if (target < 0 || target >= this.sections.length) {
				return
			}
			const [removed] = this.sections.splice(index, 1)
			this.sections.splice(target, 0, removed)
			this.emitUpdate()
		},

		updateSectionTitle(index, title) {
			if (this.sections[index]) {
				this.$set(this.sections[index], 'title', title)
				this.emitUpdate()
			}
		},

		addLink(sectionIndex) {
			const section = this.sections[sectionIndex]
			if (section) {
				section.links.push(makeEmptyLink())
				this.emitUpdate()
			}
		},

		deleteLink(sectionIndex, linkIndex) {
			const section = this.sections[sectionIndex]
			if (section && section.links[linkIndex] !== undefined) {
				section.links.splice(linkIndex, 1)
				this.emitUpdate()
			}
		},

		moveLink(sectionIndex, linkIndex, delta) {
			const section = this.sections[sectionIndex]
			if (!section) {
				return
			}
			const target = linkIndex + delta
			if (target < 0 || target >= section.links.length) {
				return
			}
			const [removed] = section.links.splice(linkIndex, 1)
			section.links.splice(target, 0, removed)
			this.emitUpdate()
		},

		updateLink(sectionIndex, linkIndex, field, value) {
			const section = this.sections[sectionIndex]
			if (!section) {
				return
			}
			const link = section.links[linkIndex]
			if (!link) {
				return
			}
			this.$set(link, field, value)
			this.emitUpdate()
		},

		updateOption(field, value) {
			if (field === 'linkLayout' && !VALID_LAYOUTS.includes(value)) {
				return
			}
			if (field === 'iconSize' && !VALID_SIZES.includes(value)) {
				return
			}
			this[field] = value
			this.emitUpdate()
		},

		/**
		 * REQ-LNKS-007, REQ-WDG-012 — validate every link URL across every
		 * non-empty section. Empty sections (no links) are skipped because
		 * the renderer hides them anyway (REQ-LNKS-003); the user can save
		 * a config with placeholder sections waiting to be filled.
		 *
		 * @return {string[]} validation errors (empty when valid)
		 */
		validate() {
			const errors = []
			const linkErrors = []
			this.sections.forEach((section, sIdx) => {
				const sectionErrors = []
				if (!Array.isArray(section.links)) {
					linkErrors[sIdx] = sectionErrors
					return
				}
				section.links.forEach((link, lIdx) => {
					if (typeof link.label !== 'string' || link.label.trim() === '') {
						const msg = t('mydash', 'Link label is required')
						sectionErrors[lIdx] = msg
						errors.push(msg)
					}
					const urlError = validateUrl(link.url)
					if (urlError) {
						sectionErrors[lIdx] = urlError
						errors.push(urlError)
					}
				})
				linkErrors[sIdx] = sectionErrors
			})
			this.linkErrors = linkErrors
			this.firstError = errors.length > 0 ? errors[0] : ''
			return errors
		},
	},
}
</script>

<style scoped>
.links-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.links-form__sections {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.links-form__section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-background-hover);
}

.links-form__section-header {
	display: flex;
	gap: 8px;
	align-items: center;
}

.links-form__section-title {
	flex: 1;
	font-weight: 600;
	font-size: 14px;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.links-form__section-actions {
	display: flex;
	gap: 4px;
}

.links-form__links {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding-left: 8px;
}

.links-form__link-row {
	display: flex;
	gap: 6px;
	align-items: center;
	flex-wrap: wrap;
}

.links-form__link-row--invalid .links-form__input {
	border-color: var(--color-error);
}

.links-form__input {
	flex: 1;
	min-width: 80px;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
}

.links-form__input--narrow {
	flex: 0 0 auto;
	width: 110px;
}

.links-form__btn {
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 13px;
}

.links-form__btn--small {
	padding: 2px 6px;
	font-size: 12px;
}

.links-form__btn--ghost {
	border-style: dashed;
	background-color: transparent;
	align-self: flex-start;
}

.links-form__btn--danger {
	border-color: var(--color-error);
	color: var(--color-error);
}

.links-form__btn:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.links-form__options {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.links-form__option {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
}

.links-form__option--checkbox {
	flex-direction: row;
	align-items: center;
	gap: 6px;
}

.links-form__error {
	margin: 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
