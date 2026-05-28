<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="menu-widget"
		:class="rootClasses"
		@keydown.esc.stop="closeAll">
		<!-- Empty state -->
		<div
			v-if="isEmpty"
			class="menu-widget__empty"
			:class="{ 'menu-widget__empty--clickable': isInEditMode }"
			:role="isInEditMode ? 'button' : null"
			:tabindex="isInEditMode ? 0 : -1"
			@click="onEmptyStateClick"
			@keyup.enter="onEmptyStateClick"
			@keyup.space="onEmptyStateClick">
			<span class="menu-widget__empty-icon" aria-hidden="true">⚙</span>
			<span>{{ t('mydash', 'No menu items yet — click the gear icon to add some.') }}</span>
		</div>

		<!-- Tree style -->
		<ul
			v-else-if="effectiveStyle === 'tree'"
			class="menu-widget__tree"
			role="tree">
			<MenuTreeNode
				v-for="(item, idx) in items"
				:key="`tree-${idx}`"
				:item="item"
				:depth="1"
				:show-icons="showIcons"
				:expanded-by-default="expandedByDefault"
				:active-path="activePath"
				:active-leaf-key="activeLeafKey"
				:current-key="`${idx}`"
				:active-highlight="activeItemHighlight"
				@navigate="onNavigate" />
		</ul>

		<!-- Megamenu style -->
		<div
			v-else-if="effectiveStyle === 'megamenu'"
			class="menu-widget__megamenu">
			<ul
				class="menu-widget__bar"
				role="menubar">
				<li
					v-for="(item, idx) in items"
					:key="`mega-top-${idx}`"
					role="none"
					class="menu-widget__bar-item">
					<button
						:ref="(el) => setTopRef(el, idx)"
						type="button"
						role="menuitem"
						class="menu-widget__bar-button"
						:class="topItemClass(`${idx}`)"
						:aria-haspopup="hasChildren(item) ? 'menu' : null"
						:aria-expanded="megaOpenIndex === idx ? 'true' : 'false'"
						@click="onMegaTopClick(idx, item)"
						@keydown="onMegaTopKey($event, idx, item)">
						<span v-if="showIcons" class="menu-widget__icon" :class="{ 'menu-widget__icon--hidden': !item.icon }">
							<MenuItemIcon v-if="item.icon" :icon="item.icon" />
						</span>
						<span class="menu-widget__label">{{ item.label }}</span>
					</button>
				</li>
			</ul>
			<div
				v-if="megaOpenIndex !== null && hasChildren(items[megaOpenIndex])"
				class="menu-widget__mega-panel"
				role="menu">
				<div
					v-for="(child, childIdx) in items[megaOpenIndex].children"
					:key="`mega-group-${childIdx}`"
					class="menu-widget__mega-group">
					<button
						type="button"
						role="menuitem"
						class="menu-widget__mega-group-title"
						:class="topItemClass(`${megaOpenIndex}.${childIdx}`)"
						@click="onNavigate(child)">
						<span v-if="showIcons" class="menu-widget__icon" :class="{ 'menu-widget__icon--hidden': !child.icon }">
							<MenuItemIcon v-if="child.icon" :icon="child.icon" />
						</span>
						<span class="menu-widget__label">{{ child.label }}</span>
					</button>
					<ul
						v-if="hasChildren(child)"
						class="menu-widget__mega-leaves">
						<li
							v-for="(leaf, leafIdx) in child.children"
							:key="`mega-leaf-${leafIdx}`"
							role="none">
							<button
								type="button"
								role="menuitem"
								class="menu-widget__mega-leaf"
								:class="topItemClass(`${megaOpenIndex}.${childIdx}.${leafIdx}`)"
								@click="onNavigate(leaf)">
								<span v-if="showIcons" class="menu-widget__icon" :class="{ 'menu-widget__icon--hidden': !leaf.icon }">
									<MenuItemIcon v-if="leaf.icon" :icon="leaf.icon" />
								</span>
								<span class="menu-widget__label">{{ leaf.label }}</span>
							</button>
						</li>
					</ul>
				</div>
			</div>
		</div>

		<!-- Dropdown style (default) -->
		<ul
			v-else
			class="menu-widget__bar"
			:class="{ 'menu-widget__bar--vertical': orientation === 'vertical' }"
			role="menubar">
			<li
				v-for="(item, idx) in items"
				:key="`drop-top-${idx}`"
				role="none"
				class="menu-widget__bar-item">
				<button
					:ref="(el) => setTopRef(el, idx)"
					type="button"
					role="menuitem"
					class="menu-widget__bar-button"
					:class="topItemClass(`${idx}`)"
					:aria-haspopup="hasChildren(item) ? 'menu' : null"
					:aria-expanded="dropOpenIndex === idx ? 'true' : 'false'"
					@click="onDropdownTopClick(idx, item)"
					@keydown.tab="closeAll"
					@keydown.enter.prevent="onDropdownTopClick(idx, item)"
					@keydown.space.prevent="onDropdownTopClick(idx, item)"
					@keydown.down.prevent="openDropdown(idx)">
					<span v-if="showIcons" class="menu-widget__icon" :class="{ 'menu-widget__icon--hidden': !item.icon }">
						<MenuItemIcon v-if="item.icon" :icon="item.icon" />
					</span>
					<span class="menu-widget__label">{{ item.label }}</span>
					<span v-if="hasChildren(item)" class="menu-widget__caret" aria-hidden="true">▾</span>
				</button>
				<ul
					v-if="dropOpenIndex === idx && hasChildren(item)"
					class="menu-widget__dropdown"
					role="menu">
					<li
						v-for="(child, childIdx) in item.children"
						:key="`drop-child-${childIdx}`"
						role="none"
						class="menu-widget__dropdown-item-wrap"
						@mouseenter="hasChildren(child) && (flyoutOpenKey = `${idx}.${childIdx}`)">
						<button
							type="button"
							role="menuitem"
							class="menu-widget__dropdown-item"
							:class="topItemClass(`${idx}.${childIdx}`)"
							@click="onNavigate(child)">
							<span v-if="showIcons" class="menu-widget__icon" :class="{ 'menu-widget__icon--hidden': !child.icon }">
								<MenuItemIcon v-if="child.icon" :icon="child.icon" />
							</span>
							<span class="menu-widget__label">{{ child.label }}</span>
							<span v-if="hasChildren(child)" class="menu-widget__caret menu-widget__caret--right" aria-hidden="true">▸</span>
						</button>
						<ul
							v-if="flyoutOpenKey === `${idx}.${childIdx}` && hasChildren(child)"
							class="menu-widget__flyout"
							role="menu">
							<li
								v-for="(leaf, leafIdx) in child.children"
								:key="`drop-leaf-${leafIdx}`"
								role="none">
								<button
									type="button"
									role="menuitem"
									class="menu-widget__dropdown-item"
									:class="topItemClass(`${idx}.${childIdx}.${leafIdx}`)"
									@click="onNavigate(leaf)">
									<span v-if="showIcons" class="menu-widget__icon" :class="{ 'menu-widget__icon--hidden': !leaf.icon }">
										<MenuItemIcon v-if="leaf.icon" :icon="leaf.icon" />
									</span>
									<span class="menu-widget__label">{{ leaf.label }}</span>
								</button>
							</li>
						</ul>
					</li>
				</ul>
			</li>
		</ul>
	</div>
