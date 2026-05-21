# Design — Dashboard Language Content

## Overview

The dashboard-language-content change extends MyDash with per-language variants for dashboard names, descriptions, and widget trees. Today dashboards are created in a single language determined by the dashboard owner's Nextcloud locale; this change allows users to create and manage alternate language versions of the same dashboard and exposes the appropriate version based on the viewer's locale or an explicit query parameter.

The implementation centralises all locale normalisation and fallback logic in a single `DashboardTranslationService` class, stores variant data in a dedicated `oc_mydash_dash_translations` table with a unique index on `(dashboard_uuid, language_code)`, and exposes REST endpoints for variant CRUD and promotion. Existing dashboards continue to work unchanged; the migration auto-seeds a primary translation from each dashboard's existing content.

## Goals

- Support multi-language dashboards without breaking existing single-language installs
- Guarantee exactly one primary variant per dashboard (service-enforced invariant)
- Provide deterministic locale resolution with explicit fallback semantics
- Maintain backwards compatibility: dashboards created before this change remain readable without migration
- Isolate translation lifecycle from dashboard lifecycle (variant updates do not touch the dashboard record)

## Non-goals

- Not a general-purpose i18n system for the entire app (this is dashboard-content-only)
- Not runtime schema validation per key/value (type discipline lives in PHP/JS code, not databases)
- Not a UI feature (endpoints only; UI integration is a separate change)

## Architecture

### Data Model

```
oc_mydash_dash_translations
├─ id (BIGINT, auto-increment primary key)
├─ dashboardUuid (VARCHAR(36), references oc_mydash_dashboards.uuid)
├─ languageCode (VARCHAR(16), ISO 639-1 base code: nl, en, de, fr)
├─ name (VARCHAR(255), localised dashboard name)
├─ description (TEXT, localised description)
├─ widgetTreeJson (TEXT, localised widget tree JSON)
├─ isPrimary (SMALLINT 0/1, exactly one per dashboard_uuid = 1)
├─ createdAt (DATETIME)
└─ updatedAt (DATETIME)

Indexes:
├─ PRIMARY KEY (id)
├─ INDEX mydash_trans_dash_idx (dashboard_uuid) — lookups by dashboard
├─ UNIQUE mydash_trans_unique_idx (dashboard_uuid, language_code) — one row per (dashboard, language) pair
└─ INDEX mydash_trans_primary_idx (dashboard_uuid, is_primary) — accelerates primary-variant lookup
```

### PHP Service Layer

```
DashboardTranslationService
├─ normaliseLanguageCode(string): string — collapses nl_NL, nl-BE, EN → nl, en, etc.
├─ resolveForLocale(uuid, locale): {translation: DashboardTranslation?, isFallback: bool}
├─ createVariant(uuid, languageCode, name?, description?, widgetTreeJson?, copyFrom?): DashboardTranslation
├─ updateVariant(uuid, languageCode, name?, description?, widgetTreeJson?): DashboardTranslation
├─ deleteVariant(uuid, languageCode): void (guards: not-the-only-variant, not-primary)
├─ promoteVariantToPrimary(uuid, languageCode): DashboardTranslation
├─ seedPrimaryFor(uuid, dashboardName, description, widgetTreeJson, ownerLocale): DashboardTranslation
├─ deleteAllForDashboard(uuid): void (cascade-delete)
└─ materialiseLegacyVariant(dashboard): DashboardTranslation (in-memory only)

DashboardTranslationMapper
├─ insert(translation): DashboardTranslation
├─ update(translation): DashboardTranslation
├─ delete(id): void
├─ findByDashboardAndLanguage(uuid, languageCode): DashboardTranslation?
├─ findByDashboard(uuid): Array<DashboardTranslation>
└─ deleteByDashboardUuid(uuid): void
```

### REST API

