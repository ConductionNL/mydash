<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<li
		class="org-nav-item"
		:class="{
			'org-nav-item--active': isActive,
			'org-nav-item--has-active-child': hasActiveDescendant,
			'org-nav-item--section': !node.url,
		}"
		role="treeitem"
		:aria-expanded="hasChildren ? expanded : null">
		<a
			v-if="node.url"
			:href="node.url"
			:target="node.openInNewTab ? '_blank' : null"
			:rel="node.openInNewTab ? 'noopener noreferrer' : null"
			class="org-nav-item__row"
			@click="onActivate">
			<span class="org-nav-item__indent" :style="{ paddingLeft: indentPx }" />
			<span v-if="hasChildren" class="org-nav-item__toggle" @click.prevent.stop="toggle">
				{{ expanded ? '▾' : '▸' }}
			</span>
			<span v-else class="org-nav-item__toggle org-nav-item__toggle--placeholder" />
			<img
				v-if="iconIsUrl"
				class="org-nav-item__icon"
				:src="node.icon"
				:width="24"
				:height="24"
				alt="">
			<span v-else-if="node.icon" class="org-nav-item__icon org-nav-item__icon--name">
				{{ node.icon }}
			</span>
			<span class="org-nav-item__label">{{ node.label }}</span>
		</a>
		<button
			v-else
			type="button"
			class="org-nav-item__row org-nav-item__row--section"
			@click="toggle">
			<span class="org-nav-item__indent" :style="{ paddingLeft: indentPx }" />
			<span v-if="hasChildren" class="org-nav-item__toggle">
				{{ expanded ? '▾' : '▸' }}
			</span>
			<span v-else class="org-nav-item__toggle org-nav-item__toggle--placeholder" />
			<img
				v-if="iconIsUrl"
				class="org-nav-item__icon"
				:src="node.icon"
				:width="24"
				:height="24"
				alt="">
			<span v-else-if="node.icon" class="org-nav-item__icon org-nav-item__icon--name">
				{{ node.icon }}
			</span>
			<span class="org-nav-item__label">{{ node.label }}</span>
		</button>
		<ul v-if="hasChildren && expanded" class="org-nav-item__children" role="group">
			<OrgNavigationItem
				v-for="child in node.children"
				:key="child.id"
				:node="child"
				:level="level + 1"
				:current-url="currentUrl"
				@navigate="$emit('navigate')" />
		</ul>
	</li>
</template>

<script>
/**
 * OrgNavigationItem — recursive node renderer for the org-nav tree
 * (REQ-ONAV-005, REQ-ONAV-006, REQ-ONAV-009).
 *
 * Each node renders as either:
 *   - an `<a>` link when `node.url` is non-null; clicking emits
 *     `navigate` so the parent panel can close the mobile drawer
 *   - a `<button>` section header that toggles the children list
 *
 * Active-item detection (REQ-ONAV-009) compares the node's `url` to
 * the panel-supplied `currentUrl` using prefix-or-equal semantics.
 * Sections that contain an active descendant auto-expand
 * (`expanded = true` on mount when `hasActiveDescendant`).
 *
 * Icons (REQ-ONAV-006) follow the dual-mode convention: a URL string
 * (starts with `/` or `http`) renders as `<img>`; bare names render
 * as plain text labels — the link-button-widget IconRenderer is not
 * used here because the org-nav supports MDI names AND custom URLs
 * with the same width/height contract, and the inline branch keeps
 * the SFC self-contained.
 */
export default {
	name: 'OrgNavigationItem',

	props: {
		node: {
			type: Object,
			required: true,
		},
		level: {
			type: Number,
			default: 1,
		},
		currentUrl: {
			type: String,
			default: '',
		},
	},

	emits: ['navigate'],

	data() {
		return {
			expanded: false,
		}
	},

	computed: {
		hasChildren() {
			return Array.isArray(this.node.children) && this.node.children.length > 0
		},

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		iconIsUrl() {
			const icon = this.node.icon
			if (typeof icon !== 'string' || icon === '') {
				return false
			}
			return icon.startsWith('/') || icon.startsWith('http')
		},

		isActive() {
			return this.urlMatches(this.node.url)
		},

		hasActiveDescendant() {
			return this.descendantMatches(this.node.children)
		},

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		indentPx() {
			// Visual indent — 12px per level beyond root.
			return ((this.level - 1) * 12) + 'px'
		},
	},

	/** @spec openspec/specs/navigation-editor-org/spec.md */
	mounted() {
		// Auto-expand when a descendant is active (REQ-ONAV-009).
		if (this.hasActiveDescendant) {
			this.expanded = true
		}
	},

	methods: {
		/** @spec openspec/specs/navigation-editor-org/spec.md */
		toggle() {
			if (this.hasChildren) {
				this.expanded = !this.expanded
			}
		},

		/** @spec openspec/specs/navigation-editor-org/spec.md */
		onActivate() {
			this.$emit('navigate')
		},

		/**
		 * URL-match helper (REQ-ONAV-009). A node is active when its
		 * URL exactly matches `currentUrl` OR is a path prefix of it.
		 *
		 * @param {string|null} url Candidate URL.
		 * @return {boolean}
		 */
		/** @spec openspec/specs/navigation-editor-org/spec.md */
		urlMatches(url) {
			if (!url || !this.currentUrl) {
				return false
			}
			if (url === this.currentUrl) {
				return true
			}
			// Prefix match — but require a path-segment boundary so
			// '/foo' doesn't match '/foobar'.
			if (this.currentUrl.startsWith(url)) {
				const next = this.currentUrl.charAt(url.length)
				return next === '' || next === '/' || next === '?' || next === '#'
			}
			return false
		},

		/**
		 * Recursive descendant scanner used by the auto-expand logic.
		 *
		 * @param {Array<object>|null} children Child nodes.
		 * @return {boolean}
		 */
		/** @spec openspec/specs/navigation-editor-org/spec.md */
		descendantMatches(children) {
			if (!Array.isArray(children) || children.length === 0) {
				return false
			}
			for (const child of children) {
				if (this.urlMatches(child.url)) {
					return true
				}
				if (this.descendantMatches(child.children)) {
					return true
				}
			}
			return false
		},
	},
}
</script>

<style scoped>
.org-nav-item {
	list-style: none;
}

.org-nav-item__row {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 8px;
	color: inherit;
	text-decoration: none;
	border-radius: 4px;
	background: transparent;
	border: none;
	width: 100%;
	text-align: left;
	cursor: pointer;
	font: inherit;
}

.org-nav-item__row:hover,
.org-nav-item__row:focus-visible {
	background: var(--color-background-hover, #f0f0f0);
	outline: none;
}

.org-nav-item--active > .org-nav-item__row {
	background: var(--color-primary-element-light, rgba(25, 118, 210, 0.12));
	font-weight: 600;
}

.org-nav-item--has-active-child > .org-nav-item__row {
	font-weight: 600;
}

.org-nav-item__toggle {
	display: inline-block;
	width: 12px;
	text-align: center;
	font-size: 0.8em;
	cursor: pointer;
}

.org-nav-item__toggle--placeholder {
	visibility: hidden;
}

.org-nav-item__icon {
	width: 24px;
	height: 24px;
	display: inline-block;
}

.org-nav-item__icon--name {
	font-style: italic;
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.org-nav-item__label {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.org-nav-item__children {
	list-style: none;
	margin: 0;
	padding: 0;
}
</style>
