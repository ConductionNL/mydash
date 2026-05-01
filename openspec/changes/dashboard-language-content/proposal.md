# Dashboard Language Content

## Why

Today MyDash dashboards store a single widget tree, name, and description per dashboard. Organisations operating in multi-language environments cannot provide locale-specific content variants without manually maintaining separate dashboards per language. This change introduces per-language content variants, allowing a single dashboard to carry multiple localised widget trees, names, and descriptions, with automatic detection of the viewer's Nextcloud locale.

## What Changes

- Add a new table `oc_mydash_dashboard_translations` to store per-language variants of a dashboard's widget tree, name, and description.
- Each dashboard MUST have exactly one variant marked as the primary (`isPrimary = 1`), serving as the fallback when no locale match is found and as the seed for newly-created variants.
- On dashboard creation, a primary translation is auto-created in the owner's current Nextcloud locale (`\OCP\IConfig::getUserValue($uid, 'core', 'lang')`).
- Add locale matching with three-tier precedence: exact match first, then language-part match (e.g. `nl-BE` → `nl`), then fallback to primary.
- Add translation management endpoints: `POST /api/dashboards/{uuid}/translations` to create a variant, `PUT /api/dashboards/{uuid}/translations/{lang}` to update, `DELETE /api/dashboards/{uuid}/translations/{lang}` to remove.
- Add `GET /api/dashboards/{uuid}?lang=<code>` explicit language override.
- Ensure backwards compatibility: existing dashboards with no translation rows are treated as having a single primary variant from the existing widget tree; migration backfills primary translation rows from existing data.

## Capabilities

### New Capabilities

(none — the feature folds into the existing `dashboards` capability)

### Modified Capabilities

- `dashboards`: adds REQ-DASH-026 (translation schema), REQ-DASH-027 (locale resolution and primary fallback), REQ-DASH-028 (create variant), REQ-DASH-029 (update variant), REQ-DASH-030 (delete variant), REQ-DASH-031 (promote primary), REQ-DASH-032 (backwards-compat migration). Existing REQ-DASH-001..025 are untouched.

## Impact

**Affected code:**

- `lib/Db/DashboardTranslation.php` — new entity for translation records
- `lib/Db/DashboardTranslationMapper.php` — CRUD for translation variants
- `lib/Db/Dashboard.php` — no breaking changes; existing fields remain
- `lib/Service/DashboardService.php` — locale resolution logic; translation creation/update/deletion with variant seeding
- `lib/Controller/DashboardController.php` — five new endpoints for translation management; GET /api/dashboards/{uuid} enhanced to include availableLanguages and currentLanguage
- `appinfo/routes.php` — register five new translation routes
- `lib/Migration/VersionXXXXDate2026...php` — schema migration adding `oc_mydash_dashboard_translations` table + migration backfill for primary variants from existing widget trees

**Affected APIs:**

- 5 new routes: `POST|PUT|DELETE /api/dashboards/{uuid}/translations/{lang}`, `POST /api/dashboards/{uuid}/translations`, `POST /api/dashboards/{uuid}/translations/{lang}/set-primary`
- Enhanced `GET /api/dashboards/{uuid}` to return `availableLanguages`, `currentLanguage`, `isFallback` fields
- New `GET /api/dashboards/{uuid}?lang=<code>` query parameter for explicit override

**Dependencies:**

- `OCP\IConfig::getUserValue()` — already available in Nextcloud; used to auto-detect creation locale
- No new composer or npm dependencies

**Migration:**

- Zero-impact schema: adds a new table with no constraints on the existing `oc_mydash_dashboards` table.
- Backfill: For every dashboard, a primary translation row is created with the dashboard's existing `widgetTreeJson` from the parent table, in the owner's locale if available (else 'en' fallback).
- Existing widget tree data is NOT removed from `oc_mydash_dashboards` initially (kept for backwards compat during a transition period); this is tracked separately for future deprecation.
