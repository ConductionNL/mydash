# Tasks — Dashboard Language Content

## Database & Entity Layer

- [ ] Task 1: Create migration file `lib/Migration/Version001017Date20260502130000.php` that creates the `oc_mydash_dash_translations` table with all columns and indexes as specified in REQ-DASH-038 (Data Model)
- [ ] Task 2: Create `lib/Migration/DashboardTranslationTableBuilder.php` (schema builder helper) to encapsulate table creation logic called from the migration
- [ ] Task 3: Implement `postSchemaChange()` in the migration to backfill existing dashboards: for each dashboard, create a primary translation row with languageCode from the owner's Nextcloud locale (or `'en'` fallback), copying name/description/widgetTreeJson from the dashboard record
- [ ] Task 4: Create entity class `lib/Db/DashboardTranslation.php` with properties mapping all table columns (id, dashboardUuid, languageCode, name, description, widgetTreeJson, isPrimary, createdAt, updatedAt)
- [ ] Task 5: Implement mapper class `lib/Db/DashboardTranslationMapper.php` with methods:
  - `insert(DashboardTranslation): DashboardTranslation` — persist new row
  - `update(DashboardTranslation): DashboardTranslation` — update existing row
  - `delete(int $id): void` — delete by ID
  - `findByDashboardAndLanguage(string $uuid, string $languageCode): ?DashboardTranslation` — lookup one variant
  - `findByDashboard(string $uuid): array` — lookup all variants for dashboard
  - `deleteByDashboardUuid(string $uuid): void` — cascade-delete all variants for a dashboard
  - `normaliseLanguageCode(string $locale): string` — collapse `nl_NL`, `nl-BE`, `EN`, etc. to `nl`, `en`, etc.

## Service Layer

- [ ] Task 6: Create `lib/Service/DashboardTranslationService.php` with methods:
  - `resolveForLocale(string $uuid, string $locale): ?array` — return {translation: DashboardTranslation, isFallback: bool} or null; implement three-tier resolution (exact → language-part → primary) per REQ-DASH-039
  - `createVariant(string $uuid, string $languageCode, ?string $name = null, ?string $description = null, ?string $widgetTreeJson = null, string $copyFrom = 'primary'): DashboardTranslation` — create new variant, seeding from copyFrom (primary or another language) per REQ-DASH-040
  - `updateVariant(string $uuid, string $languageCode, ?string $name, ?string $description, ?string $widgetTreeJson): DashboardTranslation` — partial updates per REQ-DASH-041
  - `deleteVariant(string $uuid, string $languageCode): void` — delete with guards (not-only-variant, not-primary) per REQ-DASH-042; throw distinguishable exceptions on violation
  - `promoteVariantToPrimary(string $uuid, string $languageCode): DashboardTranslation` — promote to primary, demote old primary, idempotent per REQ-DASH-043
  - `seedPrimaryFor(string $uuid, string $name, string $description, string $widgetTreeJson, string $ownerLocale): DashboardTranslation` — create initial primary variant on dashboard creation
  - `deleteAllForDashboard(string $uuid): void` — cascade-delete all variants per REQ-DASH-044
  - `materialiseLegacyVariant(Dashboard $dashboard): DashboardTranslation` — synthesise in-memory variant from dashboard record for pre-migration dashboards per REQ-DASH-044

- [ ] Task 7: Ensure `DashboardTranslationService::resolveForLocale()` implements REQ-DASH-039 resolution logic:
  - Normalise the input locale via mapper's `normaliseLanguageCode()`
  - Query for exact match (e.g., `nl`)
  - If not found and locale has hyphen, try language-part match (e.g., `nl` from `nl-BE`)
  - If still not found, fetch primary (where `isPrimary = 1`)
  - If no translation rows exist, use `materialiseLegacyVariant()` for backwards compatibility
  - Return {translation, isFallback: true/false} indicating whether fallback was used

- [ ] Task 8: Create exception classes:
  - `lib/Exception/DashboardTranslationException.php` (base)
  - `lib/Exception/DuplicateLanguageVariantException.php` — when creating variant that already exists (maps to HTTP 409)
  - `lib/Exception/CannotDeleteOnlyVariantException.php` — when deleting the only remaining variant (maps to HTTP 400)
  - `lib/Exception/CannotDeletePrimaryVariantException.php` — when deleting primary while others exist (maps to HTTP 400)

## API Layer

- [ ] Task 9: Create `lib/Controller/DashboardTranslationApiController.php` with routes:
  - `POST /api/dashboards/{uuid}/translations` — create variant (REQ-DASH-040); returns HTTP 201, 409, 403 per scenarios
  - `PUT /api/dashboards/{uuid}/translations/{lang}` — update variant (REQ-DASH-041); returns HTTP 200, 404, 403
  - `DELETE /api/dashboards/{uuid}/translations/{lang}` — delete variant (REQ-DASH-042); returns HTTP 200, 400, 403
  - `POST /api/dashboards/{uuid}/translations/{lang}/set-primary` — promote variant (REQ-DASH-043); returns HTTP 200, 404, 403
  - `GET /api/dashboards/{uuid}/resolved` (optional) — explicit resolution endpoint for testing; returns {translation, currentLanguage, isFallback, availableLanguages}

- [ ] Task 10: Update `lib/Controller/DashboardApiController.php` (or equivalent dashboard read endpoint):
  - Modify `GET /api/dashboards/{uuid}` response to include:
    - `currentLanguage: string` — the language of the returned variant
    - `isFallback: boolean` — whether fallback was used
    - `availableLanguages: [string]` — all available language codes for this dashboard, alphabetically sorted
  - Respect `?lang=` query parameter for explicit override (strict: 404 if not found, no fallback)