```
GET /api/dashboards/{uuid}
  ├─ Resolves to the user's Nextcloud locale by default
  ├─ Accepts ?lang=<code> to override
  ├─ Response includes:
  │  ├─ ...existing dashboard fields...
  │  ├─ currentLanguage: string
  │  ├─ isFallback: boolean
  │  └─ availableLanguages: [string] (alphabetically sorted)
  └─ Materialises legacy variant in-memory if no translation rows exist

GET /api/dashboards/{uuid}/resolved
  ├─ Explicit resolution endpoint for testing/debugging
  ├─ Returns full translation object + resolution metadata
  └─ Response: {translation, currentLanguage, isFallback, availableLanguages}

POST /api/dashboards/{uuid}/translations
  ├─ Create new language variant
  ├─ Request: {languageCode, name?, description?, copyFrom?}
  ├─ Returns HTTP 201 on success
  ├─ Returns HTTP 409 on duplicate (language already exists)
  ├─ Returns HTTP 403 on cross-user access

PUT /api/dashboards/{uuid}/translations/{lang}
  ├─ Update translation variant (partial updates supported)
  ├─ Request: {name?, description?, widgetTreeJson?}
  ├─ Returns HTTP 200 on success
  ├─ Returns HTTP 404 if variant doesn't exist

DELETE /api/dashboards/{uuid}/translations/{lang}
  ├─ Delete translation variant
  ├─ Returns HTTP 400 if variant is the only remaining or is primary
  ├─ Returns HTTP 200 on success

POST /api/dashboards/{uuid}/translations/{lang}/set-primary
  ├─ Promote variant to primary
  ├─ Returns HTTP 200 (idempotent)
  ├─ Returns HTTP 404 if variant doesn't exist
  └─ Automatically demotes previous primary
```

### Locale Resolution Strategy

The system resolves the appropriate translation variant through a deterministic three-tier process:

1. **Normalise the requested locale** via `normaliseLanguageCode()` — input `nl_NL`, `nl-BE`, `EN` all become `nl`, `en`
2. **Look for exact match** in the translation table — if found, return it with `isFallback: false`
3. **Look for language-part match** — if locale is `nl-BE` and no `nl-BE` row exists, try `nl`; return with `isFallback: false`
4. **Fall back to primary variant** — if steps 1–3 find nothing, return the row where `isPrimary = 1` with `isFallback: true`
5. **Legacy materialisation** — if no translation rows exist at all (pre-migration dashboard), synthesise an in-memory row from the dashboard's existing `name`, `description`, `widgetTreeJson` fields

### Seed Data

Pre-migration dashboards are backfilled during the migration's `postSchemaChange()` hook. For each existing dashboard:
- Read the owner's Nextcloud locale (`\OCP\IConfig::getUserValue($userId, 'core', 'lang')`, fallback to `en`)
- Normalise it to a base code
- Insert a new translation row with `isPrimary = 1`, copying `name`, `description`, `widgetTreeJson` from the dashboard record

This happens atomically as part of the migration, so existing dashboards remain readable throughout.

Example seed data (3 variants per entity):

