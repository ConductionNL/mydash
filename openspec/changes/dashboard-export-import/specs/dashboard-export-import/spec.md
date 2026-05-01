---
capability: dashboard-export-import
delta: true
status: draft
---

# Dashboard Export & Import — New Capability

## Purpose

Dashboard export and import allow MyDash administrators to create versioned snapshots of dashboard configurations, widgets, metadata fields, and associated assets. Snapshots are portable across Nextcloud instances, enabling backup, disaster recovery, template authoring, and cross-instance sharing. This capability defines a standardized ZIP container format, collision handling semantics, and API/CLI endpoints for end-to-end export-import workflows.

## Data Model

### ZIP Container Format: `mydash-export-v1.zip`

The export container is a ZIP archive with the following structure:

```
mydash-export-v1.zip
├── manifest.json
├── dashboards/
│   ├── <uuid-1>.json
│   ├── <uuid-2>.json
│   └── ...
├── assets/
│   ├── icons/
│   │   ├── dashboard-icon-1.png
│   │   ├── dashboard-icon-2.svg
│   │   └── ...
│   └── widgets/
│       ├── <placement-uuid-1>/
│       │   ├── image.png
│       │   └── ...
│       └── <placement-uuid-2>/
│           └── ...
└── metadata-fields.json
```

### manifest.json

```json
{
  "schemaVersion": 1,
  "exportedAt": "2026-05-01T14:32:00Z",
  "exportedBy": "admin",
  "mydashVersion": "1.0.0",
  "scope": "site",
  "dashboardCount": 42,
  "includedAssets": ["icons", "widgetUploads", "metadataFields"]
}
```

**Fields:**
- `schemaVersion` (integer): Currently `1`. Required for forward compatibility and migration planning.
- `exportedAt` (ISO 8601 string): UTC timestamp of export creation.
- `exportedBy` (string): Nextcloud user ID of the exporting admin.
- `mydashVersion` (string): MyDash app version at time of export (informational, not enforced on import).
- `scope` (string): Either `"dashboard"` (single dashboard) or `"site"` (all dashboards in instance).
- `dashboardCount` (integer): Number of dashboards included.
- `includedAssets` (array of strings): Types of assets packaged (`"icons"`, `"widgetUploads"`, `"metadataFields"`). Informs importer what to expect.

### dashboards/<uuid>.json

Each dashboard is a JSON object with complete state:

```json
{
  "uuid": "<uuid>",
  "userId": "alice",
  "name": "Sales Dashboard",
  "description": "Q2 sales pipeline",
  "type": "user",
  "basedOnTemplate": null,
  "gridColumns": 12,
  "permissionLevel": "full",
  "targetGroups": [],
  "isDefault": 0,
  "isActive": 1,
  "createdAt": "2026-01-15 10:30:00",
  "updatedAt": "2026-05-01 14:00:00",
  "widgets": [
    {
      "uuid": "<placement-uuid>",
      "widgetType": "image-widget",
      "row": 0,
      "column": 0,
      "width": 6,
      "height": 5,
      "config": {
        "imageUrl": "assets/widgets/<placement-uuid>/sales-chart.png",
        "alt": "Sales chart"
      },
      "showTitle": 1,
      "isVisible": 1,
      "sortOrder": 1
    }
  ],
  "metadataFieldAssignments": [
    {
      "fieldKey": "department",
      "fieldValue": "sales"
    }
  ]
}
```

### metadata-fields.json

```json
[
  {
    "id": 42,
    "key": "department",
    "name": "Department",
    "type": "string",
    "required": false,
    "default": null,
    "pattern": null,
    "createdAt": "2026-01-10 08:00:00"
  }
]
```

## ADDED Requirements

### Requirement: REQ-EXIM-001 ZIP Format Definition

The export output MUST be a ZIP container with a versioned schema that includes manifest, dashboards, assets, and metadata field definitions. The container MUST be self-describing and machine-parseable.

#### Scenario: Single-dashboard export contains all required files
- GIVEN an admin exports a single dashboard via `POST /api/admin/export?scope=dashboard&dashboardUuid=<uuid>`
- WHEN the export completes
- THEN the response MUST be a ZIP archive with:
  - `manifest.json` at the root
  - `dashboards/<uuid>.json` containing the exported dashboard state
  - `metadata-fields.json` containing any metadata fields referenced by the dashboard
  - `assets/icons/` directory (if dashboard has an icon)
  - `assets/widgets/` directory (if dashboard widgets have uploaded files)
