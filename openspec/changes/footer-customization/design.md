# Design — Footer Customization

## Context

The source application stores its footer as a single per-language file (`{lang}/footer.json`) at the language-folder root. `FooterService::getFooter()` and `saveFooter()` accept no `pageId` or `uniqueId` parameter — they operate on the one global footer for the active language. `PageService` explicitly excludes `footer.json` from page listings, confirming it is a system-level file rather than a page attribute. Write access is controlled by `FooterService::canEdit()`, which delegates to `$languageFolder->isUpdateable()` (GroupFolder ACL). There is no per-page footer concept in the source.

MyDash departs from this pattern in two deliberate ways. First, it stores global footer configuration in admin settings rather than the filesystem, because MyDash dashboards have two storage backends (database and GroupFolder) and a filesystem-only path is not a unified fit. Second, it adds a per-dashboard footer override (`dashboardFooterMode`) that does not exist in the source at all. Both departures are intentional MyDash additions.

The global footer supports two authoring modes: raw HTML (max 8 KB, sanitised server-side) and a structured JSON config (`{logoUrl?, organisation?, address?, links?, legal?, copyrightYear?, layoutMode}`). Both modes may carry language-tagged variants (`{en: "...", nl: "..."}`). The footer is off by default; admins explicitly enable it.

Per-dashboard override gives dashboard owners three options: inherit the global footer, suppress it entirely, or supply custom HTML for that dashboard alone. This is primarily useful for branded landing dashboards that need a different footer identity from the instance-wide default.

## Goals / Non-Goals

**Goals:**

- Provide a global footer toggle and content configuration via admin settings (`mydash.footer_enabled`, `mydash.footer_html`, `mydash.footer_config`, `mydash.footer_background_color`, `mydash.footer_text_color`).
- Support two authoring modes — raw HTML and structured form-based config — selectable per instance.
- Allow per-dashboard footer override (`inherit` / `hidden` / `custom`) stored as columns on `oc_mydash_dashboards`.
- Support multi-language footer variants with a defined fallback chain: viewer NC locale → dashboard primary language → first key in the variant map.
- Sanitise all HTML through a strict allowlist identical to the text-display widget's allow-list, to avoid duplicating sanitisation rules.
- Ensure the footer appears in browser print output and PDF exports (`@media print` — no `display: none`).
- Follow NC theme colours by default; allow admin hex-string overrides.

**Non-Goals:**

- Migrating the source's per-language `footer.json` filesystem layout to MyDash — admin settings is the single storage path.
- Implementing per-row HTML upload via image-widget-style file attachments — footer assets are referenced by URL only.
- Real-time footer push after an admin edit — the updated footer appears on next page load.
- Supporting a GroupFolder ACL model for footer write permission — admin-role check via the `admin-roles` capability covers it.
- Multi-language variants on the per-dashboard `dashboardFooterHtml` field in the initial release (deferred; see Open Follow-ups).

## Decisions

### D1: Global footer storage — admin settings, not filesystem

**Decision**: Global footer config lives in five admin settings keys: `mydash.footer_enabled`, `mydash.footer_html`, `mydash.footer_config`, `mydash.footer_background_color`, `mydash.footer_text_color`. Stored and retrieved via the existing `admin-settings` capability.

**Alternatives considered:**

- **Source's `{lang}/footer.json` filesystem-per-language pattern**: rejected because MyDash dashboards can be stored in either the database or a GroupFolder, so a filesystem path is not a universal anchor. Admin settings provide a single authoritative store regardless of the active storage backend.

**Source evidence**:

- `intravox-source/lib/Service/FooterService.php:81-168` — single global `footer.json` per language; no `pageId` argument.
- `intravox-source/lib/Controller/FooterController.php` — `get()` and `save()` take no `pageId` or `uniqueId` parameter.
- `intravox-source/lib/Service/PageService.php:564,1973` — `footer.json` at the language root explicitly excluded from page listings.

**Rationale**: Admin settings is already the established pattern for MyDash instance-wide configuration. Reusing it keeps the bootstrap path simple, avoids filesystem permission complexity, and makes the footer values available via `IAppConfig` without mounting any GroupFolder.

### D2: Per-dashboard override — MyDash addition

**Decision**: Each dashboard MAY carry a footer override via two new columns on `oc_mydash_dashboards`: `dashboardFooterMode VARCHAR(16)` (values: `inherit`, `hidden`, `custom`; default `inherit`) and `dashboardFooterHtml MEDIUMTEXT NULL` (populated only when mode is `custom`). When mode is `inherit`, the dashboard renders the global footer. When `hidden`, no footer renders. When `custom`, the dashboard's own HTML renders (sanitised identically to global HTML).

**Source evidence**: None — this is entirely a MyDash addition. The source has no per-page footer concept.