```json
[
  {
    "uuid": "550e8400-e29b-41d4-a716-446655440001",
    "languageCode": "nl",
    "name": "Mijn Dashboard",
    "description": "Overzicht van mijn projecten",
    "widgetTreeJson": "{...}",
    "isPrimary": 1,
    "createdAt": "2026-05-21T10:30:00Z",
    "updatedAt": "2026-05-21T10:30:00Z"
  },
  {
    "uuid": "550e8400-e29b-41d4-a716-446655440001",
    "languageCode": "en",
    "name": "My Dashboard",
    "description": "Overview of my projects",
    "widgetTreeJson": "{...}",
    "isPrimary": 0,
    "createdAt": "2026-05-21T11:00:00Z",
    "updatedAt": "2026-05-21T11:00:00Z"
  },
  {
    "uuid": "550e8400-e29b-41d4-a716-446655440001",
    "languageCode": "de",
    "name": "Mein Dashboard",
    "description": "Übersicht meiner Projekte",
    "widgetTreeJson": "{...}",
    "isPrimary": 0,
    "createdAt": "2026-05-21T11:15:00Z",
    "updatedAt": "2026-05-21T11:15:00Z"
  },
  {
    "uuid": "550e8400-e29b-41d4-a716-446655440002",
    "languageCode": "en",
    "name": "Team Board",
    "description": "Team planning and tracking",
    "widgetTreeJson": "{...}",
    "isPrimary": 1,
    "createdAt": "2026-05-20T09:00:00Z",
    "updatedAt": "2026-05-20T09:00:00Z"
  },
  {
    "uuid": "550e8400-e29b-41d4-a716-446655440002",
    "languageCode": "fr",
    "name": "Tableau d'équipe",
    "description": "Planification et suivi de l'équipe",
    "widgetTreeJson": "{...}",
    "isPrimary": 0,
    "createdAt": "2026-05-20T10:30:00Z",
    "updatedAt": "2026-05-20T10:30:00Z"
  }
]
```

## Decisions

### Why a dedicated translation table instead of jsonb columns on oc_mydash_dashboards?

The translation table is independent from the dashboard lifecycle. A user can update a translation without touching the dashboard's `updatedAt` or triggering cascade operations. Separation keeps the concerns clear: dashboard record = metadata (owner, source, etc.); translation rows = content (localisations).

### Why a service-enforced primary invariant instead of a database constraint?

Database constraints cannot express "exactly one per (uuid, is_primary)" without making `is_primary` a unique partial index (vendor-specific). A service layer check is simpler and self-documenting: `DashboardTranslationService::ensurePrimaryExists()` is explicit about the intent. The invariant is tested in PHPUnit and enforced on every write path.

### Why normalise locale codes at write time, not read time?

Normalising at insert/lookup ensures a single canonical form in the database. A reading system does not have to re-normalise on every lookup; it just queries for the base code directly. This amortises the cost and eliminates accidental mismatches from normalisation bugs.

### Why ?lang= is strict (404 on no match) rather than falling back to primary?

The `?lang=` parameter is explicit user intent. If a user requests French and French doesn't exist, they should know (HTTP 404). The fallback mechanism applies only to implicit locale resolution (Nextcloud user locale).

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Concurrent requests promote different variants to primary | Use a transaction in `promoteVariantToPrimary()` that reads the current primary, writes the new one, and downgrade the old one in a single atomic block |
| Delete a dashboard while a variant update is in flight | Dashboard deletion uses cascade `deleteByDashboardUuid()` which locks the translation table during delete; variant updates are rejected with 404 on foreign key constraint failure (Nextcloud's IDbConnection handles this) |
| User sees a 404 for an existing language variant due to normalisation bug | Test `normaliseLanguageCode()` exhaustively in PHPUnit: `nl_NL`, `nl_BE`, `nl-NL`, `NL`, `EN`, `En`, etc. all collapse to the right base code |
| Admin deletes the dashboard from the database directly (not via API) | Cascade delete in the dashboard service ensures translation rows are cleaned up; if the cascade is skipped, orphaned rows are harmless (they reference a non-existent uuid) |

## Test strategy

- **Schema migration (PHPUnit)**: migration runs and creates the table; `postSchemaChange()` backfills existing dashboards; rollback removes the table
- **Normalisation (PHPUnit)**: all input locales normalise correctly; edge cases (`null`, empty string, invalid codes) are tested
- **Resolution (PHPUnit)**: exact match, language-part match, primary fallback, legacy materialisation all work
- **CRUD (PHPUnit + integration)**: create/update/delete operations enforce access control and invariants
- **API (integration)**: REST endpoints return correct HTTP status codes; cross-user access is rejected
- **Concurrency (PHPUnit)**: simultaneous promote requests do not corrupt the primary state
