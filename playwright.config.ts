/*
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright runner configuration for the mydash end-to-end suite.
 *
 * The suite assumes:
 *   - A reachable Nextcloud instance at NC_BASE_URL (default
 *     http://localhost:8080) with the mydash app installed.
 *   - Admin credentials NC_ADMIN_USER / NC_ADMIN_PASS (defaults
 *     admin / admin) usable to log in via /login.
 *   - The shared admin storage state is created once per run by
 *     `tests/e2e/global-setup.ts` and reused across every spec via
 *     `use.storageState`.
 *
 * Running:
 *   npm run test:e2e           # headless, list reporter
 *   npm run test:e2e:ui        # interactive UI mode
 *   npm run test:e2e:headed    # headed chrome (debugging)
 *
 * The Nextcloud test environment is single-user; we cap workers at 1 to
 * avoid race conditions on shared state (active dashboard, default
 * dashboard flags, group membership).
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

const baseURL = process.env.NC_BASE_URL ?? 'http://localhost:8080'

export default defineConfig({
	testDir: './tests/e2e',
	testIgnore: ['**/global-setup.ts', '**/fixtures/**'],
	timeout: 30_000,
	expect: {
		timeout: 5_000,
	},
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	use: {
		baseURL,
		storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		actionTimeout: 10_000,
		navigationTimeout: 15_000,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
