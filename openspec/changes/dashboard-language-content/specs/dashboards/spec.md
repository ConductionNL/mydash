---
capability: dashboards
delta: true
status: draft
---

# Dashboards — Delta from change `dashboard-language-content`

## ADDED Requirements

### Requirement: REQ-DASH-026 Translation Schema

The system MUST store per-language content variants for a dashboard in a dedicated `oc_mydash_dashboard_translations` table. Each variant holds a localised widget tree, name, and description, with exactly one row per (dashboard, language) pair.

#### Scenario: Translation table structure

- GIVEN the schema migration is applied
- THEN the `oc_mydash_dashboard_translations` table MUST exist with columns: `id` (auto-increment primary key), `dashboardUuid VARCHAR(36)`, `languageCode VARCHAR(16)`, `name VARCHAR(255)`, `description LONGTEXT`, `widgetTreeJson MEDIUMTEXT`, `isPrimary SMALLINT(0/1)`, `createdAt DATETIME`, `updatedAt DATETIME`
- AND a composite unique constraint MUST exist on `(dashboardUuid, languageCode)` to prevent duplicate language variants per dashboard

#### Scenario: Primary variant enforcement

- GIVEN any dashboard record
- THEN exactly ONE row MUST have `isPrimary = 1` for that dashboard's UUID
- AND the database MUST guarantee this invariant (enforced by service layer, not by constraint)

#### Scenario: Backwards-compatible backfill

- GIVEN an existing dashboard with widget tree data in `oc_mydash_dashboards`
- WHEN the schema migration runs
- THEN the system MUST auto-create a primary translation row with the dashboard's existing `widgetTreeJson`, `name`, `description`, and `languageCode` set to the dashboard owner's Nextcloud locale (`\OCP\IConfig::getUserValue($userId, 'core', 'lang')`, fallback to 'en' if not set)
- AND the existing widget tree MUST remain in `oc_mydash_dashboards` during the transition period (marked for future deprecation)

#### Scenario: Variant isolation from dashboard record

- GIVEN a dashboard and its translation records
- THEN updates to the dashboard's `name`, `description`, or `widgetTreeJson` fields in `oc_mydash_dashboards` MUST NOT affect the translation records
- AND all subsequent reads MUST fetch content from the translation table, not the dashboard record

### Requirement: REQ-DASH-027 Locale Resolution and Primary Fallback

The system MUST resolve the appropriate translation variant for a requesting user using a three-tier matching strategy: exact language match first, then language-part match, then primary variant fallback.

#### Scenario: Exact language match

- GIVEN a dashboard has variants for 'en', 'nl', 'de-DE'
- AND the user's Nextcloud locale is set to 'de-DE'
- WHEN `GET /api/dashboards/{uuid}` is called (no ?lang param)
- THEN the system MUST return the 'de-DE' variant
- AND the response MUST include `currentLanguage: 'de-DE'`, `isFallback: false`

#### Scenario: Language-part match (prefix fallback)

- GIVEN a dashboard has variants for 'en' and 'nl' (no 'nl-BE')
- AND the user's Nextcloud locale is 'nl-BE'
- WHEN `GET /api/dashboards/{uuid}` is called (no ?lang param)
- THEN the system MUST match the 'nl' variant by language prefix
- AND the response MUST include `currentLanguage: 'nl'`, `isFallback: false`

#### Scenario: Primary fallback when no locale match

- GIVEN a dashboard has primary variant in 'en', and secondary variants in 'nl', 'de'
- AND the user's Nextcloud locale is 'fr' (no French variant exists)
- WHEN `GET /api/dashboards/{uuid}` is called (no ?lang param)
- THEN the system MUST return the primary ('en') variant
- AND the response MUST include `currentLanguage: 'en'`, `isFallback: true`

#### Scenario: Explicit language override via query parameter

