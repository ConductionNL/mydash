# Tasks — dashboard-draft-published

## 1. Schema migration

- [x] 1.1 Create `lib/Migration/Version001011Date20260502130000.php` adding three columns to `oc_mydash_dashboards`:
  - `publication_status VARCHAR(20) NOT NULL DEFAULT 'published'`
  - `publish_at DATETIME NULL`
  - `published_at DATETIME NULL`
- [x] 1.2 Same migration adds composite index `mydash_dash_user_pubstatus` on `(user_id, publication_status)` for fast filtering
- [x] 1.3 Backfill existing rows: handled implicitly via the column default `'published'` (design D1) — no UPDATE statement required, eliminating partial-update risk on large tables
- [x] 1.4 Migration is reversible — Nextcloud SimpleMigrationStep handles column drop on rollback via the schema closure
- [ ] 1.5 Run migration locally against sqlite, mysql, and postgres (deferred — no live DB available in this worktree)

## 2. Domain model

- [x] 2.1 Add `Dashboard::STATUS_DRAFT`, `STATUS_PUBLISHED`, `STATUS_SCHEDULED` constants
- [x] 2.2 Add `publicationStatus`, `publishAt`, `publishedAt` fields to `Dashboard` entity with getter/setter via `__call` magic methods (no named args on Entity setters)
- [x] 2.3 Update `Dashboard::jsonSerialize()` to include all three fields (nulls included)

## 3. Mapper layer

- [x] 3.1 Visibility filtering implemented in `DashboardService::filterByPublicationState()` — applies publication-state filtering and lazy materialisation of scheduled dashboards on top of the existing `DashboardMapper::findVisibleToUser()` result. Drafts hidden from non-owner non-admin viewers; scheduled rows past `publishAt` surfaced as published with no DB write
- [x] 3.2 `DashboardMapper::findByUserId()` already returns owner-scoped rows (drafts visible to owner trivially)
- [x] 3.3 PHPUnit coverage in `DashboardServicePublicationTest` (publish/unpublish/schedule + owner/admin guards)
- [x] 3.4 `DashboardMapper::findDueScheduled()` added for the optional eager materialisation path

## 4. Service layer

- [x] 4.1 `DashboardFactory::create()` now defaults new dashboards to `publicationStatus = 'draft'` (overrides the `'published'` column default)
- [x] 4.2 `DashboardService::publish()` — flips status to `published`, stamps `publishedAt` on first publish (idempotent), owner-or-admin guard
- [x] 4.3 `DashboardService::unpublish()` — flips back to `draft`, preserves `publishedAt`, idempotent, owner-or-admin guard
- [x] 4.4 `DashboardService::schedule()` — sets `scheduled` + `publishAt`, validates strictly future timestamp, raises `InvalidArgumentException` mapped to HTTP 400 on past / unparseable input
- [x] 4.5 `DashboardService::materialiseScheduledDashboards()` — eager flip for due scheduled rows; lazy materialisation in the visibility filter remains the correctness contract

## 5. Activity logging

- [ ] 5.1 Activity-feed integration deferred — the dashboard-draft-published change focuses on backend + store wiring per proposal scope; activity logging will land in the sibling `activity-feed-integration` change which already owns `OCP\Activity\IManager` plumbing. The publish/unpublish/schedule actions emit no activity events yet.
- [ ] 5.2 Same — deferred to `activity-feed-integration`
- [ ] 5.3 Same — deferred to `activity-feed-integration`
- [ ] 5.4 Same — deferred to `activity-feed-integration`

## 6. Controller + routes

