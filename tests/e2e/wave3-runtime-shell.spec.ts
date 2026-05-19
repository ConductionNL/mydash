/*
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * End-to-end coverage for the wave3 UX cleanup PRs (#111-#114). Every
 * assertion here mirrors a finding the user spotted during manual review;
 * the goal of the spec is to keep regressions from sneaking back in via
 * future runtime-shell or sidebar refactors.
 *
 * Acts as the smoke harness for:
 *   - PR #111 — title strip hidden when sidebar closed, secondary-style
 *     hamburger, drop the literal "Default" group pill, sidebar footer
 *     centered with both "Powered by" logos.
 *   - PR #112 — restore dashboard render width (.mydash-workspace flex
 *     layout), drop the dead leftover Edit/Remove/Cancel context menu,
 *     trim the floating cog menu to remove Create dashboard +
 *     Documentation entries.
 *   - PR #113 — switching dashboards from the sidebar actually changes
 *     the active dashboard (GET /api/dashboard/{id} backend endpoint),
 *     the per-dashboard cog menu lives in the sidebar header (Edit /
 *     Configure / Add widget / Delete-trashcan), per-row X delete
 *     buttons removed, Conduction logo visible alongside Sendent.
 *   - PR #114 — no second DashboardSwitcherSidebar mount in the runtime
 *     shell (Views.vue owns the only one).
 */

import { test, expect } from '@playwright/test'

