# Tasks — dashboard-export-import

## 1. Service layer: export

- [x] 1.1 Create `lib/Service/ExportService.php` with public method `exportDashboard(string $dashboardUuid, string $currentUserId): StreamResponse` — fetches dashboard by UUID, checks admin/owner permission, builds ZIP in a temporary stream
- [x] 1.2 Add `exportSite(string $currentUserId): StreamResponse` — Nextcloud-admin only; exports all dashboards (personal, admin_template, group_shared). Streams ZIP to avoid memory exhaustion on 1K+ dashboards
- [x] 1.3 Add private `buildManifest(string $scope, int $dashboardCount, array $includedAssets): array` — returns manifest data structure with `schemaVersion: 1`, `exportedAt: ISO 8601`, `exportedBy: userId`, `mydashVersion: string`, `scope`, `dashboardCount`, `includedAssets` list
- [x] 1.4 Add private `serializeDashboard(Dashboard $dashboard): array` — returns dashboard as JSON object with full widget tree, grid config, metadata field refs, all fields (name, description, type, gridColumns, permissionLevel, widgets, etc.)
- [x] 1.5 Add private `collectAssets(array $dashboards): array` — placeholder asset directories reserved per dashboard (`assets/widgets/<uuid>/`); icon/widget bytes wired through once dashboard-icons / resource-uploads expose a read-by-uuid API. Manifest declares the included asset categories.
- [x] 1.6 Add private `writeZip(string $tempFile, array $manifest, array $dashboards, array $assets): void` — implemented as `buildArchive()`; uses `ZipArchive` to write `manifest.json`, `dashboards/<uuid>.json`, `metadata-fields.json`, and the asset directory layout to a temp file
- [x] 1.7 Add private `streamZipResponse(string $zipPath, string $filename): StreamResponse` — streams the temp ZIP with `Content-Type: application/zip` + `Content-Disposition: attachment` and registers a shutdown handler to delete the temp file

## 2. Service layer: import

- [x] 2.1 Create `lib/Service/ImportService.php` with public method `import(string $zipPath, bool $preserveUuids, string $currentUserId): array` — returns `{importedDashboardCount: int, skippedDashboardCount: int, errors: [...], status, manifest}`
- [x] 2.2 Add public `validateZipStructure(ZipArchive $zip): array` — extracts and validates `manifest.json`; throws `\InvalidArgumentException` if missing, malformed, missing required fields, or `schemaVersion !== 1`; returns parsed manifest
- [x] 2.3 Add public `remapUuids(array $dashboards, bool $preserveUuids): array` — when `preserveUuids=false`, generates fresh UUIDv4s and rewrites `parentUuid` references so the imported tree stays internally consistent
- [ ] 2.4 Add private `importMetadataFields(...)` — deferred to follow-up change; current importer carries the metadata-field stub through the manifest but does not yet reconcile field IDs (no `MetadataFieldMapper` shipped on the parent stack)
- [ ] 2.5 Add private `remapMetadataFieldIds(...)` — deferred with 2.4
- [ ] 2.6 Add private `importAssets(...)` — deferred until the `dashboard-icons` / `resource-uploads` capabilities expose a read/write-by-uuid surface (see open follow-up note)
- [x] 2.7 Add private `importDashboardBatch(array $dashboards, string $currentUserId, bool $preserveUuids): array` — wraps each dashboard insert in a `IDBConnection::beginTransaction()/commit()/rollBack()` cycle, persists widget placements, and collects per-dashboard errors so partial failure is observable
- [x] 2.8 Permission handling: imported `type='user'` dashboards are reassigned to the importing user when UUIDs are remapped, mirroring the safe-import default

## 3. Mapper layer

- [ ] 3.1 Extend `MetadataFieldMapper` — deferred with the metadata-field reconciliation tasks above; the `metadata-fields` capability is not present on the parent stack

## 4. Controller

- [x] 4.1 Add `AdminController::export(string $scope, ?string $dashboardUuid)` and `AdminController::import(bool $preserveUuids)` methods, calling `ExportService` / `ImportService` and returning a `StreamResponse` or `JSONResponse`
- [x] 4.2 Both methods invoke `requireAdmin()` (`IGroupManager::isAdmin`) before delegating
- [x] 4.3 Validate `scope` param; reject unsupported scope with HTTP 400
- [x] 4.4 Validate `dashboardUuid` is UUID-shape and exists when scope=dashboard; HTTP 400 on bad shape, HTTP 404 on missing record
- [x] 4.5 Return 409 Conflict with the `errors` envelope when import detects UUID collisions and `preserveUuids=true`

## 5. Routes

- [x] 5.1 Register `POST /api/admin/export` in `appinfo/routes.php` mapped to `AdminController::export`
- [x] 5.2 Register `POST /api/admin/import` in `appinfo/routes.php` mapped to `AdminController::import`

## 6. CLI commands

