---
status: draft
---

# Footer Customization Specification

## Purpose

Footer Customization provides per-instance branding, legal disclaimers, and contact information rendered below the dashboard surface. Administrators configure global footer content (HTML or structured form), with optional per-dashboard overrides. The footer respects theme colors, supports multi-language variants, and prints correctly in PDF exports.

## Data Model

### Admin Footer Settings (oc_mydash_admin_settings extended)

Settings are stored as key-value pairs:
- **footer_enabled**: Boolean (default: `false`) — global master toggle
- **footer_html**: String, max 8KB (default: empty) — raw HTML mode; sanitised server-side
- **footer_config**: JSON object (default: empty) — structured mode; schema `{logoUrl?, organisation?, address?, links: [{label, url}]?, legal?, copyrightYear?, layoutMode: 'columns'|'inline'}`
- **footer_background_color**: Nullable hex string (default: NULL) — override background colour; falls back to theme variable
- **footer_text_color**: Nullable hex string (default: NULL) — override text colour; falls back to theme variable

Each footer setting MAY contain language-tagged variants as a nested map: `{en: "...", nl: "..."}`. Render logic selects matching viewer locale; falls back to first key if no match.

### Dashboard Footer Fields (oc_mydash_dashboards extended)

Extend `oc_mydash_dashboards` table with:
- **dashboardFooterMode**: VARCHAR(16), default `'inherit'` — values: `inherit`, `hidden`, `custom`
- **dashboardFooterHtml**: LONGTEXT, nullable — dashboard-specific footer HTML (only used if mode='custom'); sanitised identically to global HTML

Invariant: if `dashboardFooterMode = 'custom'`, then `dashboardFooterHtml` MUST be non-NULL and non-empty after trim. If mode is `inherit` or `hidden`, `dashboardFooterHtml` MUST be NULL.

## ADDED Requirements

### Requirement: REQ-FTR-001 Global Footer Enable/Disable

Administrators MUST be able to globally enable or disable the footer on all dashboards via the `footer_enabled` setting.

#### Scenario: Footer disabled by default
- GIVEN a fresh MyDash installation
- WHEN any user views a dashboard
- THEN the footer MUST NOT be rendered
- AND `footer_enabled` MUST default to `false` in the settings table

#### Scenario: Admin enables footer
- GIVEN the admin sets `footer_enabled = true` via `PUT /api/admin/footer-settings`
- WHEN a user views a dashboard
- THEN the footer MUST be rendered (assuming no per-dashboard override = `hidden`)
- AND the change MUST take effect immediately on next page load

#### Scenario: Disabling footer hides it everywhere
- GIVEN `footer_enabled = true` and footers are visible on all dashboards
- WHEN the admin sets `footer_enabled = false`
- THEN all dashboards MUST hide the footer
- AND per-dashboard custom footers (mode='custom') are exempted: they MUST still render

#### Scenario: Non-admin cannot toggle footer
- GIVEN a regular user "alice"
- WHEN she sends `PUT /api/admin/footer-settings` with body `{"footerEnabled": false}`
- THEN the system MUST return HTTP 403 (Forbidden)
- AND no setting MUST be modified

### Requirement: REQ-FTR-002 HTML Mode — Raw HTML Input and Sanitisation

Administrators MUST be able to provide raw HTML for the footer, which is sanitised server-side before storage and rendering.

#### Scenario: Admin provides valid HTML
- GIVEN the admin sends `PUT /api/admin/footer-settings` with `{"footerHtml": "<p>Copyright 2026</p>"}`
- WHEN the setting is stored
- THEN the HTML MUST be sanitised: allowed tags (`<a>`, `<p>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`, `<img>`) pass through; all others stripped
- AND the footer MUST render with the sanitised HTML when `footer_enabled = true`

