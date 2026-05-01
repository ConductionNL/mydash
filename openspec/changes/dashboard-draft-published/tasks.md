# Tasks — dashboard-draft-published

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddPublicationState.php` adding three columns to `oc_mydash_dashboards`:
  - `publicationStatus ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft'`
  - `publishAt TIMESTAMP NULL`
  - `publishedAt TIMESTAMP NULL`
- [ ] 1.2 Same migration adds composite index `idx_mydash_dash_user_pubstatus` on `(userId, publicationStatus)` for fast filtering in `findVisibleToUser()`
- [ ] 1.3 Backfill existing rows: `UPDATE oc_mydash_dashboards SET publicationStatus = 'published' WHERE publicationStatus IS NULL` to preserve current visibility behaviour
- [ ] 1.4 Confirm migration is reversible (drop columns + index in `postSchemaChange` rollback path)
- [ ] 1.5 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Add `Dashboard::STATUS_DRAFT = 'draft'`, `STATUS_PUBLISHED = 'published'`, `STATUS_SCHEDULED = 'scheduled'` constants
- [ ] 2.2 Add `publicationStatus`, `publishAt`, `publishedAt` fields to `Dashboard` entity with getter/setter (Entity `__call` pattern — no named args)
- [ ] 2.3 Update `Dashboard::jsonSerialize()` to include all three fields (nulls are included)

## 3. Mapper layer

- [ ] 3.1 Add `DashboardMapper::findVisibleToUser(string $userId): array` — applies publication-state filtering and lazy-materialisation of scheduled dashboards
  - Fetches dashboards where `(userId = ? AND publicationStatus IN ('published', 'scheduled'))` OR `(userId = ? AND publicationStatus = 'draft' AND userId = currentUser)` logic
  - For each `scheduled` dashboard with `publishAt <= now()`, materializes it as published (lazy resolution without DB write)
  - Returns array of Dashboard objects ready for API response
- [ ] 3.2 Update `DashboardMapper::findByUserId()` to respect publication-state filtering (draft dashboards visible only to owner, scheduled treated as published if `publishAt <= now()`)
- [ ] 3.3 Add PHPUnit test covering: draft visible only to owner, published visible to owner, scheduled-with-past-publishAt treated as published, scheduled-with-future-publishAt behaves as draft for non-owner

## 4. Service layer

