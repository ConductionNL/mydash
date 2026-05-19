/*
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright end-to-end test for the `text` widget covering tasks 7.1..7.3
 * of the `text-display-widget` OpenSpec change.
 *
 * NOTE: Playwright infrastructure is not yet wired up in mydash. This file
 * is committed alongside the rest of the change so it runs once the cohort-
 * wide Playwright bootstrap lands. Do not delete — it is the canonical e2e
 * coverage for REQ-TXT-001, REQ-TXT-003, REQ-TXT-004.
 */

import { test, expect } from '@playwright/test'

const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

test.describe('text-display widget', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${NEXTCLOUD_URL}/index.php/apps/mydash`)
		// Tests assume the user is already authenticated via Playwright
		// storageState; in CI this is set up by the Hydra harness.
	})

	test('add → fill → save → reload renders text and survives round-trip', async ({ page }) => {
		// 1. Open Add Widget modal
		await page.getByRole('button', { name: /add widget/i }).click()

		// 2. Pick the Text type
		await page.getByText('Text', { exact: true }).click()

		// 3. Fill the form
		await page.getByLabel('Text').fill('Hello <b>world</b>')
		await page.getByLabel('Font Size').fill('20px')

		// 4. Save
		await page.getByRole('button', { name: /save|add/i }).click()

		// 5. Verify the rendered widget appears on the dashboard with the
		//    sanitised HTML in place — <b> survives, no <script> ever could.
		const placement = page.locator('.text-display-widget').filter({ hasText: 'Hello world' })
		await expect(placement).toBeVisible()
		await expect(placement.locator('b')).toHaveCount(1)

		// 6. Reload the page — the placement must still render the same content
		await page.reload()
		const reloaded = page.locator('.text-display-widget').filter({ hasText: 'Hello world' })
		await expect(reloaded).toBeVisible()
	})

	test('REQ-TXT-004: edit mode pre-fills, change → save → renders new values', async ({ page }) => {
		// Assumes the previous test left a placement; if not, re-create.
		const placement = page.locator('.text-display-widget').first()
		await placement.click({ button: 'right' })
		await page.getByRole('menuitem', { name: /edit/i }).click()

		await expect(page.getByLabel('Text')).not.toBeEmpty()

		await page.getByLabel('Text').fill('Updated content')
		await page.getByLabel('Font Size').fill('32px')
		await page.getByRole('button', { name: /save|add/i }).click()

		const updated = page.locator('.text-display-widget').filter({ hasText: 'Updated content' })
		await expect(updated).toBeVisible()
		const innerStyle = await updated.locator('.text-display-widget__content').getAttribute('style')
		expect(innerStyle || '').toContain('font-size: 32px')
	})

	test('REQ-TXT-003: empty-text widget shows the localised placeholder', async ({ page }) => {
		// Add a text widget but leave the text blank — the modal should
		// keep the Add button disabled until validate() returns []. To
		// exercise the placeholder branch we create the placement directly
		// via the API and then assert the rendered fallback.
		await page.evaluate(async () => {
			await fetch('/index.php/apps/mydash/api/widget-placements', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					type: 'text',
					styleConfig: { type: 'text', content: { text: '' } },
				}),
			})
		})
		await page.reload()
		const empty = page.locator('.text-display-widget__placeholder').first()
		await expect(empty).toBeVisible()
		await expect(empty).toHaveText('No text content')
	})
})