#### Scenario: HTML injection attempt blocked
- GIVEN the admin sends `{"footerHtml": "<p onclick='alert()'>Click me</p>"}`
- WHEN the setting is stored
- THEN the `onclick` attribute MUST be stripped
- AND the stored result MUST be sanitised to `<p>Click me</p>`

#### Scenario: Script tag stripped
- GIVEN the admin sends `{"footerHtml": "<p>Text <script>alert('xss')</script> here</p>"}`
- WHEN the setting is stored
- THEN the `<script>` tag and its content MUST be removed entirely
- AND the result MUST be sanitised to `<p>Text  here</p>`

#### Scenario: External links get rel attribute
- GIVEN the admin sends `{"footerHtml": "<a href='https://example.com'>Link</a>"}`
- WHEN the setting is stored
- THEN the link MUST automatically have `rel="noopener noreferrer"` and `target="_blank"` added
- AND the rendered HTML MUST include both attributes

#### Scenario: Data URIs stripped from img
- GIVEN the admin sends `{"footerHtml": "<img src='data:image/png;base64,...' />"}`
- WHEN the setting is stored
- THEN the `src` attribute MUST be stripped (data URIs not allowed)
- AND the tag MUST either be removed or rendered as empty

#### Scenario: Oversized HTML rejected
- GIVEN the admin sends a footer HTML string of 9000 bytes (exceeds 8KB limit)
- WHEN the `PUT /api/admin/footer-settings` is processed
- THEN the system MUST return HTTP 413 (Payload Too Large)
- AND the setting MUST NOT be updated

#### Scenario: Allowed tags list enforcement
- GIVEN the admin sends `{"footerHtml": "<div>Not allowed</div><p>Allowed</p>"}`
- WHEN the setting is stored
- THEN the `<div>` MUST be stripped (not in allowlist)
- AND the `<p>` MUST be preserved
- AND the result MUST be `Allowed paragraph text only`
- NOTE: Allowed tags are: `<a>`, `<p>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`, `<img>` (src-only)

### Requirement: REQ-FTR-003 Structured Mode — Form-Based Configuration

Administrators MUST be able to configure footer content via a structured JSON schema, avoiding raw HTML.

#### Scenario: Admin sets structured footer config
- GIVEN the admin sends `PUT /api/admin/footer-settings` with:
  ```json
  {
    "footerConfig": {
      "logoUrl": "https://example.com/logo.png",
      "organisation": "ACME Corp",
      "address": "Main Street 123\n1234 AB Amsterdam",
      "links": [{"label": "Privacy", "url": "https://example.com/privacy"}],
      "legal": "All rights reserved",
      "copyrightYear": 2026,
      "layoutMode": "columns"
    }
  }
  ```
- WHEN the setting is stored
- THEN the system MUST validate the schema (all keys match expected structure, no extra keys)
- AND the JSON MUST be stored in `footer_config` setting

#### Scenario: Structured config schema validation
- GIVEN the admin sends a `footerConfig` with an unexpected key `"unknownField": "value"`
- WHEN the `PUT /api/admin/footer-settings` is processed
- THEN the system MUST reject the request with HTTP 400 (Bad Request)
- AND the error message MUST indicate which keys are allowed

#### Scenario: Partial structured config (optional fields)
- GIVEN the admin sends `{"footerConfig": {"organisation": "ACME", "layoutMode": "inline"}}`
- WHEN the setting is stored
- THEN the system MUST accept the partial object (all fields except `organisation`, `layoutMode`, and `links` are optional)
- AND the footer renderer MUST skip missing fields gracefully

#### Scenario: Links array in structured config
- GIVEN the admin sends `{"footerConfig": {"links": [{"label": "Privacy", "url": "https://..."}, {"label": "Contact", "url": "https://..."}]}}`
- WHEN the setting is stored
- THEN the links array MUST be stored as-is
- AND the footer renderer MUST iterate the array and render each link with proper HTML anchors

### Requirement: REQ-FTR-004 Footer Rendering — Global Content

The footer MUST be rendered below the dashboard grid when enabled globally and not overridden at the dashboard level.

