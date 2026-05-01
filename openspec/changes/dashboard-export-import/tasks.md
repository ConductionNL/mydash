# Tasks — dashboard-export-import

## 1. Service layer: export

- [ ] 1.1 Create `lib/Service/ExportService.php` with public method `exportDashboard(string $dashboardUuid, string $currentUserId): StreamResponse` — fetches dashboard by UUID, checks admin/owner permission, builds ZIP in a temporary stream
- [ ] 1.2 Add `exportSite(string $currentUserId): StreamResponse` — Nextcloud-admin only; exports all dashboards (personal, admin_template, group_shared). Streams ZIP to avoid memory exhaustion on 1K+ dashboards
- [ ] 1.3 Add private `buildManifest(string $scope, int $dashboardCount, array $includedAssets): array` — returns manifest data structure with `schemaVersion: 1`, `exportedAt: ISO 8601`, `exportedBy: userId`, `mydashVersion: string`, `scope`, `dashboardCount`, `includedAssets` list
- [ ] 1.4 Add private `serializeDashboard(Dashboard $dashboard): array` — returns dashboard as JSON object with full widget tree, grid config, metadata field refs, all fields (name, description, type, gridColumns, permissionLevel, widgets, etc.)
- [ ] 1.5 Add private `collectAssets(array $dashboards): array` — scans all widget configurations and metadata field definitions; returns `{icons: [...], widgetUploads: {...}, metadataFields: [...]}` mapping paths to collect
- [ ] 1.6 Add private `writeZip(string $tempFile, array $manifest, array $dashboards, array $assets): void` — uses `ZipArchive` to write manifest.json, dashboards/<uuid>.json, assets/, and metadata-fields.json to the temporary file
- [ ] 1.7 Add private `streamZipResponse(string $zipPath, string $filename): StreamResponse` — reads ZIP from disk, sets Content-Type and Content-Disposition headers, streams to response body, deletes temp file

## 2. Service layer: import

- [ ] 2.1 Create `lib/Service/ImportService.php` with public method `import(UploadedFile $file, bool $preserveUuids, string $currentUserId): array` — returns `{importedDashboardCount: int, skippedDashboardCount: int, errors: [...]}`
- [ ] 2.2 Add private `validateZipStructure(ZipArchive $zip): array` — extracts and validates manifest.json; throws `\InvalidArgumentException` if missing or `schemaVersion !== 1`; returns parsed manifest
- [ ] 2.3 Add private `remapUuids(array $dashboards, bool $preserveUuids): array` — if `preserveUuids=false`, replaces all UUIDs in dashboard and widget placement records with fresh v4 UUIDs. Returns modified dashboard array and UUID mapping
- [ ] 2.4 Add private `importMetadataFields(array $importedFields, string $currentUserId): array` — for each imported field, lookup by `key` in target instance via `MetadataFieldMapper::findByKey()`; if exists, check `type` match (fail dashboard on mismatch); if not, create new via `MetadataFieldService::create()`. Returns `{oldId => newId}` mapping and `{oldId => error message}` for mismatches
- [ ] 2.5 Add private `remapMetadataFieldIds(array $dashboards, array $fieldMapping): array` — updates all dashboard metadata field references using the import mapping (e.g., field assignments in dashboard config)
- [ ] 2.6 Add private `importAssets(ZipArchive $zip, array $dashboards): array` — extracts `assets/icons/` and `assets/widgets/` from ZIP; for each, attempt to write to Nextcloud via `IRootFolder`; on collision, append `-imported-<timestamp>` to filename; updates dashboard references to new paths; returns `{skippedAssets: [...], renamedAssets: {...}}`
- [ ] 2.7 Add private `importDashboardBatch(array $dashboards, array $fieldMapping, string $currentUserId): array` — for each dashboard, wrap in a DB transaction: validate JSON, call `DashboardService::create()` (or update if preserveUuids=true and exists), commit/rollback. Collect errors per-dashboard. Returns `{success: int, skipped: int, errors: {...}}`
- [ ] 2.8 Add private `resolvePermissionLevel(Dashboard $imported, string $currentUserId): string` — if the imported dashboard is `type='user'` and current user is not admin, force the effective permission level from admin settings. Otherwise use the imported permission level.

## 3. Mapper layer

- [ ] 3.1 Extend `lib/Db/MetadataFieldMapper.php` with method `findByKey(string $key): ?MetadataField` — returns a single row by unique key, or null if not found

## 4. Controller

- [ ] 4.1 Add methods to `lib/Controller/AdminController.php`:
  - `export(string $scope, ?string $dashboardUuid, IRequest $request): StreamResponse` — validates query params, calls `ExportService::exportDashboard()` or `ExportService::exportSite()`, returns streamed ZIP
  - `import(IRequest $request): DataResponse` — multipart, calls `ImportService::import()` with file, `preserveUuids` query param, returns JSON response
- [ ] 4.2 Both methods check admin-only via `IGroupManager::isAdmin($currentUserId)` (semantic auth, since the controller has `#[NoAdminRequired]`)
- [ ] 4.3 Validate `scope` param; reject unsupported scope with HTTP 400
- [ ] 4.4 Validate `dashboardUuid` is UUID v4 format and exists when scope=dashboard; return HTTP 400 on invalid format or 404 on not found
- [ ] 4.5 Return 409 Conflict with `{collisions: [...]}` if import fails due to UUID collision and `preserveUuids=true`

