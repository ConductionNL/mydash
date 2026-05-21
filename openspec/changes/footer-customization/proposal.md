# Footer Customization

Administrators can configure per-instance branding, legal disclaimers, and contact information rendered below the dashboard surface. MyDash supports both raw HTML mode (sanitised server-side) and structured form-based configuration (JSON schema), with optional per-dashboard overrides, multi-language variants, theme-aware colors, and print-correct rendering.

## Affected code units

- `lib/Controller/AdminFooterSettingsController.php` — new controller for `GET/PUT /api/admin/footer-settings`
- `lib/Service/FooterService.php` — new service handling footer logic (global settings, per-dashboard resolution, sanitisation)
- `lib/Service/HtmlSanitiserService.php` — new service with allowlist-based HTML sanitisation (shared with text-display widget)
- `lib/Settings/mydash_register.json` — extend `oc_mydash_admin_settings` with five new keys (`footer_enabled`, `footer_html`, `footer_config`, `footer_background_color`, `footer_text_color`)
- `db/Migration/Version20260521*` — add `dashboardFooterMode` (VARCHAR 16, default 'inherit') and `dashboardFooterHtml` (LONGTEXT nullable) columns to `oc_mydash_dashboards`
- `src/components/DashboardFooter.vue` — new component rendering global or per-dashboard footer with theme colors and multi-language selection
- `src/composables/useFooterResolver.js` — new composable handling language fallback chain (viewer locale → dashboard primary language → first key)
- `src/api/admin-footer.js` — new API client for footer settings endpoints
- Dashboard API — extend `GET /api/dashboard/{uuid}` response to include `dashboardFooterMode` and `dashboardFooterHtml` fields
- Update `PUT /api/dashboard/{uuid}` to accept `dashboardFooterMode` and `dashboardFooterHtml` in request body

## Why a delta / Why a new capability

Footer customization is a MyDash-original feature: the source application (`/source/Footer`) stores footer content as per-language filesystem files with no per-page override capability. MyDash deliberately departs from this pattern (see context-brief D1, D2) to support:

1. **Unified storage** — five footer settings live in `oc_mydash_admin_settings` (app config), not the filesystem, because MyDash dashboards may be backed by either the database or a GroupFolder
2. **Per-dashboard override** — new columns on `oc_mydash_dashboards` enable dashboard owners to customize their own footer without affecting others
3. **Structured mode** — form-based configuration avoids raw HTML burden on non-technical admins

This is a new capability with no direct source counterpart.

## Approach

### Global Footer Settings (REQ-FTR-001 to REQ-FTR-005, REQ-FTR-009)

- Administrators configure footer via `PUT /api/admin/footer-settings` with optional fields:
  - `footerEnabled` (boolean, default false) — global master toggle
  - `footerHtml` (string max 8KB) — raw HTML mode; sanitised on store before render
  - `footerConfig` (JSON) — structured mode; validated against schema `{logoUrl?, organisation?, address?, links: [{label, url}]?, legal?, copyrightYear?, layoutMode: 'columns'|'inline'}`
  - `footerBackgroundColor` (nullable hex) — override background; falls back to theme variable
  - `footerTextColor` (nullable hex) — override text; falls back to theme variable
- Each footer setting MAY contain language-tagged variants as a nested map: `{en: "...", nl: "..."}`.
- HTML sanitisation allowlist (shared with text-display widget): `<a>` (href+rel), `<p>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`, `<img>` (src only).
- External links auto-receive `rel="noopener noreferrer"` and `target="_blank"`.

### Per-Dashboard Override (REQ-FTR-006)

- Extend `oc_mydash_dashboards` with two columns:
  - `dashboardFooterMode` (VARCHAR 16, default 'inherit') — values: `inherit`, `hidden`, `custom`
  - `dashboardFooterHtml` (LONGTEXT nullable) — dashboard-specific footer HTML (only used if mode='custom'); sanitised identically
- Dashboard owners can set custom footer via `PUT /api/dashboard/{uuid}` with `dashboardFooterMode` and `dashboardFooterHtml` in request body.
- Invariant: if mode='custom', HTML must be non-null and non-empty after trim. If mode is 'inherit' or 'hidden', HTML must be null.

### Multi-Language Support (REQ-FTR-007)

- Footer content supports language-tagged variants via three-step fallback:
  1. Viewer's Nextcloud locale (from `getUserValue($uid, 'core', 'lang')`)
  2. Dashboard's primary language (from `dashboard-language-content` capability)
  3. First key in the variant map
- Composable `useFooterResolver.js` implements the fallback chain and is called at render time.

### Rendering (REQ-FTR-004, REQ-FTR-008)

- `DashboardFooter.vue` component receives resolved footer content and theme overrides, renders below the grid.
- Structured mode layouts:
  - `columns`: 3-column CSS grid (logo+org, address, links+legal)
  - `inline`: single horizontal row with vertical bars/dots between items (mobile-responsive)
- Print-friendly CSS: `@media print { ... }` keeps footer visible and readable in PDF exports.

### API Endpoints (REQ-FTR-010)

- `GET /api/admin/footer-settings` — returns all five settings (admin-only)
- `PUT /api/admin/footer-settings` — patches settings (admin-only); untouched keys retain their values
- Dashboard API modified to expose `dashboardFooterMode` and `dashboardFooterHtml` in both GET and PUT operations

## Capabilities

**New Capability:**

- `footer-customization` (covers REQ-FTR-001 through REQ-FTR-010)

## Notes

- The HTML sanitisation allowlist is a single canonical definition shared with the text-display widget; layout tags (`<table>`, `<div>`) are NOT added — layout is provided by structured config's `layoutMode` field instead (D4).
- Footer rendering respects the theme by default; admins may override individual colors but the system always has a sensible fallback.
- Per-dashboard custom footers are exempted from the global `footer_enabled = false` toggle (REQ-FTR-001 Scenario 3): they render regardless.
