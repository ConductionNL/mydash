# Dashboard Export & Import

## Why

MyDash administrators and end-users need to back up dashboards, share dashboard templates across instances, and author reusable dashboard layouts as artifacts. Today, dashboards exist only within their source Nextcloud instance; there is no standardized way to export a dashboard configuration, widgets, metadata fields, and associated assets, or to re-import them on the same or a different instance. This change introduces a versioned ZIP export format and corresponding import endpoints that allow administrators to export individual dashboards or entire site inventories and re-import them with collision handling, asset preservation, and atomic per-dashboard transactions.

## What Changes

- Define a versioned ZIP container format `mydash-export-v1.zip` containing:
  - `manifest.json` at root with schema version, export timestamp, scope (dashboard or site), dashboard count, and included asset types
  - `dashboards/<uuid>.json` — one JSON file per dashboard with full widget tree, grid config, metadata field references, and per-language descriptions
  - `assets/icons/` — dashboard icon files uploaded to Nextcloud
  - `assets/widgets/<placement-uuid>/` — user-uploaded files referenced by widgets (e.g., image-widget PNG)
  - `metadata-fields.json` — field definitions (name, type, key, etc.) used by exported dashboards
- Expose `POST /api/admin/export` with query parameters `scope` (dashboard|site) and `dashboardUuid` (required if scope=dashboard) — streams a ZIP archive. Nextcloud-admin or Dashboard-Admin only.
- Expose `POST /api/admin/import` (multipart) accepting a `file` field — validates manifest, imports dashboards, creates missing metadata fields, handles UUID collisions. Nextcloud-admin only.
- UUID collision handling: by default, imported dashboards receive fresh UUIDs (safe re-import on the same instance); optional query param `preserveUuids=true` keeps original UUIDs and fails with HTTP 409 on collision.
- Metadata field collision handling: if an imported field's `key` exists in the target, reuse the existing field's ID and remap dashboard references. Fail the affected dashboard if the existing field's `type` differs.
- Asset collision handling: rename imported files with `-imported-<timestamp>` suffix on collision.
- Validation: reject ZIP if manifest is missing or schema version is unsupported (HTTP 400). Per-dashboard JSON is validated; invalid records are skipped and listed in import response errors.
- CLI commands: `php occ mydash:export --scope=site --output=/tmp/mydash-backup.zip` and `php occ mydash:import --file=/tmp/mydash-backup.zip [--preserve-uuids]`.
- Memory-efficient streaming: site exports with 1K+ dashboards stream ZIP writes rather than building in memory.
- Atomic-ish import: each dashboard import wraps in a DB transaction; partial failure (corrupt widget tree) skips just that dashboard, not the batch.

## Capabilities

### New Capabilities

- `dashboard-export-import`: exposes export/import API endpoints, CLI commands, and validation for the versioned ZIP format and collision handling semantics.

### Modified Capabilities

- (none — `dashboards` and `metadata-fields` capabilities remain unchanged)

## Impact

**Affected code:**

- `lib/Controller/AdminController.php` — add `export()` and `import()` methods
- `lib/Service/ExportService.php` (new) — build and stream ZIP, validate manifest, call mapper/service methods
- `lib/Service/ImportService.php` (new) — parse ZIP, validate, create/remap dashboards and metadata fields
- `lib/Db/MetadataFieldMapper.php` — extend with `findByKey()` to support collision detection
- `appinfo/routes.php` — register `POST /api/admin/export` and `POST /api/admin/import`
- `lib/Command/ExportCommand.php` (new) — CLI for `php occ mydash:export`
- `lib/Command/ImportCommand.php` (new) — CLI for `php occ mydash:import`
- No schema migration — uses existing tables only

**Affected APIs:**

- `POST /api/admin/export?scope=dashboard&dashboardUuid=<uuid>` (streams ZIP)
- `POST /api/admin/export?scope=site` (streams ZIP)
- `POST /api/admin/import` (multipart, returns `{importedDashboardCount, skippedDashboardCount, errors: [...]}`)
- `php occ mydash:export --scope=site --output=/tmp/mydash-backup.zip`
- `php occ mydash:import --file=/tmp/mydash-backup.zip [--preserve-uuids]`

**Dependencies:**

- `ZipArchive` (standard library) — for ZIP reading/writing
- `OCP\Files\IRootFolder` — to access Nextcloud storage for asset export
- No new composer or npm dependencies

**Backward compatibility:**

- Zero-impact. No existing endpoints or data models change.

## References

- `openspec/specs/dashboards/spec.md` — dashboard structure and lifecycle
- `openspec/specs/admin-settings/spec.md` — admin authorization patterns
- `openspec/specs/metadata-fields/spec.md` — metadata field definition and assignment (assumed to exist)
