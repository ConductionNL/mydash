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
	// Root-level ignore covers fixtures only. The `docs-screenshots`
	// spec is filtered per-project below — keeping it out of the root
	// ignore so the `docs-capture` project can still match it.
	testIgnore: [
		'**/global-setup.ts',
		'**/fixtures/**',
	],
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
		navigationTimeout: 60_000,
	},
	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts'],
			use: { ...devices['Desktop Chrome'] },
		},
		// Dedicated project for the documentation capture spec. Opt in:
		//   npx playwright test --project docs-capture
		// Output lands in `docs/screenshots/tutorials/{user,admin}/`.
		{
			name: 'docs-capture',
			testMatch: /docs-screenshots\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
			},
			timeout: 90_000,
		},
	],
})
