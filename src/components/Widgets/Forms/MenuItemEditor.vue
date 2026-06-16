<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="menu-item-editor" :style="rowStyle">
		<div class="menu-item-editor__row">
			<span class="menu-item-editor__depth">
				{{ depthLabel }}
			</span>
			<input
				type="text"
				class="menu-item-editor__input"
				:value="item.label"
				:placeholder="t('mydash', 'Label')"
				@input="emitFieldChange('label', $event.target.value)">
			<input
				type="text"
				class="menu-item-editor__input"
				:value="item.url"
				:placeholder="t('mydash', 'URL')"
				@input="emitFieldChange('url', $event.target.value)">
			<input
				type="text"
				class="menu-item-editor__input menu-item-editor__input--icon"
				:value="item.icon"
				:placeholder="t('mydash', 'Icon')"
				@input="emitFieldChange('icon', $event.target.value)">
			<button
				v-if="canAddChildren"
				type="button"
				class="menu-item-editor__btn"
				@click="$emit('add-child', { path })">
				+ {{ t('mydash', 'Add Children') }}
			</button>
			<button
				type="button"
				class="menu-item-editor__btn menu-item-editor__btn--danger"
				:title="t('mydash', 'Remove Item')"
				@click="$emit('remove-item', { path })">
				✕
			</button>
		</div>
		<div v-if="hasChildren" class="menu-item-editor__children">
			<MenuItemEditor
				v-for="(child, idx) in item.children"
				:key="`child-${idx}`"
				:item="child"
				:depth="depth + 1"
				:path="[...path, idx]"
				@update-item="$emit('update-item', $event)"
				@remove-item="$emit('remove-item', $event)"
				@add-child="$emit('add-child', $event)" />
		</div>
	</div>
</template>

<script>
/**
 * MenuItemEditor — recursive row editor used by `MenuForm`. Inline
 * label/url/icon inputs plus per-row "Add Children" and "Remove"
 * actions. The "Add Children" button is hidden once the row reaches
 * depth 3 so the user cannot create a tree the server would reject
 * (REQ-MENU-009 scenario "Depth validation prevents overly nested
 * drops").
 *
 * Field changes bubble up via the `update-item` event with the row's
 * `path` (an index list) so the parent form can mutate the canonical
 * `items` array in one place.
 */
export default {
	name: 'MenuItemEditor',

	props: {
		/** Single menu item being edited. */
		item: {
			type: Object,
			required: true,
		},
		/** 1-indexed depth — drives the indent and the depth label. */
		depth: {
			type: Number,
			default: 1,
		},
		/** Path of indices from the root to this item (e.g. `[0, 2]`). */
		path: {
			type: Array,
			required: true,
		},
	},

	emits: ['update-item', 'remove-item', 'add-child'],

	computed: {
		hasChildren() {
			return Array.isArray(this.item?.children) && this.item.children.length > 0
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		canAddChildren() {
			return this.depth < 3
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		depthLabel() {
			if (this.depth === 1) {
				return t('mydash', 'Level 1')
			}
			if (this.depth === 2) {
				return t('mydash', 'Level 2')
			}
			return t('mydash', 'Level 3 - max reached')
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		rowStyle() {
			return { paddingLeft: `${(this.depth - 1) * 16}px` }
		},
	},

	methods: {
		/** @spec openspec/specs/menu-widget/spec.md */
		emitFieldChange(field, value) {
			const updated = { ...this.item, [field]: value }
			this.$emit('update-item', { path: this.path, item: updated })
		},
	},
}
</script>

<style scoped>
.menu-item-editor {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.menu-item-editor__row {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.menu-item-editor__depth {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	min-width: 80px;
}

.menu-item-editor__input {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	flex: 1;
	min-width: 60px;
}

.menu-item-editor__input--icon {
	flex: 0 0 90px;
}

.menu-item-editor__btn {
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 12px;
	cursor: pointer;
}

.menu-item-editor__btn--danger {
	color: var(--color-error, #c00);
}

.menu-item-editor__children {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
