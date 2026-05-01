<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	LinkButtonWidget — capability `link-button-widget` (REQ-LBN-001..007)

	Renders a styled action button. Three actionTypes are supported:
	  - external: window.open(url, '_blank', 'noopener,noreferrer')
	  - internal: useInternalActions().invoke(url) — url is the action ID
	  - createFile: opens an inline filename-prompt modal, then POSTs
	                /api/files/create and opens the result in a new tab

	All click handlers are suppressed when isAdmin === true so that
	configuring the widget does not accidentally fire actions (REQ-LBN-001).

	Default colours fall back to var(--color-primary) /
	var(--color-primary-text) when the persisted values are empty (REQ-LBN-007).
-->

<template>
	<div class="link-button-widget">
		<!-- Primary action button -->
		<button
			class="link-button-widget__btn"
			:style="buttonStyle"
			:disabled="isExecuting || creatingDoc"
			@click="handleClick">
			<!-- Icon (optional) — REQ-LBN-002 -->
			<IconRenderer
				v-if="resolvedIcon"
				:name="resolvedIcon"
				:size="48"
				class="link-button-widget__icon" />
			<span class="link-button-widget__label">{{ resolvedLabel }}</span>
		</button>

		<!-- Inline createFile modal — REQ-LBN-003 -->
		<div
			v-if="showDocModal"
			class="link-button-widget__modal-backdrop"
			@click.self="cancelModal">
			<div class="link-button-widget__modal">
				<h3 class="link-button-widget__modal-title">
					{{ tt('Create Document') }}
				</h3>
				<p class="link-button-widget__modal-ext">
					{{ tt('File Name') }}: <code>.{{ resolvedExtension }}</code>
				</p>
				<input
					v-model="docName"
					class="link-button-widget__modal-input"
					type="text"
					:placeholder="tt('Enter filename')"
					@keydown.enter="submitCreate">
				<p
					v-if="createError"
					class="link-button-widget__modal-error">
					{{ tt('Failed to create document') }}
				</p>
				<div class="link-button-widget__modal-actions">
					<button
						class="link-button-widget__modal-cancel"
						@click="cancelModal">
						{{ tt('Cancel') }}
					</button>
					<button
						class="link-button-widget__modal-create"
						:disabled="!docName.trim() || creatingDoc"
						@click="submitCreate">
						{{ creatingDoc ? tt('Creating…') : tt('Create') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import IconRenderer from '../../Dashboard/IconRenderer.vue'
import { useInternalActions } from '../../../composables/useInternalActions.js'

export default {
	name: 'LinkButtonWidget',

	components: {
		IconRenderer,
	},

	props: {
		/**
		 * Persisted widget content blob.
		 * Shape: { label, url, icon, actionType, backgroundColor, textColor }
		 */
		widget: {
			type: Object,
			default: () => ({}),
		},

		/** Button label. Falls back to widget.content.label. */
		label: {
			type: String,
			default: '',
		},

		/** URL / action-ID / extension string. Falls back to widget.content.url. */
		url: {
			type: String,
			default: '',
		},

		/** Icon name or custom URL. Falls back to widget.content.icon. */
		icon: {
			type: String,
			default: '',
		},

		/**
		 * Action type: 'external' | 'internal' | 'createFile'.
		 * Empty string means "read from widget.content.actionType".
		 */
		actionType: {
			type: String,
			default: '',
		},

		/** Background colour (CSS value). Empty → var(--color-primary). */
		backgroundColor: {
			type: String,
			default: '',
		},

		/** Text colour (CSS value). Empty → var(--color-primary-text). */
		textColor: {
			type: String,
			default: '',
		},

		/**
		 * When true, all click handlers are suppressed (edit mode).
		 * REQ-LBN-001: suppressed in admin/edit mode.
		 */
		isAdmin: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			/** Controls inline modal visibility. */
			showDocModal: false,
			/** User-edited filename (without extension). */
			docName: `document_${Date.now()}`,
			/** Tracks active file-creation POST. */
			creatingDoc: false,
			/** True while any other async action is in flight. */
			isExecuting: false,
			/** Error flag for the createFile modal toast. */
			createError: false,
		}
	},

	computed: {
		/** Resolved content blob, with prop overrides taking precedence. */
		content() {
			return this.widget?.content || {}
		},

		resolvedLabel() {
			return this.label || this.content.label || ''
		},

		resolvedUrl() {
			return this.url || this.content.url || ''
		},

		resolvedIcon() {
			const icon = this.icon || this.content.icon || ''
			return icon.trim() !== '' ? icon : null
		},

		resolvedActionType() {
			return this.actionType || this.content.actionType || 'external'
		},

		resolvedBg() {
			const bg = this.backgroundColor || this.content.backgroundColor || ''
			return bg.trim() !== '' ? bg : 'var(--color-primary)'
		},

		resolvedText() {
			const tc = this.textColor || this.content.textColor || ''
			return tc.trim() !== '' ? tc : 'var(--color-primary-text)'
		},

		buttonStyle() {
			return {
				backgroundColor: this.resolvedBg,
				color: this.resolvedText,
				cursor: this.isAdmin ? 'default' : 'pointer',
				opacity: (this.isExecuting || this.creatingDoc) ? 0.6 : 1,
			}
		},

		/**
		 * Extension derived from url field (e.g. 'docx' → 'docx').
		 * Used in the createFile modal to display the suffix.
		 */
		resolvedExtension() {
			return (this.resolvedUrl || '').trim().replace(/^\./, '').toLowerCase()
		},
	},

	methods: {
		tt(key) {
			if (typeof t === 'function') {
				return t('mydash', key)
			}

			return key
		},

		/**
		 * Dispatch click based on actionType.
		 * All handlers are suppressed when isAdmin === true (REQ-LBN-001).
		 *
		 * @return {void}
		 */
		handleClick() {
			if (this.isAdmin === true) {
				return
			}

			if (this.isExecuting || this.creatingDoc) {
				return
			}

			const type = this.resolvedActionType

			if (type === 'external') {
				this.openExternal()
			} else if (type === 'internal') {
				this.invokeInternal()
			} else if (type === 'createFile') {
				this.openCreateModal()
			}
		},

		/**
		 * Open the URL in a new tab (REQ-LBN-001 external branch).
		 *
		 * @return {void}
		 */
		openExternal() {
			window.open(this.resolvedUrl, '_blank', 'noopener,noreferrer')
		},

		/**
		 * Look up the action ID (url field) in the registry and invoke it.
		 * Warns on miss but does not throw (REQ-LBN-005).
		 *
		 * @return {void}
		 */
		invokeInternal() {
			useInternalActions().invoke(this.resolvedUrl)
		},

		/**
		 * Open the inline filename-prompt modal (REQ-LBN-003).
		 *
		 * @return {void}
		 */
		openCreateModal() {
			this.docName = `document_${Date.now()}`
			this.createError = false
			this.showDocModal = true
		},

		/** Close the modal without creating a file. */
		cancelModal() {
			this.showDocModal = false
			this.createError = false
		},

		/**
		 * Submit the file-creation POST.
		 * On success opens the returned URL in a new tab and closes the modal.
		 * On error shows the translated toast error (REQ-LBN-003).
		 *
		 * @return {Promise<void>}
		 */
		async submitCreate() {
			const name = (this.docName || '').trim()
			if (name === '') {
				return
			}

			const ext = this.resolvedExtension
			const filename = ext ? `${name}.${ext}` : name

			this.creatingDoc = true
			this.createError = false

			try {
				const response = await fetch(
					'/index.php/apps/mydash/api/files/create',
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ filename, dir: '/', content: '' }),
					},
				)

				const data = await response.json()

				if (response.ok && data.status === 'success') {
					window.open(data.url, '_blank')
					this.showDocModal = false
				} else {
					this.createError = true
				}
			} catch {
				this.createError = true
			} finally {
				this.creatingDoc = false
			}
		},
	},
}
</script>