- AND the manifest MUST declare `scope: "dashboard"` and `dashboardCount: 1`
- AND all file paths inside the ZIP MUST be relative (no leading `/`)

#### Scenario: Site export includes all dashboards
- GIVEN an admin exports the entire site via `POST /api/admin/export?scope=site`
- WHEN the export completes
- THEN the ZIP MUST contain one JSON file per dashboard in `dashboards/` (personal, admin_template, group_shared)
- AND the manifest MUST declare `scope: "site"` and `dashboardCount: N` matching the actual count
- AND a single `metadata-fields.json` MUST be present listing all metadata field definitions in the instance

#### Scenario: Manifest schema version enforces forward compatibility
- GIVEN the export ZIP is created with `manifest.json`
- WHEN the manifest is written
- THEN `schemaVersion` MUST be set to `1`
- AND the importer MUST reject any ZIP with unsupported schemaVersion (e.g., 2, 0) with HTTP 400

#### Scenario: Asset paths are normalized in dashboard JSON
- GIVEN a dashboard contains an icon and a widget with an uploaded image
- WHEN the dashboard is serialized to `dashboards/<uuid>.json`
- THEN all file references MUST use relative asset paths (e.g., `"assets/icons/dashboard-icon.png"` or `"assets/widgets/<placement-uuid>/image.png"`)
- AND these paths MUST correspond to actual files in the ZIP archive

### Requirement: REQ-EXIM-002 Dashboard Scope Export

An admin MUST be able to export a single dashboard with all its configuration, widgets, and associated assets. The export MUST include only the selected dashboard and its dependencies (metadata fields and assets).

#### Scenario: Export single dashboard with widgets
- GIVEN a dashboard with 3 widgets and 2 metadata field assignments
- WHEN admin calls `POST /api/admin/export?scope=dashboard&dashboardUuid=<uuid>`
- THEN the response MUST be HTTP 200 with Content-Type `application/zip`
- AND the ZIP MUST contain exactly one dashboard JSON file
- AND the dashboards JSON MUST include all 3 widgets with their full config (row, column, width, height, config object)
- AND the metadata-fields.json MUST include only the 2 fields referenced by the dashboard (not unrelated instance metadata)

#### Scenario: Export non-existent dashboard
- GIVEN an admin calls `POST /api/admin/export?scope=dashboard&dashboardUuid=00000000-0000-0000-0000-000000000000`
- AND that UUID does not exist in the instance
- THEN the system MUST return HTTP 404

#### Scenario: Invalid UUID format rejected
- GIVEN an admin calls `POST /api/admin/export?scope=dashboard&dashboardUuid=invalid-uuid-format`
- THEN the system MUST return HTTP 400 with error message `"Invalid dashboard UUID format"`

#### Scenario: dashboardUuid parameter required for scope=dashboard
- GIVEN an admin calls `POST /api/admin/export?scope=dashboard` (missing dashboardUuid)
- THEN the system MUST return HTTP 400 with error message `"dashboardUuid parameter is required when scope=dashboard"`

### Requirement: REQ-EXIM-003 Site Scope Export

An admin MUST be able to export all dashboards in the instance in a single operation. The export MUST include all personal, admin_template, and group_shared dashboards, plus all metadata field definitions and assets.

#### Scenario: Site export includes all dashboard types
- GIVEN the instance contains 5 personal dashboards, 2 admin templates, and 1 group_shared dashboard
- WHEN a Nextcloud admin calls `POST /api/admin/export?scope=site`
- THEN the response MUST be HTTP 200 with a ZIP containing all 8 `dashboards/<uuid>.json` files
- AND the manifest `dashboardCount` MUST be `8`
- AND the `metadata-fields.json` MUST contain all metadata field definitions used across all 8 dashboards (deduplicated by key)

#### Scenario: Empty instance site export
- GIVEN an instance with no dashboards
- WHEN an admin calls `POST /api/admin/export?scope=site`
- THEN the system MUST return HTTP 200 with a valid ZIP
- AND the manifest MUST declare `dashboardCount: 0`
- AND the `dashboards/` directory MUST be empty (or absent)

