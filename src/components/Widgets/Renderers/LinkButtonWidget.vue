<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="link-button-widget">
		<button
			type="button"
			class="link-button-widget__button"
			:style="buttonStyle"
			:disabled="isExecuting"
			@click="onClick">
			<span v-if="hasIcon" class="link-button-widget__icon">
				<img
					v-if="isCustomIcon"
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
					.{{ extension }}
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
 * Click is fully suppressed while the surrounding dashboard is in
 * admin/edit mode (`isAdmin === true` AND `canEdit === true`) so
 * configuring the widget cannot accidentally fire actions
 * (REQ-LBN-001 scenario "Click in edit mode is suppressed"). The
 * button is `disabled` while an action is in flight to defeat
 * double-clicks (REQ-LBN-001 scenario "Disabled while action is in flight").
 */
export default {
	name: 'LinkButtonWidget',

	components: {
		IconRenderer,
	},

	props: {
		/**
		 * Persisted widget content. Shape:
		 * `{label, url, icon, actionType, backgroundColor, textColor}`.
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
			modalTitleId: `link-button-widget-modal-${modalIdCounter}`,
		}
	},

	computed: {
		label() {
			return typeof this.content?.label === 'string' ? this.content.label : ''
		},

		url() {
			return typeof this.content?.url === 'string' ? this.content.url : ''
		},

		icon() {
			return typeof this.content?.icon === 'string' ? this.content.icon : ''
		},

		actionType() {
			const declared = this.content?.actionType
			if (declared === ACTION_TYPES.INTERNAL || declared === ACTION_TYPES.CREATE_FILE) {
				return declared
			}
			return ACTION_TYPES.EXTERNAL
		},

		backgroundColor() {
			const value = this.content?.backgroundColor
			return (typeof value === 'string' && value !== '') ? value : 'var(--color-primary)'
		},

		textColor() {
			const value = this.content?.textColor
			return (typeof value === 'string' && value !== '') ? value : 'var(--color-primary-text)'
		},

		hasIcon() {
			return this.icon !== ''
		},

		isCustomIcon() {
			return isCustomIconUrl(this.icon)
		},

		displayLabel() {
			return this.label !== '' ? this.label : t('mydash', 'Link Button')
		},

		buttonStyle() {
			return {
				'background-color': this.backgroundColor,
				color: this.textColor,
			}
		},

		isInEditMode() {
			return this.isAdmin === true && this.canEdit === true
		},

		extension() {
			// In createFile mode the widget's `url` field carries the
			// extension token (e.g. `docx`, `txt`). Strip a leading dot
			// for cosmetic safety.
			const raw = this.url.trim().replace(/^\./, '')
			return raw.toLowerCase()
		},

		canCreate() {
			return this.filenameDraft.trim() !== ''
		},
	},

	methods: {
		onClick() {
			if (this.isInEditMode) {
				return
			}
			if (this.isExecuting) {
				return
			}

			switch (this.actionType) {
			case ACTION_TYPES.EXTERNAL:
				this.handleExternal()
				break
			case ACTION_TYPES.INTERNAL:
				this.handleInternal()
				break
			case ACTION_TYPES.CREATE_FILE:
				this.openCreateFileModal()
				break
			}
		},

		handleExternal() {
			if (this.url === '') {
				return
			}
			window.open(this.url, '_blank', 'noopener,noreferrer')
		},

		handleInternal() {
			const { invoke } = useInternalActions()
			const result = invoke(this.url)
			// Promise-returning actions block the button so a slow
			// internal action cannot be re-entered.
			if (result && typeof result.then === 'function') {
				this.isExecuting = true
				Promise.resolve(result).finally(() => {
					this.isExecuting = false
				})
			}
		},

		openCreateFileModal() {
			this.filenameDraft = `document_${Math.floor(Date.now() / 1000)}`
			this.modalOpen = true
			this.$nextTick(() => {
				if (this.$refs.filenameInput && typeof this.$refs.filenameInput.focus === 'function') {
					this.$refs.filenameInput.focus()
				}
			})
		},

		closeModal() {
			if (this.isExecuting) {
				return
			}
			this.modalOpen = false
		},

		async onCreateConfirm() {
			if (!this.canCreate || this.isExecuting) {
				return
			}

			const ext = this.extension
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