</template>

<script>
import MenuTreeNode from './MenuTreeNode.vue'
import MenuItemIcon from './MenuItemIcon.vue'
import { isActiveItem, computeActivePath } from '../../../utils/menuActive.js'

const VALID_STYLES = ['dropdown', 'megamenu', 'tree']
const VALID_ORIENTATIONS = ['horizontal', 'vertical']
const VALID_HIGHLIGHTS = ['background', 'underline', 'left-bar', 'none']

/**
 * MenuWidget — renders a hierarchical navigation widget with three visual
 * styles (dropdown, megamenu, tree) and active-item highlighting based on
 * `window.location.pathname` (REQ-MENU-001..011).
 *
 * Active-item detection is done once on mount via {@link computeActivePath}
 * and re-runs whenever `items` or the URL changes; ancestors of the active
 * leaf are flagged as "in current path" so the configured highlight style
 * (`underline`, `background`, `left-bar`, `none`) renders consistently.
 *
 * Keyboard navigation conforms to the WAI-ARIA Menu/Menubar pattern: Tab
 * closes any open dropdown and moves focus on; Enter/Space opens the
 * dropdown; Esc closes everything; ArrowDown opens. The tree style
 * additionally honours ArrowRight/Left for expand/collapse.
 */
export default {
	name: 'MenuWidget',

	components: {
		MenuTreeNode,
		MenuItemIcon,
	},

	props: {
		/**
		 * Persisted widget content. Shape: `{items, style, orientation,
		 * showIcons, expandedByDefault, activeItemHighlight}`.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Whether the current user is an admin. Combined with `canEdit`
		 * to enable the empty-state gear-icon click target.
		 */
		isAdmin: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether the surrounding shell is in edit mode.
		 */
		canEdit: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['edit-request'],

	data() {
		return {
			dropOpenIndex: null,
			flyoutOpenKey: null,
			megaOpenIndex: null,
			currentLocation: this.readLocation(),
			topRefs: [],
		}
	},

	computed: {
		/** @spec openspec/specs/menu-widget/spec.md */
		items() {
			const arr = this.content?.items
			return Array.isArray(arr) ? arr : []
		},

		isEmpty() {
			return this.items.length === 0
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		effectiveStyle() {
			const s = this.content?.style
			return VALID_STYLES.includes(s) ? s : 'dropdown'
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		orientation() {
			const o = this.content?.orientation
			if (this.effectiveStyle === 'tree') {
				return 'vertical'
			}
			return VALID_ORIENTATIONS.includes(o) ? o : 'horizontal'
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		showIcons() {
			return this.content?.showIcons !== false
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		expandedByDefault() {
			return this.content?.expandedByDefault === true
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		activeItemHighlight() {
			const h = this.content?.activeItemHighlight
			return VALID_HIGHLIGHTS.includes(h) ? h : 'underline'
		},

		isInEditMode() {
			return this.isAdmin === true && this.canEdit === true
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		rootClasses() {
			return {
				[`menu-widget--style-${this.effectiveStyle}`]: true,
				[`menu-widget--highlight-${this.activeItemHighlight}`]: true,
				[`menu-widget--orientation-${this.orientation}`]: true,
			}
		},

		/** Map of dotted-key -> 'active' | 'in-path' | undefined. */
		/** @spec openspec/specs/menu-widget/spec.md */
		activePathMap() {
			return computeActivePath({
				items: this.items,
				currentLocation: this.currentLocation,
			})
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		activePath() {
			return this.activePathMap.path
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		activeLeafKey() {
			return this.activePathMap.leafKey
		},
	},

	/** @spec openspec/specs/menu-widget/spec.md */
	mounted() {
		this.boundLocationListener = () => {
			this.currentLocation = this.readLocation()
		}
		if (typeof window !== 'undefined') {
			window.addEventListener('popstate', this.boundLocationListener)
			window.addEventListener('hashchange', this.boundLocationListener)
		}
	},

	/** @spec openspec/specs/menu-widget/spec.md */
	beforeDestroy() {
		if (typeof window !== 'undefined' && this.boundLocationListener) {
			window.removeEventListener('popstate', this.boundLocationListener)
			window.removeEventListener('hashchange', this.boundLocationListener)
		}
	},

	methods: {
		/** @spec openspec/specs/menu-widget/spec.md */
		readLocation() {
			if (typeof window === 'undefined' || !window.location) {
				return { pathname: '/', host: '' }
			}
			return {
				pathname: window.location.pathname || '/',
				host: window.location.host || '',
			}
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		setTopRef(el, idx) {
			if (el) {
				this.topRefs[idx] = el
			}
		},

		hasChildren(item) {
			return item && Array.isArray(item.children) && item.children.length > 0
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		topItemClass(key) {
			const state = this.activePath[key]
			if (state === 'active' || key === this.activeLeafKey) {
				return 'menu-widget__item--active'
			}
			if (state === 'in-path') {
				return 'menu-widget__item--in-path'
			}
			return ''
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onDropdownTopClick(idx, item) {
			if (!this.hasChildren(item)) {
				this.onNavigate(item)
				return
			}
			if (this.dropOpenIndex === idx) {
				this.dropOpenIndex = null
				this.flyoutOpenKey = null
			} else {
				this.dropOpenIndex = idx
				this.flyoutOpenKey = null
			}
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		openDropdown(idx) {
			if (this.hasChildren(this.items[idx])) {
				this.dropOpenIndex = idx
			}
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onMegaTopClick(idx, item) {
			if (!this.hasChildren(item)) {
				this.onNavigate(item)
				return
			}
			this.megaOpenIndex = this.megaOpenIndex === idx ? null : idx
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onMegaTopKey(event, idx, item) {
			if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') {
				event.preventDefault()
				this.onMegaTopClick(idx, item)
			} else if (event.key === 'Tab') {
				this.megaOpenIndex = null
			}
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		closeAll() {
			this.dropOpenIndex = null
			this.flyoutOpenKey = null
			this.megaOpenIndex = null
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onNavigate(item) {
			if (!item || typeof item.url !== 'string' || item.url === '') {
				return
			}
			this.closeAll()
			const url = item.url
			if (this.isExternal(url)) {
				if (typeof window !== 'undefined' && typeof window.open === 'function') {
					window.open(url, '_blank', 'noopener,noreferrer')
				}
				return
			}
			// Internal: prefer router.push when available, otherwise hard navigate.
			if (this.$router && typeof this.$router.push === 'function') {
				try {
					this.$router.push(url)
					return
				} catch (e) {
					// Fall through to location assignment.
				}
			}
			if (typeof window !== 'undefined' && window.location) {
				window.location.href = url
			}
		},

		isExternal(url) {
			return typeof url === 'string' && /^https?:\/\//i.test(url)
		},

		/** @spec openspec/specs/menu-widget/spec.md */
		onEmptyStateClick() {
			if (this.isInEditMode) {
				this.$emit('edit-request')
			}
		},

		// Exposed for tests.
		/** @spec openspec/specs/menu-widget/spec.md */
		__isActive(itemUrl) {
			return isActiveItem(itemUrl, this.currentLocation)
		},
	},
}
</script>

<style scoped>
.menu-widget {
	width: 100%;
	height: 100%;
	padding: 4px;
	color: var(--color-main-text);
	font-size: 14px;
}

.menu-widget__empty {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
}

.menu-widget__empty--clickable {
	cursor: pointer;
}

.menu-widget__empty-icon {
	font-size: 18px;
}

.menu-widget__bar {
	display: flex;
	flex-direction: row;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.menu-widget__bar--vertical {
	flex-direction: column;
}

.menu-widget__bar-item {
	position: relative;
	list-style: none;
}

.menu-widget__bar-button,
.menu-widget__dropdown-item,
.menu-widget__mega-group-title,
.menu-widget__mega-leaf {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 10px;
	background: transparent;
	border: none;
	border-radius: var(--border-radius);
	color: inherit;
	font-size: 14px;
	cursor: pointer;
	text-align: left;
	width: 100%;
}

.menu-widget__mega-group-title {
	font-weight: 600;
}

.menu-widget__bar-button:hover,
.menu-widget__dropdown-item:hover,
.menu-widget__mega-group-title:hover,
.menu-widget__mega-leaf:hover {
	background: var(--color-background-hover);
}

.menu-widget__bar-button:focus-visible,
.menu-widget__dropdown-item:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.menu-widget__icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
}

.menu-widget__icon--hidden {
	visibility: hidden;
}

.menu-widget__caret {
	margin-left: auto;
	font-size: 10px;
}

.menu-widget__dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	z-index: 50;
	margin: 4px 0 0;
	padding: 4px;
	min-width: 180px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
	list-style: none;
}

.menu-widget__dropdown-item-wrap {
	position: relative;
}

.menu-widget__flyout {
	position: absolute;
	top: 0;
	left: 100%;
	margin-left: 2px;
	padding: 4px;
	min-width: 160px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
	list-style: none;
}

.menu-widget__mega-panel {
	margin-top: 4px;
	padding: 12px;
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.menu-widget__mega-leaves {
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
}

.menu-widget__tree {
	margin: 0;
	padding: 0;
	list-style: none;
}

/* Active-item highlight variants. */
.menu-widget--highlight-underline .menu-widget__item--active {
	border-bottom: 3px solid var(--color-primary);
}

.menu-widget--highlight-underline .menu-widget__item--in-path {
	border-bottom: 2px solid var(--color-primary);
	opacity: 0.85;
}

.menu-widget--highlight-background .menu-widget__item--active,
.menu-widget--highlight-background .menu-widget__item--in-path {
	background: var(--color-primary-element-light, rgba(0, 112, 192, 0.1));
	color: var(--color-primary);
}

.menu-widget--highlight-left-bar .menu-widget__item--active {
	border-left: 5px solid var(--color-primary);
	padding-left: 6px;
}

.menu-widget--highlight-left-bar .menu-widget__item--in-path {
	border-left: 4px solid var(--color-primary);
	padding-left: 7px;
	opacity: 0.85;
}
</style>
