# Tasks — dashboard-language-content

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddDashboardTranslations.php` adding `oc_mydash_dashboard_translations` table with columns: `id`, `dashboardUuid`, `languageCode VARCHAR(16)`, `name`, `description TEXT`, `widgetTreeJson MEDIUMTEXT`, `isPrimary SMALLINT(0/1)`, `createdAt`, `updatedAt`
- [ ] 1.2 Add composite unique index `(dashboardUuid, languageCode)` to enforce one row per language per dashboard
- [ ] 1.3 Add backfill logic: for each row in `oc_mydash_dashboards`, query the owner's Nextcloud locale via `\OCP\IConfig::getUserValue($userId, 'core', 'lang')` (fallback to 'en' if not set), then insert a primary translation row with the existing widget tree, name, description
- [ ] 1.4 Confirm migration is reversible (drop table in `postSchemaChange` rollback path)
- [ ] 1.5 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly and backfill row counts match dashboard count

## 2. Domain model

- [ ] 2.1 Create `DashboardTranslation` entity at `lib/Db/DashboardTranslation.php` with fields: `id`, `dashboardUuid`, `languageCode`, `name`, `description`, `widgetTreeJson`, `isPrimary`, `createdAt`, `updatedAt`
- [ ] 2.2 Implement `__construct()` with Entity `__call` pattern (no named args on setters per user preference)
- [ ] 2.3 Implement `jsonSerialize()` including all fields

## 3. Mapper layer

- [ ] 3.1 Create `DashboardTranslationMapper` at `lib/Db/DashboardTranslationMapper.php` extending `QBMapper`
- [ ] 3.2 Add `findByDashboardUuid(string $dashboardUuid): array` — returns all variants for a dashboard
- [ ] 3.3 Add `findByDashboardUuidAndLanguage(string $dashboardUuid, string $languageCode): ?DashboardTranslation` — returns single variant or null
- [ ] 3.4 Add `findPrimaryByDashboardUuid(string $dashboardUuid): ?DashboardTranslation` — returns the `isPrimary = 1` row
- [ ] 3.5 Add `findByDashboardUuidWithLocaleMatching(string $dashboardUuid, string $preferredLanguage): DashboardTranslation` — implements 3-tier matching (exact, language-part, primary fallback)
- [ ] 3.6 Add `deleteByDashboardUuid(string $dashboardUuid)` for cascade delete when dashboard is deleted
- [ ] 3.7 Add fixture-based PHPUnit test covering: exact match, language-part fallback (nl-BE → nl), primary fallback, missing primary guard

## 4. Service layer

- [ ] 4.1 Add `DashboardTranslationService::createVariant(string $dashboardUuid, string $languageCode, ?string $name, ?string $description, ?string $copyFromLanguage)` — create a new variant, optionally seeded from an existing variant or primary; throw 409 exception if language already exists
- [ ] 4.2 Add `DashboardTranslationService::updateVariant(string $dashboardUuid, string $languageCode, array $patch)` — update `name`, `description`, `widgetTreeJson`; ensure primary variant cannot be deleted via this method (use set-primary instead)
- [ ] 4.3 Add `DashboardTranslationService::deleteVariant(string $dashboardUuid, string $languageCode)` — delete one variant; throw exception if attempting to delete the only remaining variant (must promote another first)
- [ ] 4.4 Add `DashboardTranslationService::promoteVariantToPrimary(string $dashboardUuid, string $languageCode)` — set the target variant's `isPrimary = 1` and downgrade the current primary to 0
- [ ] 4.5 Integrate translation resolution into `DashboardService::getDashboard()` — call mapper's locale-matching method and return the matched translation's widget tree in the response
- [ ] 4.6 Update `DashboardService::createDashboard()` to auto-create a primary translation in the owner's current Nextcloud locale

## 5. Controller + routes

- [ ] 5.1 Enhance `DashboardController::get(string $uuid)` (GET /api/dashboards/{uuid}) to accept optional `?lang=<code>` query param; add response fields `availableLanguages`, `currentLanguage`, `isFallback`
- [ ] 5.2 Add `DashboardController::createTranslation(string $uuid)` mapped to `POST /api/dashboards/{uuid}/translations` (logged-in user, `#[NoAdminRequired]`); body `{languageCode, name?, description?, copyFrom?: 'primary'|<lang>}`
- [ ] 5.3 Add `DashboardController::updateTranslation(string $uuid, string $lang)` mapped to `PUT /api/dashboards/{uuid}/translations/{lang}` (logged-in, `#[NoAdminRequired]`); body `{name?, description?, widgetTreeJson?}`
- [ ] 5.4 Add `DashboardController::deleteTranslation(string $uuid, string $lang)` mapped to `DELETE /api/dashboards/{uuid}/translations/{lang}` (logged-in, `#[NoAdminRequired]`)
- [ ] 5.5 Add `DashboardController::setTranslationPrimary(string $uuid, string $lang)` mapped to `POST /api/dashboards/{uuid}/translations/{lang}/set-primary` (logged-in, `#[NoAdminRequired]`)
- [ ] 5.6 Register all five routes in `appinfo/routes.php`; {lang} route param should accept any VARCHAR(16) string (no special validation)
- [ ] 5.7 Ownership checks: all mutation endpoints MUST verify that the dashboard belongs to the current user (reject 403 for cross-user attempts)

