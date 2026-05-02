/*
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright end-to-end test for the `image` widget covering tasks 4.7..4.9
 * of the `image-widget` OpenSpec change.
 *
 * NOTE: Playwright infrastructure is not yet wired up in mydash. This file
 * is committed alongside the rest of the change so it runs once the cohort-
 * wide Playwright bootstrap lands. Do not delete — it is the canonical e2e
 * coverage for REQ-IMG-002, REQ-IMG-003, REQ-IMG-005.
 */

import { test, expect } from '@playwright/test'
import * as path from 'path'

const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

test.describe('image widget', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${NEXTCLOUD_URL}/index.php/apps/mydash`)
		// Tests assume the user is already authenticated via Playwright
		// storageState; in CI this is set up by the Hydra harness.
	})

	test('REQ-IMG-005: upload → preview → save → reload still shows image', async ({ page }) => {
		await page.getByRole('button', { name: /add widget/i }).click()
		await page.getByLabel('Widget type').selectOption({ label: 'Image' })

		// Upload a tiny PNG bundled with the test fixtures.
		const fileChooserPromise = page.waitForEvent('filechooser')
		await page.getByLabel('Upload Image').click()
		const fc = await fileChooserPromise
		await fc.setFiles(path.join(__dirname, 'fixtures', 'tiny.png'))

		// Wait for the upload to complete (form.url populated → preview visible).
		const preview = page.locator('.image-form__preview')
		await expect(preview).toBeVisible()

		await page.getByRole('button', { name: /save|add/i }).click()

		// Verify the rendered widget appears on the dashboard.
		const placement = page.locator('.image-widget__img')
		await expect(placement).toBeVisible()

		// Reload and verify persistence.
		await page.reload()
		await expect(page.locator('.image-widget__img')).toBeVisible()
	})

	test('REQ-IMG-003: external URL with click-through opens new tab', async ({ context, page }) => {
		await page.getByRole('button', { name: /add widget/i }).click()
		await page.getByLabel('Widget type').selectOption({ label: 'Image' })
		await page.getByLabel('Or enter Image URL').fill('https://placehold.co/200x200.png')
		await page.getByLabel('Link (optional)').fill('https://example.com')
		await page.getByRole('button', { name: /save|add/i }).click()

		const cell = page.locator('.image-widget')
		await expect(cell).toBeVisible()

		// Click triggers a new tab via window.open(..., '_blank',
		// 'noopener,noreferrer'). Wait for the new page on the context.
		const popupPromise = context.waitForEvent('page')
		await cell.click()
		const popup = await popupPromise
		await popup.waitForLoadState()
		expect(popup.url()).toMatch(/example\.com/)
	})

	test('REQ-IMG-002: empty-URL cell shows camera placeholder and ignores clicks', async ({ context, page }) => {
		await page.getByRole('button', { name: /add widget/i }).click()
		await page.getByLabel('Widget type').selectOption({ label: 'Image' })
		// Force-save with empty URL by typing whitespace then clearing — the
		// validator blocks save, so for the placeholder rendering test we use
		// the API to seed a placement directly. Skipped here as a TODO once
		// the cohort-wide test fixtures expose a programmatic seed helper.
		test.skip(true, 'Programmatic seed helper not yet available; placeholder rendering covered by Vitest unit test.')
	})
})
