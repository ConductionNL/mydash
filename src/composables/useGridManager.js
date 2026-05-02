/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * useGridManager — Vue 2 composable that owns the right-click context-menu
 * state for widget placements on the dashboard grid (REQ-WDG-015..017).
 *
 * Responsibilities:
 *  - Track popover state: `contextMenuOpen`, `contextMenuPosition` ({x, y}),
 *    `selectedWidget`.
 *  - Provide `onWidgetRightClick(event, widget)` — early-returns when
 *    `!canEdit`, calls `event.preventDefault()`, captures `clientX/Y`,
 *    clamps to the viewport (REQ-WDG-017), sets `selectedWidget`, opens
 *    the popover. Right-clicking a different widget while the popover is
 *    already open swaps it to the new position rather than stacking
 *    (REQ-WDG-016).
 *  - Provide `closeContextMenu()` — close + clear selection.
 *  - Manage a single document-level `click` listener that closes the
 *    popover on outside click (REQ-WDG-016). Listener is attached in
 *    `attach()` and detached in `detach()`; the host calls these from
 *    `mounted()` / `beforeDestroy()` so the composable cleans up after
 *    itself and never leaks state into a subsequent mount.
 *
 * Design notes:
 *  - Keeps zero coupling to a particular grid library — the host wires
 *    `@contextmenu` on each grid item itself and forwards `(event, widget)`.
 *    `Edit` and `Remove` are emitted via callbacks the host provides
 *    (`onEdit`, `onRemove`) so the composable does no API work itself.
 *  - `canEdit` is a `Ref<boolean>` (or any object exposing `.value`) so the
 *    composable stays reactive to the host's edit-mode toggle. When the
 *    runtime-shell capability ships, the host will inject this from the
 *    typed initial-state contract instead of deriving it locally.
 */

import Vue from 'vue'

/**
 * Default popover dimensions used when clamping. A real popover is
 * `min-width: 150px` (REQ-WDG-017) and approximately three buttons tall;
 * the actual rendered height varies with theme padding so we use a
 * conservative estimate that keeps the menu fully on-screen even when the
 * theme bumps line-height. Hosts can override these by passing
 * `{ menuWidth, menuHeight }` to the factory.
 */
const DEFAULT_MENU_WIDTH = 150
const DEFAULT_MENU_HEIGHT = 132

/**
 * Create a grid-manager state container.
 *
 * @param {object} options factory options
 * @param {{value: boolean}} options.canEdit reactive boolean (Vue.observable
 *   wrapper, ref, or computed) controlling whether right-click opens the
 *   popover; when `.value` is false the right-click falls through to the
 *   browser's native context menu (REQ-WDG-015 view-mode scenario).
 * @param {Function} [options.onEdit] called with `(widget)` when the user
 *   clicks the Edit item. Hosts wire this to open `AddWidgetModal` with
 *   `editingWidget` set (REQ-WDG-010).
 * @param {Function} [options.onRemove] called with `(widget)` when the user
 *   clicks Remove. Hosts wire this to the placement-delete path of
 *   REQ-WDG-005 (`DELETE /api/placements/{id}`).
 * @param {number} [options.menuWidth] override for clamp width (px)
 * @param {number} [options.menuHeight] override for clamp height (px)
 * @param {{innerWidth: number, innerHeight: number}} [options.viewport]
 *   override for the viewport (defaults to `window`); injectable for tests.
 * @return {{
 *   state: {contextMenuOpen: boolean, contextMenuPosition: {x: number, y: number}, selectedWidget: (object|null)},
 *   onWidgetRightClick: (event: MouseEvent, widget: object) => void,
 *   closeContextMenu: () => void,
 *   triggerEdit: () => void,
 *   triggerRemove: () => void,
 *   attach: () => void,
 *   detach: () => void,
 * }}
 */
