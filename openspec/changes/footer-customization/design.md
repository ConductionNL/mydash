# Design — Footer Customization

## Context

MyDash dashboards are blank canvases for end-users, with no footer rendered by default. Administrators of large instances (municipalities, consultancies, non-profits) need to display global branding, legal disclaimers, and contact information at the bottom of every dashboard — typically to satisfy compliance (GDPR privacy notices, accessibility statements), brand consistency, or user support signage.

The source application (`/source/Footer`) stores footer content as per-language static files on the filesystem. MyDash dashboards, however, may be backed by either the database or a GroupFolder, and administrators expect the footer to be stored in the same authoritative location as all other admin settings (app config, theme, widget catalogue).

Additionally, some dashboard owners want to customize the footer for their specific dashboard without affecting others — e.g. a team lead might add "Team A Contact: ..." to their team's dashboard. The source has no per-page override concept.

## Goals / Non-Goals

**Goals:**

- Provide administrators a unified place to configure global footer content (HTML or structured form).
- Render the footer on every dashboard (unless globally disabled or overridden to hidden at dashboard level).
- Allow dashboard owners to override the global footer on their own dashboard (three modes: inherit, hidden, custom).
- Support multi-language footer variants with a predictable fallback chain (viewer locale → dashboard language → first key).
- Respect Nextcloud theme colors by default, with optional admin override.
- Ensure footer renders correctly in PDF exports and print previews.
- Sanitise HTML inputs to prevent XSS while preserving semantic markup.

**Non-Goals:**

- Per-widget footer (out of scope; footer is global or dashboard-level only).
- Animated transitions or dynamic footer updates without page reload.
- Footer scheduling (e.g. "show X during hours Y-Z") — static content only.
- A/B testing or analytics on footer clicks — basic links only, no telemetry.
- Footer in PDF headers/footers beyond the visible page content (print support is inline footer visibility).

## Decisions

### D1: Store footer settings in admin settings (app config), not the filesystem

**Decision**: All five footer settings (`footer_enabled`, `footer_html`, `footer_config`, `footer_background_color`, `footer_text_color`) are stored as keys in the `oc_mydash_admin_settings` table, not as files on disk.

**Rationale**: MyDash dashboards may be backed by either the database or a GroupFolder. A filesystem-only path is not a universal anchor. Admin settings provide a single authoritative store regardless of the active storage backend. This matches REQ-GRID-005 and REQ-WDG-008 patterns: settings that apply globally to the app live in admin settings, not in the dashboard or widget tables themselves.

**Alternatives rejected:**
- Store in filesystem like `/source/Footer` — dashboard backends vary; not a universal anchor.
- Duplicate in both admin settings and filesystem — sync risk; complex migration.

### D2: Per-dashboard override with three modes (inherit, hidden, custom)

**Decision**: Extend `oc_mydash_dashboards` with `dashboardFooterMode` (VARCHAR 16, default 'inherit') and `dashboardFooterHtml` (LONGTEXT nullable). Three modes:
- `inherit` — use global footer (if enabled)
- `hidden` — no footer, even if global is enabled
- `custom` — use dashboard-specific footer HTML (always rendered, regardless of global toggle)

**Rationale**: Owners of specific dashboards (e.g. team leads) often want to customize presentation without affecting instance-wide footer. Three modes cover the common cases: most dashboards inherit global (no per-dashboard data), some explicitly hide it (e.g. a "reports" dashboard that is footer-less by design), and a few customize it. This is a MyDash-original feature with no source counterpart.

**Alternatives rejected:**
- No per-dashboard override — too restrictive; doesn't serve the team-lead use case.
- Boolean "footerEnabled" per dashboard only — lacks flexibility; can't customize content per dashboard.
- Four modes (inherit, hidden, custom, disabled) — redundant; "disabled" is equivalent to "hidden".

### D3: Multi-language fallback chain (viewer locale → dashboard language → first key)

