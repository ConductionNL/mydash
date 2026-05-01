# Tasks — dashboard-versioning

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddDashboardVersionsTable.php` adding `oc_mydash_dashboard_versions` table with columns: id (PK), dashboardUuid, versionNumber, snapshotJson (MEDIUMTEXT), createdBy, createdAt, note
- [ ] 1.2 Same migration adds composite unique index `idx_mydash_dvers_uuid_num` on `(dashboardUuid, versionNumber)` for efficient per-dashboard lookups
- [ ] 1.3 Add composite index `idx_mydash_dvers_created` on `(dashboardUuid, createdAt DESC)` for fast "newest-first" ordering
- [ ] 1.4 Confirm migration is reversible (drop table in `preSchemaChange` / `postSchemaChange` rollback path)
- [ ] 1.5 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Create `lib/Db/DashboardVersion.php` entity with properties: id, dashboardUuid, versionNumber, snapshotJson, createdBy, createdAt, note
- [ ] 2.2 Add getter/setter for each property (Entity `__call` pattern — no named args on setters)
- [ ] 2.3 Add `Dashboard::BACKEND_DATABASE = 'database'` and `Dashboard::BACKEND_GROUPFOLDER = 'groupfolder'` constants (or equivalent for content-backend tracking)
- [ ] 2.4 Add `contentBackend` field to `Dashboard` entity if not already present (part of `groupfolder-storage-backend` change; coordinate with that spec)

## 3. Mapper layer

- [ ] 3.1 Create `lib/Db/DashboardVersionMapper.php` with standard insert/update/delete + findByDashboardUuid(string $dashboardUuid): array
- [ ] 3.2 Add `findLatestByDashboard(string $dashboardUuid, int $limit): array` — returns newest N versions ordered DESC by versionNumber
- [ ] 3.3 Add `findByDashboardAndVersion(string $dashboardUuid, int $versionNumber): DashboardVersion` — single-version fetch, throws NotFound if missing
- [ ] 3.4 Add `pruneOldVersions(string $dashboardUuid, int $keepCount = 50): void` — DELETE oldest versions beyond keepCount for this dashboard
- [ ] 3.5 Add `countByDashboard(string $dashboardUuid): int` — used to determine when pruning threshold is crossed
- [ ] 3.6 Add fixture-based PHPUnit test covering: insert/fetch, ordering by versionNumber, pruning logic, edge case (exactly 50 versions)

## 4. Service layer — versioning strategy pattern

- [ ] 4.1 Create `lib/Service/VersioningStrategy/VersioningStrategyInterface.php` with methods: listVersions(), fetchSnapshot(), restoreVersion(), createSnapshot()
- [ ] 4.2 Create `lib/Service/VersioningStrategy/DatabaseVersioningStrategy.php` — implements interface using DashboardVersionMapper, handles pruning, returns DB-row-backed version objects
- [ ] 4.3 Create `lib/Service/VersioningStrategy/FilesVersioningStrategy.php` — implements interface using `IVersionManager`, maps NC file-versions to compatible response objects, handles soft-failure (returns empty list if versioning unavailable), synthesizes versionNumber from timestamp
- [ ] 4.4 Create strategy factory: `DashboardVersioningStrategyFactory::create(Dashboard $dashboard): VersioningStrategyInterface` — routes to Database or Files strategy based on `$dashboard->getContentBackend()`

## 5. Service layer — version management

- [ ] 5.1 Create `lib/Service/DashboardVersionService.php` with `__construct(DashboardVersioningStrategyFactory, IAppConfig)` injections
- [ ] 5.2 Add `captureSnapshot(Dashboard $dashboard, string $snapshotJson, string $createdBy, ?string $note = null): void` — routes to strategy, enforces debounce (at most one snapshot per 60s per dashboard via APCu cache key `mydash:snapshot_ts:{dashboardUuid}`)
- [ ] 5.3 Add `listVersions(Dashboard $dashboard, string $requestingUser): array` — permission check (owner or admin), calls strategy->listVersions()
- [ ] 5.4 Add `fetchSnapshot(Dashboard $dashboard, int $versionNumber, string $requestingUser): string` — permission check, calls strategy->fetchSnapshot() to get full JSON body
- [ ] 5.5 Add `restoreVersion(Dashboard $dashboard, int $versionNumber, string $restoringUser): string` — permission check, captures pre-restore state as new snapshot, calls strategy->restoreVersion(), emits activity event, returns new content
- [ ] 5.6 Update `DashboardService::updateDashboard()` or equivalent to call `DashboardVersionService::captureSnapshot()` after successful content save (pass the new snapshotJson, the userId, and null for note to capture automatic snapshot)
- [ ] 5.7 Add i18n key for activity event: `activity_dashboard_restored` with params `{dashboard, version, user}` in both nl and en

## 6. Controller + routes

- [ ] 6.1 Create `lib/Controller/DashboardVersionController.php` with `__construct(Request, IAppConfig, DashboardVersionService, DashboardService)` injections
- [ ] 6.2 Add `listVersions(string $uuid)` mapped to `GET /api/dashboards/{uuid}/versions` (`#[NoAdminRequired]`)
  - Permission check: dashboard owner or admin
  - Returns: `{versions: [{versionNumber, createdBy, createdAt, note, sizeBytes}, ...], modeSupported: true|false}` ordered newest-first