<style scoped>
.link-button-widget {
	box-sizing: border-box;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
}

.link-button-widget__btn {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 12px 20px;
	border: none;
	border-radius: 8px;
	font-size: 14px;
	font-weight: 600;
	text-align: center;
	transition: transform 0.15s ease, box-shadow 0.15s ease;
	width: 100%;
	height: 100%;
	box-sizing: border-box;
}

.link-button-widget__btn:not(:disabled):hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
}

.link-button-widget__btn:disabled {
	cursor: not-allowed;
}

.link-button-widget__icon {
	flex-shrink: 0;
}

.link-button-widget__label {
	word-break: break-word;
}

/* Modal backdrop */
.link-button-widget__modal-backdrop {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.45);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 9999;
}

.link-button-widget__modal {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 24px;
	min-width: 320px;
	max-width: 480px;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.28);
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.link-button-widget__modal-title {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
	color: var(--color-main-text);
}

.link-button-widget__modal-ext {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.link-button-widget__modal-input {
	width: 100%;
	box-sizing: border-box;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 14px;
}

.link-button-widget__modal-error {
	margin: 0;
	padding: 6px 10px;
	font-size: 12px;
	color: var(--color-error);
	background: rgba(192, 0, 0, 0.1);
	border-radius: 4px;
}

.link-button-widget__modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.link-button-widget__modal-cancel,
.link-button-widget__modal-create {
	padding: 8px 16px;
	border-radius: 4px;
	font-size: 14px;
	cursor: pointer;
	border: 1px solid var(--color-border);
	background: var(--color-background-secondary);
	color: var(--color-main-text);
	transition: background-color 0.15s;
}

.link-button-widget__modal-create {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-color: var(--color-primary);
}

.link-button-widget__modal-create:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
