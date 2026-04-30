/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Shared grid configuration constants for GridStack initialization.
 * These constants are used across DashboardGrid and other grid-aware components.
 * Per REQ-GRID-012, all geometry and breakpoint values must be centralized here.
 */

/**
 * Cell height in pixels. Kept at 80 per existing implementation.
 * REQ-GRID-012: centralized constant for all grid calculations.
 * @type {number}
 */
export const CELL_HEIGHT = 80

/**
 * Grid margin (inter-cell spacing) in pixels. Kept at 12 per existing implementation.
 * REQ-GRID-012: centralized constant for all grid calculations.
 * @type {number}
 */
export const GRID_MARGIN = 12

/**
 * Default column count for the grid.
 * REQ-GRID-007: the maximum column count in the breakpoint set.
 * @type {number}
 */
export const GRID_COLUMNS = 12

/**
 * Responsive breakpoints for GridStack column adaptation.
 * Per REQ-GRID-007, applied in descending viewport order.
 * GridStack uses the first matching breakpoint where viewport width >= w.
 * Below the smallest width (480 px), the smallest column count (1) applies.
 * @type {Array<{w: number, c: number}>}
 */
export const BREAKPOINTS = [
	{ w: 1400, c: 12 },
	{ w: 1100, c: 8 },
	{ w: 768, c: 4 },
	{ w: 480, c: 1 },
]

/**
 * Column layout algorithm for reflow on breakpoint change.
 * Per REQ-GRID-007, 'moveScale' proportionally rescales widget widths
 * to preserve user intent (e.g. a half-width widget stays half-width at any column count).
 * @type {string}
 */
export const COLUMN_LAYOUT = 'moveScale'