## 5. Routes

- [ ] 5.1 Register `POST /api/admin/export` in `appinfo/routes.php` mapped to `AdminController::export()`
- [ ] 5.2 Register `POST /api/admin/import` in `appinfo/routes.php` mapped to `AdminController::import()`

## 6. CLI commands

- [ ] 6.1 Create `lib/Command/ExportCommand.php` implementing `Command` interface with:
  - `--scope=site|dashboard` (required)
  - `--dashboard-uuid=<uuid>` (required if scope=dashboard)
  - `--output=/path/to/file.zip` (required)
  - Calls `ExportService` and writes ZIP to output path
  - Displays progress message: "Exported X dashboards to /path/to/file.zip"
- [ ] 6.2 Create `lib/Command/ImportCommand.php` implementing `Command` interface with:
  - `--file=/path/to/file.zip` (required)
  - `--preserve-uuids` (optional boolean flag, defaults false)
  - Calls `ImportService` and displays summary: "Imported X dashboards, skipped Y, encountered Z errors"
  - List any errors to the console

## 7. ZIP format validation & versioning

- [ ] 7.1 Document the ZIP schema in `README.md` or spec:
  - `manifest.json` structure with required fields (schemaVersion, exportedAt, exportedBy, scope, dashboardCount, includedAssets)
  - `dashboards/<uuid>.json` expected fields
  - `assets/icons/<filename>`, `assets/widgets/<placement-uuid>/<filename>` directory structure
  - `metadata-fields.json` array of field objects
- [ ] 7.2 Add validation in `ImportService` to reject schema versions other than 1 (future-proof for v2 migration)
- [ ] 7.3 Per-dashboard JSON validation: reject if missing `uuid`, `name`, or `widgets` key; log and skip with error message

## 8. Memory & streaming

- [ ] 8.1 Use `ZipArchive::addStream()` or equivalent to stream large dashboards rather than buffering in memory
- [ ] 8.2 Test export of 1K+ dashboards locally to confirm peak memory stays below 100 MB
- [ ] 8.3 Use temporary file on disk (`/tmp/mydash-*.zip`) rather than buffering ZIP in memory

## 9. PHPUnit tests

- [ ] 9.1 `ExportServiceTest::testExportDashboard` — single dashboard exports correctly with all fields and assets
- [ ] 9.2 `ExportServiceTest::testExportSite` — site export includes all dashboards (personal, template, group_shared); manifest counts correct
- [ ] 9.3 `ExportServiceTest::testManifestStructure` — manifest has schemaVersion: 1, ISO timestamp, correct scope, asset list
- [ ] 9.4 `ImportServiceTest::testImportDashboardPreservingUuids` — reimport same ZIP with preserveUuids=true on same instance fails with 409
- [ ] 9.5 `ImportServiceTest::testImportDashboardFreshUuids` — reimport same ZIP with preserveUuids=false succeeds, new UUIDs generated
- [ ] 9.6 `ImportServiceTest::testMetadataFieldCollisionSameType` — imported field with existing key and same type reuses existing field ID
- [ ] 9.7 `ImportServiceTest::testMetadataFieldCollisionTypeMismatch` — imported field with existing key but different type fails that dashboard with error message
- [ ] 9.8 `ImportServiceTest::testAssetCollisionRename` — icon/widget file on collision renamed to `-imported-<timestamp>`
- [ ] 9.9 `ImportServiceTest::testInvalidZip` — missing manifest.json rejected with HTTP 400
- [ ] 9.10 `ImportServiceTest::testUnsupportedSchemaVersion` — manifest with schemaVersion: 2 rejected with HTTP 400
- [ ] 9.11 `ImportServiceTest::testPartialFailure` — one dashboard's widget JSON is corrupt; import succeeds with that dashboard skipped and listed in errors
- [ ] 9.12 `AdminControllerTest::testExportNonAdminForbidden` — non-admin user calling POST /api/admin/export returns 403
- [ ] 9.13 `AdminControllerTest::testImportNonAdminForbidden` — non-admin user calling POST /api/admin/import returns 403

## 10. End-to-end Playwright tests

- [ ] 10.1 Admin API call to `POST /api/admin/export?scope=dashboard&dashboardUuid=<uuid>` returns HTTP 200 with ZIP content-type; file can be extracted and contains manifest.json
- [ ] 10.2 Admin API call to `POST /api/admin/export?scope=site` returns ZIP with all dashboards from the test instance
- [ ] 10.3 Admin API call to `POST /api/admin/import` with valid ZIP succeeds with importedDashboardCount > 0
- [ ] 10.4 Non-admin API call to export/import returns 403

## 11. Quality gates

- [ ] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues
- [ ] 11.2 SPDX headers on every new PHP file inside docblock
- [ ] 11.3 i18n keys for all error messages (`"Invalid ZIP manifest"`, `"Metadata field type mismatch"`, etc.) in both `nl` and `en`
- [ ] 11.4 Run all Hydra gates locally before opening PR
- [ ] 11.5 CLI help text documented in `php occ mydash:export --help` and `php occ mydash:import --help` with examples

## 12. Documentation

- [ ] 12.1 Update `README.md` with export/import section: use cases (backup, migration, template authoring), scope differences (dashboard vs site), collision handling strategy
- [ ] 12.2 Document the ZIP format schema and manifest structure
- [ ] 12.3 Add example CLI commands and API curl snippets
