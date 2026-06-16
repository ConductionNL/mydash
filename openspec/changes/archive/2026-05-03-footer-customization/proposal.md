# Footer Customization

## Why

MyDash currently has no branded footer mechanism. Organizations need to display copyright, legal links, contact information, and custom branding (logos, organization name, address) below the dashboard content. Without a configurable footer, instances lack a professional closure and legal disclaimers, forcing admins to inject HTML at the reverse-proxy level or rely on Nextcloud theme customizations that don't directly integrate with the dashboard surface.

## What Changes

- Add two global admin settings: `mydash.footer_enabled` (boolean, default `false`) and `mydash.footer_html` (sanitised HTML string, max 8KB).
- Add structured configuration alternative: `mydash.footer_config` (JSON object) with schema `{logoUrl?, organisation?, address?, links: [{label, url}]?, legal?, copyrightYear?, layoutMode: 'columns'|'inline'}` — use when admin prefers form-based input over raw HTML.
- Add admin UI in MyDash settings: tabbed "HTML Mode" vs "Structured Mode" with live preview.
- Add Vue 3 SFC `DashboardFooter.vue` mounted by `App.vue` below the dashboard grid. Renders nothing when `footer_enabled = false`.
- HTML sanitisation: allow `<a>`, `<p>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`, `<img>` (src-only); strip everything else; force `rel="noopener noreferrer"` on external links.
- Structured mode rendering: `columns` layout = 3-column flex grid (logo+org / address / links+legal); `inline` layout = single row with separators.
- Per-dashboard override: dashboard owner MAY set `dashboardFooterMode: 'inherit'|'hidden'|'custom'` on their dashboard object. `inherit` = use global; `hidden` = no footer; `custom` = render dashboard's own `dashboardFooterHtml` field (sanitised identically).
- Print support: footer prints in PDFs (survives export).
- Theme awareness: footer text + background colours follow NC theme variables; admin MAY override with `mydash.footer_background_color` and `mydash.footer_text_color` settings.
- Multi-language support: HTML/structured config MAY contain language-tagged variants `{en: "...", nl: "..."}`. Render picks matching variant based on viewer's NC locale; falls back to first key if no match.

## Capabilities

### New Capabilities

- `footer-customization` — standalone capability covering all footer configuration, rendering, and override mechanisms.

### Modified Capabilities

- `dashboards` — extends with per-dashboard footer mode and HTML field (`dashboardFooterMode`, `dashboardFooterHtml`), but no breaking changes to existing REQ-DASH-001..010.

## Impact

**Affected code:**

- `lib/Db/AdminSettings.php` — add three new settings keys + getter/setter chain
- `lib/Db/Dashboard.php` — add `dashboardFooterMode` and `dashboardFooterHtml` fields (nullable, entity layer)
- `lib/Service/AdminSettingsService.php` — getters/setters for footer settings, HTML sanitisation logic
- `lib/Service/DashboardService.php` — inherit footer config on dashboard mutations (override precedence logic)
- `lib/Controller/AdminSettingsController.php` — new endpoints to read/write footer settings
- `lib/Migration/VersionXXXXDate2026...AddFooterColumns.php` — schema migration
- `src/components/DashboardFooter.vue` — new SFC, columns/inline layout rendering, language variant selection
- `src/views/App.vue` — mount DashboardFooter below grid
- `src/views/AdminSettings.vue` — extend with footer tab (HTML/structured mode selector, live preview)
- `src/stores/dashboards.js` — track footer config in store state
- `appinfo/routes.php` — register new admin footer endpoints

**Affected APIs:**

- 2 new admin endpoints: `GET|PUT /api/admin/footer-settings` (auth: admin)
- Existing `GET|PUT /api/dashboard/{uuid}` extended to include `dashboardFooterMode` and `dashboardFooterHtml` fields (backward compatible)

**Dependencies:**

- `OCP\Constants::HTML5` or similar; otherwise custom sanitiser (no new composer/npm deps)
- Nextcloud theme CSS variable access (already available)

**Migration:**

- Zero-impact: schema adds nullable columns; no backfill needed. Existing dashboards get `dashboardFooterMode = 'inherit'` and `dashboardFooterHtml = NULL` by default.

## Risks

- **Injection vector**: unsanitised HTML from admin allows XSS. Mitigation: strict allowlist in sanitiser (no script, no event handlers, no data URIs).
- **PDF rendering**: footer may not print correctly if CSS relies on modern layout (Grid, custom properties). Mitigation: test print output; use fallback inline styles.
- **Locale fallback**: if user locale is not present in language variants, which key is picked? Mitigation: explicit ordering (first key is fallback).
- **Performance**: rendering three-column layout may cause reflow. Mitigation: lazy-load footer; use CSS containment.