**Decision**: Footer content (both HTML and structured config fields) may be language-tagged variants as nested maps: `{en: "...", nl: "..."}`. At render time, select the matching variant using:
1. Viewer's Nextcloud locale (from `getUserValue($uid, 'core', 'lang')`)
2. Dashboard's primary language (from `dashboard-language-content` capability), if step 1 misses
3. First key in the variant map, if both step 1 and 2 miss

**Rationale**: Administrators often configure footers in multiple languages to serve diverse user bases. The three-step fallback handles:
- Single-language footers (plain string, not a map) — no variant selection needed, render as-is
- Multi-language footers where the user's locale is present — render their language
- Multi-language footers where the dashboard has a different primary language — fall back to that
- Graceful degradation if neither the user nor dashboard language is in the map — use the first one (deterministic)

**Alternatives rejected:**
- Only step 1 (user locale) — ignores dashboard context; misses when no user locale match.
- Steps 1 and 3 only, skip step 2 — misses the opportunity to respect dashboard language.
- Per-key language variants — adds complexity; a single version bump per change is simpler and correct since PHP and JS ship together.

### D4: Shared HTML sanitisation allowlist (with text-display widget)

**Decision**: The allowed tags for footer HTML are: `<a>` (href only), `<p>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`, `<img>` (src only). External links automatically receive `rel="noopener noreferrer"` and `target="_blank"`. Layout tags (`<table>`, `<div>`) are NOT allowed.

**Rationale**: Footers are semantic content, not layout containers. Layout is provided by the structured config's `layoutMode` field (columns, inline). A narrow allowlist reduces XSS surface and enforces consistency with the text-display widget (single canonical definition, not duplicated). External links get hardened by default.

**Alternatives rejected:**
- Allow `<table>` and `<div>` for complex layouts — defeats the purpose of structured mode; encourages raw HTML abuse.
- Per-field allowlists (e.g. footer links can have onclick) — adds complexity; narrow is safer.
- No sanitisation, trust admins — admins are humans; typos happen. Sanitisation is cheap and catches XSS in admin-provided HTML.

### D5: Structured mode uses JSON, not a second UI wizard

**Decision**: Structured mode accepts a JSON object with schema `{logoUrl?, organisation?, address?, links: [{label, url}]?, legal?, copyrightYear?, layoutMode: 'columns'|'inline'}`. The UI (if provided) shows a form that maps to this JSON; the spec does not mandate exact UI behavior, only that the JSON can be edited.

**Rationale**: JSON is the API-native format; it's unambiguous, easy to validate, and pairs naturally with the existing app config layer (all settings are key-value strings that hold JSON). A form UI is optional and can be added later; the backend stores and renders the structure regardless.

**Alternatives rejected:**
- Wizard-driven UI (step-by-step modal) — more opinionated than needed; JSON schema covers it.
- Hard-coded template fields (org name textbox, address textarea, links repeater) — requires frontend coupling to schema; JSON is more flexible.

### D6: Theme colors by default, admins can override individual colors

**Decision**: The footer background and text inherit Nextcloud theme colors (`--primary-background-color`, `--primary-text-color`) by default. Administrators may override the background color and/or text color individually via `footer_background_color` and `footer_text_color` (nullable hex strings). If one is overridden and the other is not, the non-overridden one still uses the theme value.

**Rationale**: Respecting theme colors ensures the footer looks good in light, dark, and custom themes out of the box. Allowing selective override (just background, just text, or both) gives admins fine-grained control without forcing them to set both values. Fallback to theme when an override is null is cleaner than requiring both or neither.

**Alternatives rejected:**
- No theme awareness; hardcoded defaults — footer looks bad in dark theme and doesn't adapt.
- Force both colors to be set or both to be null — too rigid; admins should be able to override one.
- Full CSS customisation (stylesheet upload) — too powerful; invites complexity and support burden.

### D7: Print support via @media print CSS, not separate PDF controller

**Decision**: The `DashboardFooter.vue` component includes a `@media print { ... }` rule that ensures the footer is visible and properly formatted when printed. No separate PDF export controller is needed.

