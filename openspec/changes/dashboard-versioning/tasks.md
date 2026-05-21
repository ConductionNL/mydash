# Tasks — dashboard-versioning

## Tasks

### Core Data Model & Persistence

- [ ] Task 1: Create `lib/Db/DashboardVersion.php` entity class representing a version snapshot with properties: id, dashboardUuid, versionNumber, snapshotJson, createdBy, createdAt, note; include `jsonSerialize()` method that deliberately omits snapshotJson for list responses
- [ ] Task 2: Create `lib/Db/DashboardVersionMapper.php` implementing persistence layer with methods: `insert()`, `findByUuid()`, `findByUuidAndNumber()`, `deleteByUuid()`, `pruneOldVersions($dashboardUuid)` (deletes all but 50 most recent)
- [ ] Task 3: Create migration `lib/Migration/Version001015Date20260502130000.php` that creates `oc_mydash_dashboard_versions` table with columns: id (BIGINT PK), dashboard_uuid (STRING(36) NOT NULL), version_number (BIGINT NOT NULL), snapshot_json (TEXT, MEDIUMTEXT on MySQL), created_by (STRING(64) NOT NULL), created_at (DATETIME NOT NULL), note (STRING(500) nullable); add indexes: unique on (dashboard_uuid, version_number), regular on (dashboard_uuid, created_at)
- [ ] Task 4: Register migration in `appinfo/app.php` or `appinfo/routes.php` migration loader

### Service Layer

- [ ] Task 5: Create `lib/Service/DashboardVersionService.php` with methods:
  - `captureSnapshot(string $dashboardUuid, string $snapshotJson, string $createdBy, ?string $note = null, bool $explicit = false): DashboardVersion` — captures a snapshot, allocates next versionNumber, calls pruneOldVersions if > 50
  - `listVersions(string $dashboardUuid): array` — returns {versions: [], modeSupported: bool} with full list ordered newest-first, metadata only (no snapshotJson)
  - `fetchSnapshot(string $dashboardUuid, int $versionNumber): ?DashboardVersion` — returns full snapshot body or null if not found
  - `restoreVersion(string $dashboardUuid, int $versionNumber): ?array` — captures pre-restore snapshot, restores content, updates dashboard updatedAt, returns restored state or null on error; throws ERR_VERSIONING_UNAVAILABLE for groupfolder soft-fail
  - `isGroupfolderBacked(Dashboard $dashboard): bool` — dispatch hook checking contentBackend field (returns false until groupfolder-storage-backend lands)
  - `deleteVersionsForDashboard(string $dashboardUuid): void` — cleanup on dashboard deletion (wired by cascade-events listener)
  - `DEBOUNCE_SECONDS = 60` constant
  - Constants for backend modes (once groupfolder-storage-backend adds contentBackend column)

- [ ] Task 6: Wire debounce in `captureSnapshot()` using `ICacheFactory::createDistributed('mydash_versioning')` with key `mydash_ver_debounce_{uuid}` (TTL = 60s). Explicit snapshots (explicit: true parameter) bypass debounce. Capture failures are log-and-swallow.

- [ ] Task 7: Implement backend dispatch in `listVersions()`, `fetchSnapshot()`, `restoreVersion()` using `isGroupfolderBacked()`. Database path queries mapper; groupfolder path calls `IVersionManager` and maps results. Soft-failure: groupfolder paths return {versions: [], modeSupported: false} on unavailable versioning.

### Controller & Routes

- [ ] Task 8: Create `lib/Controller/DashboardVersionApiController.php` with routes:
  - `POST /api/dashboards/{uuid}/versions` → `createVersion($uuid, array $body)` — creates explicit snapshot with optional note; returns HTTP 201 with version object; authorization check: owner or admin only
  - `GET /api/dashboards/{uuid}/versions` → `listVersions($uuid)` — returns {versions: [], modeSupported: bool}; authorization check: owner or admin only
  - `GET /api/dashboards/{uuid}/versions/{versionNumber}` → `fetchVersion($uuid, $versionNumber)` — returns full snapshot; authorization check: owner or admin only; 404 if not found
  - `POST /api/dashboards/{uuid}/versions/{versionNumber}/restore` → `restoreVersion($uuid, $versionNumber)` — restores version and returns new state; authorization check: owner or admin only; 404 if not found