#### Scenario: Memory efficiency for large site exports
- GIVEN an instance with 1000+ dashboards
- WHEN an admin calls `POST /api/admin/export?scope=site`
- THEN the system MUST NOT buffer the entire ZIP in memory
- AND the system MUST stream the ZIP data to the response body
- AND peak memory usage MUST NOT exceed 100 MB (independent of dashboard count)
- NOTE: Implementation MUST use streaming writes via ZipArchive or equivalent

### Requirement: REQ-EXIM-004 Import Endpoint

An admin MUST be able to import a previously exported ZIP archive into the same or a different MyDash instance. The import process MUST validate the ZIP structure, create or update dashboards, handle collisions, and return a summary of imported/skipped records.

#### Scenario: Import valid ZIP creates new dashboards
- GIVEN a valid `mydash-export-v1.zip` with 3 dashboards
- WHEN an admin calls `POST /api/admin/import` with the ZIP file (multipart/form-data, file field)
- THEN the system MUST return HTTP 200 with JSON response:
  ```json
  {
    "importedDashboardCount": 3,
    "skippedDashboardCount": 0,
    "errors": []
  }
  ```
- AND all 3 dashboards MUST be created in the instance with fresh UUIDs (by default)

#### Scenario: Missing manifest.json fails import
- GIVEN a ZIP archive without manifest.json
- WHEN an admin calls `POST /api/admin/import` with the ZIP
- THEN the system MUST return HTTP 400 with error message `"manifest.json not found in archive"`
- AND no dashboards MUST be imported

#### Scenario: Invalid JSON in dashboard file skips that dashboard
- GIVEN a ZIP with 3 dashboards, one of which has corrupt `dashboards/<uuid>.json`
- WHEN admin imports the ZIP
- THEN the system MUST return HTTP 200 (partial success)
- AND the response MUST include `importedDashboardCount: 2, skippedDashboardCount: 1`
- AND the `errors` array MUST contain an error message identifying the corrupt dashboard
- AND the other 2 valid dashboards MUST be imported

#### Scenario: Import multipart file upload
- GIVEN an admin prepares multipart/form-data POST with `file` field set to the ZIP archive
- WHEN they send `POST /api/admin/import` with Content-Type `multipart/form-data`
- THEN the system MUST extract the file from the multipart data
- AND proceed with ZIP validation and import

### Requirement: REQ-EXIM-005 UUID Collision Handling on Import

When importing dashboards with preserved UUIDs, the system MUST detect and report UUID collisions. By default, imported dashboards receive fresh UUIDs; an optional query parameter allows collision-aware imports to fail on conflict.

#### Scenario: Fresh UUIDs by default (safe re-import)
- GIVEN a ZIP from a previous export of the same instance, containing dashboard with uuid `abc-123`
- AND that dashboard uuid already exists in the instance
- WHEN admin calls `POST /api/admin/import?preserveUuids=false` (or omits the parameter)
- THEN the system MUST assign a new UUID to the imported dashboard
- AND the import MUST succeed with `importedDashboardCount: 1`
- AND the instance now has both: the original `abc-123` dashboard and the newly imported dashboard with a different UUID

#### Scenario: Preserve UUIDs with collision detection
- GIVEN a ZIP with dashboard uuid `abc-123`
- AND that uuid already exists in the instance
- WHEN admin calls `POST /api/admin/import?preserveUuids=true`
- THEN the system MUST return HTTP 409 Conflict with JSON:
  ```json
  {
    "importedDashboardCount": 0,
    "skippedDashboardCount": 0,
    "errors": [
      {
        "type": "uuidCollision",
        "dashboard": "abc-123",
        "message": "Dashboard with UUID abc-123 already exists. Use preserveUuids=false to assign new UUIDs."
      }
    ]
  }
  ```
- AND no dashboards MUST be imported

#### Scenario: Multiple UUID collisions reported together
- GIVEN a ZIP with 5 dashboards, 3 of which have UUIDs that exist in the instance
- WHEN admin calls `POST /api/admin/import?preserveUuids=true`
- THEN the system MUST return HTTP 409 with all 3 collisions listed in `errors`
- AND no dashboards MUST be imported (all-or-nothing on collision)