- [x] 6.1 Create `lib/Command/ExportCommand.php` (`mydash:export`) with `--scope`, `--dashboard-uuid`, `--output`; reuses `ExportService::buildManifest()` / `serializeDashboard()` and writes the ZIP to disk
- [x] 6.2 Create `lib/Command/ImportCommand.php` (`mydash:import`) with `--file`, `--preserve-uuids`, `--user`; prints the import summary and lists per-dashboard errors
- [x] 6.3 Register both commands in `appinfo/info.xml` so `php occ` discovers them

## 7. ZIP format validation & versioning

- [x] 7.1 Document the ZIP schema in the capability spec — see `openspec/changes/dashboard-export-import/specs/dashboard-export-import/spec.md` (REQ-EXIM-001)
- [x] 7.2 Add validation in `ImportService::validateZipStructure()` to reject `schemaVersion` other than 1
- [x] 7.3 Per-dashboard JSON validation: reject if missing `uuid`, `name`, or `widgets`; skip with an `invalidDashboard` error in the response

## 8. Memory & streaming

- [x] 8.1 Use `ZipArchive::addFromString` against an on-disk temp file so the archive bytes are not materialised in PHP memory
- [ ] 8.2 1K-dashboard memory benchmark — to be measured during the upcoming load-test pass; not a blocker for the code change
- [x] 8.3 Use `tempnam(sys_get_temp_dir(), 'mydash-export-')` for the on-disk archive; the response is streamed and the temp file is cleaned up via `register_shutdown_function`

## 9. PHPUnit tests

- [x] 9.1 `ExportServiceTest::testExportDashboardProducesValidArchive` — single dashboard exports correctly with manifest + dashboard JSON
- [x] 9.2 `ExportServiceTest::testExportSiteCollectsAllDashboards` — site export includes admin templates + personal roots, manifest counts correct
- [x] 9.3 `ExportServiceTest::testManifestStructure` — manifest carries `schemaVersion: 1`, ISO timestamp, scope, asset list
- [x] 9.4 `ImportServiceTest::testImportDashboardPreservingUuidsCollision` — collision returns the `uuidCollision` status the controller maps to HTTP 409
- [x] 9.5 `ImportServiceTest::testImportDashboardFreshUuidsRemap` — fresh UUIDs assigned, dashboard inserted under transactional commit
- [ ] 9.6 Metadata field collision (same type) — deferred with the metadata-fields tasks
- [ ] 9.7 Metadata field collision (type mismatch) — deferred with the metadata-fields tasks
- [ ] 9.8 Asset collision rename — deferred with the asset-import follow-up
- [x] 9.9 `ImportServiceTest::testInvalidZipMissingManifest` — missing manifest rejected with `InvalidArgumentException`
- [x] 9.10 `ImportServiceTest::testUnsupportedSchemaVersion` — `schemaVersion: 2` rejected with `InvalidArgumentException`
- [x] 9.11 `ImportServiceTest::testPartialFailureRollsBackOneDashboard` — one bad dashboard rolled back, sibling dashboards still imported
- [x] 9.12 `AdminControllerExportImportTest::testExportNonAdminForbidden` — non-admin export returns 403
- [x] 9.13 `AdminControllerExportImportTest::testImportNonAdminForbidden` — non-admin import returns 403

## 10. End-to-end Playwright tests

- [ ] 10.1 Admin export single dashboard E2E — to be added in the next E2E pass; PHPUnit + frontend unit coverage gates the merge here
- [ ] 10.2 Admin export site E2E — same as 10.1
- [ ] 10.3 Admin import valid ZIP E2E — same as 10.1
- [ ] 10.4 Non-admin export/import 403 E2E — covered by the controller-level test

## 11. Quality gates

- [x] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan, PHPUnit) passes — see verify report
- [x] 11.2 SPDX headers on every new PHP file inside the docblock
- [x] 11.3 i18n keys for the new UI copy in both `en` and `nl` (`l10n/en.json|js`, `l10n/nl.json|js`)
- [ ] 11.4 Hydra gates pre-PR — owned by the Hydra coordinator, not the implementing change
- [x] 11.5 CLI help text — Symfony Console auto-renders descriptions from `setDescription()` / `addOption()` metadata attached to `mydash:export` and `mydash:import`

## 12. Documentation

- [x] 12.1 Use cases + collision handling — captured in the capability spec (`Purpose`, REQ-EXIM-005..007)
- [x] 12.2 ZIP format schema documented in the capability spec (REQ-EXIM-001)
- [x] 12.3 CLI usage documented in `lib/Command/{Export,Import}Command.php` `setDescription()` + `addOption()` metadata; surfaced via `php occ mydash:export --help`

## 13. Frontend

- [x] 13.1 `src/components/admin/DashboardExportImport.vue` — admin UI for downloading the site export and uploading a ZIP; consumed by `AdminSettings.vue`
- [x] 13.2 `src/services/api.js` — `exportDashboards()` returns a binary blob; `importDashboards(file)` posts a multipart upload
- [x] 13.3 i18n strings for export/import UI copy (en + nl)