**Rationale**: The footer is part of the normal DOM; CSS media queries handle print presentation gracefully. Inline footers render in the visible page, which is the expected behavior for dashboards. No special PDF manipulation is needed.

**Alternatives rejected:**
- Separate PDF controller that inserts footer — over-engineered; print via browser is the common path.
- Exclude footer from print entirely — defeats the purpose; admins want legal disclaimers to appear in exported PDFs.

### D8: Dashboard API extended to include footer fields, not a separate endpoint

**Decision**: The dashboard API (`GET /api/dashboard/{uuid}`, `PUT /api/dashboard/{uuid}`) is extended to include `dashboardFooterMode` and `dashboardFooterHtml` in both request and response. No new endpoint is introduced.

**Rationale**: Footer configuration is part of the dashboard state, not a separate concern. Including it in the dashboard API keeps the API surface minimal and makes the relationship explicit.

**Alternatives rejected:**
- New endpoint `PUT /api/dashboard/{uuid}/footer` — fragment the API; adds conceptual overhead.
- Footer-only GET/PUT endpoints — misleading; not all footer changes are per-dashboard (global changes are via `/api/admin/footer-settings`).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Admin provides XSS-laden HTML in footer | Sanitisation allowlist is applied on store before persistence; rendered output is also escaped. Double defense. |
| Dashboard language field is empty or missing | Step 2 of fallback chain is skipped; step 3 (first key) is reached; graceful degradation. |
| Admin disables global footer but forgets some dashboards have custom footers | Custom footers (mode='custom') are exempted from the global toggle and render regardless (REQ-FTR-001 Scenario 3). Document this behavior prominently in admin UI. |
| Per-dashboard override hijacked by non-owner | Dashboard ownership check is enforced: only the owner can set `dashboardFooterMode` / `dashboardFooterHtml` via the dashboard API. Non-owners receive HTTP 403. |
| Structured config with invalid keys | Schema validation rejects unknown keys; error message names allowed keys. Admin sees clear feedback. |
| Color override with invalid hex | Validation on input; HTTP 400 if invalid. Admin must provide valid hex or omit the field. |
| Render-time fallback chain is slow | Fallback is O(n) where n=number of locale map keys (typically 2-5); negligible cost. Caching could be added later if needed. |
| Dashboard with both footerHtml set AND mode='inherit' | Invariant enforced: if mode != 'custom', footerHtml MUST be null. API rejects or clears stale HTML when mode changes away from 'custom'. |

## Seed Data

This change does NOT introduce new OpenRegister schemas. Footer settings are stored in `oc_mydash_admin_settings` (app config), not in OpenRegister. The setting keys are created on install via the existing `SettingsLoadService` pipeline.

No mock data is required in `lib/Settings/mydash_register.json`.

## Test Strategy

- **PHP**: Unit tests for `FooterService` (language fallback, sanitisation, per-dashboard resolution), validation of settings schema, invariant checks
- **JS**: Unit tests for `useFooterResolver` composable (fallback chain, language selection), `DashboardFooter.vue` component rendering (structured vs raw HTML mode, theme colors, print CSS)
- **Integration**: Test footer persistence via admin settings API, per-dashboard override via dashboard API, invariant enforcement
- **E2E (Playwright)**: Admin configures global footer (HTML + structured), views footer on dashboards, overrides footer on one dashboard, verifies language selection, prints to PDF

## Migration Plan

1. **Migration file** — add two columns to `oc_mydash_dashboards` with defaults (mode='inherit', HTML=null)
2. **Service layer lands first** — `FooterService`, `HtmlSanitiserService`, tests. No behavior exposed yet.
3. **Controller + API** — add `AdminFooterSettingsController` for global footer settings.
4. **Frontend component** — add `DashboardFooter.vue`, `useFooterResolver.js`, integrate into dashboard layout.
5. **Dashboard API extended** — include footer fields in GET/PUT responses.
6. **Tests** — all layers covered, integration and E2E.

## Open Questions

- None.
