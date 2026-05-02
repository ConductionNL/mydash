/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `useGridManager.js` covering both halves of the
 * combined composable:
 *
 *   - REQ-GRID-007 (Responsive breakpoints): the BREAKPOINTS table has the
 *     four expected entries, monotonically descending column counts, and
 *     `getColumnOpts()` wires the `moveScale` layout + `breakpointForWindow`
 *     flag.
 *   - REQ-GRID-012 (Cell geometry constants): `CELL_HEIGHT === 60`,
 *     `GRID_MARGIN === 8`, and the height-math scenario.
 *   - syncCellHeightCssVar() writes the `--mydash-cell-height` custom
 *     property on `:root` from the JS constant.
 *   - REQ-WDG-015..017 (Right-click context menu): edit-mode opens the
 *     popover, view-mode falls through, viewport clamping, swap-not-stack,
 *     outside-click closes, listener cleanup.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import {
	BREAKPOINTS,
	CELL_HEIGHT,
	CELL_HEIGHT_CSS_VAR,
	COLUMN_LAYOUT,
	DEFAULT_COLUMNS,
	GRID_MARGIN,
	getColumnOpts,
	syncCellHeightCssVar,
} from '../useGridManager.js'

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

describe('useGridManager — grid configuration', () => {
	describe('REQ-GRID-012 cell geometry constants', () => {
		it('CELL_HEIGHT is 60 px', () => {
			expect(CELL_HEIGHT).toBe(60)
		})

		it('GRID_MARGIN is 8 px', () => {
			expect(GRID_MARGIN).toBe(8)
		})

		it('DEFAULT_COLUMNS is 12', () => {
			expect(DEFAULT_COLUMNS).toBe(12)
		})

		it('height math: 4 rows + 3 inter-row margins = 264 px', () => {
			const rows = 4
			const innerMargins = rows - 1
			expect((rows * CELL_HEIGHT) + (innerMargins * GRID_MARGIN)).toBe(264)
		})
	})

	describe('REQ-GRID-007 responsive breakpoints', () => {
		it('BREAKPOINTS has exactly four entries', () => {
			expect(BREAKPOINTS).toHaveLength(4)
		})

		it('BREAKPOINTS entries match the spec table 1400/1100/768/480', () => {
			expect(BREAKPOINTS).toEqual([
				{ w: 1400, c: 12 },
				{ w: 1100, c: 8 },
				{ w: 768, c: 4 },
				{ w: 480, c: 1 },
			])
		})

		it('column counts descend monotonically as widths shrink', () => {
			for (let i = 1; i < BREAKPOINTS.length; i++) {
				expect(BREAKPOINTS[i].w).toBeLessThan(BREAKPOINTS[i - 1].w)
				expect(BREAKPOINTS[i].c).toBeLessThan(BREAKPOINTS[i - 1].c)
			}
		})

		it('BREAKPOINTS is frozen so callers cannot mutate the canonical table', () => {
			expect(Object.isFrozen(BREAKPOINTS)).toBe(true)
		})

		it('COLUMN_LAYOUT is "moveScale"', () => {
			expect(COLUMN_LAYOUT).toBe('moveScale')
		})

		it('getColumnOpts() returns a fresh deep copy of breakpoints + the moveScale layout + breakpointForWindow', () => {
			const opts = getColumnOpts()
			expect(opts.layout).toBe('moveScale')
			expect(opts.breakpointForWindow).toBe(true)
			expect(opts.breakpoints).toEqual([
				{ w: 1400, c: 12 },
				{ w: 1100, c: 8 },
				{ w: 768, c: 4 },
				{ w: 480, c: 1 },
			])
			opts.breakpoints[0].c = 99
			expect(BREAKPOINTS[0].c).toBe(12)
		})
	})

	describe('CSS custom-property sync', () => {
		it('syncCellHeightCssVar writes the cell height to documentElement', () => {
			document.documentElement.style.removeProperty(CELL_HEIGHT_CSS_VAR)
			syncCellHeightCssVar()
			const value = document.documentElement.style.getPropertyValue(CELL_HEIGHT_CSS_VAR)
			expect(value).toBe(`${CELL_HEIGHT}px`)
		})

		it('CELL_HEIGHT_CSS_VAR is the documented `--mydash-cell-height` name', () => {
			expect(CELL_HEIGHT_CSS_VAR).toBe('--mydash-cell-height')
		})
	})
})

describe('useGridManager — context menu', () => {
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