#### Scenario: preserveUuids parameter default is false
- GIVEN an admin calls `POST /api/admin/import` without specifying `preserveUuids`
- WHEN the import includes dashboards with existing UUIDs
- THEN the system MUST behave as if `preserveUuids=false` (assign fresh UUIDs)

### Requirement: REQ-EXIM-006 Metadata Field Collision Handling

When importing dashboards that reference metadata fields, the system MUST detect field collisions by key. If a collision occurs with the same field type, reuse the existing field; if types differ, skip the affected dashboard and report the mismatch.

#### Scenario: Reuse existing field on key collision (same type)
- GIVEN the instance has a metadata field `{key: "department", type: "string"}`
- AND the imported ZIP contains a dashboard that references `{fieldKey: "department", type: "string"}`
- WHEN import processes the ZIP
- THEN the system MUST NOT create a duplicate field
- AND the dashboard MUST be updated to reference the existing field's ID (not the imported field ID)
- AND import MUST succeed with that dashboard counted in `importedDashboardCount`

#### Scenario: Skip dashboard on field type mismatch
- GIVEN the instance has a metadata field `{key: "priority", type: "string", id: 5}`
- AND the imported ZIP contains a dashboard that references `{fieldKey: "priority", type: "number"}`
- WHEN admin imports the ZIP
- THEN the system MUST NOT create a duplicate field
- AND the dashboard MUST be skipped (field type mismatch cannot be auto-resolved)
- AND the `errors` array MUST include: `{type: "metadataFieldTypeMismatch", field: "priority", message: "Field 'priority' exists as type 'string' but import requires type 'number'"}`
- AND the dashboard count MUST be in `skippedDashboardCount`

#### Scenario: New fields created if not present
- GIVEN the imported ZIP contains a dashboard that references `{fieldKey: "custom_field", type: "string"}`
- AND `custom_field` does NOT exist in the instance
- WHEN admin imports the ZIP
- THEN the system MUST create the new field via `MetadataFieldService::create()`
- AND the dashboard MUST reference the newly created field
- AND import MUST succeed

#### Scenario: Field remapping preserves dashboard integrity
- GIVEN a ZIP with 2 dashboards both referencing field `{key: "team", type: "string", id: 42}`
- WHEN the ZIP is imported into an instance that already has `{key: "team", type: "string", id: 99}`
- THEN the system MUST update both dashboards' field references to use ID `99`
- AND both dashboards MUST be imported with correct field bindings

### Requirement: REQ-EXIM-007 Asset Import and Collision Handling

When importing dashboards with asset references (icons, widget uploads), the system MUST extract and restore those assets to Nextcloud storage. On filename collision, assets MUST be renamed with a collision-suffix rather than overwriting existing files.

#### Scenario: Icons imported to Nextcloud storage
- GIVEN a ZIP with an icon file `assets/icons/dashboard-logo.png`
- AND the dashboard JSON references `"iconPath": "assets/icons/dashboard-logo.png"`
- WHEN admin imports the ZIP
- THEN the system MUST extract the PNG file from the ZIP
- AND write it to Nextcloud storage at the original path (e.g., `/admin/dashboard-icons/dashboard-logo.png` or equivalent)
- AND update the dashboard JSON to reference the new Nextcloud path

#### Scenario: Widget uploads imported to storage
- GIVEN a ZIP with widget upload `assets/widgets/<placement-uuid>/chart-data.csv`
- WHEN admin imports
- THEN the system MUST extract the CSV and write it to Nextcloud storage
- AND the dashboard's widget config MUST reference the new Nextcloud path

#### Scenario: Asset filename collision triggers rename
- GIVEN a ZIP with icon file `assets/icons/logo.png`
- AND an existing Nextcloud file already at `/admin/dashboard-icons/logo.png`
- WHEN admin imports the ZIP
- THEN the system MUST NOT overwrite the existing file
- AND instead write the imported asset to `/admin/dashboard-icons/logo-imported-20260501T143200Z.png` (or similar collision-suffix pattern)
- AND update the dashboard JSON to reference the renamed path

#### Scenario: Missing asset in ZIP does not block dashboard import
- GIVEN a ZIP with a dashboard that references `assets/icons/missing-icon.png`
- AND that file does NOT exist in the ZIP
- WHEN admin imports
- THEN the system MUST log a warning: `"Asset not found in ZIP: assets/icons/missing-icon.png"`
- AND the dashboard MUST still be imported with the asset path left as-is (importer cannot resolve the reference, but dashboard structure is preserved)
- AND import MUST succeed (not treated as an error)