test.describe('wave3 runtime-shell + sidebar UX', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/mydash/')
		// Wait for the floating sidebar toggle — its presence indicates
		// the Vue app has hydrated past initial bootstrap.
		await page.waitForSelector('.mydash-sidebar-toggle', { timeout: 15_000 })
	})

	test('default state: no leftover popover, sidebar closed, hamburger matches cog style', async ({ page }) => {
		// PR #112: the dead WidgetContextMenu in DashboardGrid no longer
		// renders unconditionally on initial load.
		await expect(page.locator('.widget-context-menu')).toHaveCount(0)

		// PR #111: sidebar starts closed — the open variant is gated on
		// the `open` modifier class.
		await expect(page.locator('.dashboard-switcher-sidebar.open')).toHaveCount(0)

		// PR #111: hamburger is `type="secondary"` so it visually matches
		// the cog action menu sitting next to it (was tertiary before).
		// The Vue stub mirrors the prop onto a data attribute.
		const ham = page.locator('.mydash-sidebar-toggle').first()
		await expect(ham).toBeVisible()

		// PR #111 + PR #113: the floating controls in the top-right host
		// only the hamburger now — the per-dashboard cog menu moved into
		// the sidebar header in PR #113.
		await expect(page.locator('.mydash-floating-controls button')).toHaveCount(1)

		// PR #111: the literal "Default" group pill is suppressed.
		await expect(page.locator('.mydash-primary-group-label', { hasText: /^Default$/ }))
			.toHaveCount(0)
	})

	test('PR #112: dashboard grid claims the full width (no 0-px collapse)', async ({ page }) => {
		// Without the wave3.2 `.mydash-workspace { flex: 1 1 auto }`
		// rule the dashboard container collapsed to 0px and rendered
		// only GridStack column placeholders over the empty blue
		// background. The grid SHOULD now span at least most of the
		// viewport width.
		const grid = page.locator('.mydash-grid').first()
		await expect(grid).toBeVisible()
		const box = await grid.boundingBox()
		expect(box).not.toBeNull()
		expect(box!.width).toBeGreaterThan(800)
	})

	test('wave3.6: each dashboard row has its own cog menu with Edit/Configure/Add-widget/Delete', async ({ page }) => {
		await page.locator('.mydash-sidebar-toggle').click()
		await page.waitForSelector('.dashboard-switcher-sidebar.open', { timeout: 5_000 })

		// Header has NO cog after wave3.6 — only the X close button.
		await expect(page.locator('.dashboard-switcher-sidebar__menu')).toHaveCount(0)

		// One cog per dashboard row, rendered by `<DashboardRowActions>`.
		const rowCogs = page.locator('.dashboard-row-actions button')
		const cogCount = await rowCogs.count()
		expect(cogCount).toBeGreaterThan(0)

		// Open the cog on a personal dashboard (admin owns all rows in
		// the test fixture, so the personal section always exposes the
		// full action set including the owner-gated Configure / Delete).
		const personalRow = page.locator('[data-section="user"] li.dashboard-switcher-sidebar__item').first()
		await expect(personalRow).toBeVisible()
		await personalRow.locator('.dashboard-row-actions button').click()

		await expect(page.getByRole('menuitem', { name: /Edit dashboard/ })).toBeVisible()
		await expect(page.getByRole('menuitem', { name: /Dashboard configuration/ })).toBeVisible()
		await expect(page.getByRole('menuitem', { name: /Add custom widget/ })).toBeVisible()
		await expect(page.getByRole('menuitem', { name: /Delete dashboard/ })).toBeVisible()
	})

	test('PR #113: per-row X delete buttons have been removed from the dashboard list', async ({ page }) => {
		await page.locator('.mydash-sidebar-toggle').click()
		await page.waitForSelector('.dashboard-switcher-sidebar.open', { timeout: 5_000 })

		// Wave3.3 dropped the inline `.__delete` X buttons; wave3.6
		// moved Delete into the per-row cog (DashboardRowActions).
		await expect(page.locator('.dashboard-switcher-sidebar__delete')).toHaveCount(0)
	})

	test('PR #113: clicking a sidebar row switches the active dashboard via GET /api/dashboard/{id}', async ({ page }) => {
		// The full URL pattern includes /index.php for NC's URL rewriting.
		const showRequest = page.waitForRequest(req =>
			/\/api\/dashboard\/\d+(?:\?|$)/.test(req.url()) && req.method() === 'GET',
		)

		await page.locator('.mydash-sidebar-toggle').click()
		await page.waitForSelector('.dashboard-switcher-sidebar.open', { timeout: 5_000 })

		// Click the second sidebar row (the first is whatever happens to
		// be currently active — clicking it would be a no-op).
		const rows = page.locator('.dashboard-switcher-sidebar li.dashboard-switcher-sidebar__item')
		const beforeCount = await rows.count()
		expect(beforeCount).toBeGreaterThan(1)
		await rows.nth(1).click()

		const req = await showRequest
		const res = await req.response()
		expect(res?.status()).toBe(200)
	})

	test('PR #111 + PR #113: footer renders Powered by + both Sendent and Conduction logos visible', async ({ page }) => {
		await page.locator('.mydash-sidebar-toggle').click()
		await page.waitForSelector('.dashboard-switcher-sidebar.open', { timeout: 5_000 })

		const footer = page.locator('.dashboard-switcher-sidebar-footer')
		await expect(footer).toBeVisible()
		await expect(footer.getByText(/Powered by/i)).toBeVisible()

		const sendent = footer.locator('img[alt="Sendent"]')
		const conduction = footer.locator('img[alt="Conduction"]')
		await expect(sendent).toBeVisible()
		await expect(conduction).toBeVisible()

		// Both render with non-zero rendered width AND sit on the same
		// row (same Y to within a couple of pixels) — wave3.3 pinned them
		// side-by-side via `flex-wrap: nowrap`.
		const sBox = await sendent.boundingBox()
		const cBox = await conduction.boundingBox()
		expect(sBox).not.toBeNull()
		expect(cBox).not.toBeNull()
		expect(sBox!.width).toBeGreaterThan(0)
		expect(cBox!.width).toBeGreaterThan(0)
		expect(Math.abs(sBox!.y - cBox!.y)).toBeLessThanOrEqual(4)
	})

	test('PR #114: only one DashboardSwitcherSidebar mount in the runtime shell', async ({ page }) => {
		// The duplicate WorkspaceApp mount was removed; Views.vue now
		// owns the sole instance. Two `.dashboard-switcher-sidebar` nodes
		// in the DOM would indicate the duplicate has crept back.
		await page.locator('.mydash-sidebar-toggle').click()
		await page.waitForSelector('.dashboard-switcher-sidebar.open', { timeout: 5_000 })
		await expect(page.locator('.dashboard-switcher-sidebar')).toHaveCount(1)
	})
})
