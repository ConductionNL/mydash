<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="menu-form">
		<NcSelect
			:value="style"
			:options="styleOptions"
			:input-label="t('mydash', 'Menu Style')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('style', $event)" />

		<NcSelect
			v-if="style !== 'tree'"
			:value="orientation"
			:options="orientationOptions"
			:input-label="t('mydash', 'Orientation')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('orientation', $event)" />

		<NcSelect
			:value="activeItemHighlight"
			:options="highlightOptions"
			:input-label="t('mydash', 'Active Item Highlight')"
			:reduce="(option) => option.value"
			label="label"
			:clearable="false"
			@input="updateField('activeItemHighlight', $event)" />

		<label class="menu-form__toggle">
			<input
				type="checkbox"
				:checked="showIcons"
				@change="updateField('showIcons', $event.target.checked)">
			{{ t('mydash', 'Show Icons') }}
		</label>

		<label v-if="style === 'tree'" class="menu-form__toggle">
			<input
				type="checkbox"
				:checked="expandedByDefault"
				@change="updateField('expandedByDefault', $event.target.checked)">
			{{ t('mydash', 'Expanded by Default') }}
		</label>

		<div class="menu-form__items">
			<h4 class="menu-form__items-title">
				{{ t('mydash', 'Items') }}
			</h4>
			<MenuItemEditor
				v-for="(item, idx) in items"
				:key="`item-${idx}`"
				:item="item"
				:depth="1"
				:path="[idx]"
				@update-item="onUpdateItem"
				@remove-item="onRemoveItem"
				@add-child="onAddChild" />
			<button
				type="button"
				class="menu-form__add-top"
				@click="onAddTop">
				+ {{ t('mydash', 'Add Item') }}
			</button>
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@conduction/nextcloud-vue'
import MenuItemEditor from './MenuItemEditor.vue'

const VALID_STYLES = ['dropdown', 'megamenu', 'tree']
const VALID_ORIENTATIONS = ['horizontal', 'vertical']
const VALID_HIGHLIGHTS = ['background', 'underline', 'left-bar', 'none']

const DEFAULT_CONTENT = Object.freeze({
	items: [],
	style: 'dropdown',
	orientation: 'horizontal',
	showIcons: true,
	expandedByDefault: false,
	activeItemHighlight: 'underline',
})

/**
 * MenuForm — sub-form for the AddWidgetModal when creating or editing a
 * `menu` placement (REQ-MENU-009). Surfaces the five config dropdowns/
 * toggles and a recursive item editor that enforces the 3-level depth
 * cap by hiding the "Add Children" button on level-3 rows.
 *
 * `validate()` performs the same depth check the server-side
 * `MenuService` does so the user gets immediate feedback before the
 * round-trip.
 */
