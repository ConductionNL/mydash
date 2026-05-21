# Tasks — dashboard-export-import

## 1. Backend: Export Service

- [ ] 1.1 Create `lib/Service/DashboardExportService.php` class
- [ ] 1.2 Implement `exportSingleDashboard(string $uuid): StreamedResponse` method
- [ ] 1.3 Implement `exportAllDashboards(): StreamedResponse` method
- [ ] 1.4 Implement `buildManifest(string $scope, int $dashboardCount): array` private method
- [ ] 1.5 Implement streaming ZIP builder using `ZipArchive::addFromString()` (not buffered in memory)
- [ ] 1.6 Implement asset extraction logic: copy dashboard icons and widget uploads into `assets/` structure
- [ ] 1.7 Implement metadata field collection: gather all fields referenced by exported dashboards
- [ ] 1.8 Add query to fetch dashboards by type/scope without loading full objects (pagination/streaming support)
- [ ] 1.9 Validate dashboard UUID format with UUID validation helper

## 2. Backend: Import Service

- [ ] 2.1 Create `lib/Service/DashboardImportService.php` class
- [ ] 2.2 Implement `importFromZip(string $zipPath, bool $preserveUuids = false): ImportResult` method
- [ ] 2.3 Implement ZIP validation: check `manifest.json` exists, valid JSON, schema version check
- [ ] 2.4 Implement manifest validation: required fields, scope, dashboardCount
- [ ] 2.5 Implement dashboard JSON validation: required fields (uuid, name, owner, etc.), valid JSON
- [ ] 2.6 Implement UUID collision detection: scan for existing UUIDs when `preserveUuids=true`
- [ ] 2.7 Implement metadata field collision detection: match by key + type
- [ ] 2.8 Implement metadata field reuse: if key+type match exist, use existing field ID
- [ ] 2.9 Implement metadata field creation: create new fields for fields not present in instance
- [ ] 2.10 Implement asset extraction: extract `assets/` files and write to Nextcloud storage
- [ ] 2.11 Implement asset collision handling: rename on filename collision with `-imported-{timestamp}` suffix
- [ ] 2.12 Implement per-dashboard transaction wrapping: `DB::beginTransaction()` / `commit()` / `rollback()`
- [ ] 2.13 Implement partial failure handling: skip invalid dashboards, continue with others
- [ ] 2.14 Implement error collection: gather errors during import, return in response
- [ ] 2.15 Create `ImportResult` DTO with `importedDashboardCount`, `skippedDashboardCount`, `errors[]`
- [ ] 2.16 Create exception classes: `InvalidZipException`, `ZipValidationException`, `ImportException`

## 3. Backend: Admin API Controller

- [ ] 3.1 Create `lib/Controller/AdminController.php` methods (if not exists, or extend existing)
- [ ] 3.2 Implement `exportDashboards(Request $request): StreamedResponse` (POST `/api/admin/export`)
- [ ] 3.3 Implement parameter validation: `scope` (required), `dashboardUuid` (required if scope=dashboard)
- [ ] 3.4 Implement UUID format validation: return 400 on invalid format
- [ ] 3.5 Implement `importDashboards(Request $request): JsonResponse` (POST `/api/admin/import`)
- [ ] 3.6 Implement multipart file extraction from request
- [ ] 3.7 Implement `preserveUuids` query parameter handling (default: false)
- [ ] 3.8 Add HTTP header: `Content-Type: application/zip`, `Content-Disposition: attachment; filename=mydash-export-v1.zip`
- [ ] 3.9 Add permission checks: only Nextcloud admin can export/import
- [ ] 3.10 Add response status codes: 200 (success), 400 (validation), 404 (not found), 409 (UUID collision)

## 4. Backend: CLI Commands

- [ ] 4.1 Create `lib/Command/ExportCommand.php` Symfony console command
- [ ] 4.2 Define CLI arguments: `--scope` (required, site|dashboard), `--dashboard-uuid` (optional), `--output` (required)
- [ ] 4.3 Implement command logic: validate arguments, call ExportService, write ZIP to file
- [ ] 4.4 Implement output messages: success message with dashboard count and file path
- [ ] 4.5 Implement error handling: display error messages, return exit code 1
- [ ] 4.6 Create `lib/Command/ImportCommand.php` Symfony console command
- [ ] 4.7 Define CLI arguments: `--file` (required, path to ZIP), `--preserve-uuids` (optional flag)
- [ ] 4.8 Implement command logic: validate file exists, call ImportService, display summary
- [ ] 4.9 Implement output messages: show imported count, skipped count, error list
- [ ] 4.10 Implement error handling: 404 for missing file, validation errors, collision errors

## 5. Backend: Database & Schema

- [ ] 5.1 Verify no schema migrations needed (all data lives in existing `oc_mydash_*` tables)
- [ ] 5.2 Verify Dashboard, Widget, MetadataField entities support export fields (uuid, name, type, etc.)
- [ ] 5.3 Update `appinfo/routes.php` to register `POST /api/admin/export` and `POST /api/admin/import` routes
- [ ] 5.4 Add admin permission check to both routes (use Middleware or controller check)

## 6. Frontend: Import/Export UI (Optional, Out of Initial Scope)

