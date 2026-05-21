# Tasks — footer-customization

## Backend Setup

- [ ] **Task 1:** Create migration `Version20260521*FooterSettings.php` to add two columns to `oc_mydash_dashboards`: `dashboardFooterMode` (VARCHAR 16, default 'inherit') and `dashboardFooterHtml` (LONGTEXT nullable); verify migration runs on install and upgrade
- [ ] **Task 2:** Create `lib/Service/HtmlSanitiserService.php` with allowlist-based sanitisation (allowed tags: `<a>` with href only, `<p>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`, `<img>` with src only); strip forbidden attributes (`class`, `style`, `data-*`, etc.); add `rel="noopener noreferrer"` and `target="_blank"` to external links; block data URIs in img src; handle nesting; write unit tests covering all scenarios from REQ-FTR-002 and REQ-FTR-005
- [ ] **Task 3:** Create `lib/Service/FooterService.php` with methods:
  - `getGlobalFooterSettings()` — returns all five admin settings with defaults
  - `validateFooterConfig($config)` — validates structured config schema (REQ-FTR-003); reject unknown keys with HTTP 400
  - `validateColors($bgColor, $textColor)` — validate hex color strings (REQ-FTR-009); reject invalid with HTTP 400
  - `resolveDashboardFooter($userId, $dashboard)` — implements per-dashboard resolution logic (inherit/hidden/custom) and language fallback chain (viewer locale → dashboard language → first key); returns resolved footer content
  - write unit tests for language fallback (REQ-FTR-007), per-dashboard modes (REQ-FTR-006), invariant enforcement (custom mode requires HTML), and empty-string handling

## API & Admin Settings

- [ ] **Task 4:** Create `lib/Controller/AdminFooterSettingsController.php` with:
  - `GET /api/admin/footer-settings` — return all five settings (admin-only); HTTP 403 for non-admin
  - `PUT /api/admin/footer-settings` — accept partial PATCH; validate HTML size (max 8KB, HTTP 413 if exceeded), validate footerConfig schema, validate colors; sanitise footer_html via HtmlSanitiserService; return `{"status": "ok"}` on success; HTTP 403 for non-admin; untouched settings retain their values
  - write integration tests covering all scenarios from REQ-FTR-001, REQ-FTR-002, REQ-FTR-003, REQ-FTR-009, REQ-FTR-010

- [ ] **Task 5:** Extend `lib/Controller/DashboardController.php` (or relevant endpoint) to include `dashboardFooterMode` and `dashboardFooterHtml` in:
  - `GET /api/dashboard/{uuid}` response
  - `PUT /api/dashboard/{uuid}` request body (accept and persist these two fields)
  - add owner-only check: non-owner cannot set custom footer (HTTP 403); REQ-FTR-006 Scenario 6
  - enforce invariant: if mode='custom', HTML must be non-null and non-empty; if mode != 'custom', HTML must be null (clears stale HTML on mode change); REQ-FTR-006 Scenario 5
  - write tests for REQ-FTR-006 all scenarios

## Frontend Components

- [ ] **Task 6:** Create `src/composables/useFooterResolver.js` composable that implements the three-step language fallback chain:
  - Step 1: check viewer's NC locale (from `getCurrentUser().locale` or `getUserValue('lang')`)
  - Step 2: check dashboard's `primaryLanguage` (from `dashboard-language-content` capability)
  - Step 3: use first key in variant map (deterministic, insertion-order)
  - handle single-language strings (no variant map) — return as-is
  - write unit tests for all scenarios in REQ-FTR-007

- [ ] **Task 7:** Create `src/components/DashboardFooter.vue` component that:
  - accepts props: `footer` (resolved content), `footerConfig` (structured JSON), `backgroundColor`, `textColor`
  - render global HTML mode: sanitised footer_html in a div with theme colors (fallback: `--primary-background-color`, `--primary-text-color` CSS vars)
  - render structured config modes:
    - `columns`: 3-column CSS grid (gap ~2rem) with logo+org in col1, address in col2, links+legal in col3
    - `inline`: single horizontal row with items separated by CSS `::before` pseudo-element (vertical bar or dot); mobile-responsive (flex-wrap: wrap)
  - include `@media print { ... }` rule ensuring footer is visible and printer-friendly in PDF (REQ-FTR-008)
  - handle missing fields in structured config gracefully (skip rendering if not present)
  - write unit tests for both HTML and structured render modes, color fallback logic, print media query

- [ ] **Task 8:** Create `src/api/admin-footer.js` API client with:
  - `getAdminFooterSettings()` — GET `/api/admin/footer-settings`
  - `updateAdminFooterSettings(patch)` — PUT `/api/admin/footer-settings` with partial object
  - proper error handling and response validation

## Integration