#### Scenario: Footer renders with enabled global footer
- GIVEN `footer_enabled = true` and `footer_html = "<p>Copyright 2026</p>"`
- WHEN a user views a dashboard with `dashboardFooterMode = 'inherit'` (or NULL)
- THEN the footer MUST appear below the dashboard grid
- AND the content MUST be the stored `footer_html` text

#### Scenario: Footer is not rendered when disabled
- GIVEN `footer_enabled = false`
- WHEN a user views any dashboard
- THEN the footer MUST NOT be rendered
- AND no footer element MUST be visible in the DOM

#### Scenario: Footer renders structured content in columns layout
- GIVEN `footer_config = {logoUrl: "...", organisation: "...", address: "...", links: [...], legal: "...", layoutMode: "columns"}`
- WHEN a user views a dashboard
- THEN the footer MUST render as a 3-column CSS grid:
  - Column 1: logo image and organisation name
  - Column 2: address text (split by newlines)
  - Column 3: links list (ul/li) and legal text below
- AND columns MUST be evenly spaced with gap of ~2rem

#### Scenario: Footer renders structured content in inline layout
- GIVEN `footer_config = {..., layoutMode: "inline"}`
- WHEN a user views a dashboard
- THEN the footer MUST render as a single horizontal row
- AND items (logo, organisation, address, links, legal) MUST be separated by vertical bars or dots (CSS ::before pseudo-element)
- AND the row MUST wrap on narrow screens (mobile-responsive)

### Requirement: REQ-FTR-005 HTML Sanitisation Rules

HTML MUST be sanitised according to a strict allowlist before storage and rendering.

#### Scenario: Sanitiser strips forbidden attributes
- GIVEN input HTML: `<p class='danger' data-value='test'>Text</p>`
- WHEN sanitised
- THEN the `class` and `data-value` attributes MUST be stripped
- AND the result MUST be `<p>Text</p>`

#### Scenario: Style attributes not allowed
- GIVEN input: `<p style='color: red;'>Text</p>`
- WHEN sanitised
- THEN the `style` attribute MUST be stripped
- AND the result MUST be `<p>Text</p>`

#### Scenario: Images with src-only
- GIVEN input: `<img src='https://example.com/logo.png' alt='logo' />`
- WHEN sanitised
- THEN the `src` attribute MUST be preserved
- AND the `alt` attribute MUST be stripped (not in allowlist)
- AND the result MUST be `<img src='https://example.com/logo.png' />`

#### Scenario: Nested tags
- GIVEN input: `<strong><em>Bold and italic</em></strong>`
- WHEN sanitised
- THEN both tags MUST be preserved (both are allowed)
- AND nesting MUST work correctly
- NOTE: Do NOT strip nesting; allow reasonable HTML structure

### Requirement: REQ-FTR-006 Per-Dashboard Footer Override

Dashboard owners MUST be able to override the global footer on their own dashboard using three modes: inherit (global), hidden (no footer), or custom (dashboard-specific HTML).

#### Scenario: Dashboard with inherit mode (default)
- GIVEN a dashboard with `dashboardFooterMode = 'inherit'` (or NULL)
- WHEN `footer_enabled = true` globally with `footer_html = "..."` 
- THEN the dashboard MUST render the global footer
- AND the dashboard's own `dashboardFooterHtml` field MUST be ignored (must be NULL)

#### Scenario: Dashboard with hidden mode
- GIVEN a dashboard with `dashboardFooterMode = 'hidden'`
- WHEN `footer_enabled = true` globally
- THEN the dashboard MUST NOT render any footer
- AND the footer MUST be hidden even though global footer is enabled

#### Scenario: Dashboard with custom mode
- GIVEN a dashboard with `dashboardFooterMode = 'custom'` and `dashboardFooterHtml = "<p>Custom footer</p>"`
- WHEN any user views the dashboard
- THEN the dashboard MUST render the custom footer (NOT the global footer)
- AND the custom HTML MUST be sanitised identically to global HTML

