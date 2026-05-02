/*
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright end-to-end test for the responsive grid breakpoints covering
 * tasks 3.1 + 3.2 of the `responsive-grid-breakpoints` OpenSpec change.
 *
 * Asserts:
 *   - REQ-GRID-007: at five viewport widths (1500 / 1200 / 900 / 640 / 320
 *     px) the grid's `opts.column` matches the expected entry from the
 *     BREAKPOINTS table — 12 / 8 / 4 / 1 / 1 respectively. The 320 px case
 *     verifies "below smallest breakpoint clamps to smallest column count".
 *   - Visual regression: a six-widget layout snapshot at each of the four
 *     in-table breakpoints (1500 / 1200 / 900 / 480 px).
 *
 * NOTE: Playwright infrastructure is not yet wired up in mydash. This file
 * is committed alongside the rest of the change so it runs once the cohort-
 * wide Playwright bootstrap lands. Do not delete — it is the canonical e2e
 * coverage for REQ-GRID-007 / REQ-GRID-012 / REQ-GRID-013.
 */

import { test, expect } from '@playwright/test'

const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

/**
 * (viewportWidthPx, expectedColumnCount) tuples driven from the BREAKPOINTS
 * table in `src/composables/useGridManager.js`. Includes one width above
 * the largest entry (1500 -> 12) and one below the smallest (320 -> 1) so
 * the clamping behaviour at both ends is asserted.
 */
const BREAKPOINT_CASES: Array<{ viewport: number, columns: number }> = [
	{ viewport: 1500, columns: 12 },
	{ viewport: 1200, columns: 8 },
	{ viewport: 900, columns: 4 },
	{ viewport: 640, columns: 1 },
	{ viewport: 320, columns: 1 },
]

test.describe('responsive grid breakpoints', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${NEXTCLOUD_URL}/index.php/apps/mydash`)
		// Tests assume the user is already authenticated via Playwright
		// storageState; in CI this is set up by the Hydra harness.
	})

	for (const { viewport, columns } of BREAKPOINT_CASES) {
		test(`grid uses ${columns} columns at ${viewport}px viewport`, async ({ page }) => {
			await page.setViewportSize({ width: viewport, height: 900 })
			// Wait for the grid to finish reflowing after the resize.
			await page.waitForFunction(() => {
				const el = document.querySelector('.grid-stack') as
					| (HTMLElement & { gridstack?: { opts: { column: number } } })
					| null
				return Boolean(el?.gridstack)
			})
			const actualColumns = await page.evaluate(() => {
				const el = document.querySelector('.grid-stack') as
					HTMLElement & { gridstack: { opts: { column: number } } }
				return el.gridstack.opts.column
			})
			expect(actualColumns).toBe(columns)
		})
	}

	test('visual regression: six-widget layout at each in-table breakpoint', async ({ page }) => {
		// Run sequentially (not table-driven) so the screenshot names group
		// under one Playwright report node.
		const visualWidths = [1500, 1200, 900, 480]
		for (const width of visualWidths) {
			await page.setViewportSize({ width, height: 900 })
			await page.waitForTimeout(300) // settle reflow animation
			await expect(page.locator('.grid-stack')).toHaveScreenshot(
				`grid-${width}.png`,
				{ maxDiffPixelRatio: 0.02 },
			)
		}
	})
})