**Rationale**: Branded landing dashboards and departmental dashboards often need different footer identities. Storing the override on the dashboard row keeps the resolution logic local to `DashboardService::resolveEffectiveFooter()` and avoids a separate override table. The invariant (mode=`custom` requires non-NULL, non-empty HTML; all other modes require NULL) is enforced at the service layer.

### D3: Multi-language fallback — viewer NC locale → dashboard primary language → first key

**Decision**: When footer content (HTML mode or structured mode fields) is a language-tagged map (`{en: "...", nl: "..."}`), the render selects the variant by:

1. Viewer's NC locale, retrieved via `getUserValue($uid, 'core', 'lang')`.
2. Dashboard's primary language variant (per the `dashboard-language-content` capability's `primaryLanguage` field), if the viewer's locale is absent from the map.
3. First key in the map, if neither of the above yields a match.

**Rationale**: The footer is read by the viewer, so the viewer's own locale is the strongest signal. Falling through to the dashboard's primary language handles the common case where a mono-lingual dashboard was authored in one language and the viewer's locale is simply not present in a partially-translated footer. First-key fallback gives deterministic output over an arbitrary locale miss.

### D4: HTML sanitisation — shared allow-list with text-display widget

**Decision**: HTML-mode footer content and per-dashboard `dashboardFooterHtml` both pass through the same allow-list used by the text-display widget: `<a>` (href only), `<p>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`, `<img>` (src only, no `srcset`, no `onerror`, no data URIs). External `<a>` tags automatically receive `rel="noopener noreferrer"`. All other tags and attributes are stripped before storage.

**Rationale**: Using one canonical allow-list prevents the two surfaces from drifting apart and avoids duplication of sanitisation logic. The footer allow-list is intentionally not extended with `<table>` or `<div>` — layout is left to the structured config's columns/inline modes.

### D5: Print stylesheet — footer prints by default

**Decision**: The `DashboardFooter.vue` component does not apply `display: none` in `@media print`. The footer participates in NC's print stylesheet and appears in PDF exports of a dashboard.

**Source evidence**: No explicit print handling found in the source footer implementation — this is a MyDash addition.

**Rationale**: Organizations printing or exporting dashboards for archiving or compliance typically need the footer (copyright, legal disclaimers, contact) to appear in the export. Opt-out printing would require admins to discover a non-obvious CSS override.

### D6: Theme awareness — NC variables with optional hex overrides

**Decision**: Footer background and text colours follow NC theme CSS variables (`--color-main-background`, `--color-main-text`) by default. If `mydash.footer_background_color` or `mydash.footer_text_color` are set (valid CSS hex strings), those override the theme variables inline on the root footer element.

**Rationale**: Theme-first keeps the footer consistent with NC's dark/high-contrast modes without additional work. Per-instance overrides allow organizations with strict brand colour requirements to pin exact hex values without needing a custom NC theme.

### D7: Off by default

**Decision**: `mydash.footer_enabled` defaults to `false`. A fresh MyDash installation renders no footer until an admin explicitly enables it via the admin settings UI or API.

**Rationale**: An empty or placeholder footer on day one is visually awkward and may confuse end users. Defaulting to off means the footer only appears once the admin has configured meaningful content, matching the principle of safe defaults across MyDash admin settings.

## Spec Changes Implied

- **REQ-FTR-001** (storage): add a NOTE confirming that `footer_enabled` and companion keys live in admin settings, not in the source's `{lang}/footer.json` filesystem pattern, and cite D1.
- **REQ-FTR-006** (per-dashboard override): add a NOTE marking this requirement as a MyDash addition with no source counterpart, citing D2. Confirm storage as `dashboardFooterMode VARCHAR(16)` + `dashboardFooterHtml MEDIUMTEXT NULL` on `oc_mydash_dashboards`.
- **REQ-FTR-007** (multi-language): pin the three-step fallback chain from D3 (viewer NC locale → dashboard primary language → first key). Update the "Locale fallback" scenario to reflect step 2 before step 3.
- **REQ-FTR-005** (sanitisation): add a cross-reference to the text-display widget's allow-list to make the shared definition explicit; note that the footer does not extend the list with `<table>` or `<div>`.

## Open Follow-ups

- Whether `dashboardFooterHtml` should support language-tagged variants (`{en: "...", nl: "..."}`) — likely yes, using the same shape as global config; deferred to a follow-up to keep the initial schema simple.
- Whether the structured-mode `logoUrl` field should accept NC file-ID references (e.g., `fileId:12345`) in addition to plain URLs — this would align with the `header-widget`'s `backgroundImageFileId` pattern.
- Whether the public-share render path includes the footer — the expected answer is yes (preserves branding for external viewers), but this depends on the `dashboard-public-share` capability's rendering context and should be confirmed before implementation.