export function useGridManager(options = {}) {
	const {
		canEdit,
		onEdit,
		onRemove,
		menuWidth = DEFAULT_MENU_WIDTH,
		menuHeight = DEFAULT_MENU_HEIGHT,
		viewport,
	} = options

	const state = Vue.observable({
		contextMenuOpen: false,
		contextMenuPosition: { x: 0, y: 0 },
		selectedWidget: null,
	})

	/**
	 * Read the current viewport size. Pulled out so tests can inject a
	 * stub (`{innerWidth, innerHeight}`) without monkey-patching `window`.
	 *
	 * @return {{width: number, height: number}}
	 */
	function getViewport() {
		const v = viewport || (typeof window !== 'undefined' ? window : null)
		if (!v) {
			return { width: Infinity, height: Infinity }
		}
		return { width: v.innerWidth, height: v.innerHeight }
	}

	/**
	 * Shift `(x, y)` so the popover stays fully visible at the right and
	 * bottom edges of the viewport (REQ-WDG-017). When the popover would
	 * overflow, slide it left / up by exactly the overflow amount; never
	 * push past the top-left corner (clamp to `0`).
	 *
	 * @param {number} x raw clientX from the right-click event
	 * @param {number} y raw clientY from the right-click event
	 * @return {{x: number, y: number}}
	 */
	function clampToViewport(x, y) {
		const { width, height } = getViewport()
		let clampedX = x
		let clampedY = y
		if (clampedX + menuWidth > width) {
			clampedX = Math.max(0, width - menuWidth)
		}
		if (clampedY + menuHeight > height) {
			clampedY = Math.max(0, height - menuHeight)
		}
		return { x: clampedX, y: clampedY }
	}

	/**
	 * Right-click handler. View-mode (`!canEdit.value`) MUST fall through
	 * to the browser's native context menu (REQ-WDG-015) — we early-return
	 * BEFORE calling `preventDefault`. Edit-mode swallows the native menu,
	 * captures the cursor coordinates, and opens the popover (or moves it
	 * to the new position if it was already open for a different widget).
	 *
	 * @param {MouseEvent} event the contextmenu event
	 * @param {object} widget the placement under the cursor
	 */
	function onWidgetRightClick(event, widget) {
		if (!canEdit || !canEdit.value) {
			return
		}
		event.preventDefault()
		const { x, y } = clampToViewport(event.clientX, event.clientY)
		state.contextMenuPosition = { x, y }
		state.selectedWidget = widget
		state.contextMenuOpen = true
	}

	/**
	 * Close the popover and drop the selected widget reference. Called
	 * from outside-click, Cancel, post-Edit, and post-Remove flows.
	 */
	function closeContextMenu() {
		state.contextMenuOpen = false
		state.selectedWidget = null
	}

	/**
	 * Forward the Edit click to the host callback, capturing the selected
	 * widget BEFORE close clears it. Hosts wire this to the AddWidgetModal
	 * open path with `editingWidget` set (REQ-WDG-010).
	 */
	function triggerEdit() {
		const widget = state.selectedWidget
		closeContextMenu()
		if (typeof onEdit === 'function' && widget) {
			onEdit(widget)
		}
	}

	/**
	 * Forward the Remove click to the host callback. Hosts wire this to
	 * the placement-delete path of REQ-WDG-005.
	 */
	function triggerRemove() {
		const widget = state.selectedWidget
		closeContextMenu()
		if (typeof onRemove === 'function' && widget) {
			onRemove(widget)
		}
	}

	/**
	 * Document-level click handler — closes the popover on outside click
	 * (REQ-WDG-016). Clicks inside `.widget-context-menu` are stopped by
	 * the popover root via `@click.stop`, so they never reach this
	 * listener. Right-clicks on widgets go through `onWidgetRightClick`
	 * directly and replace the popover instead of closing it.
	 *
	 * @param {MouseEvent} event the document-level click event
	 */
	function handleDocumentClick(event) {
		if (!state.contextMenuOpen) {
			return
		}
		// Defensive — the .stop on the popover should already cover this.
		const target = event.target
		if (target && typeof target.closest === 'function' && target.closest('.widget-context-menu')) {
			return
		}
		closeContextMenu()
	}

	let attached = false

	/**
	 * Attach the document-level click listener. Called from the host's
	 * `mounted()` so the composable owns its DOM bindings.
	 */
	function attach() {
		if (attached || typeof document === 'undefined') {
			return
		}
		document.addEventListener('click', handleDocumentClick)
		attached = true
	}

	/**
	 * Detach the document-level click listener and reset state so the
	 * composable doesn't leak into a subsequent mount (REQ-WDG-016
	 * listener-cleanup scenario).
	 */
	function detach() {
		if (!attached || typeof document === 'undefined') {
			return
		}
		document.removeEventListener('click', handleDocumentClick)
		attached = false
		closeContextMenu()
	}

	return {
		state,
		onWidgetRightClick,
		closeContextMenu,
		triggerEdit,
		triggerRemove,
		attach,
		detach,
	}
}