- [ ] 6.3 Add `fetchVersion(string $uuid, int $versionNumber)` mapped to `GET /api/dashboards/{uuid}/versions/{versionNumber}` (`#[NoAdminRequired]`)
  - Permission check: dashboard owner or admin
  - Returns: full snapshot body (JSON content of dashboard at that version)
- [ ] 6.4 Add `createVersion(string $uuid)` mapped to `POST /api/dashboards/{uuid}/versions` (`#[NoAdminRequired]`)
  - Permission check: dashboard owner or admin
  - Parses request body for optional `note` field
  - Calls `DashboardVersionService::captureSnapshot()` with current dashboard content + note
  - Returns: HTTP 201 with new version object
- [ ] 6.5 Add `restoreVersion(string $uuid, int $versionNumber)` mapped to `POST /api/dashboards/{uuid}/versions/{versionNumber}/restore` (`#[NoAdminRequired]`)
  - Permission check: dashboard owner or admin
  - Calls `DashboardVersionService::restoreVersion()`
  - Returns: HTTP 200 with restored dashboard content
- [ ] 6.6 Register all 4 routes in `appinfo/routes.php` with proper URL requirements (`{uuid}` = UUID regex, `{versionNumber}` = integer)
- [ ] 6.7 Confirm every method carries `#[NoAdminRequired]` attribute and performs in-body permission checks via `PermissionService` or equivalent

## 7. Integration into existing PUT handler

- [ ] 7.1 Locate `DashboardService::updateDashboard()` or the controller method that handles PUT /api/dashboard/{uuid}/content
- [ ] 7.2 After successful content persistence, insert a call to `DashboardVersionService::captureSnapshot()` with the new content JSON, userId, and null note
- [ ] 7.3 Ensure the debounce check inside `captureSnapshot()` prevents duplicate snapshots during rapid saves
- [ ] 7.4 Test locally: rapid PUT requests should trigger at most one snapshot in a 60s window

## 8. Activity event integration

- [ ] 8.1 Inject `IActivityManager` into `DashboardVersionService`
- [ ] 8.2 In `restoreVersion()` after successful restore, call `IActivityManager->publish()` with event type `dashboard_restored`, subject `{dashboardUuid}`, and attributes `{versionNumber, restoringUser, restoredAt}`
- [ ] 8.3 Register activity event type in `appinfo/app.php` or via `ActivityProvider` if the app uses one
- [ ] 8.4 Add i18n translation for activity message (both nl and en) in `translationfiles/` or equivalent

