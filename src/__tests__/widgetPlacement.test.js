/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/* eslint-disable n/no-unpublished-import */
import { describe, it, expect, beforeEach, vi } from 'vitest'
/* eslint-enable n/no-unpublished-import */
import { placeNewWidget } from '../utils/widgetPlacement.js'

describe('widgetPlacement', () => {
	let mockGridInstance
	let layout

	beforeEach(() => {
		layout = []

		// Mock GridStack instance
		mockGridInstance = {
			addWidget: vi.fn(),
			update: vi.fn(),
			removeWidget: vi.fn(),
			engine: {
				nodes: [],
			},
		}

		// Mock DOM methods
		vi.spyOn(document, 'createElement').mockReturnValue({
			setAttribute: vi.fn(),
		})
	})

	describe('auto-position into empty space', () => {
		it('should use GridStack auto-position when slot is within viewport', () => {
			const spec = { w: 4, h: 4 }

			// Mock GridStack to return a slot at y=0 (within viewport)
			const mockEl = { setAttribute: vi.fn() }
			document.createElement = vi.fn().mockReturnValue(mockEl)

			const mockNode = { el: mockEl, x: 6, y: 0 }
			mockGridInstance.addWidget = vi.fn((el) => {
				mockGridInstance.engine.nodes.push(mockNode)
			})

			const result = placeNewWidget(spec, layout, mockGridInstance, 8)

			expect(result).toEqual({
				x: 6,
				y: 0,
				w: 4,
				h: 4,
			})
			expect(mockGridInstance.removeWidget).toHaveBeenCalled()
		})
	})

	describe('push-down fallback when grid is full at top', () => {
		it('should compute overlapping widgets correctly', () => {
			// Existing widgets occupying [0..12] × [0..4]
			layout = [
				{ id: 1, gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 },
				{ id: 2, gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 4 },
			]

			const spec = { w: 4, h: 4 }

			// Mock GridStack to fail auto-position
			mockGridInstance.addWidget = vi.fn().mockImplementation(() => {
				throw new Error('no space')
			})

			vi.spyOn(document, 'querySelector').mockReturnValue({})

			const result = placeNewWidget(spec, layout, mockGridInstance, 8)

			// New widget should be at top-left
			expect(result).toEqual({
				x: 0,
				y: 0,
				w: 4,
				h: 4,
			})
		})
	})

	describe('non-overlapping widgets unchanged', () => {
		it('should not move widgets that do not overlap new widget', () => {
			layout = [
				{ id: 1, gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 },
				{ id: 2, gridX: 6, gridY: 5, gridWidth: 6, gridHeight: 4 }, // Below the new widget
			]

			const spec = { w: 4, h: 4 }
			mockGridInstance.addWidget = vi.fn().mockImplementation(() => {
				throw new Error('no space')
			})

			vi.spyOn(document, 'querySelector').mockReturnValue({})

			const result = placeNewWidget(spec, layout, mockGridInstance, 8)

			expect(result).toEqual({
				x: 0,
				y: 0,
				w: 4,
				h: 4,
			})

			// Only one call to update (for the overlapping widget)
			expect(mockGridInstance.update).toHaveBeenCalledTimes(1)
		})
	})

	describe('default size on omitted dimensions', () => {
		it('should use default w=4, h=4 when caller omits w and h', () => {
			const spec = { type: 'text' }

			mockGridInstance.addWidget = vi.fn().mockImplementation(() => {
				throw new Error('no space')
			})

			const result = placeNewWidget(spec, layout, mockGridInstance, 8)

			expect(result.w).toBe(4)
			expect(result.h).toBe(4)
		})

		it('should use spec.w when provided, default h=4', () => {
			const spec = { w: 6 }

			mockGridInstance.addWidget = vi.fn().mockImplementation(() => {
				throw new Error('no space')
			})

			const result = placeNewWidget(spec, layout, mockGridInstance, 8)

			expect(result.w).toBe(6)
			expect(result.h).toBe(4)
		})

		it('should use spec.h when provided, default w=4', () => {
			const spec = { h: 3 }

			mockGridInstance.addWidget = vi.fn().mockImplementation(() => {
				throw new Error('no space')
			})

			const result = placeNewWidget(spec, layout, mockGridInstance, 8)

			expect(result.w).toBe(4)
			expect(result.h).toBe(3)
		})
	})

	describe('viewport row boundary detection', () => {
		it('should use fallback when auto-position slot is below viewport', () => {
			const spec = { w: 4, h: 4 }

			const mockEl = { setAttribute: vi.fn() }
			document.createElement = vi.fn().mockReturnValue(mockEl)

			const mockNode = { el: mockEl, x: 0, y: 10 } // Below viewport (8 rows)
			mockGridInstance.addWidget = vi.fn((el) => {
				mockGridInstance.engine.nodes.push(mockNode)
			})

			const result = placeNewWidget(spec, layout, mockGridInstance, 8)

			// Should fall back to top-left instead of using slot at y=10
			expect(result).toEqual({
				x: 0,
				y: 0,
				w: 4,
				h: 4,
			})
		})
	})

	describe('pushed widgets only change gridY', () => {
		it('should identify overlapping widgets and push them', () => {
			layout = [
				{ id: 1, gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 2 },
			]

			const spec = { w: 6, h: 3 }
			mockGridInstance.addWidget = vi.fn().mockImplementation(() => {
				throw new Error('no space')
			})

			vi.spyOn(document, 'querySelector').mockReturnValue({})

			placeNewWidget(spec, layout, mockGridInstance, 8)

			// Should be called once for the overlapping widget
			expect(mockGridInstance.update).toHaveBeenCalledTimes(1)
		})
	})
})
