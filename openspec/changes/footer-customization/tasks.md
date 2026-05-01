# Tasks — footer-customization

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddFooterColumns.php` adding `dashboardFooterMode VARCHAR(16)` and `dashboardFooterHtml LONGTEXT` to `oc_mydash_dashboards`
- [ ] 1.2 Add default constraint `dashboardFooterMode = 'inherit'` (all existing rows get this value)
- [ ] 1.3 Add index on `dashboardFooterMode` for fast filtering if needed in future
- [ ] 1.4 Confirm migration is reversible (drop columns in `postSchemaChange` rollback path)
- [ ] 1.5 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Admin settings domain model

- [ ] 2.1 Add three new settings keys to `AdminSettings` entity: `footer_enabled` (bool, default false), `footer_html` (string, max 8KB, default empty), `footer_config` (JSON, default empty object)
- [ ] 2.2 Add theme override settings: `footer_background_color` (hex string, nullable) and `footer_text_color` (hex string, nullable)
- [ ] 2.3 Each setting MUST have getter/setter in `AdminSettingsService` for consistent read/write

## 3. Dashboard domain model

- [ ] 3.1 Add `dashboardFooterMode` field to `Dashboard` entity: enum values `inherit`, `hidden`, `custom` (default: `inherit`)
- [ ] 3.2 Add `dashboardFooterHtml` field to `Dashboard` entity (nullable LONGTEXT)
- [ ] 3.3 Both fields MUST include getter/setter following Entity `__call` pattern (no named args)
- [ ] 3.4 Update `Dashboard::jsonSerialize()` to include both fields in API responses

## 4. HTML sanitisation layer

- [ ] 4.1 Create `lib/Service/HtmlSanitiserService.php` that implements allowlist-based HTML sanitisation
- [ ] 4.2 Allowed tags: `<a>` (href, title only), `<p>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`, `<img>` (src only)
- [ ] 4.3 Force all `<a>` tags to have `rel="noopener noreferrer"` and `target="_blank"` on external URLs (scheme check: http, https)
- [ ] 4.4 Strip all other tags, attributes, event handlers, data URIs, style attributes
- [ ] 4.5 Max input size: 8192 bytes (reject oversized inputs with HTTP 413)
- [ ] 4.6 PHPUnit test: sanitiser preserves allowed tags, strips forbidden ones, handles edge cases (nested tags, empty tags, malformed HTML)

## 5. Admin settings endpoints + service layer

- [ ] 5.1 Add `AdminSettingsService::getFooterSettings(): array` returning all five footer settings as flat object
- [ ] 5.2 Add `AdminSettingsService::updateFooterSettings(array $patch): void` that validates/sanitises inputs before save
- [ ] 5.3 In `updateFooterSettings()`, if `footer_html` is provided, pass it through `HtmlSanitiserService::sanitise()`
- [ ] 5.4 If `footer_config` is provided, validate schema (keys match expected structure, no extra keys) before save
- [ ] 5.5 Add controller method `AdminSettingsController::getFooterSettings()` mapped to `GET /api/admin/footer-settings` (admin-only)
- [ ] 5.6 Add controller method `AdminSettingsController::updateFooterSettings()` mapped to `PUT /api/admin/footer-settings` (admin-only, calls service + sanitiser)
- [ ] 5.7 Both endpoints MUST carry admin auth attribute (#[RequireUserRole] or equivalent); return HTTP 403 for non-admin
- [ ] 5.8 Update `appinfo/routes.php` with both new routes

## 6. Dashboard footer override logic

- [ ] 6.1 In `DashboardService::updateDashboard()`, when `dashboardFooterMode` is set, validate it's one of `inherit`, `hidden`, `custom`
- [ ] 6.2 If mode is `custom`, require `dashboardFooterHtml` to be present (non-null, non-empty after trim)
- [ ] 6.3 If mode is `custom`, sanitise the provided `dashboardFooterHtml` via `HtmlSanitiserService`
- [ ] 6.4 If mode is `inherit` or `hidden`, clear `dashboardFooterHtml` to NULL on save (to avoid stale data)
- [ ] 6.5 Add `DashboardService::resolveFooterForDashboard(Dashboard $dashboard): ?array` that returns `{mode, html, colors}` or NULL if footer disabled
  - If dashboard mode = `hidden`: return NULL
  - If dashboard mode = `custom`: return `{mode: 'custom', html: sanitised dashboardFooterHtml, colors: ...}`
  - If dashboard mode = `inherit` (or NULL): check global `footer_enabled`; if false return NULL; if true return `{mode: 'global', html: footer_html or rendered footer_config, colors: ...}`
- [ ] 6.6 Propagate resolved footer to frontend via dashboard API response (new optional field `effectiveFooter`)

## 7. Footer rendering component (Vue 3)

- [ ] 7.1 Create `src/components/DashboardFooter.vue` SFC with props `{footer: Object, layoutMode: String}`
- [ ] 7.2 If `footer` is null/undefined, render nothing (v-if guard)
- [ ] 7.3 Parse language variants: if footer.html is an object (map), select variant matching user's NC locale (use `useI18n().locale.value`); fall back to first key if no match
- [ ] 7.4 Render HTML mode: v-html directive on sanitised HTML (server-sanitised, but double-check client-side strip of any `<script>`)
- [ ] 7.5 Render structured mode (layoutMode='columns' or 'inline'):
  - **columns**: 3-column CSS grid (gap 2rem)
    - Column 1: logo (img tag), organisation name
    - Column 2: address (rendered as lines)
    - Column 3: links list (ul/li with a tags), legal text
  - **inline**: single row, items separated by vertical bar or dot (use CSS ::before pseudo-element)
- [ ] 7.6 Apply theme-aware colours: use CSS custom properties --primary-text-color, --primary-background-color by default; if admin set overrides, use those (fallback to theme vars)
- [ ] 7.7 Make footer sticky or absolutely positioned at page bottom (clarify UX: is it sticky or fixed-height section?)
- [ ] 7.8 Add print stylesheet: `@media print { footer { ... } }` to ensure footer is visible in PDF exports

## 8. Admin UI for footer settings

- [ ] 8.1 Extend `src/views/AdminSettings.vue` with a new tab "Footer" alongside existing tabs
- [ ] 8.2 Add toggle "Enable footer" (controls `footer_enabled` setting)
- [ ] 8.3 Add tabs "HTML Mode" vs "Structured Mode" (controls which setting path is saved: `footer_html` vs `footer_config`)
- [ ] 8.4 HTML Mode UI:
  - Textarea for raw HTML (max 8KB)
  - "Info" box showing allowed tags + sanitisation rules
  - Live preview pane rendering the sanitised HTML
- [ ] 8.5 Structured Mode UI:
  - Form fields: Logo URL (input), Organisation (input), Address (textarea), Links (repeating rows: label/url pairs), Legal text (textarea), Copyright year (number input), Layout mode dropdown (columns/inline)
  - Save button serialises to JSON and stores in `footer_config`
  - Live preview pane showing rendered columns/inline layout with sample data
- [ ] 8.6 Add "Color overrides" section: two color pickers for background and text (optional, labeled "Advanced")
- [ ] 8.7 Multi-language support in UI:
  - If `footer_html` or any `footer_config` field is a language-tagged object, show tabs for each language variant
  - Allow editing en and nl variants separately
  - Fallback indicator showing which language is the default
- [ ] 8.8 Save button calls `PUT /api/admin/footer-settings` with sanitised payload
- [ ] 8.9 On load, call `GET /api/admin/footer-settings` and populate UI accordingly

## 9. Dashboard-level footer override UI

- [ ] 9.1 Extend dashboard edit/settings form with a "Footer" section
- [ ] 9.2 Add dropdown "Footer mode": inherit / hidden / custom
- [ ] 9.3 If mode='custom', show textarea for dashboard-specific footer HTML
- [ ] 9.4 When mode changes away from 'custom', clear the textarea (optional; suggest to user)
- [ ] 9.5 Live preview of final footer (inherited or custom) below the form
- [ ] 9.6 Save button includes `dashboardFooterMode` and `dashboardFooterHtml` in dashboard PATCH payload

## 10. Frontend store wiring

- [ ] 10.1 Extend `src/stores/dashboards.js` to cache footer settings from `GET /api/admin/footer-settings` in module state
- [ ] 10.2 Add `footerSettings` getter that returns cached settings (with fallback to `{footer_enabled: false}`)
- [ ] 10.3 Add `resolveFooterForDashboard(dashboard)` action that applies override logic (inherit → global, hidden → null, custom → dashboard HTML)
- [ ] 10.4 When dashboard list is fetched, populate each dashboard's `effectiveFooter` from backend (or compute client-side if backend doesn't return it)
- [ ] 10.5 Subscribe to settings changes: after `PUT /api/admin/footer-settings`, refresh cached footer state

## 11. DashboardFooter mount in App.vue

- [ ] 11.1 In `src/views/App.vue`, add `<DashboardFooter :footer="dashboardFooter" />` component below the main dashboard grid
- [ ] 11.2 Bind `dashboardFooter` to store getter that returns effective footer for active dashboard
- [ ] 11.3 Ensure DashboardFooter is mounted even on lazy-loaded dashboards (use watch or computed to track active dashboard UUID)

## 12. i18n strings

- [ ] 12.1 Add i18n keys for all new UI strings: "Enable footer", "HTML Mode", "Structured Mode", "Footer settings", "Logo URL", "Organisation name", "Address", "Links", "Legal text", "Copyright year", "Layout mode", "Color overrides", "Footer mode", "Inherit global footer", "Hide footer on this dashboard", "Custom footer for this dashboard", "Allowed HTML tags", "Sanitisation applied", etc.
- [ ] 12.2 Provide translations for `nl` and `en` in `translationfiles/` or `i18n/` directory
- [ ] 12.3 All error messages (oversize HTML, invalid schema, etc.) MUST have i18n keys in both languages

## 13. PHPUnit tests

- [ ] 13.1 `HtmlSanitiserServiceTest` — test allowlist enforcement, event handler stripping, external link rel-tagging
- [ ] 13.2 `AdminSettingsServiceTest::testFooterSettingsRoundTrip` — save and retrieve footer settings, verify JSON encoding/decoding
- [ ] 13.3 `AdminSettingsServiceTest::testFooterHtmlSanitisation` — confirm malicious input is stripped before save
- [ ] 13.4 `DashboardServiceTest::testResolveFooterForDashboard` — test all mode combinations (inherit, hidden, custom with/without global enabled)
- [ ] 13.5 `DashboardServiceTest::testDashboardFooterOverrideValidation` — mode must be one of three values; custom mode requires HTML; inherit/hidden clear HTML
- [ ] 13.6 `AdminSettingsControllerTest` — auth enforcement (non-admin gets 403), valid/invalid payloads, size limits
- [ ] 13.7 Dashboard API test: verify `dashboardFooterMode`, `dashboardFooterHtml`, `effectiveFooter` fields round-trip correctly

## 14. End-to-end Playwright tests

- [ ] 14.1 Admin user enables footer, sets HTML mode with sample content, verifies footer appears on dashboard
- [ ] 14.2 Admin switches to structured mode, fills form (logo, org, address, links), verifies columns layout renders
- [ ] 14.3 Admin sets footer_enabled = false, verifies footer disappears from dashboard
- [ ] 14.4 Dashboard owner overrides footer with custom mode, edits text, verifies override is visible on their dashboard only
- [ ] 14.5 Multi-language: admin sets en and nl variants, verify user sees correct language based on NC locale
- [ ] 14.6 Print test: export dashboard to PDF, verify footer is included in PDF (manual or via headless browser screenshot)
- [ ] 14.7 Theme awareness: change NC theme, verify footer colours respond to theme variables (or admin overrides if set)

## 15. Quality gates

- [ ] 15.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 15.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 15.3 Update OpenAPI spec / Postman collection with new endpoints
- [ ] 15.4 All i18n keys for both nl and en (per i18n requirement)
- [ ] 15.5 SPDX headers on every new PHP file (inside docblock per SPDX-in-docblock convention)
- [ ] 15.6 Run all hydra gates locally before opening PR
- [ ] 15.7 Verify no hardcoded "footer-customization" or "footer" feature-flag references; if toggling is needed, use generic mechanism (e.g., `footer_enabled` setting is the toggle, no separate feature flag)

## 16. Documentation

- [ ] 16.1 Add admin guide section to `.github/docs/` describing footer setup (HTML mode, structured mode, overrides, multi-language)
- [ ] 16.2 Include screenshots of admin UI and live preview
- [ ] 16.3 Note sanitisation rules and security best practices (e.g., avoid user-supplied URLs in structured mode)
- [ ] 16.4 Document per-dashboard override feature and use cases