- [ ] Task 9: Modify `lib/Controller/DashboardApiController.php` — add private method `captureAutomaticSnapshot(Dashboard $dashboard)` called after successful `update()` (PUT /api/dashboard/{uuid}/content). Method calls `DashboardVersionService::captureSnapshot(..., explicit: false)`. Failures are swallowed (log warning, do not fail the user's PUT).

- [ ] Task 10: Register new routes in `appinfo/routes.php`:
  - POST /api/dashboards/{uuid}/versions
  - GET /api/dashboards/{uuid}/versions
  - GET /api/dashboards/{uuid}/versions/{versionNumber}
  - POST /api/dashboards/{uuid}/versions/{versionNumber}/restore

### Activity Integration (Deferred Phase 2)

- [ ] Task 11: Hook `DashboardVersionService::restoreVersion()` to emit Nextcloud activity via `IActivityManager::publish()` with type `dashboard_restored`, actor (current user), and full context (uuid, version number, dashboard name). Activity template: "{{ actor }} restored dashboard '{{ dashboardName }}' to version {{ versionNumber }}". (Currently deferred; timestamp check in updatedAt field serves as audit marker.)

### Frontend (Deferred to follow-up UI chain)

- [ ] Task 12: Frontend Vue component `src/views/DashboardVersionsView.vue` — displays list of versions with version number, created-by, created-at, note columns; "Restore" button for each row (disabled if groupfolder backend and modeSupported: false); confirmation dialog before restore; empty state when versions: [] and modeSupported: true
- [ ] Task 13: Integrate version list into dashboard detail view/modal — add "Version History" tab or sidebar section; wire GET /api/dashboards/{uuid}/versions; listen for modeSupported flag and hide/disable UI accordingly

### Testing

- [ ] Task 14: PHPUnit — `tests/Unit/Service/DashboardVersionServiceTest.php` — test snapshot capture with debounce (rapid PUTs create only 1 snapshot, waits debounce window); explicit snapshots bypass debounce; captures full state; failed PUTs do not create snapshots
- [ ] Task 15: PHPUnit — snapshot restoration: restores correct content; creates pre-restore snapshot; updates dashboard updatedAt; restoring to current version is idempotent (no new snapshot); non-existent version returns null
- [ ] Task 16: PHPUnit — retention pruning: 60+ versions trigger prune, keeps 50, deletes oldest, versionNumber remains monotonic; edge case of exactly 50 versions
- [ ] Task 17: PHPUnit — list versions ordered newest-first, metadata only (no snapshotJson); empty dashboard returns empty array (not 404); soft-failure envelope for unavailable versioning (modeSupported: false)
- [ ] Task 18: PHPUnit — authorization: owner/admin can create/list/fetch/restore; non-owner returns 403; shared dashboard permissions check
- [ ] Task 19: Integration test — groupfolder backend dispatch (mocked IVersionManager); verify soft-failure returns {versions: [], modeSupported: false} when IVersionManager unavailable
- [ ] Task 20: Postman/Newman collection — test all 4 endpoints with success + error scenarios (404, 403, 400); regenerate OpenAPI spec for new routes
- [ ] Task 21: Playwright E2E — test rapid PUT debounce; explicit snapshot creation; restore reversibility (v3 → v5, then v5 → v3); soft-failure UI (version history disabled button state); empty state when no versions

### Quality Gates & Standards

- [ ] Task 22: SPDX headers — add @license + @copyright PHPDoc to all new PHP files (DashboardVersion, DashboardVersionMapper, DashboardVersionService, DashboardVersionApiController, migration)
- [ ] Task 23: Static analysis — `composer check:strict` (Psalm strict mode), PHPStan level 9; all gates green
- [ ] Task 24: ESLint + Stylelint on new Vue components; PHPCodeSniffer (PSR-12) on PHP
- [ ] Task 25: I18n — extract English + Dutch translations for error messages ("version not found", "unauthorized", "restore failed"), empty-state copy ("Version history is not available"), activity template; register in appinfo/l10n/en.json and appinfo/l10n/nl.json
- [ ] Task 26: Hydra gates — run all 10 hydra-gates (SPDX, modal-isolation, route-auth, semantic-auth, admin-router, initial-state, stub-scan, composer-audit, forbidden-patterns, no-admin-idor); document in design.md why debounce is per-request via cache (not cron) and why automatic snapshots do not emit activity events
- [ ] Task 27: Accessibility — version list semantic HTML (<ul>/<li> + <button>), keyboard navigation (Tab/Enter/Space), ARIA labels ("Version 5, created 2026-03-15 by alice"), restore confirmation read by screen readers
- [ ] Task 28: OpenAPI spec regeneration — if using OpenAPI tooling, regenerate after new routes added; verify Postman collection valid

### Documentation

- [ ] Task 29: Changelog entry — one paragraph covering dashboard versioning feature, automatic debounce, explicit snapshots, one-click restore, 50-version retention, backend-agnostic dispatch
- [ ] Task 30: Design.md — document why debounce window is per-request (cache-based, not cron) and why automatic snapshots do NOT emit activity events (only restores, to avoid log flooding)

## Verification

`openspec validate` exits clean. Dashboard version snapshots are created on PUT (debounced correctly), explicit POST creates snapshots bypassing debounce, list/fetch/restore all work correctly, retention keeps 50 versions, authorization checks pass, backend dispatch routes correctly to database (for now) or groupfolder (when contentBackend lands), soft-failure envelope returns for unavailable versioning.

## Tests (company-wide ADR-009)

PHPUnit per Tasks 14–20; Playwright per Task 21. Postman/Newman collection updated for the 4 new endpoints. All hydra-gates pass.

## Documentation (company-wide ADR-010)

Changelog entry per Task 29. Design.md updated per Task 30.

## i18n (company-wide ADR-007)

`nl_NL` + `en_US` for error messages, empty states, activity templates, UI copy per Task 25.