### Requirement: REQ-EXIM-008 Manifest and ZIP Validation

The import process MUST validate the ZIP structure, manifest schema, and per-dashboard JSON before committing any changes. Invalid or unsupported archives MUST be rejected with clear error messages.

#### Scenario: Reject unsupported schema version
- GIVEN a ZIP with `manifest.json` containing `schemaVersion: 2`
- WHEN admin calls `POST /api/admin/import`
- THEN the system MUST return HTTP 400 with error message `"Unsupported manifest schema version: 2. Only version 1 is supported."`
- AND no dashboards MUST be imported

#### Scenario: Reject manifest with invalid JSON
- GIVEN a ZIP with malformed `manifest.json`
- WHEN admin imports
- THEN the system MUST return HTTP 400 with error message `"manifest.json is not valid JSON"`

#### Scenario: Reject ZIP missing required manifest fields
- GIVEN a ZIP with `manifest.json` missing `schemaVersion` or `scope` field
- WHEN admin imports
- THEN the system MUST return HTTP 400 listing which required field(s) are missing

#### Scenario: Dashboard JSON missing required fields
- GIVEN a ZIP with a `dashboards/<uuid>.json` missing the `name` or `uuid` field
- WHEN admin imports
- THEN the system MUST skip that dashboard
- AND report in `errors` array: `{type: "invalidDashboard", uuid: "<uuid>", message: "Missing required field: name"}`
- AND other valid dashboards in the batch MUST still be imported

#### Scenario: ZIP file is not a valid ZIP archive
- GIVEN an admin uploads a file that is NOT a valid ZIP (e.g., a text file)
- WHEN `POST /api/admin/import` processes the file
- THEN the system MUST return HTTP 400 with error message `"Uploaded file is not a valid ZIP archive"`

### Requirement: REQ-EXIM-009 Schema Versioning and Migration Path

The export format MUST support forward-compatible versioning. Only `schemaVersion: 1` is implemented now; the spec MUST document migration semantics for future versions.

#### Scenario: Current version is schemaVersion 1
- GIVEN an export created by the current MyDash version
- WHEN the export ZIP is generated
- THEN the manifest MUST contain `schemaVersion: 1`

#### Scenario: Version 2 migration path documented
- GIVEN that a future version of MyDash might export `schemaVersion: 2`
- WHEN the specification is updated
- THEN the migration process MUST be documented in a future ADR or spec change (e.g., `ADR-XXX: Dashboard Export Schema v2`)
- AND the importer MUST refuse `schemaVersion: 2` on v1-only instances with HTTP 400
- AND migration tooling (if needed) MUST be provided in a separate change proposal

#### Scenario: Version mismatch does not corrupt existing data
- GIVEN an instance running export/import for `schemaVersion: 1`
- WHEN an unsupported `schemaVersion: 2` ZIP is encountered
- THEN the import MUST fail cleanly (HTTP 400)
- AND the instance database MUST remain unchanged
- AND the user MUST be instructed to upgrade MyDash if they need to import v2 archives

### Requirement: REQ-EXIM-010 CLI Commands for Export and Import

Administrators MUST be able to export and import dashboards via command-line interface for automation, backup scripting, and disaster recovery workflows.

#### Scenario: Export all dashboards via CLI
- GIVEN an admin runs `php occ mydash:export --scope=site --output=/tmp/mydash-backup.zip`
- THEN the command MUST create a ZIP archive at `/tmp/mydash-backup.zip`
- AND the command output MUST display: `"Exported N dashboards to /tmp/mydash-backup.zip"`
- AND the command exit code MUST be `0` (success)

#### Scenario: Export single dashboard via CLI
- GIVEN an admin runs `php occ mydash:export --scope=dashboard --dashboard-uuid=<uuid> --output=/tmp/single-dashboard.zip`
- THEN the command MUST create a ZIP with that single dashboard
- AND the output MUST display: `"Exported 1 dashboard to /tmp/single-dashboard.zip"`

#### Scenario: CLI export missing required parameters
- GIVEN an admin runs `php occ mydash:export --scope=site` (missing --output)
- THEN the command MUST return exit code `1` (error)
- AND the command output MUST display: `"--output parameter is required"`

