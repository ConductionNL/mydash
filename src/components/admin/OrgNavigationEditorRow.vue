<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<li class="org-nav-row" :data-test-id="node.id">
		<div class="org-nav-row__row" :style="{ paddingLeft: indentPx }">
			<span class="org-nav-row__handle" aria-hidden="true">⋮⋮</span>
			<input
				v-model="localLabel"
				type="text"
				class="org-nav-row__label"
				:aria-label="t('mydash', 'Label')"
				@input="emitPatch({ label: localLabel })">
			<input
				v-model="localUrl"
				type="text"
				class="org-nav-row__url"
				:placeholder="t('mydash', 'URL (leave empty for section)')"
				:aria-label="t('mydash', 'URL')"
				@input="emitPatch({ url: localUrl || null })">
			<input
				v-model="localIcon"
				type="text"
				class="org-nav-row__icon"
				:placeholder="t('mydash', 'Icon')"
				:aria-label="t('mydash', 'Icon')"
				@input="emitPatch({ icon: localIcon || null })">
			<label class="org-nav-row__checkbox">
				<input
					v-model="localOpenInNewTab"
					type="checkbox"
					@change="emitPatch({ openInNewTab: localOpenInNewTab })">
				{{ t('mydash', 'New tab') }}
			</label>
			<button
				type="button"
				class="org-nav-row__btn"
				:disabled="!canMoveUp"
				:aria-label="t('mydash', 'Move up')"
				@click="$emit('move-up', { siblings, index })">
				↑
			</button>
			<button
				type="button"
				class="org-nav-row__btn"
				:disabled="!canMoveDown"
				:aria-label="t('mydash', 'Move down')"
				@click="$emit('move-down', { siblings, index })">
				↓
			</button>
			<button
				type="button"
				class="org-nav-row__btn"
				:disabled="!canAddChild"
				:title="canAddChild ? '' : t('mydash', 'Tree depth cannot exceed 3 levels')"
				@click="$emit('add-child', { parent: node, kind: 'link' })">
				{{ t('mydash', 'Add child') }}
			</button>
			<button
				type="button"
				class="org-nav-row__btn org-nav-row__btn--danger"
				@click="$emit('delete', { siblings, index })">
				{{ t('mydash', 'Delete') }}
			</button>
		</div>
		<div class="org-nav-row__visibility" :style="{ paddingLeft: indentPx }">
			<label class="org-nav-row__checkbox">
				<input
					type="checkbox"
					:checked="localVisibility === null"
					@change="onToggleVisibilityAll($event.target.checked)">
				{{ t('mydash', 'Visible to everyone') }}
			</label>
			<select
				v-if="localVisibility !== null && groups.length > 0"
				v-model="localVisibility"
				multiple
				class="org-nav-row__groups"
				@change="emitPatch({ groupVisibility: localVisibility })">
				<option v-for="group in groups" :key="group.id" :value="group.id">
					{{ group.displayName || group.id }}
				</option>
			</select>
			<input
				v-else-if="localVisibility !== null"
				v-model="freeTextGroups"
				type="text"
				class="org-nav-row__groups-text"
				:placeholder="t('mydash', 'Group ids, comma separated')"
				@input="onFreeTextGroupsInput">
		</div>
		<ul v-if="hasChildren" class="org-nav-row__children">
			<OrgNavigationEditorRow
				v-for="(child, idx) in node.children"
				:key="child.id"
				:node="child"
				:level="level + 1"
				:index="idx"
				:siblings="node.children"
				:max-depth="maxDepth"
				:groups="groups"
				@update="(payload) => $emit('update', payload)"
				@delete="(payload) => $emit('delete', payload)"
				@move-up="(payload) => $emit('move-up', payload)"
				@move-down="(payload) => $emit('move-down', payload)"
				@add-child="(payload) => $emit('add-child', payload)" />
		</ul>
	</li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * OrgNavigationEditorRow — single row inside the admin tree editor.
 *
 * Supports inline editing of label / url / icon / openInNewTab and a
 * group-visibility selector. Reorder is wired through up/down buttons
 * (a future change can layer drag-and-drop on top without touching
 * the controlled-component contract).
 *
 * Depth enforcement (REQ-ONAV-007): the "Add child" button is
 * disabled when adding would exceed `maxDepth`, with a tooltip
 * explaining why.
 */
export default {
	name: 'OrgNavigationEditorRow',

	props: {
		node: {
			type: Object,
			required: true,
		},
		level: {
			type: Number,
			default: 1,
		},
		index: {
			type: Number,
			required: true,
		},
		siblings: {
			type: Array,
			required: true,
		},
		maxDepth: {
			type: Number,
			default: 3,
		},
		groups: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update', 'delete', 'move-up', 'move-down', 'add-child'],

	data() {
		return {
			localLabel: this.node.label || '',
			localUrl: this.node.url || '',
			localIcon: this.node.icon || '',
			localOpenInNewTab: !!this.node.openInNewTab,
			localVisibility: Array.isArray(this.node.groupVisibility)
				? [...this.node.groupVisibility]
				: null,
			freeTextGroups: Array.isArray(this.node.groupVisibility)
				? this.node.groupVisibility.join(', ')
				: '',
		}
	},

	computed: {
		hasChildren() {
			return Array.isArray(this.node.children) && this.node.children.length > 0
		},

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		canAddChild() {
			return this.level < this.maxDepth
		},

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		canMoveUp() {
			return this.index > 0
		},

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		canMoveDown() {
			return this.index < this.siblings.length - 1
		},

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		indentPx() {
			return ((this.level - 1) * 16) + 'px'
		},
	},

	methods: {
		t,

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		emitPatch(patch) {
			this.$emit('update', { node: this.node, patch })
		},

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		onToggleVisibilityAll(allVisible) {
			if (allVisible) {
				this.localVisibility = null
				this.emitPatch({ groupVisibility: null })
			} else {
				this.localVisibility = []
				this.emitPatch({ groupVisibility: [] })
			}
		},

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		onFreeTextGroupsInput() {
			const ids = (this.freeTextGroups || '')
				.split(',')
				.map((s) => s.trim())
				.filter((s) => s.length > 0)
			this.localVisibility = ids
			this.emitPatch({ groupVisibility: ids.length > 0 ? ids : null })
		},
	},
}
</script>

<style scoped>
.org-nav-row {
	list-style: none;
	border-bottom: 1px solid var(--color-border, #eee);
	padding: 8px 0;
}

.org-nav-row__row {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}

.org-nav-row__handle {
	color: var(--color-text-maxcontrast, #888);
	cursor: grab;
	font-size: 1.2em;
}

.org-nav-row__label {
	flex: 1;
	min-width: 120px;
}

.org-nav-row__url,
.org-nav-row__icon {
	flex: 1;
	min-width: 100px;
}

.org-nav-row__btn {
	background: transparent;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: 4px;
	padding: 4px 8px;
	cursor: pointer;
	font: inherit;
}

.org-nav-row__btn[disabled] {
	opacity: 0.5;
	cursor: not-allowed;
}

.org-nav-row__btn--danger {
	color: var(--color-error, #d32f2f);
}

.org-nav-row__visibility {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 4px;
	flex-wrap: wrap;
}

.org-nav-row__groups,
.org-nav-row__groups-text {
	min-width: 200px;
}

.org-nav-row__children {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
}

.org-nav-row__checkbox {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}
</style>