## 6. Response envelope

- [ ] 6.1 `GET /api/dashboards/{uuid}` response includes:
  - All existing dashboard fields
  - `widgetTreeJson` from the matched translation
  - New fields: `availableLanguages: ['en','nl','de']`, `currentLanguage: 'nl'`, `isFallback: true|false`
- [ ] 6.2 `POST /api/dashboards/{uuid}/translations` success (201) returns the created `DashboardTranslation` object
- [ ] 6.3 `POST /api/dashboards/{uuid}/translations` conflict (409) when language already exists
- [ ] 6.4 `DELETE /api/dashboards/{uuid}/translations/{lang}` with one variant remaining returns 400 (cannot delete last variant without promoting another first)

## 7. PHPUnit tests

- [ ] 7.1 `DashboardTranslationMapperTest::testFindByDashboardUuidAndLanguage` — exact match, null on missing
- [ ] 7.2 `DashboardTranslationMapperTest::testFindByDashboardUuidWithLocaleMatching` — exact match, language-part fallback, primary fallback
- [ ] 7.3 `DashboardTranslationMapperTest::testFindPrimaryByDashboardUuid` — returns primary, throws if no primary exists
- [ ] 7.4 `DashboardTranslationServiceTest::testCreateVariant` — success, 409 on duplicate language, optional seed from primary or other variant
- [ ] 7.5 `DashboardTranslationServiceTest::testDeleteVariant` — success, 400 when deleting only variant
- [ ] 7.6 `DashboardTranslationServiceTest::testPromoteVariantToPrimary` — primary flag flipped, old primary downgraded
- [ ] 7.7 `DashboardControllerTest::testGetDashboardWithLangParam` — explicit lang override via ?lang=<code>, 404 if no variant exists for that code
- [ ] 7.8 `DashboardControllerTest::testGetDashboardAutoDetectLocale` — returns user's Nextcloud locale variant if available
- [ ] 7.9 `DashboardControllerTest::testCreateTranslationOwnershipCheck` — non-owner gets 403
- [ ] 7.10 `DashboardControllerTest::testDeleteTranslationLastVariantGuard` — 400 when deleting only variant
- [ ] 7.11 Regression: existing dashboard tests (REQ-DASH-001..025) continue to pass unchanged

## 8. Migration backfill verification

- [ ] 8.1 Create a fixture with 3 dashboards (user alice, user bob, admin template) each with widget tree data
- [ ] 8.2 Run migration; verify 3 translation rows created, each with correct `dashboardUuid`, `isPrimary=1`, language from owner's Nextcloud locale or 'en' fallback
- [ ] 8.3 Verify `GET /api/dashboards/{uuid}` on a pre-existing dashboard returns the backfilled translation's data

## 9. End-to-end Playwright tests

- [ ] 9.1 User creates a dashboard; auto-created primary translation is in user's current Nextcloud locale
- [ ] 9.2 User creates a new translation variant via API; `GET /api/dashboards/{uuid}` with explicit `?lang=<code>` returns that variant
- [ ] 9.3 User without matching language variant falls back to primary on `GET /api/dashboards/{uuid}` (isFallback=true in response)
- [ ] 9.4 User deletes a translation variant; attempt to delete the only remaining variant returns 400
- [ ] 9.5 User promotes a non-primary variant to primary; subsequent `GET /api/dashboards/{uuid}` (no lang param) returns that new primary

## 10. Quality gates

- [ ] 10.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 10.2 `i18n` keys for all new error messages (`Language variant already exists`, `Cannot delete the only language variant`, etc.) in both `nl` and `en` per the i18n requirement
- [ ] 10.3 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 10.4 Update generated OpenAPI spec / Postman collection so external API consumers see the new translation management endpoints