- [ ] 6.1 Add export button to admin dashboard view (if admin UI exists)
- [ ] 6.2 Add import file upload dialog to admin dashboard view
- [ ] 6.3 Display import progress/results (imported count, errors)
- [ ] 6.4 Handle error toasts on 400/409 responses

## 7. Testing: Unit Tests (PHPUnit)

- [ ] 7.1 Test ExportService::exportSingleDashboard() with valid UUID
- [ ] 7.2 Test ExportService::exportSingleDashboard() with non-existent UUID (404)
- [ ] 7.3 Test ExportService::exportSingleDashboard() with invalid UUID format (400)
- [ ] 7.4 Test ExportService::exportAllDashboards() with mixed dashboard types
- [ ] 7.5 Test ExportService::exportAllDashboards() with empty instance
- [ ] 7.6 Test manifest JSON structure: schemaVersion, scope, dashboardCount, exportedAt
- [ ] 7.7 Test dashboard JSON serialization: all required fields present
- [ ] 7.8 Test metadata field collection: only referenced fields included
- [ ] 7.9 Test asset extraction: icon and widget file paths are relative in ZIP
- [ ] 7.10 Test ImportService::importFromZip() with valid 3-dashboard ZIP
- [ ] 7.11 Test import returns correct DTO: importedDashboardCount, skippedDashboardCount, empty errors
- [ ] 7.12 Test import assigns fresh UUIDs by default (preserveUuids=false)
- [ ] 7.13 Test UUID collision detection: preserveUuids=true with collision returns 409
- [ ] 7.14 Test UUID collision detection: multiple collisions all listed in errors
- [ ] 7.15 Test metadata field reuse: existing field by key+type is reused
- [ ] 7.16 Test metadata field type mismatch: dashboard skipped, error reported
- [ ] 7.17 Test metadata field creation: new fields created if not present
- [ ] 7.18 Test field remapping: 2 dashboards both reference same field get remapped correctly
- [ ] 7.19 Test asset collision: file renamed with `-imported-{timestamp}` suffix
- [ ] 7.20 Test missing asset warning: logged but dashboard still imported
- [ ] 7.21 Test ZIP validation: missing manifest.json returns 400
- [ ] 7.22 Test ZIP validation: unsupported schemaVersion returns 400
- [ ] 7.23 Test ZIP validation: invalid manifest JSON returns 400
- [ ] 7.24 Test ZIP validation: missing required manifest fields returns 400
- [ ] 7.25 Test ZIP validation: corrupt dashboard JSON skips dashboard, others succeed
- [ ] 7.26 Test ZIP validation: dashboard missing required fields (name, uuid) skips it
- [ ] 7.27 Test ZIP validation: non-ZIP file returns 400 with appropriate message
- [ ] 7.28 Test per-dashboard transaction: widget failure rolls back entire dashboard
- [ ] 7.29 Test partial import: one dashboard fails, others succeed, summary correct
- [ ] 7.30 Test import with 10 dashboards, expecting 9 imported, 1 skipped

## 8. Testing: Integration Tests

- [ ] 8.1 Test full export-import cycle: export site, import into same instance (with fresh UUIDs)
- [ ] 8.2 Test full export-import cycle: export site, import into different instance
- [ ] 8.3 Test import preserves metadata field assignments across dashboards
- [ ] 8.4 Test import preserves widget placements, config, order
- [ ] 8.5 Test CLI export command: creates valid ZIP file
- [ ] 8.6 Test CLI import command: imports dashboards from file
- [ ] 8.7 Test CLI commands with invalid parameters (exit code 1, error message)

## 9. Testing: Functional Tests (Playwright/Browser)

- [ ] 9.1 Test admin can initiate export via API (if UI added)
- [ ] 9.2 Test admin can download exported ZIP
- [ ] 9.3 Test admin can upload and import ZIP (if UI added)
- [ ] 9.4 Test imported dashboards appear in admin list
- [ ] 9.5 Test imported dashboards display correctly in workspace (widgets, icons, metadata)

## 10. Testing: Performance Tests

- [ ] 10.1 Test streaming export with 1000 dashboards: measure peak memory (< 100 MB)
- [ ] 10.2 Test streaming export: verify resulting ZIP is valid and extractable
- [ ] 10.3 Test import with 1000 dashboards: measure memory usage

## 11. Documentation & Quality

- [ ] 11.1 Generate OpenAPI/Swagger docs for `POST /api/admin/export` and `POST /api/admin/import`
- [ ] 11.2 Document CLI commands in help text (`php occ mydash:export --help`, etc.)
- [ ] 11.3 Add inline comments explaining collision detection logic
- [ ] 11.4 Add error message translation strings (Dutch + English) for validation errors
- [ ] 11.5 Verify PHPStan level 8 compliance
- [ ] 11.6 Run `composer check:strict` (PSR-12 formatting)
- [ ] 11.7 Run full test suite: PHPUnit, integration tests, Playwright
- [ ] 11.8 Create CHANGELOG entry describing export/import functionality

## 12. Seed Data / Test Fixtures

- [ ] 12.1 Generate test ZIP file with 3 seed dashboards (personal, admin template, group shared)
- [ ] 12.2 Each seed dashboard includes: 2-3 widgets, 2 metadata field assignments, 1 icon/asset
- [ ] 12.3 Store test ZIP in `tests/fixtures/mydash-export-v1.zip` for use in automated tests