- [ ] **Task 9:** Integrate `DashboardFooter.vue` into the dashboard layout:
  - locate the main dashboard template (e.g., `src/pages/Workspace.vue` or the workspace dashboard layout)
  - place `<DashboardFooter />` component below the dashboard grid
  - pass resolved footer content via composable or store; use `useFooterResolver` to compute the resolved footer at render time based on current user and dashboard
  - ensure footer is only rendered when `footer_enabled = true` (or per-dashboard custom mode is active)
  - test that footer appears/disappears correctly based on global + per-dashboard settings

- [ ] **Task 10:** Extend the dashboard API response transformer to compute `effectiveFooter` field (optional, recommended):
  - when rendering a dashboard GET response, resolve the effective footer using `FooterService::resolveDashboardFooter()`
  - include the resolved content in the response as an optional `effectiveFooter` field for client-side caching (REQ-FTR-010 Scenario 5)

## Quality & Testing

- [ ] **Task 11:** Write PHPUnit tests for:
  - `HtmlSanitiserService` — all sanitisation scenarios (REQ-FTR-002, REQ-FTR-005)
  - `FooterService::validateFooterConfig()` — valid/invalid schemas, unknown keys, partial configs (REQ-FTR-003)
  - `FooterService::validateColors()` — valid hex, invalid values, nulls (REQ-FTR-009)
  - `FooterService::resolveDashboardFooter()` — all three per-dashboard modes, language fallback, edge cases (REQ-FTR-006, REQ-FTR-007)
  - `AdminFooterSettingsController` — all API scenarios (REQ-FTR-010)
  - Dashboard API — footer fields in GET/PUT responses, invariant enforcement (REQ-FTR-006)

- [ ] **Task 12:** Write Vitest tests for:
  - `useFooterResolver` — language fallback chain, single-language strings, missing primary language (REQ-FTR-007)
  - `DashboardFooter.vue` — render HTML mode, render columns layout, render inline layout, color fallback, print media query
  - `admin-footer.js` API client — request/response shape, error handling

- [ ] **Task 13:** Write Playwright E2E tests:
  - Admin user navigates to footer settings page (if UI provided) or uses API directly
  - Admin sets `footer_enabled = true` with footer HTML; global footer appears on all dashboards
  - Admin sets `footer_config` with structured data (columns layout); footer renders with 3-column grid
  - Dashboard owner overrides footer on their dashboard (mode='custom'); custom footer renders on that dashboard only
  - Admin disables global footer (`footer_enabled = false`); footers disappear except for dashboards with custom override
  - Admin sets language-tagged footer variants; viewer sees the matching language variant
  - User prints dashboard to PDF; footer is visible and readable in the PDF
  - Verify footer respects theme colors (light/dark mode switching)

- [ ] **Task 14:** Code quality checks:
  - ESLint clean on all touched JS/Vue files
  - PHPCS + PHPMD + PHPStan + Psalm pass (run `composer check:strict`)
  - No new frontend regressions: test existing dashboard functionality (grid layout, widget interactions, etc.) still work
  - i18n review: no new user-facing strings expected; if any are introduced (e.g., error messages in admin UI), add them to both `nl` and `en` locale files

## Verification

- [ ] **Task 15:** Run `openspec validate` to verify spec completeness and consistency
- [ ] **Task 16:** All tests pass (PHPUnit, Vitest, Playwright)
- [ ] **Task 17:** Manual smoke test:
  - Fresh install: footer not rendered by default (footer_enabled=false)
  - Admin enables footer with HTML; footer appears on all dashboards
  - Admin sets structured config; footer renders with correct layout
  - Dashboard owner customizes footer; custom footer appears only on that dashboard
  - User views dashboard in different language; correct language variant renders
  - Print to PDF; footer is visible

## Documentation

- [ ] **Task 18:** Add inline code comments to `FooterService.php` and `useFooterResolver.js` explaining:
  - Three-step language fallback chain (REQ-FTR-007 Design D3)
  - Sanitisation allowlist rationale (shared with text-display widget, REQ-FTR-005 Design D4)
  - Per-dashboard override modes and invariants (REQ-FTR-006 Design D2)

- [ ] **Task 19:** Add changelog entry covering:
  - New admin footer settings (HTML + structured modes)
  - Per-dashboard footer override capability
  - Multi-language footer variants
  - Theme-aware colors with admin override

## Rollback Plan

Pure backend (migration, services, API) + pure frontend (components, composables, API client). Reverting the PR:
1. Removes migration (existing data in `dashboardFooterMode` and `dashboardFooterHtml` columns remains but is unused)
2. Removes all new backend/frontend code
3. No data loss; dashboard rows unchanged
4. Admin settings keys are removed from the app config (standard behavior when app is disabled/removed)