- [ ] Task 11: Ensure all API responses use proper i18n for error messages (Dutch and English per ADR-007):
  - Duplicate language error: "Deze taal bestaat al voor dit dashboard" / "This language already exists for this dashboard"
  - Cannot delete only variant: "Dit is de enige taalmogelijkheid. Promoveer eerst een ander variant." / "This is the only language variant. Promote another variant first."
  - Cannot delete primary: "Het primaire variant kan niet worden verwijderd. Promoveer eerst een ander variant." / "The primary variant cannot be deleted. Promote another variant first."

## Service & Dashboard Integration

- [ ] Task 12: Update `lib/Service/DashboardService.php`:
  - Modify `createDashboard()` to call `DashboardTranslationService::seedPrimaryFor()` immediately after inserting the dashboard record
  - Modify `deleteDashboard()` to call `DashboardTranslationService::deleteAllForDashboard()` before deleting the dashboard record (cascade-delete)

- [ ] Task 13: Update `lib/Controller/DashboardController.php` (or equivalent):
  - When a dashboard is created, extract the owner's Nextcloud locale and pass it to `seedPrimaryFor()`
  - Ensure backwards compatibility: existing dashboards without translation rows still work (materialised variants)

## Testing

- [ ] Task 14: PHPUnit — Database & Entity Layer (`tests/unit/Db/DashboardTranslationMapperTest.php`):
  - Test `normaliseLanguageCode()` with inputs: `nl_NL`, `nl_BE`, `nl-NL`, `NL`, `EN`, `En`, `de_DE`, `fr-FR`, empty string, null
  - Test `findByDashboard()` returns all variants in order
  - Test `deleteByDashboardUuid()` deletes all variants for a dashboard
  - Test unique constraint on `(dashboard_uuid, language_code)` is enforced

- [ ] Task 15: PHPUnit — Locale Resolution (`tests/unit/Service/DashboardTranslationServiceTest.php`):
  - Test exact match scenario (REQ-DASH-039 scenario 1)
  - Test language-part match scenario (REQ-DASH-039 scenario 2)
  - Test primary fallback scenario (REQ-DASH-039 scenario 3)
  - Test legacy materialisation for pre-migration dashboards
  - Test `availableLanguages` list is sorted alphabetically

- [ ] Task 16: PHPUnit — CRUD Operations (`tests/unit/Service/DashboardTranslationServiceTest.php`):
  - Test `createVariant()` with copyFrom='primary' and copyFrom={language}
  - Test `createVariant()` raises `DuplicateLanguageVariantException` on duplicate language
  - Test `updateVariant()` with partial updates (only name, only description, only widgetTreeJson)
  - Test `deleteVariant()` raises `CannotDeleteOnlyVariantException` when deleting the only variant
  - Test `deleteVariant()` raises `CannotDeletePrimaryVariantException` when deleting primary with others present
  - Test `promoteVariantToPrimary()` correctly updates isPrimary flags
  - Test `promoteVariantToPrimary()` is idempotent when variant already primary

- [ ] Task 17: PHPUnit — Concurrency (`tests/unit/Service/DashboardTranslationServiceTest.php`):
  - Mock concurrent promote requests; verify only one primary variant survives using transaction semantics

- [ ] Task 18: Integration Tests (`tests/Integration/Controller/DashboardTranslationApiControllerTest.php`):
  - Test HTTP 201 on `POST /api/dashboards/{uuid}/translations` with valid data
  - Test HTTP 409 on duplicate language
  - Test HTTP 403 on cross-user access (alice creates, bob tries to modify)
  - Test HTTP 404 on `?lang=nonexistent` (strict semantics)
  - Test HTTP 200 on `?lang=valid` (override user locale)
  - Test `availableLanguages` in response

- [ ] Task 19: Migration Rollback Test (`tests/Integration/Migration/DashboardTranslationMigrationTest.php`):
  - Test migration creates table
  - Test `postSchemaChange()` backfills existing dashboards
  - Test rollback drops table
  - Test dashboard records remain intact after rollback

## Quality & Documentation

- [ ] Task 20: Code quality:
  - Run `composer check:strict` (Psalm static analysis) and fix any errors
  - Run `composer format` and ensure code adheres to project style
  - Add PHPDoc to all public methods with `@param`, `@return`, `@throws` tags
  - Add class-level docblock on `DashboardTranslationService` explaining the three-tier resolution strategy and primary invariant

- [ ] Task 21: Documentation:
  - Update `CHANGELOG.md` with a note describing the new translation feature, the migration backfill, and how developers add new language variants
  - Add inline documentation in the service explaining the locale normalisation rules (ISO 639-1 base codes)
  - Document that `?lang=` is strict (404 on no match) vs. implicit locale uses fallback

- [ ] Task 22: Verification:
  - Run `openspec validate` to ensure the spec is complete and valid
  - Ensure all six requirements (REQ-DASH-038..044) are covered by at least one test scenario
  - Manual smoke test: create a dashboard, add two language variants, verify resolution logic with different locale overrides

## Verification

`openspec validate` exits clean. All test suites pass: `composer test`, schema migration backfills and rolls back cleanly, API endpoints return correct status codes and response shapes per REQ-DASH-038 through REQ-DASH-044.

## Tests (company-wide ADR-009)

PHPUnit per Tasks 14–19. No new endpoint surface beyond REQ-DASH translation CRUD. Integration tests verify the full lifecycle.

## Documentation (company-wide ADR-010)

Changelog entry per Task 21 plus inline docblocks and schema documentation per Task 20.

## i18n (company-wide ADR-007)

Error messages per Task 11 translated to Dutch and English. No user-facing UI strings added (API-only in this change).