## 9. PHPUnit tests

- [ ] 9.1 `DashboardVersionMapperTest::testInsertAndFind` — insert version, fetch by dashboardUuid + versionNumber, verify all fields
- [ ] 9.2 `DashboardVersionMapperTest::testLatestVersions` — insert 5 versions, fetch latest 3, verify descending order
- [ ] 9.3 `DashboardVersionMapperTest::testPruneOldVersions` — insert 60 versions, prune to 50, verify oldest 10 deleted, newest 50 remain, versionNumbers unbroken from 11
- [ ] 9.4 `FilesVersioningStrategyTest::testListVersionsGracefulDegradation` — mock IVersionManager to throw exception, verify strategy returns empty list and `modeSupported: false`
- [ ] 9.5 `DashboardVersionServiceTest::testDebounce` — call captureSnapshot twice within 60s, verify only 1 DB insert; call after 60s, verify 2nd insert succeeds
- [ ] 9.6 `DashboardVersionServiceTest::testRestoreCreatesNewSnapshot` — call restoreVersion(), verify pre-restore state captured as new version before content is overwritten
- [ ] 9.7 `DashboardVersionServiceTest::testPermissionGuard` — non-owner calls listVersions/fetchSnapshot/restoreVersion, verify HTTP 403 returned
- [ ] 9.8 `DashboardVersionServiceTest::testRestoreEmitsActivity` — mock IActivityManager, call restoreVersion(), verify activity event published with correct payload
- [ ] 9.9 `DashboardVersionControllerTest::testListVersionsNewestFirst` — call GET /api/dashboards/{uuid}/versions, verify array ordered newest-first
- [ ] 9.10 `DashboardVersionControllerTest::testFetchNonExistentVersion` — call GET /api/dashboards/{uuid}/versions/99 for non-existent version, verify HTTP 404

## 10. End-to-end Playwright tests

- [ ] 10.1 Authenticated user saves a dashboard, waits 61 seconds, saves again; call GET /api/dashboards/{uuid}/versions, verify 2 snapshots exist
- [ ] 10.2 Call POST /api/dashboards/{uuid}/versions with `{"note": "v1.0"}` within 60s of last PUT; verify explicit snapshot created despite debounce window
- [ ] 10.3 Call POST /api/dashboards/{uuid}/versions/{versionNumber}/restore, verify content reverted and new snapshot created
- [ ] 10.4 Restore the pre-restore version (reversibility); verify content restored to original and a new version created
- [ ] 10.5 Test groupfolder backend: dashboard stored in Files; call GET /api/dashboards/{uuid}/versions; verify file-versions are mapped to response objects
- [ ] 10.6 Test soft-failure: disable NC versioning for a groupfolder dashboard; call GET /api/dashboards/{uuid}/versions; verify HTTP 200 with `{versions: [], modeSupported: false}`
- [ ] 10.7 Non-owner user attempts to call GET /api/dashboards/{uuid}/versions, POST restore; verify HTTP 403 each time

## 11. Quality gates

- [ ] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 11.2 ESLint + Stylelint clean on all touched Vue/JS files (none for this backend-focused change, but verify if controller generates API responses that affect frontend)
- [ ] 11.3 Update generated OpenAPI spec / Postman collection to include the 4 new endpoints
- [ ] 11.4 i18n keys for all new error messages and activity events in both `nl` and `en`
- [ ] 11.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 11.6 Run all hydra-gates locally before opening PR

## 12. Documentation

- [ ] 12.1 Add endpoint documentation to `README.md` or API docs: GET|POST /api/dashboards/{uuid}/versions, GET /api/dashboards/{uuid}/versions/{versionNumber}, POST restore
- [ ] 12.2 Note the 60-second debounce window and 50-version retention limit in configuration docs
- [ ] 12.3 Mention soft-failure behavior for groupfolder backend versioning unavailability