- GIVEN a dashboard has variants for 'en', 'nl', 'de'
- AND the user's Nextcloud locale is 'en'
- WHEN `GET /api/dashboards/{uuid}?lang=de` is called
- THEN the system MUST return the 'de' variant, ignoring the user's locale
- AND the response MUST include `currentLanguage: 'de'`, `isFallback: false`

#### Scenario: Explicit lang parameter with no matching variant returns 404

- GIVEN a dashboard has only 'en' and 'nl' variants
- WHEN `GET /api/dashboards/{uuid}?lang=ja` is called
- THEN the system MUST return HTTP 404
- AND MUST NOT fall back to the primary variant (explicit lang param is strict)

#### Scenario: Available languages list

- GIVEN a dashboard has variants for 'en', 'nl', 'de-DE', 'fr'
- WHEN `GET /api/dashboards/{uuid}` is called
- THEN the response MUST include `availableLanguages: ['de-DE', 'en', 'fr', 'nl']` (sorted alphabetically)

### Requirement: REQ-DASH-028 Create Translation Variant

Users MUST be able to create new language variants for a dashboard, optionally seeding the new variant from an existing variant or the primary.

#### Scenario: Create a new translation variant

- GIVEN user "alice" owns a dashboard with a primary 'en' variant
- WHEN she sends `POST /api/dashboards/{uuid}/translations` with body `{"languageCode": "nl", "name": "Mijn Dashboard", "description": "Beschrijving", "copyFrom": "primary"}`
- THEN the system MUST create a new translation row with `languageCode='nl'`, `name='Mijn Dashboard'`, `description='Beschrijving'`, `widgetTreeJson` copied from the primary variant, and `isPrimary=0`
- AND the response MUST return HTTP 201 with the created translation object

#### Scenario: Create variant without copyFrom (defaults to primary)

- GIVEN user "alice" owns a dashboard with primary 'en' variant
- WHEN she sends `POST /api/dashboards/{uuid}/translations` with body `{"languageCode": "de"}` (no name, description, copyFrom)
- THEN the system MUST create a variant seeded from the primary: `widgetTreeJson`, `name`, `description` all copied from primary, `isPrimary=0`
- AND the `name` and `description` MAY be overridden in the same request (optional fields)

#### Scenario: Create variant by copying from non-primary variant

- GIVEN a dashboard has 'en' (primary) and 'nl' (secondary) variants
- WHEN user "alice" sends `POST /api/dashboards/{uuid}/translations` with body `{"languageCode": "de", "copyFrom": "nl"}`
- THEN the system MUST seed the 'de' variant from the 'nl' variant's data, not primary
- AND the response MUST return HTTP 201

#### Scenario: Duplicate language returns conflict error

- GIVEN a dashboard has an 'en' variant
- WHEN user "alice" sends `POST /api/dashboards/{uuid}/translations` with body `{"languageCode": "en"}`
- THEN the system MUST return HTTP 409
- AND the response body MUST include an error message (localized to 'nl' or 'en' based on user locale)

#### Scenario: Cross-user access is rejected

- GIVEN user "alice" owns a dashboard
- WHEN user "bob" sends `POST /api/dashboards/{uuid}/translations`
- THEN the system MUST return HTTP 403
- AND no variant MUST be created

### Requirement: REQ-DASH-029 Update Translation Variant

Users MUST be able to update a translation variant's name, description, and widget tree.

#### Scenario: Update translation name and description

- GIVEN user "alice" owns a dashboard with an 'nl' variant
- WHEN she sends `PUT /api/dashboards/{uuid}/translations/nl` with body `{"name": "Updated Name", "description": "Updated desc"}`
- THEN the system MUST update the 'nl' variant's `name` and `description` fields
- AND set `updatedAt` to the current timestamp
- AND return HTTP 200 with the updated translation object

#### Scenario: Update translation widget tree

- GIVEN user "alice" owns a dashboard with an 'en' variant
- WHEN she sends `PUT /api/dashboards/{uuid}/translations/en` with body `{"widgetTreeJson": "{...new tree...}"}`
- THEN the system MUST replace the entire widget tree
- AND return HTTP 200

