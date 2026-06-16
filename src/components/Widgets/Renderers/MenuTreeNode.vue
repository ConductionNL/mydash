<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<li
		class="menu-tree-node"
		:class="`menu-tree-node--depth-${depth}`"
		role="treeitem"
		:aria-expanded="hasChildren ? String(expanded) : null">
		<div
			class="menu-tree-node__row"
			:class="rowClass"
			:style="rowStyle">
			<button
				v-if="hasChildren"
				type="button"
				class="menu-tree-node__caret"
				:class="{ 'menu-tree-node__caret--open': expanded }"
				:aria-label="expanded ? t('mydash', 'Collapse') : t('mydash', 'Expand')"
				@click.stop="toggle">
				<span aria-hidden="true">{{ expanded ? '▾' : '▸' }}</span>
			</button>
			<span v-else class="menu-tree-node__caret-spacer" />
			<button
				type="button"
				class="menu-tree-node__label-button"
				@click="onLabelClick"
				@keydown.right.prevent="onArrowRight"
				@keydown.left.prevent="onArrowLeft">
				<span v-if="showIcons" class="menu-tree-node__icon" :class="{ 'menu-tree-node__icon--hidden': !item.icon }">
					<MenuItemIcon v-if="item.icon" :icon="item.icon" />
				</span>
				<span class="menu-tree-node__label">{{ item.label }}</span>
			</button>
		</div>
		<ul
			v-if="hasChildren && expanded"
			class="menu-tree-node__children"
			role="group">
			<MenuTreeNode
				v-for="(child, idx) in item.children"
				:key="`tree-child-${idx}`"
				:item="child"
				:depth="depth + 1"
				:show-icons="showIcons"
				:expanded-by-default="expandedByDefault"
				:active-path="activePath"
				:active-leaf-key="activeLeafKey"
				:current-key="`${currentKey}.${idx}`"
				:active-highlight="activeHighlight"
				@navigate="$emit('navigate', $event)" />
		</ul>
	</li>
</template>

<script>
import MenuItemIcon from './MenuItemIcon.vue'

/**
 * MenuTreeNode — recursive tree-style row used by `MenuWidget` when
 * `style === 'tree'` (REQ-MENU-005). Caret toggles children; label
 * routes to the parent's navigation handler (REQ-MENU-008).
 *
 * Active-state classes come from the dotted-key path map computed
 * once in the parent so every node sees a consistent view of the
 * "current path" without re-walking the tree.
 */
export default {
	name: 'MenuTreeNode',

	components: {
		MenuItemIcon,
	},

	props: {
		/** Single menu item: `{label, url, icon, children}`. */
		item: {
			type: Object,
			required: true,
		},
		/** 1-indexed depth — drives indentation and the caret padding. */
		depth: {
			type: Number,
			default: 1,
		},
		/** When false, all icon slots collapse to invisible spacers. */
		showIcons: {
			type: Boolean,
			default: true,
		},
		/** REQ-MENU-005 — when true, every node mounts already expanded. */
		expandedByDefault: {
			type: Boolean,
			default: false,
		},
		/** Dotted-key map of `'active' | 'in-path'` flags. */
		activePath: {
			type: Object,
			default: () => ({}),
		},
		/** Key of the deepest active leaf; used to disambiguate ties. */
		activeLeafKey: {
			type: String,
			default: null,
		},
		/** Key of THIS node within the parent's path map. */
		currentKey: {
			type: String,
			required: true,
		},
		/** Highlight style — drives the row class so CSS handles the look. */
		activeHighlight: {
			type: String,
			default: 'underline',
		},
	},

	emits: ['navigate'],

	data() {
		return {
			expanded: this.expandedByDefault,
		}
	},

	computed: {
		hasChildren() {
			return Array.isArray(this.item?.children) && this.item.children.length > 0
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		rowState() {
			if (this.currentKey === this.activeLeafKey) {
				return 'active'
			}
			return this.activePath[this.currentKey] || ''
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		rowClass() {
			if (this.rowState === 'active') {
				return 'menu-widget__item--active'
			}
			if (this.rowState === 'in-path') {
				return 'menu-widget__item--in-path'
			}
			return ''
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		rowStyle() {
			return { paddingLeft: `${(this.depth - 1) * 20}px` }
		},
	},

	methods: {
		/** @spec openspec/specs/menu-widget/spec.md */
		toggle() {
			this.expanded = !this.expanded
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onLabelClick() {
			if (typeof this.item?.url === 'string' && this.item.url !== '') {
				this.$emit('navigate', this.item)
				return
			}
			// Bare branch labels with no URL just toggle children.
			if (this.hasChildren) {
				this.toggle()
			}
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onArrowRight() {
			if (this.hasChildren && !this.expanded) {
				this.expanded = true
			}
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onArrowLeft() {
			if (this.hasChildren && this.expanded) {
				this.expanded = false
			}
		},
	},
}
</script>

<style scoped>
.menu-tree-node {
	list-style: none;
}

.menu-tree-node__row {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 6px;
	border-radius: var(--border-radius);
}

.menu-tree-node__row:hover {
	background: var(--color-background-hover);
}

.menu-tree-node__caret,
.menu-tree-node__caret-spacer {
	width: 18px;
	height: 18px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: transparent;
	border: none;
	cursor: pointer;
	color: inherit;
	font-size: 12px;
	padding: 0;
}

.menu-tree-node__caret-spacer {
	cursor: default;
}

.menu-tree-node__label-button {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background: transparent;
	border: none;
	cursor: pointer;
	color: inherit;
	font-size: 14px;
	text-align: left;
	padding: 2px 4px;
	flex: 1;
}

.menu-tree-node__label-button:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: 1px;
}

.menu-tree-node__icon {
	display: inline-flex;
	width: 20px;
	height: 20px;
}

.menu-tree-node__icon--hidden {
	visibility: hidden;
}

.menu-tree-node__children {
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
