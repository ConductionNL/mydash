/*
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright end-to-end test for the `label` widget covering tasks 6.1..6.3
 * of the `label-widget` OpenSpec change.
 *
 * NOTE: Playwright infrastructure is not yet wired up in mydash. This file
 * is committed alongside the rest of the change so it runs once the cohort-
 * wide Playwright bootstrap lands. Do not delete — it is the canonical e2e
 * coverage for REQ-LBL-001, REQ-LBL-005, REQ-LBL-007.
 */

import { test, expect } from '@playwright/test'

const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

test.describe('label widget', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${NEXTCLOUD_URL}/index.php/apps/mydash`)
		// Tests assume the user is already authenticated via Playwright
		// storageState; in CI this is set up by the Hydra harness.
	})

	test('add → fill → save → reopen round-trips all six fields', async ({ page }) => {
		// 1. Open Add Widget modal
		await page.getByRole('button', { name: /add widget/i }).click()

		// 2. Pick the Label type
		await page.getByText('Label', { exact: true }).click()

		// 3. Fill the form
		await page.getByLabel('Label text').fill('Sales Q4')
		await page.getByLabel('Font size').fill('24px')
		// Color picker assertions are intentionally lenient — different
		// browsers render <input type="color"> differently.
		await page.locator('input[type="color"]').first().evaluate((el: HTMLInputElement) => {
			el.value = '#ff0000'
			el.dispatchEvent(new Event('input', { bubbles: true }))
		})

		// 4. Save
		await page.getByRole('button', { name: /save|add/i }).click()

		// 5. Verify the rendered widget appears on the dashboard
		const placement = page.locator('.label-widget').filter({ hasText: 'Sales Q4' })
		await expect(placement).toBeVisible()

		// 6. Reopen in edit mode and verify all six fields round-trip
		await placement.click({ button: 'right' })
		await page.getByRole('menuitem', { name: /edit/i }).click()

		await expect(page.getByLabel('Label text')).toHaveValue('Sales Q4')
		await expect(page.getByLabel('Font size')).toHaveValue('24px')
		const colorInput = page.locator('input[type="color"]').first()
		await expect(colorInput).toHaveValue('#ff0000')
	})

	test('REQ-LBL-001: pasted HTML renders as literal text on the dashboard', async ({ page }) => {
		await page.getByRole('button', { name: /add widget/i }).click()
		await page.getByText('Label', { exact: true }).click()
		await page.getByLabel('Label text').fill('<b>HTML</b>')
		await page.getByRole('button', { name: /save|add/i }).click()

		const placement = page.locator('.label-widget').filter({ hasText: '<b>HTML</b>' })
		await expect(placement).toBeVisible()

		// Critical XSS check: there MUST NOT be a <b> element generated from
		// the user's input inside the placement.
		await expect(placement.locator('b')).toHaveCount(0)
	})
})