export default {
	name: 'MenuForm',

	components: {
		NcSelect,
		MenuItemEditor,
	},

	props: {
		/** Placement being edited, or `null` in create mode. */
		editingWidget: {
			type: Object,
			default: null,
		},
		/** Initial content from the registry default. */
		value: {
			type: Object,
			default: () => ({ ...DEFAULT_CONTENT }),
		},
	},

	emits: ['update:content'],

	data() {
		const initial = this.editingWidget?.content || this.value || {}
		return {
			items: Array.isArray(initial.items) ? this.cloneItems(initial.items) : [],
			style: VALID_STYLES.includes(initial.style) ? initial.style : DEFAULT_CONTENT.style,
			orientation: VALID_ORIENTATIONS.includes(initial.orientation) ? initial.orientation : DEFAULT_CONTENT.orientation,
			showIcons: typeof initial.showIcons === 'boolean' ? initial.showIcons : DEFAULT_CONTENT.showIcons,
			expandedByDefault: typeof initial.expandedByDefault === 'boolean' ? initial.expandedByDefault : DEFAULT_CONTENT.expandedByDefault,
			activeItemHighlight: VALID_HIGHLIGHTS.includes(initial.activeItemHighlight) ? initial.activeItemHighlight : DEFAULT_CONTENT.activeItemHighlight,
		}
	},

	computed: {
		/** @spec openspec/specs/menu-widget/spec.md */
		styleOptions() {
			return [
				{ value: 'dropdown', label: t('mydash', 'Dropdown') },
				{ value: 'megamenu', label: t('mydash', 'Megamenu') },
				{ value: 'tree', label: t('mydash', 'Tree') },
			]
		},
		/** @spec openspec/specs/menu-widget/spec.md */
		orientationOptions() {
			return [
				{ value: 'horizontal', label: t('mydash', 'Horizontal') },
				{ value: 'vertical', label: t('mydash', 'Vertical') },
			]
		},
		/** @spec openspec/specs/menu-widget/spec.md */
		highlightOptions() {
			return [
				{ value: 'underline', label: t('mydash', 'Underline') },
				{ value: 'background', label: t('mydash', 'Background') },
				{ value: 'left-bar', label: t('mydash', 'Left Bar') },
				{ value: 'none', label: t('mydash', 'None') },
			]
		},
		/** @spec openspec/specs/menu-widget/spec.md */
		assembledContent() {
			return {
				items: this.cloneItems(this.items),
				style: this.style,
				orientation: this.orientation,
				showIcons: this.showIcons,
				expandedByDefault: this.expandedByDefault,
				activeItemHighlight: this.activeItemHighlight,
			}
		},
	},

	methods: {
		/** @spec openspec/specs/menu-widget/spec.md */
		updateField(field, value) {
			this[field] = value
			this.emitChange()
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		emitChange() {
			this.$emit('update:content', this.assembledContent)
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		cloneItems(arr) {
			if (!Array.isArray(arr)) {
				return []
			}
			return arr.map((it) => ({
				label: typeof it?.label === 'string' ? it.label : '',
				url: typeof it?.url === 'string' ? it.url : '',
				icon: typeof it?.icon === 'string' ? it.icon : '',
				children: this.cloneItems(it?.children || []),
			}))
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		setItemAtPath(items, path, mutator) {
			if (path.length === 0) {
				return
			}
			const head = path[0]
			if (path.length === 1) {
				mutator(items, head)
				return
			}
			if (items[head] && Array.isArray(items[head].children)) {
				this.setItemAtPath(items[head].children, path.slice(1), mutator)
			}
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onUpdateItem({ path, item }) {
			this.setItemAtPath(this.items, path, (arr, idx) => {
				const existing = arr[idx]
				arr.splice(idx, 1, {
					...existing,
					label: typeof item.label === 'string' ? item.label : existing.label,
					url: typeof item.url === 'string' ? item.url : existing.url,
					icon: typeof item.icon === 'string' ? item.icon : existing.icon,
					children: Array.isArray(existing?.children) ? existing.children : [],
				})
			})
			this.emitChange()
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onRemoveItem({ path }) {
			this.setItemAtPath(this.items, path, (arr, idx) => {
				arr.splice(idx, 1)
			})
			this.emitChange()
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onAddChild({ path }) {
			this.setItemAtPath(this.items, path, (arr, idx) => {
				const target = arr[idx]
				if (!Array.isArray(target.children)) {
					target.children = []
				}
				target.children.push({ label: '', url: '', icon: '', children: [] })
			})
			this.emitChange()
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onAddTop() {
			this.items.push({ label: '', url: '', icon: '', children: [] })
			this.emitChange()
		},

		/**
		 * REQ-MENU-009: client-side mirror of the server-side depth
		 * cap enforced by `MenuService::validateMenuItems`.
		 *
		 * @return {string[]} validation errors (empty when valid)
		 */
		/** @spec openspec/specs/menu-widget/spec.md */
		validate() {
			const errors = []
			const walk = (list, depth) => {
				if (depth > 3) {
					errors.push(t('mydash', 'Menu items can nest at most 3 levels deep'))
					return
				}
				list.forEach((it) => {
					if (Array.isArray(it.children) && it.children.length > 0) {
						walk(it.children, depth + 1)
					}
				})
			}
			walk(this.items, 1)
			// Dedupe.
			return Array.from(new Set(errors))
		},
	},
}
</script>

<style scoped>
.menu-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.menu-form__toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
}

.menu-form__items {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.menu-form__items-title {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
}

.menu-form__add-top {
	align-self: flex-start;
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 13px;
}
</style>
