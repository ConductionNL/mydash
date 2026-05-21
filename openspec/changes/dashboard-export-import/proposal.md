# Dashboard Export & Import Specification

## Purpose

Dashboard export and import enable MyDash administrators to create versioned snapshots of dashboard configurations, widgets, metadata fields, and associated assets. Snapshots are portable across Nextcloud instances, enabling backup, disaster recovery, template authoring, and cross-instance sharing. This capability defines a standardised ZIP container format (`mydash-export-v1.zip`), collision handling semantics, and API/CLI endpoints for end-to-end export-import workflows.

## Affected code units

**Backend:**
- `lib/Controller/AdminController.php` — new methods: `exportDashboards(scope, dashboardUuid?, preserveUuids?)` (POST `/api/admin/export`), `importDashboards(zipFile, preserveUuids?)` (POST `/api/admin/import`)
- `lib/Service/DashboardExportService.php` — new service to build ZIP archives with manifest, dashboards, assets, metadata fields
- `lib/Service/DashboardImportService.php` — new service to validate ZIP structure, handle collisions, import dashboards/fields/assets atomically
- `lib/Command/ExportCommand.php` — new Symfony console command for `occ mydash:export`
- `lib/Command/ImportCommand.php` — new Symfony console command for `occ mydash:import`
- `lib/Exception/InvalidZipException.php` — exception class for malformed archives
- Database schema: NO changes (no new tables; all state lives in existing `oc_mydash_*` tables)

**Frontend:**
- `src/views/AdminApp.vue` — optional UI for manual export/import (out of scope for initial spec; admin uses API/CLI)

**Assets:**
- ZIP container stored in Nextcloud temp/upload directories, streamed to response
- Asset files extracted from ZIP and persisted to Nextcloud storage (configurable per-app paths)

## Why a new capability (`dashboard-export-import`) rather than extending existing ones

The `dashboards`, `widgets`, `tiles` capabilities cover the dashboard *data model* and *runtime behaviour*. Export-import is a distinct *operational concern*: versioning, portability, collision handling, and asset preservation. Grouping it with the data model would conflate different responsibilities and make both harder to reason about.

Export-import shares the same entity shape (Dashboard, Widget, MetadataField) but introduces new semantics (schema versions, collision detection, atomic batch operations). Those are best defined as a separate capability so future export-related changes (schema v2, streaming imports, compression) can amend a cohesive baseline.

## Approach

- Define the ZIP container format as a static JSON schema (manifest + dashboards + metadata-fields)
- Support two scopes: `scope=dashboard` (single) and `scope=site` (all)
- Export builds the ZIP by streaming dashboard rows to avoid memory exhaustion on large instances
- Import validates manifest and per-dashboard JSON before committing any writes
- Handle three types of collisions:
  1. **UUID collisions** — by default assign fresh UUIDs; opt-in `preserveUuids=true` detects and fails
  2. **Metadata field collisions** — by key + type; reuse if types match, skip dashboard if mismatch
  3. **Asset filename collisions** — rename with collision-suffix rather than overwrite
- Wrap each dashboard import in a transaction so partial failures are rolled back per-dashboard
- For large site exports (1000+ dashboards), stream the ZIP to response (not buffered in memory)

## Notes

- The ZIP manifest includes `schemaVersion: 1` for forward compatibility; importer rejects unsupported versions with HTTP 400
- No custom entity/mapper needed — uses existing DashboardRepository, MetadataFieldRepository, and file storage
- CLI commands use Symfony console conventions (`--scope`, `--dashboard-uuid`, `--file`, `--output`, `--preserve-uuids`)
- Asset import does not validate file types — trust the admin's exported archive. Missing assets in the ZIP are logged but do not block dashboard import (the path reference is preserved as-is)
- The import API accepts multipart/form-data for the ZIP file (standard file upload pattern)
