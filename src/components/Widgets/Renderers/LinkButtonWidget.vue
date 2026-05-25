<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="link-button-widget" :class="rootClass">
		<!-- Single-button mode (REQ-LBN-001..007). Default for legacy / button-mode placements. -->
		<button
			v-if="!isListMode"
			type="button"
			class="link-button-widget__button"
			:style="buttonStyle"
			:disabled="isExecuting"
			@click="onSingleClick">
			<span v-if="hasIcon" class="link-button-widget__icon">
				<img
					v-if="isCustomIcon(icon)"
					:src="icon"
					width="48"
					height="48"
					alt="">
				<IconRenderer
					v-else
					:name="icon"
					:size="48" />
			</span>
			<span class="link-button-widget__label">{{ displayLabel }}</span>
		</button>

		<!-- Vertical list mode (REQ-LBLM-005, REQ-LBLM-008). -->
		<ul
			v-else-if="isVerticalList"
			role="list"
			class="link-button-widget__list link-button-widget__list--vertical"
			:style="listContainerStyle">
			<li
				v-for="(link, index) in renderableLinks"
				:key="`link-${index}`"
				class="link-button-widget__list-item-wrap">
				<button
					type="button"
					class="link-button-widget__list-item"
					:style="listItemStyle(link)"
					:disabled="isExecuting"
					:aria-label="link.label || ''"
					@click="onListClick(link)">
					<span v-if="link.icon" class="link-button-widget__list-icon">
						<img
							v-if="isCustomIcon(link.icon)"
							:src="link.icon"
							width="24"
							height="24"
							alt="">
						<IconRenderer
							v-else
							:name="link.icon"
							:size="24" />
					</span>
					<span class="link-button-widget__list-label">{{ link.label || '' }}</span>
				</button>
			</li>
		</ul>

		<!-- Horizontal list mode (REQ-LBLM-005, REQ-LBLM-008). -->
		<div
			v-else
			role="list"
			class="link-button-widget__list link-button-widget__list--horizontal"
			:style="listContainerStyle">
			<div
				v-for="(link, index) in renderableLinks"
				:key="`link-${index}`"
				role="listitem"
				class="link-button-widget__list-item-wrap link-button-widget__list-item-wrap--horizontal">
				<button
					type="button"
					class="link-button-widget__list-item link-button-widget__list-item--horizontal"
					:style="listItemStyle(link)"
					:disabled="isExecuting"
					:aria-label="link.label || ''"
					@click="onListClick(link)">
					<span v-if="link.icon" class="link-button-widget__list-icon">
						<img
							v-if="isCustomIcon(link.icon)"
							:src="link.icon"
							width="24"
							height="24"
							alt="">
						<IconRenderer
							v-else
							:name="link.icon"
							:size="24" />
					</span>
					<span class="link-button-widget__list-label">{{ link.label || '' }}</span>
				</button>
			</div>
		</div>

		<div
			v-if="modalOpen"
			class="link-button-widget__modal-backdrop"
			role="dialog"
			aria-modal="true"
			:aria-labelledby="modalTitleId"
			@click.self="closeModal">
			<div class="link-button-widget__modal">
				<h3 :id="modalTitleId" class="link-button-widget__modal-title">
					{{ t('mydash', 'Create Document') }}
				</h3>
				<label class="link-button-widget__modal-label">
					{{ t('mydash', 'File Name') }}
					<input
						ref="filenameInput"
						v-model="filenameDraft"
						type="text"
						class="link-button-widget__modal-input"
						:placeholder="t('mydash', 'Enter filename')"
						@keyup.enter="onCreateConfirm">
				</label>
				<p class="link-button-widget__modal-extension">
					.{{ pendingExtension }}
				</p>
				<div class="link-button-widget__modal-actions">
					<button
						type="button"
						class="link-button-widget__modal-cancel"
						:disabled="isExecuting"
						@click="closeModal">
						{{ t('mydash', 'Cancel') }}
					</button>
					<button
						type="button"
						class="link-button-widget__modal-create"
						:disabled="!canCreate || isExecuting"
						@click="onCreateConfirm">
						{{ isExecuting ? t('mydash', 'Creating…') : t('mydash', 'Create') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import IconRenderer from '../../Dashboard/IconRenderer.vue'
import { isCustomIconUrl } from '../../../constants/dashboardIcons.js'
import { useInternalActions } from '../../../composables/useInternalActions.js'

// `@nextcloud/axios` and `@nextcloud/dialogs` are loaded lazily inside
// `onCreateConfirm()` so that import-graph consumers (like the widget
// registry) don't pay the cost of dragging in `@nextcloud/vue` chunks
// that vitest's css-no-op plugin can't intercept transitively.

let modalIdCounter = 0

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

const GAP_REM = Object.freeze({
	compact: '0.5rem',
	normal: '1rem',
	spacious: '1.5rem',
})

/**
 * LinkButtonWidget — renders a styled clickable tile that dispatches one
 * of three explicit action types (REQ-LBN-001):
 *
 *   - `external` → opens the configured `url` in a new tab.
 *   - `internal` → looks up the configured `url` (an action id) in the
 *     {@link useInternalActions} singleton registry and invokes the
 *     registered function. Missing ids log a `console.warn` but never
 *     throw (REQ-LBN-005).
 *   - `createFile` → opens an inline modal that POSTs `/api/files/create`
 *     and opens the resulting file in the Files app.
 *
 * The widget also supports a `displayMode = 'list'` (REQ-LBLM-001..009)
 * that renders an array of links (`content.links[]`) as either a
 * vertical `<ul role="list">` stack or a horizontal `<div role="list">`
 * row of pills. Each link entry reuses the same three action types as
 * the single-button mode, the same icon resolution rules, and the same
 * edit-mode suppression. Default behaviour and legacy placements
 * (`displayMode` absent) render the single button unchanged.
 *
 * Click is fully suppressed while the surrounding dashboard is in
 * admin/edit mode (`isAdmin === true` AND `canEdit === true`) so
 * configuring the widget cannot accidentally fire actions
 * (REQ-LBN-001 scenario "Click in edit mode is suppressed",
 * REQ-LBLM-003 scenario "List item click suppressed in edit mode").
 * The button is `disabled` while an action is in flight to defeat
 * double-clicks (REQ-LBN-001 scenario "Disabled while action is in
 * flight").
 */
export default {
	name: 'LinkButtonWidget',

	components: {
		IconRenderer,
	},

	props: {
		/**
		 * Persisted widget content. Shape (single-button mode):
		 * `{label, url, icon, actionType, backgroundColor, textColor}`.
		 *
		 * Shape (list mode, REQ-LBLM-002):
		 * `{displayMode: 'list', listOrientation, listItemGap, links: [
		 *   {label, url, icon, actionType, value?, backgroundColor?,
		 *    textColor?},
		 *   ...
		 * ]}`.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Whether the current user is an admin. Combined with
		 * `canEdit` to suppress click handlers in edit mode.
		 */
		isAdmin: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether the surrounding dashboard shell is in edit mode.
		 * Suppresses click handlers when both this and `isAdmin` are true.
		 */
		canEdit: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		modalIdCounter += 1
		return {
			isExecuting: false,
			modalOpen: false,
			filenameDraft: '',
			pendingExtension: '',
			pendingLink: null,
			modalTitleId: `link-button-widget-modal-${modalIdCounter}`,
		}
	},

	computed: {
		/** @spec openspec/specs/link-button-widget/spec.md */
		label() {
			return typeof this.content?.label === 'string' ? this.content.label : ''
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		url() {
			return typeof this.content?.url === 'string' ? this.content.url : ''
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		icon() {
			return typeof this.content?.icon === 'string' ? this.content.icon : ''
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		actionType() {
			const declared = this.content?.actionType
			if (declared === ACTION_TYPES.INTERNAL || declared === ACTION_TYPES.CREATE_FILE) {
				return declared
			}
			return ACTION_TYPES.EXTERNAL
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		backgroundColor() {
			const value = this.content?.backgroundColor
			return (typeof value === 'string' && value !== '') ? value : 'var(--color-primary)'
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		textColor() {
			const value = this.content?.textColor
			return (typeof value === 'string' && value !== '') ? value : 'var(--color-primary-text)'
		},

		hasIcon() {
			return this.icon !== ''
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		displayLabel() {
			return this.label !== '' ? this.label : t('mydash', 'Link Button')
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		buttonStyle() {
			return {
				'background-color': this.backgroundColor,
				color: this.textColor,
			}
		},

		isInEditMode() {
			return this.isAdmin === true && this.canEdit === true
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		extension() {
			// In createFile mode the widget's `url` field carries the
			// extension token (e.g. `docx`, `txt`). Strip a leading dot
			// for cosmetic safety.
			const raw = this.url.trim().replace(/^\./, '')
			return raw.toLowerCase()
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		canCreate() {
			return this.filenameDraft.trim() !== ''
		},

		/**
		 * REQ-LBLM-001: detect display mode. Legacy / unset values
		 * fall back to `'button'` to preserve backward compatibility.
		 *
		 * @return {string} 'button' or 'list'
		 */
		/** @spec openspec/specs/link-button-widget/spec.md */
		displayMode() {
			return this.content?.displayMode === DISPLAY_MODES.LIST
				? DISPLAY_MODES.LIST
				: DISPLAY_MODES.BUTTON
		},

		/**
		 * REQ-LBLM-002: list-mode renders only when both displayMode
		 * is 'list' AND the links array has at least one entry.
		 *
		 * @return {boolean} true when the list renderer should activate
		 */
		/** @spec openspec/specs/link-button-widget/spec.md */
		isListMode() {
			return this.displayMode === DISPLAY_MODES.LIST
				&& Array.isArray(this.content?.links)
				&& this.content.links.length > 0
		},

		/**
		 * REQ-LBLM-005: orientation defaults to vertical.
		 *
		 * @return {string} 'vertical' or 'horizontal'
		 */
		/** @spec openspec/specs/link-button-widget/spec.md */
		listOrientation() {
			return this.content?.listOrientation === ORIENTATIONS.HORIZONTAL
				? ORIENTATIONS.HORIZONTAL
				: ORIENTATIONS.VERTICAL
		},

		isVerticalList() {
			return this.listOrientation === ORIENTATIONS.VERTICAL
		},

		/**
		 * REQ-LBLM-005: spacing defaults to normal.
		 *
		 * @return {string} 'compact' | 'normal' | 'spacious'
		 */
		/** @spec openspec/specs/link-button-widget/spec.md */
		listItemGap() {
			const declared = this.content?.listItemGap
			if (declared === GAPS.COMPACT || declared === GAPS.SPACIOUS) {
				return declared
			}
			return GAPS.NORMAL
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		listGapValue() {
			return GAP_REM[this.listItemGap]
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		listContainerStyle() {
			return { gap: this.listGapValue }
		},

		/**
		 * Sanitised list of links that pass minimum schema (string label
		 * or string url present). Each entry is normalised so the
		 * renderer can rely on string fields.
		 *
		 * @return {Array<object>} normalised link entries
		 */
		/** @spec openspec/specs/link-button-widget/spec.md */
		renderableLinks() {
			if (Array.isArray(this.content?.links) === false) {
				return []
			}
			return this.content.links.map((raw) => {
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
					backgroundColor: typeof link.backgroundColor === 'string' ? link.backgroundColor : '',
					textColor: typeof link.textColor === 'string' ? link.textColor : '',
				}
			})
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		rootClass() {
			return {
				'link-button-widget--list': this.isListMode,
				[`link-button-widget--list-${this.listOrientation}`]: this.isListMode,
			}
		},
	},

	methods: {
		isCustomIcon(icon) {
			return isCustomIconUrl(icon)
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		listItemStyle(link) {
			const bg = (typeof link.backgroundColor === 'string' && link.backgroundColor !== '')
				? link.backgroundColor
				: this.backgroundColor
			const fg = (typeof link.textColor === 'string' && link.textColor !== '')
				? link.textColor
				: this.textColor
			return {
				'background-color': bg,
				color: fg,
			}
		},

		/**
		 * REQ-LBN-001: dispatch the single-button click handler.
		 *
		 * @return {void}
		 */
		/** @spec openspec/specs/link-button-widget/spec.md */
		onSingleClick() {
			if (this.isInEditMode || this.isExecuting) {
				return
			}
			this.dispatchAction({
				actionType: this.actionType,
				url: this.url,
				value: '',
			})
		},

		/**
		 * REQ-LBLM-003: dispatch the list-item click handler. Click
		 * is suppressed in edit mode and when an action is already in
		 * flight, mirroring single-button behaviour.
		 *
		 * @param {object} link the normalised link entry
		 * @return {void}
		 */
		/** @spec openspec/specs/link-button-widget/spec.md */
		onListClick(link) {
			if (this.isInEditMode || this.isExecuting) {
				return
			}
			this.dispatchAction({
				actionType: link.actionType,
				url: link.url,
				value: link.value,
			})
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		dispatchAction({ actionType, url, value }) {
			switch (actionType) {
			case ACTION_TYPES.EXTERNAL:
				this.handleExternal(url)
				break
			case ACTION_TYPES.INTERNAL:
				this.handleInternal(url)
				break
			case ACTION_TYPES.CREATE_FILE:
				// For list items the extension lives in `value`; for
				// the single button it lives in `url` (see REQ-LBN-003
				// and REQ-LBLM-003).
				this.openCreateFileModal(value !== '' ? value : url)
				break
			}
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		handleExternal(url) {
			if (typeof url !== 'string' || url === '') {
				return
			}
			window.open(url, '_blank', 'noopener,noreferrer')
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		handleInternal(actionId) {
			const { invoke } = useInternalActions()
			const result = invoke(actionId)
			// Promise-returning actions block the button so a slow
			// internal action cannot be re-entered.
			if (result && typeof result.then === 'function') {
				this.isExecuting = true
				Promise.resolve(result).finally(() => {
					this.isExecuting = false
				})
			}
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		openCreateFileModal(extensionToken) {
			const raw = (typeof extensionToken === 'string' ? extensionToken : '')
				.trim()
				.replace(/^\./, '')
				.toLowerCase()
			this.pendingExtension = raw
			this.filenameDraft = `document_${Math.floor(Date.now() / 1000)}`
			this.modalOpen = true
			this.$nextTick(() => {
				if (this.$refs.filenameInput && typeof this.$refs.filenameInput.focus === 'function') {
					this.$refs.filenameInput.focus()
				}
			})
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		closeModal() {
			if (this.isExecuting) {
				return
			}
			this.modalOpen = false
		},

		/** @spec openspec/specs/link-button-widget/spec.md */
		async onCreateConfirm() {
			if (!this.canCreate || this.isExecuting) {
				return
			}

			const ext = this.pendingExtension
			const safeName = this.filenameDraft.trim()
			const filename = ext === '' ? safeName : `${safeName}.${ext}`

			this.isExecuting = true
			try {
				// Lazy imports — see file header note. Tests stub these
				// modules via `vi.mock(...)` calls before mount.
				const [{ default: axios }, { generateUrl }, { showError }] = await Promise.all([
					import('@nextcloud/axios'),
					import('@nextcloud/router'),
					import('@nextcloud/dialogs'),
				])

				try {
					const response = await axios.post(
						generateUrl('/apps/mydash/api/files/create'),
						{ filename, dir: '/', content: '' },
					)
					const data = response?.data
					if (data && data.status === 'success' && typeof data.url === 'string') {
						window.open(data.url, '_blank')
						this.modalOpen = false
					} else {
						showError(t('mydash', 'Failed to create document'))
					}
				} catch (err) {
					showError(t('mydash', 'Failed to create document'))
				}
			} finally {
				this.isExecuting = false
			}
		},
	},
}
</script>

<style scoped>
.link-button-widget {
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 8px;
}

.link-button-widget--list {
	align-items: stretch;
	justify-content: stretch;
}

.link-button-widget__button {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 8px;
	width: 100%;
	height: 100%;
	min-height: 96px;
	padding: 12px;
	border: none;
	border-radius: var(--border-radius-large, 8px);
	cursor: pointer;
	font-size: 14px;
	font-weight: 600;
	transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.link-button-widget__button:hover:not(:disabled) {
	transform: translateY(-2px);
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.link-button-widget__button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.link-button-widget__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 48px;
	height: 48px;
}

.link-button-widget__label {
	display: block;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 100%;
}

.link-button-widget__list {
	width: 100%;
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
}

.link-button-widget__list--vertical {
	flex-direction: column;
}

.link-button-widget__list--horizontal {
	flex-direction: row;
	flex-wrap: wrap;
	align-items: flex-start;
}

.link-button-widget__list-item-wrap {
	margin: 0;
	padding: 0;
	list-style: none;
}

.link-button-widget__list-item {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 8px 12px;
	border: none;
	border-radius: var(--border-radius, 6px);
	cursor: pointer;
	font-size: 14px;
	font-weight: 500;
	text-align: left;
	transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.link-button-widget__list-item--horizontal {
	width: auto;
}

.link-button-widget__list-item:hover:not(:disabled) {
	transform: translateY(-2px);
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.link-button-widget__list-item:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.link-button-widget__list-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	flex-shrink: 0;
}

.link-button-widget__list-label {
	display: inline-block;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.link-button-widget__modal-backdrop {
	position: fixed;
	inset: 0;
	background-color: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.link-button-widget__modal {
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 20px;
	border-radius: var(--border-radius-large, 8px);
	min-width: 320px;
	max-width: 90vw;
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.link-button-widget__modal-title {
	margin: 0 0 12px 0;
	font-size: 16px;
}

.link-button-widget__modal-label {
	display: flex;
	flex-direction: column;
	gap: 6px;
	font-size: 13px;
}

.link-button-widget__modal-input {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.link-button-widget__modal-extension {
	margin: 8px 0 12px 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.link-button-widget__modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.link-button-widget__modal-cancel,
.link-button-widget__modal-create {
	padding: 6px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 13px;
}

.link-button-widget__modal-create {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-color: var(--color-primary);
}

.link-button-widget__modal-create:disabled,
.link-button-widget__modal-cancel:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}
</style>
