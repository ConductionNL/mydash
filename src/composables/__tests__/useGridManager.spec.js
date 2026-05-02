/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `useGridManager` composable. Covers REQ-WDG-015
 * (right-click opens popover in edit mode, falls through in view mode),
 * REQ-WDG-016 (outside-click closes; switching widgets swaps; listener
 * cleanup), and REQ-WDG-017 (viewport clamping on right + bottom edges).
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

/**
 * Build a fake right-click event whose `preventDefault` we can spy on.
 *
 * @param {number} clientX cursor x coordinate
 * @param {number} clientY cursor y coordinate
 * @return {{clientX: number, clientY: number, preventDefault: () => void, target: object}}
 */
function makeEvent(clientX, clientY) {
	return {
		clientX,
		clientY,
		preventDefault: vi.fn(),
		target: { closest: () => null },
	}
}

describe('useGridManager', () => {
	it('REQ-WDG-015: edit mode right-click opens popover at cursor position', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		const event = makeEvent(300, 400)
		const widget = { id: 7 }
		grid.onWidgetRightClick(event, widget)
		expect(event.preventDefault).toHaveBeenCalledOnce()
		expect(grid.state.contextMenuOpen).toBe(true)
		expect(grid.state.contextMenuPosition).toEqual({ x: 300, y: 400 })
		expect(grid.state.selectedWidget).toBe(widget)
	})

	it('REQ-WDG-015: view mode right-click does not open popover and does not preventDefault', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: false }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		const event = makeEvent(300, 400)
		grid.onWidgetRightClick(event, { id: 7 })
		expect(event.preventDefault).not.toHaveBeenCalled()
		expect(grid.state.contextMenuOpen).toBe(false)
		expect(grid.state.selectedWidget).toBeNull()
	})

	it('REQ-WDG-017: clamps left when popover would overflow right edge', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 800, innerHeight: 600 },
			menuWidth: 150,
			menuHeight: 100,
		})
		// Right-click 50px from right edge — popover (150px wide) would
		// overflow by 100px. Expect rendered left to shift back to 650.
		grid.onWidgetRightClick(makeEvent(750, 200), { id: 1 })
		expect(grid.state.contextMenuPosition.x).toBe(650)
		expect(grid.state.contextMenuPosition.x + 150).toBeLessThanOrEqual(800)
	})

	it('REQ-WDG-017: clamps top when popover would overflow bottom edge', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 800, innerHeight: 600 },
			menuWidth: 150,
			menuHeight: 100,
		})
		// Right-click 20px from bottom — popover (100px tall) overflows
		// by 80px. Expect rendered top to shift back to 500.
		grid.onWidgetRightClick(makeEvent(400, 580), { id: 1 })
		expect(grid.state.contextMenuPosition.y).toBe(500)
		expect(grid.state.contextMenuPosition.y + 100).toBeLessThanOrEqual(600)
	})

	it('REQ-WDG-017: leaves coordinates untouched when popover fits', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
			menuWidth: 150,
			menuHeight: 132,
		})
		grid.onWidgetRightClick(makeEvent(300, 400), { id: 1 })
		expect(grid.state.contextMenuPosition).toEqual({ x: 300, y: 400 })
	})

	it('REQ-WDG-016: right-clicking a different widget swaps popover position (no stacking)', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		grid.onWidgetRightClick(makeEvent(100, 100), { id: 'a' })
		expect(grid.state.selectedWidget.id).toBe('a')
		expect(grid.state.contextMenuPosition).toEqual({ x: 100, y: 100 })
		grid.onWidgetRightClick(makeEvent(500, 500), { id: 'b' })
		expect(grid.state.selectedWidget.id).toBe('b')
		expect(grid.state.contextMenuPosition).toEqual({ x: 500, y: 500 })
		// The popover is still open — it was swapped, not stacked.
		expect(grid.state.contextMenuOpen).toBe(true)
	})

	it('REQ-WDG-016: closeContextMenu clears state', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		grid.onWidgetRightClick(makeEvent(100, 100), { id: 'a' })
		grid.closeContextMenu()
		expect(grid.state.contextMenuOpen).toBe(false)
		expect(grid.state.selectedWidget).toBeNull()
	})

	it('REQ-WDG-016: outside click via document listener closes popover', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		grid.attach()
		grid.onWidgetRightClick(makeEvent(100, 100), { id: 'a' })
		expect(grid.state.contextMenuOpen).toBe(true)

		// Dispatch a click whose target is NOT inside .widget-context-menu.
		const outsideTarget = document.createElement('div')
		document.body.appendChild(outsideTarget)
		const evt = new MouseEvent('click', { bubbles: true })
		Object.defineProperty(evt, 'target', { value: outsideTarget })
		document.dispatchEvent(evt)

		expect(grid.state.contextMenuOpen).toBe(false)
		grid.detach()
		outsideTarget.remove()
	})

	it('REQ-WDG-016: click inside .widget-context-menu does NOT close popover', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		grid.attach()
		grid.onWidgetRightClick(makeEvent(100, 100), { id: 'a' })

		// Build an element that is inside .widget-context-menu so closest()
		// returns the wrapper. The composable should bail before closing.
		const wrapper = document.createElement('div')
		wrapper.className = 'widget-context-menu'
		const inner = document.createElement('button')
		wrapper.appendChild(inner)
		document.body.appendChild(wrapper)

		const evt = new MouseEvent('click', { bubbles: true })
		Object.defineProperty(evt, 'target', { value: inner })
		document.dispatchEvent(evt)

		expect(grid.state.contextMenuOpen).toBe(true)
		grid.detach()
		wrapper.remove()
	})

	it('REQ-WDG-016: detach removes the document click listener (no leaks across mounts)', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const grid = useGridManager({
			canEdit,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		const removeSpy = vi.spyOn(document, 'removeEventListener')
		grid.attach()
		grid.detach()
		const removeCalls = removeSpy.mock.calls.filter((c) => c[0] === 'click')
		expect(removeCalls.length).toBeGreaterThan(0)
		// Detach also clears any popover state so no leak into next mount.
		expect(grid.state.contextMenuOpen).toBe(false)
		removeSpy.mockRestore()
	})

	it('REQ-WDG-015 edit: triggerEdit forwards selected widget to onEdit and closes popover', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const onEdit = vi.fn()
		const grid = useGridManager({
			canEdit,
			onEdit,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		const widget = { id: 'edit-me' }
		grid.onWidgetRightClick(makeEvent(100, 100), widget)
		grid.triggerEdit()
		expect(onEdit).toHaveBeenCalledWith(widget)
		expect(grid.state.contextMenuOpen).toBe(false)
	})

	it('REQ-WDG-015 remove: triggerRemove forwards selected widget to onRemove and closes popover', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const onRemove = vi.fn()
		const grid = useGridManager({
			canEdit,
			onRemove,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		const widget = { id: 'kill-me' }
		grid.onWidgetRightClick(makeEvent(100, 100), widget)
		grid.triggerRemove()
		expect(onRemove).toHaveBeenCalledWith(widget)
		expect(grid.state.contextMenuOpen).toBe(false)
	})

	it('REQ-WDG-015 cancel: closeContextMenu fires no API callback', async () => {
		const { useGridManager } = await import('../useGridManager.js')
		const canEdit = { value: true }
		const onEdit = vi.fn()
		const onRemove = vi.fn()
		const grid = useGridManager({
			canEdit,
			onEdit,
			onRemove,
			viewport: { innerWidth: 1920, innerHeight: 1080 },
		})
		grid.onWidgetRightClick(makeEvent(100, 100), { id: 'x' })
		grid.closeContextMenu()
		expect(onEdit).not.toHaveBeenCalled()
		expect(onRemove).not.toHaveBeenCalled()
	})
})