#### Scenario: Cross-user update is rejected

- GIVEN user "alice" owns a dashboard
- WHEN user "bob" sends `PUT /api/dashboards/{uuid}/translations/en`
- THEN the system MUST return HTTP 403

#### Scenario: Update non-existent variant returns 404

- GIVEN a dashboard has only 'en' and 'nl' variants
- WHEN user "alice" sends `PUT /api/dashboards/{uuid}/translations/de`
- THEN the system MUST return HTTP 404

#### Scenario: Partial updates are supported

- GIVEN a dashboard's 'nl' variant has name='Original Name', description='Original desc', widgetTreeJson='{...}'
- WHEN user "alice" sends `PUT /api/dashboards/{uuid}/translations/nl` with body `{"name": "New Name"}` (description and widgetTreeJson omitted)
- THEN the system MUST update only the `name` field
- AND `description` and `widgetTreeJson` MUST remain unchanged

### Requirement: REQ-DASH-030 Delete Translation Variant

Users MUST be able to delete language variants, with a guard against deleting the only remaining variant.

#### Scenario: Delete a non-primary variant

- GIVEN user "alice" owns a dashboard with 'en' (primary) and 'nl' (secondary) variants
- WHEN she sends `DELETE /api/dashboards/{uuid}/translations/nl`
- THEN the system MUST delete the 'nl' variant row
- AND the response MUST return HTTP 200

#### Scenario: Delete when multiple variants remain

- GIVEN a dashboard has 'en' (primary), 'nl', and 'de' variants
- WHEN user "alice" sends `DELETE /api/dashboards/{uuid}/translations/nl`
- THEN the system MUST allow the deletion (other variants remain)
- AND the dashboard's primary variant MUST still be accessible

#### Scenario: Cannot delete the only remaining variant

- GIVEN user "alice" owns a dashboard with only one variant ('en', primary)
- WHEN she sends `DELETE /api/dashboards/{uuid}/translations/en`
- THEN the system MUST return HTTP 400
- AND the response MUST include an error message: "Cannot delete the only language variant. Promote another variant to primary first."
- AND the variant MUST NOT be deleted

#### Scenario: Delete primary variant when others exist

- GIVEN a dashboard has 'en' (primary), 'nl', and 'de' variants
- WHEN user "alice" sends `DELETE /api/dashboards/{uuid}/translations/en`
- THEN the system MUST return HTTP 400 (primary cannot be directly deleted)
- AND the error message MUST indicate: "Cannot delete the primary variant. Use POST /api/dashboards/{uuid}/translations/{lang}/set-primary to promote another variant first."
- AND the variant MUST NOT be deleted

#### Scenario: Cross-user delete is rejected

- GIVEN user "alice" owns a dashboard
- WHEN user "bob" sends `DELETE /api/dashboards/{uuid}/translations/nl`
- THEN the system MUST return HTTP 403

### Requirement: REQ-DASH-031 Promote Variant to Primary

Users MUST be able to promote a non-primary variant to become the new primary, downgrading the current primary.

#### Scenario: Promote a secondary variant

- GIVEN user "alice" owns a dashboard with 'en' (primary, isPrimary=1) and 'nl' (secondary, isPrimary=0)
- WHEN she sends `POST /api/dashboards/{uuid}/translations/nl/set-primary`
- THEN the system MUST set 'nl' variant's `isPrimary=1`
- AND set 'en' variant's `isPrimary=0`
- AND return HTTP 200 with the promoted translation object

#### Scenario: Promote idempotent when already primary

- GIVEN a dashboard's 'en' variant is already primary (isPrimary=1)
- WHEN user "alice" sends `POST /api/dashboards/{uuid}/translations/en/set-primary`
- THEN the system MUST return HTTP 200 (idempotent operation)
- AND the variant MUST remain primary
- AND no other variant MUST be affected

#### Scenario: Only one variant can be primary