#### Scenario: Custom mode requires HTML
- GIVEN the dashboard owner sends `PUT /api/dashboard/{uuid}` with `dashboardFooterMode = 'custom'` but no `dashboardFooterHtml`
- WHEN the request is processed
- THEN the system MUST return HTTP 400
- AND the error message MUST indicate that custom mode requires footer HTML

#### Scenario: Mode change clears stale HTML
- GIVEN a dashboard with `dashboardFooterMode = 'custom'` and `dashboardFooterHtml = "<p>Old footer</p>"`
- WHEN the dashboard owner sends `PUT /api/dashboard/{uuid}` with `dashboardFooterMode = 'inherit'` (no `dashboardFooterHtml` in request)
- THEN the system MUST update mode to `inherit`
- AND the `dashboardFooterHtml` MUST be cleared to NULL
- AND subsequent renders MUST use the global footer

#### Scenario: Only dashboard owner can set custom footer
- GIVEN a dashboard owned by "alice" with `dashboardFooterMode = 'view_only'` permission for user "bob"
- WHEN "bob" sends `PUT /api/dashboard/{uuid}` with `dashboardFooterMode = 'custom'`
- THEN the system MUST return HTTP 403 (Forbidden)
- AND the setting MUST NOT be modified (only the owner can override)

### Requirement: REQ-FTR-007 Multi-Language Support

Footer content MUST support language-tagged variants. The footer renderer MUST select the matching variant based on the viewer's Nextcloud locale.

#### Scenario: Single-language footer (default)
- GIVEN `footer_html = "<p>Welcome</p>"` (plain string, not a map)
- WHEN a user with NC locale `nl` views the dashboard
- THEN the footer MUST render the plain HTML (no variant selection needed)

#### Scenario: Language-tagged footer variants
- GIVEN `footer_html = {en: "<p>Welcome</p>", nl: "<p>Welkom</p>"}`
- WHEN a user with NC locale `nl` views the dashboard
- THEN the footer MUST render the `nl` variant: `<p>Welkom</p>`

#### Scenario: Locale fallback (no match)
- GIVEN `footer_html = {en: "<p>English</p>", fr: "<p>Français</p>"}`
- WHEN a user with NC locale `de` (no matching key) views the dashboard
- THEN the footer MUST fall back to the first key in the map (language order is implementation-defined; typically `en`)

#### Scenario: Language variants in structured config
- GIVEN `footer_config = {organisation: {en: "ACME", nl: "ACME NL"}, layoutMode: "columns"}`
- WHEN a user with NC locale `nl` views the dashboard
- THEN the footer renderer MUST select the `nl` variant for the organisation field
- AND the footer MUST display "ACME NL"

#### Scenario: Admin i18n editor in settings UI
- GIVEN the admin visits the footer settings tab
- WHEN the admin sets `footer_html` as a language-tagged map
- THEN the UI MUST show tabs for each language variant (e.g., "English", "Dutch")
- AND the admin can edit and preview each variant independently
- NOTE: This is a UI requirement; spec does not mandate exact UI behavior, only that variants can be configured and rendered correctly

### Requirement: REQ-FTR-008 Print Support and CSS

The footer MUST be visible and properly styled when the dashboard is exported to PDF or printed.

#### Scenario: Footer prints in PDF
- GIVEN a dashboard with `footer_enabled = true` and `footer_html = "<p>Copyright 2026</p>"`
- WHEN a user prints the dashboard to PDF (via browser print dialog)
- THEN the footer MUST appear on the PDF output
- AND the footer styling MUST be printer-friendly (no fixed positioning, readable in grayscale)

#### Scenario: Print-specific CSS
- GIVEN the DashboardFooter component has a `@media print { ... }` rule
- WHEN the page is printed
- THEN the footer MUST be visible and properly formatted
- AND background colours and images MUST render in the PDF (if color printing is enabled)

