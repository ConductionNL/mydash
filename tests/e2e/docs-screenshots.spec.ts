/*
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Documentation screenshot capture suite.
 *
 * This spec is *not* a regression test — its only job is to drive the
 * MyDash UI through every flow documented under
 * `docs/tutorials/{user,admin}/*.md` and write a fresh PNG into
 * `docs/screenshots/tutorials/<track>/<filename>.png` for each step the
 * markdown references.
 *
 * Run it manually whenever the UI changes and the tutorial screenshots
 * need to be refreshed:
 *
 *     NC_BASE_URL=http://localhost:8080 \
 *     NC_ADMIN_USER=admin NC_ADMIN_PASS=admin \
 *       npx playwright test tests/e2e/docs-screenshots.spec.ts \
 *           --project chromium --headed
 *
 * The headless run also works — `--headed` is just useful for debugging
 * when a selector misses. The viewport is fixed to 1280×800 so every
 * docs screenshot has identical dimensions.
 *
 * The capture commands are skipped from the regular `npm run test:e2e`
 * default project list — a separate `docs-capture` project flag in
 * `playwright.config.ts` opts in. Adding it to the default would make
 * every PR pipeline reshoot screenshots, which is wasteful and noisy.
 *
 * The spec is order-dependent inside each describe block: later steps
 * rely on the dashboard / widget state created by earlier ones. Each
 * describe block is independent so the suite can be re-run partially
 * via `--grep` (e.g. `--grep "user track"`).
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(__dirname, '..', '..', 'docs', 'static', 'screenshots', 'tutorials')

/**
 * Save a screenshot under `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNGs into the build root —
 * markdown image refs use the absolute `/screenshots/...` path the static
 * dir resolves to. Ensures the destination directory exists.
 */
async function shoot(page: Page, track: 'user' | 'admin', file: string): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

/**
 * Open the slide-in dashboard sidebar.
 */
async function openSidebar(page: Page): Promise<void> {
	const toggle = page.locator('.mydash-sidebar-toggle').first()
	await toggle.click()
	await expect(page.locator('.dashboard-switcher-sidebar')).toBeVisible()
}

/**
 * Close the sidebar via its close button.
 */
async function closeSidebar(page: Page): Promise<void> {
	await page.locator('.dashboard-switcher-sidebar__close').click()
	await expect(page.locator('.dashboard-switcher-sidebar')).toHaveCount(0)
}

/**
 * Open the per-row cog menu for the dashboard whose label matches `name`.
 * Returns once the popover is visible.
 */
async function openCogFor(page: Page, name: string): Promise<void> {
	const row = page.locator('.dashboard-switcher-sidebar__item', { hasText: name })
	await row.locator('.dashboard-switcher-sidebar__cog, [aria-label="Dashboard menu"]').click()
	await expect(page.getByRole('menuitem').first()).toBeVisible()
}

// Capture flows are independent — each test re-navigates from `/apps/mydash/`
// so a selector miss on one doesn't cascade. Selector misses are the
// expected first-run failure mode (UI markup drifts faster than docs);
// failures land per-test in `test-results/` rather than killing the suite.
// Switch to `mode: 'serial'` if you actually need state continuity.
test.describe.configure({ mode: 'default' })

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
	await page.goto('/apps/mydash/')
	await page.waitForSelector('.mydash-sidebar-toggle', { timeout: 15_000 })
})

