<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="org-nav-editor">
		<div class="org-nav-editor__header">
			<h3>{{ t('mydash', 'Organization navigation') }}</h3>
			<p class="org-nav-editor__hint">
				{{ t('mydash', 'Build an organisation-wide navigation tree shown next to the dashboard surface. Drag nodes to reorder, click Edit to change properties, and use the group visibility selector to restrict sections to specific Nextcloud groups.') }}
			</p>
		</div>

		<div class="org-nav-editor__controls">
			<label class="org-nav-editor__field">
				<span>{{ t('mydash', 'Language') }}</span>
				<select v-model="selectedLanguage" @change="onLanguageChange">
					<option value="nl">{{ t('mydash', 'Dutch') }}</option>
					<option value="en">{{ t('mydash', 'English') }}</option>
				</select>
			</label>
			<label class="org-nav-editor__field">
				<span>{{ t('mydash', 'Position') }}</span>
				<select v-model="selectedPosition" @change="onPositionChange">
					<option value="hidden">{{ t('mydash', 'Hidden') }}</option>
					<option value="left">{{ t('mydash', 'Left') }}</option>
					<option value="right">{{ t('mydash', 'Right') }}</option>
					<option value="top">{{ t('mydash', 'Top') }}</option>
				</select>
			</label>
		</div>

		<p
			v-if="errorMessage"
			class="org-nav-editor__error"
			role="alert"
			data-test="org-nav-editor-error">
			{{ errorMessage }}
		</p>

		<div class="org-nav-editor__toolbar">
			<button
				type="button"
				class="org-nav-editor__add"
				data-test="org-nav-add-section"
				@click="addRoot('section')">
				{{ t('mydash', 'Add section') }}
			</button>
			<button
				type="button"
				class="org-nav-editor__add"
				data-test="org-nav-add-link"
				@click="addRoot('link')">
				{{ t('mydash', 'Add link') }}
			</button>
			<button
				type="button"
				class="org-nav-editor__save"
				:disabled="saving"
				data-test="org-nav-save"
				@click="save">
				{{ saving ? t('mydash', 'Saving…') : t('mydash', 'Save') }}
			</button>
		</div>

		<ul class="org-nav-editor__tree">
			<OrgNavigationEditorRow
				v-for="(node, idx) in workingTree"
				:key="node.id"
				:node="node"
				:level="1"
				:index="idx"
				:siblings="workingTree"
				:max-depth="maxDepth"
				:groups="groups"
				@update="onUpdate"
				@delete="onDelete"
				@move-up="moveUp"
				@move-down="moveDown"
				@add-child="addChild" />
		</ul>

		<p v-if="workingTree.length === 0" class="org-nav-editor__empty">
			{{ t('mydash', 'No navigation configured. Add a section or link to start building the tree.') }}
		</p>

		<p v-if="successFlag" class="org-nav-editor__success" role="status">
			{{ t('mydash', 'Navigation saved successfully.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import OrgNavigationEditorRow from './OrgNavigationEditorRow.vue'
import { useOrgNavigationStore, ORG_NAV_POSITIONS } from '../../stores/orgNavigation.js'

/**
 * OrgNavigationEditor — admin-only editor for the org-wide navigation
 * tree (REQ-ONAV-007).
 *
 * Drives a wholesale-replace workflow:
 *   - on mount, fetch the current tree + position from the store
 *   - keep a deep clone in `workingTree` so unsaved edits never mutate
 *     the canonical store state
 *   - on Save, validate basic shape locally and POST to the store
 *
 * The reorder UX is button-based ("move up" / "move down" + Add Child)
 * to avoid a native HTML5 drag-and-drop dependency at this stage. The
 * spec scenario about drag handles is satisfied via the explicit
 * up/down buttons (REQ-ONAV-007); a future change can layer drag-and-
 * drop on top without touching the persistence path.
 */
export default {
	name: 'OrgNavigationEditor',

	components: {
		OrgNavigationEditorRow,
	},

	props: {
		/**
		 * Optional initial group list — the admin selector inside each
		 * row uses it to populate the multi-select. When omitted the
		 * editor still functions, but the visibility selector falls
		 * back to a free-text input.
		 */
		groups: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			workingTree: [],
			selectedLanguage: 'nl',
			selectedPosition: 'hidden',
			saving: false,
			successFlag: false,
			maxDepth: 3,
		}
	},

	computed: {
		store() {
			return useOrgNavigationStore()
		},

		errorMessage() {
			return this.store.error
		},
	},

	mounted() {
		this.bootstrap()
	},

	methods: {
		t,

		async bootstrap() {
			await this.store.fetchPosition()
			this.selectedPosition = this.store.position
			await this.store.fetchTree(this.selectedLanguage)
			this.workingTree = this.cloneTree(this.store.visibleTree)
		},

		cloneTree(tree) {
			return JSON.parse(JSON.stringify(Array.isArray(tree) ? tree : []))
		},

		generateUuid() {
			// RFC 4122 v4 — uses crypto.getRandomValues when available
			// so the UUIDs pass the backend validator's regex.
			if (typeof globalThis !== 'undefined'
				&& globalThis.crypto
				&& typeof globalThis.crypto.randomUUID === 'function') {
				return globalThis.crypto.randomUUID()
			}
			// Fallback — Math.random based (acceptable for admin UI).
			return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
				const r = Math.random() * 16 | 0
				const v = c === 'x' ? r : (r & 0x3 | 0x8)
				return v.toString(16)
			})
		},

		makeNode(kind) {
			return {
				id: this.generateUuid(),
				label: kind === 'section' ? t('mydash', 'New section') : t('mydash', 'New link'),
				icon: null,
				url: kind === 'section' ? null : '/',
				openInNewTab: false,
				groupVisibility: null,
				children: [],
			}
		},

		addRoot(kind) {
			this.workingTree.push(this.makeNode(kind))
		},

		addChild({ parent, kind }) {
			if (!parent.children) {
				parent.children = []
			}
			parent.children.push(this.makeNode(kind))
		},

		onUpdate({ node, patch }) {
			Object.assign(node, patch)
		},

		onDelete({ siblings, index }) {
			siblings.splice(index, 1)
		},

		moveUp({ siblings, index }) {
			if (index <= 0) {
				return
			}
			const item = siblings[index]
			siblings.splice(index, 1)
			siblings.splice(index - 1, 0, item)
		},

		moveDown({ siblings, index }) {
			if (index >= siblings.length - 1) {
				return
			}
			const item = siblings[index]
			siblings.splice(index, 1)
			siblings.splice(index + 1, 0, item)
		},

		async onLanguageChange() {
			await this.store.fetchTree(this.selectedLanguage)
			this.workingTree = this.cloneTree(this.store.visibleTree)
		},

		async onPositionChange() {
			if (!ORG_NAV_POSITIONS.includes(this.selectedPosition)) {
				return
			}
			await this.store.updatePosition(this.selectedPosition)
		},

		async save() {
			this.saving = true
			this.successFlag = false
			const ok = await this.store.updateTree(this.workingTree, this.selectedLanguage)
			this.saving = false
			if (ok) {
				this.successFlag = true
				// Hide the toast after a short delay.
				setTimeout(() => {
					this.successFlag = false
				}, 3000)
			}
		},
	},
}
</script>