- [ ] 4.1 Update `DashboardFactory::create()` — new dashboards MUST default to `publicationStatus = 'draft'`
- [ ] 4.2 Add `DashboardService::publish(string $uuid, string $userId): Dashboard` — set `publicationStatus = 'published'`, set `publishedAt = now()` if not already set, owner-or-admin guard
- [ ] 4.3 Add `DashboardService::unpublish(string $uuid, string $userId): Dashboard` — set `publicationStatus = 'draft'`, preserve `publishedAt` (don't clear it), owner-or-admin guard
- [ ] 4.4 Add `DashboardService::schedule(string $uuid, string $publishAt, string $userId): Dashboard` — set `publicationStatus = 'scheduled'`, set `publishAt` to provided timestamp, validate `publishAt > now()` and return 400 if past, owner-or-admin guard
- [ ] 4.5 Add `DashboardService::materialiseScheduledDashboards(): void` (optional background-job entry point) — finds all `publicationStatus = 'scheduled'` rows with `publishAt <= now()`, updates them to `published`, sets `publishedAt = now()`

## 5. Activity logging

- [ ] 5.1 Integrate with `OCP\Activity\IManager` or MyDash's existing activity service
- [ ] 5.2 Log activity type `dashboard_published` with subject `{dashboard.name}` when publish action fires
- [ ] 5.3 Log activity type `dashboard_unpublished` with subject `{dashboard.name}` when unpublish action fires
- [ ] 5.4 Log activity type `dashboard_scheduled` with subject `{dashboard.name}` when schedule action fires

## 6. Controller + routes

- [ ] 6.1 Add `DashboardController::publish(string $uuid)` mapped to `POST /api/dashboards/{uuid}/publish` (logged-in owner or admin)
- [ ] 6.2 Add `DashboardController::unpublish(string $uuid)` mapped to `POST /api/dashboards/{uuid}/unpublish` (logged-in owner or admin)
- [ ] 6.3 Add `DashboardController::schedule(string $uuid)` mapped to `POST /api/dashboards/{uuid}/schedule` with request body parsing (logged-in owner or admin, requires `publishAt` ISO-8601 field)
- [ ] 6.4 Each endpoint MUST return HTTP 400 if `publishAt` is in the past (for schedule action), with error message in both `nl` and `en`
- [ ] 6.5 Each endpoint MUST return HTTP 403 if caller is neither owner nor admin
- [ ] 6.6 Register all three routes in `appinfo/routes.php` with proper UUID requirements
- [ ] 6.7 Confirm every new method carries the correct Nextcloud auth attribute (`#[NoAdminRequired]`) and performs runtime owner/admin checks

## 7. Frontend store

- [ ] 7.1 Extend `src/stores/dashboards.js` with `publicationStatus`, `publishAt`, `publishedAt` fields in dashboard objects
- [ ] 7.2 Add computed property or method to apply lazy-materialisation of `scheduled` dashboards (check `publishAt <= now()` client-side for instant UI feedback)
- [ ] 7.3 Update `GET /api/dashboards/visible` filtering logic to respect publication state (draft dashboards excluded unless caller is owner)

## 8. PHPUnit tests

- [ ] 8.1 `DashboardMapperTest::findVisibleToUser` — draft visible only to owner; published visible to owner; scheduled-with-past-publishAt treated as published
- [ ] 8.2 `DashboardServiceTest::testPublish` — transition to published, set publishedAt on first publish, idempotent on subsequent publish calls
- [ ] 8.3 `DashboardServiceTest::testUnpublish` — transition to draft, preserve publishedAt
- [ ] 8.4 `DashboardServiceTest::testSchedule` — valid future date accepted, past date rejected with 400
- [ ] 8.5 `DashboardControllerTest::testPublishOwnerCanPublish` — owner can publish own dashboard
- [ ] 8.6 `DashboardControllerTest::testPublishAdminCanPublish` — admin can publish other user's dashboard
- [ ] 8.7 `DashboardControllerTest::testPublishNonOwnerCannotPublish` — non-owner gets 403
- [ ] 8.8 `DashboardControllerTest::testScheduleInvalidDate` — publishAt in past returns 400 with i18n error message
- [ ] 8.9 `DashboardFactoryTest::testNewDashboardDefaultsToDraft` — new dashboards default to `publicationStatus = 'draft'`
- [ ] 8.10 Test all transitions (draft → published → draft → scheduled → published) round-trip correctly

## 9. End-to-end Playwright tests

- [ ] 9.1 User creates a dashboard via API (defaults to draft), verifies it is not visible in `/api/dashboards/visible` for other users
- [ ] 9.2 Owner publishes the dashboard via `POST /api/dashboards/{uuid}/publish`, verifies it appears in `/api/dashboards/visible` for other users
- [ ] 9.3 Owner schedules the dashboard via `POST /api/dashboards/{uuid}/schedule` with future date, verifies it behaves as draft for non-owner until scheduled time
- [ ] 9.4 Owner unpublishes a published dashboard via `POST /api/dashboards/{uuid}/unpublish`, verifies it disappears from `/api/dashboards/visible` for non-owners
- [ ] 9.5 Admin can publish/unpublish/schedule any user's dashboard (admin-override behavior)

## 10. Quality gates

- [ ] 10.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 10.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 10.3 Update generated OpenAPI spec / Postman collection so external API consumers see the three new endpoints
- [ ] 10.4 `i18n` keys for all new error messages (`Cannot schedule dashboard with past date`, etc.) in both `nl` and `en` per the i18n requirement
- [ ] 10.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 10.6 Run all 10 `hydra-gates` locally before opening PR
- [ ] 10.7 Background job (optional): create `lib/Cron/PublicationMaterialisation.php` and register in `appinfo/info.xml` if the eager materialisation is desired (5-minute schedule)