// ---------------------------------------------------------------------------
// USER TRACK
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('U1 first launch — overview + sidebar + cog', async ({ page }) => {
		// 01-first-launch-overview: the freshly-loaded workspace
		await shoot(page, 'user', '01-first-launch-overview.png')

		// 02-sidebar-open: sidebar slid in
		await openSidebar(page)
		await shoot(page, 'user', '02-sidebar-open.png')

		// 03-cog-menu: per-row cog popover for the active dashboard
		const activeName = await page.locator('.dashboard-switcher-sidebar__item.active .dashboard-switcher-sidebar__label').first().innerText()
		await openCogFor(page, activeName.trim())
		await shoot(page, 'user', '03-cog-menu.png')
	})

	test('U2 create a new dashboard — button, modal, success', async ({ page }) => {
		await openSidebar(page)
		// 02-create-add-button: highlight the + Add dashboard CTA
		await shoot(page, 'user', '02-create-add-button.png')

		// 02-create-modal: the configuration modal mid-form. Hooked on
		// `data-testid` attributes baked into DashboardConfigModal.vue.
		await page.locator('[data-action="create"]').click()
		await page.locator('[data-testid="dashboard-name-input"] input').waitFor({ state: 'visible', timeout: 5000 })
		await page.locator('[data-testid="dashboard-name-input"] input').fill(`Docs example ${Date.now()}`)
		await page.locator('[data-testid="dashboard-description-input"]').fill('Created by docs-screenshots.spec.ts')
		await shoot(page, 'user', '02-create-modal.png')

		// Save & screenshot the resulting dashboard with the default bundle
		await page.locator('[data-testid="dashboard-save-button"]').click()
		await page.waitForLoadState('networkidle')
		await shoot(page, 'user', '02-create-success.png')
	})

	test('U3 add a widget — edit-mode → picker → form → added', async ({ page }) => {
		await openSidebar(page)
		const activeName = await page.locator('.dashboard-switcher-sidebar__item.active .dashboard-switcher-sidebar__label').first().innerText()
		await openCogFor(page, activeName.trim())
		// 03-edit-mode-enter: cog menu with Edit dashboard highlighted
		await shoot(page, 'user', '03-edit-mode-enter.png')

		await page.locator('[data-testid="cog-edit-dashboard"]').click()
		await page.waitForTimeout(500) // grid transition

		// Open the AddWidgetModal via the cog → Add custom widget…
		await openCogFor(page, activeName.trim())
		await page.locator('[data-testid="cog-add-widget"]').click()
		await page.locator('[data-testid="widget-type-select"]').waitFor({ state: 'visible', timeout: 5000 })
		// 03-widget-picker: the type picker (default = label widget)
		await shoot(page, 'user', '03-widget-picker.png')

		// Switch to the text widget type — its sub-form has a markdown
		// body that's easy to fill. The select drives the per-type form
		// swap via the registry (REQ-WDG-014).
		await page.locator('[data-testid="widget-type-select"]').selectOption('text')
		await page.waitForTimeout(300)
		const body = page.locator('textarea[placeholder*="markdown" i], textarea[placeholder*="text" i]').first()
		if (await body.count() > 0) {
			await body.fill('# Hello docs\n\nThis was added by `docs-screenshots.spec.ts`.')
		}
		await shoot(page, 'user', '03-widget-form.png')

		await page.locator('[data-testid="add-widget-save"]').click()
		await page.waitForLoadState('networkidle')
		await shoot(page, 'user', '03-widget-added.png')
	})

	test('U4 reposition & resize — edit mode + drag + drop + resize + save', async ({ page }) => {
		// 04-edit-mode: post-edit-mode-toggle grid
		await openSidebar(page)
		const activeName = await page.locator('.dashboard-switcher-sidebar__item.active .dashboard-switcher-sidebar__label').first().innerText()
		await openCogFor(page, activeName.trim())
		await page.locator('[data-testid="cog-edit-dashboard"]').click()
		await page.waitForTimeout(500)
		await shoot(page, 'user', '04-edit-mode.png')

		// 04-dragging: mid-drag — start a drag, screenshot before drop.
		// `[data-testid^="widget-placement-"]` matches the WidgetWrapper
		// root for any placement (id is appended for uniqueness).
		const firstWidget = page.locator('[data-testid^="widget-placement-"]').first()
		const box = await firstWidget.boundingBox()
		if (box) {
			await page.mouse.move(box.x + box.width / 2, box.y + 30)
			await page.mouse.down()
			await page.mouse.move(box.x + 240, box.y + 120, { steps: 10 })
			await shoot(page, 'user', '04-dragging.png')
			await page.mouse.up()
			await page.waitForTimeout(300)
			// 04-reflowed: post-drop grid
			await shoot(page, 'user', '04-reflowed.png')
		}

		// 04-resizing: corner-resize a widget. GridStack injects the
		// `.ui-resizable-handle.ui-resizable-se` element on the wrapper
		// in edit mode — that's a third-party class we can't control,
		// but the stable testid on the placement scopes the lookup.
		const resizable = page.locator('[data-testid^="widget-placement-"]').first()
		const corner = resizable.locator('.ui-resizable-handle.ui-resizable-se, .gs-resize-handle').first()
		if (await corner.count() > 0) {
			const cbox = await corner.boundingBox()
			if (cbox) {
				await page.mouse.move(cbox.x + 4, cbox.y + 4)
				await page.mouse.down()
				await page.mouse.move(cbox.x + 120, cbox.y + 60, { steps: 10 })
				await shoot(page, 'user', '04-resizing.png')
				await page.mouse.up()
				await page.waitForTimeout(300)
			}
		}

		// 04-save-layout: cog menu showing Save dashboard while in edit mode
		await openSidebar(page)
		await openCogFor(page, activeName.trim())
		await shoot(page, 'user', '04-save-layout.png')
	})

	test('U5 edit content & style — context menu, edit modal, style modal', async ({ page }) => {
		const widget = page.locator('[data-testid^="widget-placement-"]').first()

		// 05-context-menu: right-click anchored popover
		await widget.click({ button: 'right' })
		await page.locator('[data-testid="widget-context-menu"]').waitFor({ state: 'visible', timeout: 5000 })
		await shoot(page, 'user', '05-context-menu.png')

		// 05-edit-content: Edit → AddWidgetModal pre-filled
		await page.locator('[data-testid="ctx-edit"]').click()
		await page.waitForTimeout(800)
		await shoot(page, 'user', '05-edit-content.png')
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// 05-style-editor: opens via the per-widget cog button (visible
		// only in edit mode). Enter edit mode first if not already.
		await openSidebar(page)
		const activeName = await page.locator('.dashboard-switcher-sidebar__item.active .dashboard-switcher-sidebar__label').first().innerText()
		await openCogFor(page, activeName.trim())
		const editEntry = page.locator('[data-testid="cog-edit-dashboard"]')
		if (await editEntry.count() > 0) {
			await editEntry.click()
			await page.waitForTimeout(500)
		} else {
			await closeSidebar(page)
		}

		const cog = widget.locator('[data-testid="widget-edit-cog"]').first()
		if (await cog.count() > 0) {
			await cog.click()
			await page.locator('[data-testid="widget-style-editor"]').waitFor({ state: 'visible', timeout: 5000 })
			await shoot(page, 'user', '05-style-editor.png')
			await page.keyboard.press('Escape')
		}
	})

	test('U6 remove widget — context menu + after', async ({ page }) => {
		const widget = page.locator('[data-testid^="widget-placement-"]').last()
		await widget.click({ button: 'right' })
		await page.locator('[data-testid="widget-context-menu"]').waitFor({ state: 'visible', timeout: 5000 })
		// reuse 05-context-menu.png; capture only the after state
		await page.locator('[data-testid="ctx-remove"]').click()
		await page.waitForTimeout(500)
		await shoot(page, 'user', '06-after-remove.png')
	})

	test('U7 set as default — sidebar with star marker', async ({ page }) => {
		await openSidebar(page)
		const activeName = await page.locator('.dashboard-switcher-sidebar__item.active .dashboard-switcher-sidebar__label').first().innerText()
		await openCogFor(page, activeName.trim())
		// Whether the row is currently pinned or not, the same testid hits.
		await page.locator('[data-testid="cog-set-default"]').click()
		await page.waitForTimeout(300)

		// 07-default-marker: sidebar with the star next to the pinned row
		await openSidebar(page)
		await shoot(page, 'user', '07-default-marker.png')

		// 07-fallback-marker: toggle the pin off again — the star should
		// fall back to the resolver's group default.
		await openCogFor(page, activeName.trim())
		await page.locator('[data-testid="cog-set-default"]').click()
		await page.waitForTimeout(300)
		await openSidebar(page)
		await shoot(page, 'user', '07-fallback-marker.png')
	})

	test('U8 deep-link — URL bar + landed + URL after switch', async ({ page }) => {
		// 08-url-bar: just a clean shot of the URL bar with a slug
		await shoot(page, 'user', '08-url-bar.png')

		// 08-deep-link-landed: navigate to a known slug and screenshot the landed state
		const slug = 'newman-validation' // assumed to exist in the seeded test env; adjust as needed
		await page.goto(`/apps/mydash/${slug}`)
		await page.waitForSelector('.mydash-sidebar-toggle')
		await shoot(page, 'user', '08-deep-link-landed.png')

		// 08-url-after-switch: switch to a different dashboard via the sidebar
		await openSidebar(page)
		const otherRow = page.locator('.dashboard-switcher-sidebar__item:not(.active)').first()
		await otherRow.click()
		await page.waitForTimeout(500)
		await shoot(page, 'user', '08-url-after-switch.png')
	})

	test('U9 switch dashboards — after-switch grid', async ({ page }) => {
		await openSidebar(page)
		const otherRow = page.locator('.dashboard-switcher-sidebar__item:not(.active)').first()
		await otherRow.click()
		await page.waitForTimeout(500)
		await shoot(page, 'user', '09-after-switch.png')
	})

	test('U10 rename or delete — config modal + delete confirm', async ({ page }) => {
		await openSidebar(page)
		const activeName = await page.locator('.dashboard-switcher-sidebar__item.active .dashboard-switcher-sidebar__label').first().innerText()
		await openCogFor(page, activeName.trim())
		await page.locator('[data-testid="cog-dashboard-config"]').click()
		await page.locator('[data-testid="dashboard-name-input"]').waitFor({ state: 'visible', timeout: 5000 })
		await shoot(page, 'user', '10-config-modal.png')

		// 10-delete-confirm: open delete prompt without confirming. The
		// confirm dialog is the browser-native window.confirm; no
		// screenshot to capture, so we just shoot the modal-with-delete-
		// button-highlighted state.
		const deleteBtn = page.locator('[data-testid="dashboard-delete-button"]')
		if (await deleteBtn.count() > 0) {
			// Set up a handler to dismiss the confirm dialog automatically
			page.once('dialog', d => d.dismiss())
			await deleteBtn.scrollIntoViewIfNeeded()
			await shoot(page, 'user', '10-delete-confirm.png')
		}
		await page.keyboard.press('Escape')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/settings/admin/mydash')
		await page.waitForLoadState('networkidle')
	})

	test('A1 toggle personal dashboards', async ({ page }) => {
		// 01-admin-settings: the full admin page above-the-fold
		await page.locator('[data-testid="admin-default-settings"]').scrollIntoViewIfNeeded()
		await shoot(page, 'admin', '01-admin-settings.png')

		// 01-toggle: zoom in on the Default settings section so the
		// "Allow users to create custom dashboards" switch is the focal
		// point of the screenshot.
		await page.locator('[data-testid="admin-allow-user-dashboards"]').scrollIntoViewIfNeeded()
		await shoot(page, 'admin', '01-toggle.png')

		// 01-user-disabled: requires actually flipping the toggle off and
		// switching to a non-admin user; skipped here so an automated
		// docs run never leaves the test instance with personal
		// dashboards disabled.
	})

	test('A2 admin templates — list + create + edit + test-user view', async ({ page }) => {
		await page.locator('[data-testid="admin-templates-section"]').scrollIntoViewIfNeeded()
		await shoot(page, 'admin', '02-templates-list.png')

		await page.locator('[data-testid="admin-create-template"]').click()
		await page.waitForTimeout(500)
		await shoot(page, 'admin', '02-template-create.png')
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// 02-template-edit: best-effort — opens the first existing
		// template's edit affordance if any. Selector permissive because
		// the per-template Edit button isn't testid-instrumented yet.
		const firstEdit = page.locator('[data-testid="admin-templates-section"]').getByRole('button', { name: /^Edit$/ }).first()
		if (await firstEdit.count() > 0) {
			await firstEdit.click()
			await page.waitForLoadState('networkidle')
			await shoot(page, 'admin', '02-template-edit.png')
		}

		// 02-test-user-view: must be captured manually as a non-admin user
	})

	test('A3 group defaults — admin cog + member view', async ({ page }) => {
		// Switch back to MyDash workspace as admin to find a group dashboard
		await page.goto('/apps/mydash/')
		await page.waitForSelector('.mydash-sidebar-toggle')
		await openSidebar(page)
		const groupRow = page.locator('[data-source="group"], [data-source="default"]').first()
		if ((await groupRow.count()) > 0) {
			await groupRow.locator('[aria-label="Dashboard menu"]').click()
			await shoot(page, 'admin', '03-group-cog.png')

			const setForGroup = page.getByRole('menuitem', { name: /^Set as default for / })
			if ((await setForGroup.count()) > 0) {
				await setForGroup.click()
				await page.waitForTimeout(300)
				await shoot(page, 'admin', '03-set-group-default.png')
			}
		}
		// 03-member-default: capture manually as a non-admin member
	})

	test('A4 restrict widgets per role — section + create modal', async ({ page }) => {
		// 04-roles-tab: scroll the roles section into view (admin
		// settings is one long page, not tabbed)
		await page.locator('[data-testid="admin-roles-section"]').scrollIntoViewIfNeeded()
		await shoot(page, 'admin', '04-roles-tab.png')

		// 04-role-create: open the Add role permission modal
		await page.locator('[data-testid="admin-add-role"]').click()
		await page.waitForTimeout(500)
		await shoot(page, 'admin', '04-role-create.png')
		await page.keyboard.press('Escape')

		// 04-filtered-picker: capture manually as a non-admin in a restricted role
	})

	test('A5 bulk operations — panel scroll-into-view', async ({ page }) => {
		// 05-bulk-panel: bulk ops is a section on the same admin page,
		// not a separate tab. Scroll it into view and screenshot.
		await page.locator('[data-testid="admin-bulk-section"]').scrollIntoViewIfNeeded()
		await shoot(page, 'admin', '05-bulk-panel.png')

		// 05-bulk-filter, 05-bulk-move-target, 05-bulk-confirm: depend
		// on seeded multi-dashboard fixtures + a deeper data-test/testid
		// pass on `DashboardBulkOperations.vue`. Capture manually for now.
	})
})