#### Scenario: Import via CLI with default settings
- GIVEN an admin runs `php occ mydash:import --file=/tmp/mydash-backup.zip`
- THEN the command MUST import the ZIP with `preserveUuids=false` (default)
- AND the output MUST display: `"Imported N dashboards, skipped M, errors: E"`

#### Scenario: Import via CLI with UUID preservation
- GIVEN an admin runs `php occ mydash:import --file=/tmp/mydash-backup.zip --preserve-uuids`
- THEN the command MUST import with `preserveUuids=true`
- AND if collisions are detected, the output MUST list them: `"UUID collision: abc-123. Use --no-preserve-uuids to assign new UUIDs."`

#### Scenario: CLI import file not found
- GIVEN an admin runs `php occ mydash:import --file=/nonexistent/file.zip`
- THEN the command MUST return exit code `1`
- AND the output MUST display: `"File not found: /nonexistent/file.zip"`

### Requirement: REQ-EXIM-011 Atomic Import Transactions and Memory Efficiency

Each dashboard import MUST be wrapped in a database transaction to ensure consistency. Site exports with 1K+ dashboards MUST use streaming to avoid memory exhaustion.

#### Scenario: Dashboard import is transaction-wrapped
- GIVEN the import process is importing a single dashboard with 5 widgets
- WHEN a widget save fails mid-transaction
- THEN the system MUST roll back the entire dashboard (widgets, metadata field assignments, etc.)
- AND the dashboard MUST NOT partially exist in the database
- AND the error MUST be reported in the `errors` array

#### Scenario: Partial import on multi-dashboard failure
- GIVEN a ZIP with 10 dashboards, where dashboard #5 has corrupt widget JSON
- WHEN import processes the batch
- THEN dashboards #1-4 and #6-10 MUST be successfully imported (within their own transactions)
- AND dashboard #5 MUST be skipped with an error message
- AND the final response MUST show `importedDashboardCount: 9, skippedDashboardCount: 1`

#### Scenario: Site export with 1000+ dashboards uses streaming
- GIVEN the instance contains 1000 dashboards
- WHEN an admin calls `POST /api/admin/export?scope=site`
- THEN the system MUST NOT build the entire ZIP in memory
- AND the ZIP output MUST be streamed to the HTTP response in chunks
- AND peak memory usage MUST remain under 100 MB

#### Scenario: Streaming preserves response stream integrity
- GIVEN an export of 500 dashboards is being streamed
- WHEN the response body is transmitted to the client
- THEN the resulting ZIP file on disk MUST be valid and extractable
- AND the manifest and all dashboard files MUST be intact

## Non-Functional Requirements

- **Performance**: Single dashboard export MUST complete within 5s; site export of 100 dashboards within 30s (excluding I/O). Import of the same 100 dashboards within 60s.
- **Memory**: Site export of 1K+ dashboards MUST stream and keep peak memory below 100 MB (independent of dashboard count).
- **Security**: Export/import endpoints MUST require Nextcloud admin (`IGroupManager::isAdmin()`) authentication. ZIP parsing MUST validate paths to prevent directory traversal attacks (e.g., `../../../etc/passwd`).
- **Atomicity**: Each dashboard import MUST be transaction-wrapped. Partial failure of one dashboard MUST NOT affect others in the batch.
- **Validation**: Manifest schema MUST be validated before any database mutations. Per-dashboard JSON MUST be validated; invalid records skipped and reported.
- **Compatibility**: Only `schemaVersion: 1` supported on v1.x of MyDash. Future versions MUST provide migration tooling for schema updates.
- **Localization**: All error messages MUST be translatable and support English and Dutch per i18n requirements.

## Standards & References

- ZIP format: [PKWARE ZIP specification](https://www.loc.gov/preservation/digital/formats/fdd/fdd000354.shtml)
- UUID v4: [RFC 4122](https://tools.ietf.org/html/rfc4122)
- ISO 8601 timestamps: [RFC 3339](https://tools.ietf.org/html/rfc3339)
- Nextcloud Admin API: `OCP\IGroupManager::isAdmin()`
- Nextcloud Files API: `OCP\Files\IRootFolder`
- PHP ZipArchive: [PHP documentation](https://www.php.net/manual/en/class.ziparchive.php)