#### Scenario: Footer does not break layout on print
- GIVEN a dashboard with content that fills the page and a footer below
- WHEN printed to PDF
- THEN the footer MUST not push content off the page; it MUST be on a new page or bottom of the current page
- AND page breaks MUST not cause the footer to be duplicated

### Requirement: REQ-FTR-009 Theme Awareness and Color Customisation

The footer MUST use Nextcloud theme colors by default, with optional admin override via color settings.

#### Scenario: Footer inherits theme colors
- GIVEN `footer_background_color = NULL` and `footer_text_color = NULL` (not overridden)
- WHEN the page renders with NC theme (e.g., dark theme)
- THEN the footer background MUST use the NC theme's primary background colour (CSS var `--primary-background-color`)
- AND the footer text MUST use the NC theme's primary text colour (CSS var `--primary-text-color`)

#### Scenario: Admin overrides footer colors
- GIVEN the admin sets `footer_background_color = "#1a1a1a"` and `footer_text_color = "#ffffff"`
- WHEN the page renders
- THEN the footer background MUST be `#1a1a1a`
- AND the footer text MUST be `#ffffff`
- AND the theme colours MUST be ignored in favor of the overrides

#### Scenario: Color override fallback
- GIVEN `footer_background_color = "#1a1a1a"` and `footer_text_color = NULL`
- WHEN the page renders
- THEN the background MUST be `#1a1a1a`
- AND the text MUST fall back to the NC theme's primary text colour (override only applies to background, text is theme-driven)

#### Scenario: Invalid color values rejected
- GIVEN the admin sends `PUT /api/admin/footer-settings` with `{"footerBackgroundColor": "not-a-color"}`
- WHEN the request is processed
- THEN the system MUST return HTTP 400
- AND the error message MUST indicate that color values must be valid hex strings

### Requirement: REQ-FTR-010 API Endpoints

The system MUST provide two new admin-only API endpoints to manage footer settings.

#### Scenario: Get footer settings
- GIVEN an admin user
- WHEN she sends `GET /api/admin/footer-settings`
- THEN the system MUST return HTTP 200 with a JSON object:
  ```json
  {
    "footerEnabled": true,
    "footerHtml": "...",
    "footerConfig": {...},
    "footerBackgroundColor": "#...",
    "footerTextColor": "#..."
  }
  ```
- AND all five keys MUST be present in the response

#### Scenario: Update footer settings
- GIVEN an admin user
- WHEN she sends `PUT /api/admin/footer-settings` with body:
  ```json
  {
    "footerEnabled": true,
    "footerHtml": "<p>New content</p>"
  }
  ```
- THEN the system MUST update the two settings
- AND the response MUST return HTTP 200 with `{"status": "ok"}` (simple success response)
- AND untouched settings (not in the PATCH) MUST retain their previous values

#### Scenario: Non-admin rejects GET
- GIVEN a regular user "alice"
- WHEN she sends `GET /api/admin/footer-settings`
- THEN the system MUST return HTTP 403 (Forbidden)
- AND the response body MUST NOT expose any footer settings

#### Scenario: Non-admin rejects PUT
- GIVEN a regular user "alice"
- WHEN she sends `PUT /api/admin/footer-settings` with any body
- THEN the system MUST return HTTP 403 (Forbidden)
- AND no setting MUST be modified

#### Scenario: Dashboard API returns effective footer
- GIVEN a user requests `GET /api/dashboard/{uuid}`
- WHEN the response is returned
- THEN the dashboard object MUST include:
  - `dashboardFooterMode` (string: `inherit`, `hidden`, or `custom`)
  - `dashboardFooterHtml` (nullable string)
- AND if the dashboard has an active global footer or custom override, an optional `effectiveFooter` field MAY be included with the resolved footer content
- NOTE: The `effectiveFooter` field is optional for API backward compatibility; recommended but not strictly required