- GIVEN a dashboard has 'en' (primary), 'nl', and 'de' variants
- WHEN user "alice" sends `POST /api/dashboards/{uuid}/translations/de/set-primary`
- THEN the system MUST set 'de' `isPrimary=1`
- AND set 'en' `isPrimary=0`
- AND ensure 'nl' remains `isPrimary=0`
- AND return HTTP 200

#### Scenario: Cross-user promotion is rejected

- GIVEN user "alice" owns a dashboard
- WHEN user "bob" sends `POST /api/dashboards/{uuid}/translations/nl/set-primary`
- THEN the system MUST return HTTP 403

#### Scenario: Promote non-existent variant returns 404

- GIVEN a dashboard has only 'en' variant
- WHEN user "alice" sends `POST /api/dashboards/{uuid}/translations/ja/set-primary`
- THEN the system MUST return HTTP 404

### Requirement: REQ-DASH-032 Backwards Compatibility and Dashboard Deletion

Existing dashboards without translation rows MUST continue to function as before. Deleting a dashboard MUST cascade-delete all its translation variants.

#### Scenario: Pre-migration dashboard behaves as single-variant

- GIVEN a dashboard was created before the translation schema was deployed
- AND no migration has run yet (no translation rows exist)
- WHEN `GET /api/dashboards/{uuid}` is called
- THEN the system MUST treat the dashboard's existing `widgetTreeJson`, `name`, `description` as the primary variant
- AND the response MUST include `availableLanguages: ['en']` (or the owner's locale if available)
- AND MUST be indistinguishable from a post-migration dashboard with one primary variant

#### Scenario: Post-migration, dashboard has backfilled primary translation

- GIVEN the migration has run on a pre-existing dashboard
- WHEN a query is issued for the dashboard's translations
- THEN exactly one translation row MUST exist with `isPrimary=1`
- AND the row's `languageCode` MUST match the dashboard owner's Nextcloud locale at migration time (or 'en' fallback)

#### Scenario: Dashboard deletion cascades to translations

- GIVEN user "alice" owns a dashboard with 3 translation variants
- WHEN she sends `DELETE /api/dashboard/{id}` (dashboard deletion endpoint)
- THEN the system MUST delete the dashboard record
- AND all 3 translation rows MUST be cascade-deleted via `DashboardTranslationMapper::deleteByDashboardUuid()`
- AND subsequent queries for the dashboard's translations MUST return empty results

#### Scenario: Admin template dashboard with translations

- GIVEN an admin template dashboard has 'en' and 'de' variants
- WHEN a user copies the template via the existing template distribution flow
- THEN the system MUST create a personal dashboard copy with its own primary variant in the user's locale
- AND the copied variant's widget tree, name, description MUST be seeded from the template's primary variant
- AND the copied dashboard MUST be independent from the template (translation updates to the template do NOT affect the copy)

#### Scenario: Migration rollback removes translation table

- GIVEN the migration has been applied and translation data exists
- WHEN the migration is rolled back via `postSchemaChange()` in reverse
- THEN the `oc_mydash_dashboard_translations` table MUST be dropped
- AND existing dashboard records MUST remain unaffected (the widget tree is still in `oc_mydash_dashboards`)

## UPDATED Requirements

(None — all existing REQ-DASH-001..025 remain unchanged in scope and implementation)

## Non-Functional Requirements

- **Performance**: Locale resolution (3-tier matching + database lookup) MUST complete in <100ms per request. `GET /api/dashboards/{uuid}` MUST return within 500ms including all translation metadata.
- **Data integrity**: The invariant "exactly one primary variant per dashboard UUID" MUST be maintained even under concurrent requests. Delete guards MUST prevent orphaning dashboards with zero variants.
- **Localization**: All new error messages MUST be translatable to Dutch and English per the i18n requirement.
- **Backwards compatibility**: Dashboards created before the translation feature MUST continue to work without manual intervention; migration backfill MUST be transparent to users.