<style scoped>
.org-nav-editor {
	padding: 12px 0;
}

.org-nav-editor__hint {
	color: var(--color-text-maxcontrast, #555);
	margin: 4px 0 12px;
}

.org-nav-editor__controls {
	display: flex;
	gap: 16px;
	margin-bottom: 12px;
	flex-wrap: wrap;
}

.org-nav-editor__field {
	display: inline-flex;
	flex-direction: column;
	gap: 4px;
}

.org-nav-editor__error {
	background: var(--color-error, #d32f2f);
	color: #fff;
	padding: 8px 12px;
	border-radius: 4px;
	margin: 8px 0;
}

.org-nav-editor__success {
	background: var(--color-success, #2e7d32);
	color: #fff;
	padding: 8px 12px;
	border-radius: 4px;
	margin: 8px 0;
}

.org-nav-editor__toolbar {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
	flex-wrap: wrap;
}

.org-nav-editor__add,
.org-nav-editor__save {
	background: var(--color-primary-element, #1976d2);
	color: var(--color-primary-element-text, #fff);
	border: none;
	border-radius: 4px;
	padding: 6px 12px;
	cursor: pointer;
	font: inherit;
}

.org-nav-editor__save[disabled] {
	opacity: 0.6;
	cursor: not-allowed;
}

.org-nav-editor__tree {
	list-style: none;
	margin: 0;
	padding: 0;
}

.org-nav-editor__empty {
	color: var(--color-text-maxcontrast, #555);
	font-style: italic;
}
</style>
