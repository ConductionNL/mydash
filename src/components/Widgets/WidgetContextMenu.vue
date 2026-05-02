<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="widget-context-menu"
		role="menu"
		:style="positionStyle"
		@click.stop>
		<button
			type="button"
			class="widget-context-menu__item"
			role="menuitem"
			@click="onEdit">
			{{ t('mydash', 'Edit') }}
		</button>
		<button
			type="button"
			class="widget-context-menu__item widget-context-menu__item--danger"
			role="menuitem"
			@click="onRemove">
			{{ t('mydash', 'Remove') }}
		</button>
		<button
			type="button"
			class="widget-context-menu__item"
			role="menuitem"
			@click="onCancel">
			{{ t('mydash', 'Cancel') }}
		</button>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'

/**
 * WidgetContextMenu — small right-click popover offering Edit / Remove /
 * Cancel for a widget placement (REQ-WDG-015..017). The component is
 * controlled: the parent owns `top` / `left` (computed from cursor +
 * viewport-clamping in the composable) and toggles visibility via
 * `v-if`. Each click emits the matching event AND closes via the parent's
 * `closeContextMenu()` so the popover is always single-instance.
 *
 * Styling: absolute positioning, `min-width: 150px`, `z-index: 10000`
 * (REQ-WDG-017 — sits above the grid and shares the modal layer; clicking
 * a popover item closes the popover before any subsequent modal opens).
 *
 * Props:
 *  - `top` (number): clamped pixel offset from the viewport top
 *  - `left` (number): clamped pixel offset from the viewport left
 *
 * Emits:
 *  - `edit`: user clicked Edit — parent should open `AddWidgetModal` with
 *    the selected widget passed as `editingWidget`
 *  - `remove`: user clicked Remove — parent should hit the placement-delete
 *    path of REQ-WDG-005 (`DELETE /api/placements/{id}`)
 *  - `close`: user clicked Cancel — no-op close
 */
export default {
	name: 'WidgetContextMenu',

	props: {
		top: {
			type: Number,
			required: true,
		},
		left: {
			type: Number,
			required: true,
		},
	},

	emits: ['edit', 'remove', 'close'],

	computed: {
		/**
		 * Absolute positioning + the fixed look (z-index, min-width) lives
		 * here so the parent only has to pass coordinates. Position is
		 * `fixed` (not `absolute`) so viewport-relative clientX/clientY
		 * values from the right-click event map cleanly without having to
		 * account for scroll offsets.
		 *
		 * @return {object}
		 */
		positionStyle() {
			return {
				top: `${this.top}px`,
				left: `${this.left}px`,
			}
		},
	},

	methods: {
		t,

		onEdit() {
			this.$emit('edit')
			this.$emit('close')
		},

		onRemove() {
			this.$emit('remove')
			this.$emit('close')
		},

		onCancel() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.widget-context-menu {
	position: fixed;
	min-width: 150px;
	z-index: 10000;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 6px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
	padding: 4px 0;
	display: flex;
	flex-direction: column;
}

.widget-context-menu__item {
	background: transparent;
	border: 0;
	padding: 8px 16px;
	text-align: left;
	cursor: pointer;
	font: inherit;
	color: inherit;
}

.widget-context-menu__item:hover,
.widget-context-menu__item:focus {
	background: var(--color-background-hover, rgba(0, 0, 0, 0.05));
	outline: none;
}

.widget-context-menu__item--danger {
	color: var(--color-error, #d32f2f);
}
</style>