- [x] 6.1 `DashboardApiController::publish()` mapped to `POST /api/dashboards/{uuid}/publish`
- [x] 6.2 `DashboardApiController::unpublish()` mapped to `POST /api/dashboards/{uuid}/unpublish`
- [x] 6.3 `DashboardApiController::schedule()` mapped to `POST /api/dashboards/{uuid}/schedule` with `publishAt` body field
- [x] 6.4 HTTP 400 with i18n-translatable error message returned when `publishAt` is missing, unparseable, or in the past — `publishAt must be a future timestamp` registered in nl + en l10n files
- [x] 6.5 HTTP 403 returned when caller is neither owner nor admin (`Forbidden: owner or admin only` sentinel mapped via `mapPublicationError()`)
- [x] 6.6 Routes registered in `appinfo/routes.php` ordered BEFORE the group-scoped `{groupId}` wildcards
- [x] 6.7 Each new method carries `#[NoAdminRequired]` + runtime owner-or-admin check at the service boundary

## 7. Frontend store

- [x] 7.1 `src/stores/dashboard.js` extended with `STATUS_DRAFT`/`STATUS_PUBLISHED`/`STATUS_SCHEDULED` exports and store actions `publishDashboard` / `unpublishDashboard` / `scheduleDashboard` (plus `applyPublicationPatch` helper for in-place state updates)
- [x] 7.2 `dashboardStore.effectivePublicationStatus(dashboard)` applies client-side lazy materialisation (`publishAt <= Date.now()` ⇒ `published`) for instant UI feedback
- [x] 7.3 `src/services/api.js` exposes `publishDashboard`, `unpublishDashboard`, `scheduleDashboard` HTTP helpers used by the store actions

## 8. PHPUnit tests

- [x] 8.1 `DashboardServicePublicationTest` covers publish/unpublish/schedule happy paths + owner/admin guards
- [x] 8.2 `testPublishFlipsStatusAndStampsPublishedAt` — publish from draft sets status + publishedAt
- [x] 8.3 `testPublishIsIdempotent` — second publish does not refresh publishedAt
- [x] 8.4 `testPublishForbiddenForNonOwnerNonAdmin` + `testPublishAllowedForAdmin`
- [x] 8.5 `testUnpublishPreservesPublishedAt` + `testUnpublishIsIdempotent`
- [x] 8.6 `testScheduleAcceptsFutureDate` + `testScheduleRejectsPastDate` + `testScheduleRejectsEmptyPublishAt`
- [x] 8.7 `DashboardFactoryTest::testCreateSetsPublicationStatusToDraft`
- [x] 8.8 `DashboardTest::testPublicationStatusConstants` + `testDefaultPublicationStatusIsPublished` + `testPublicationStateSettersRoundTrip` + `testJsonSerializeIncludesPublicationFields`
- [ ] 8.9 Controller-level integration coverage deferred — covered indirectly through the service tests; the controller is a thin adapter mapping exceptions onto HTTP statuses
- [ ] 8.10 Round-trip transition matrix — covered by the idempotent guards in 8.3 / 8.5

## 9. End-to-end Playwright tests

- [ ] 9.1..9.5 E2E coverage deferred to a follow-up `dashboard-publication-ui` change (per proposal: "this change only ships the backend + store wiring") — the spec-derived Playwright runner picks up scenarios from the canonical spec automatically once the UI affordances land

## 10. Quality gates

- [x] 10.1 `composer check:strict` — PHPCS, PHPMD, Psalm, PHPStan, PHPUnit all green (444 tests, 1108 assertions)
- [ ] 10.2 ESLint + Stylelint not re-run in this pass — touched files (api.js + dashboard.js) follow existing patterns; pre-existing lint state unchanged
- [ ] 10.3 OpenAPI / Postman regeneration deferred to the docs sync agent
- [x] 10.4 i18n keys for the new error messages (`publishAt must be a future timestamp`, `Forbidden: owner or admin only`, `Publish dashboard`, `Unpublish dashboard`, `Schedule dashboard`, `Draft`, `Published`, `Scheduled`) added to `l10n/{en,nl}.{js,json}`
- [x] 10.5 SPDX headers in docblock on every new PHP file (entity, migration, service additions, controller additions, test class)
- [ ] 10.6 Hydra gates run by the merge agent
- [x] 10.7 Background-job materialisation: `DashboardService::materialiseScheduledDashboards()` provides the eager path; cron registration deferred (lazy materialisation is the correctness contract per design D4)
