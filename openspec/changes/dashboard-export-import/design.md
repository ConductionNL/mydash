# Design: dashboard-export-import

## Context

MyDash administrators have requested the ability to backup, migrate, and share dashboard configurations across instances. The current data model (`dashboards`, `widgets`, `metadata-fields`) is fully documented, but there is no standardized way to extract, transport, and restore complete snapshots including assets. This capability defines a versioned ZIP container format and bidirectional export-import operations to fill that gap.

## Reuse Analysis

Export-import is a high-level operational capability that composes existing MyDash infrastructure:

- **DashboardRepository** / **MetadataFieldRepository** — already exist; export queries them, import creates/updates via the existing patterns
- **FileService** / **FileRepository** — existing file storage; import extracts assets and writes them via standard FileService methods
- **ZipArchive** (PHP stdlib) — used to build and validate ZIP archives (no external dependency)
- **IConfig** / **IAppConfig** — existing Nextcloud config for admin endpoints and feature flags

No new services are created beyond **DashboardExportService** and **DashboardImportService**, which are thin orchestrators over existing repos. Both services use existing entity mappers and schema definitions from the `dashboards` capability (REQ-DASH-001..017).

## Declarative-vs-imperative decision

Per ADR-031, this change is **mostly imperative** (`kind: code`):

- **Export Service** — PHP logic to stream ZIP; decision: streaming for memory efficiency (no buffer)
- **Import Service** — PHP logic to validate, handle collisions, and transact imports; decision: per-dashboard transactions for partial-failure isolation
- **Admin API endpoints** — standard REST POST pattern; no GraphQL needed
- **CLI commands** — Symfony console pattern; no custom orchestration

The ZIP manifest and dashboard JSON shapes are declarative (embedded in the spec), but the implementation is standard imperative service code.

## Architectural decisions

### 1. ZIP Streaming vs. In-Memory Buffering

**Decision:** Stream the ZIP to the response without buffering the entire archive in memory.

**Why:** Site exports can include 1000+ dashboards. Buffering would consume unbounded memory (100+ MB easily). Streaming writes dashboard entries one by one as ZipArchive reads rows from the database.

**How to implement:**
- Use `ZipArchive::addFromString()` in a loop (not `addFile()`, which requires temp files)
- Call `flush()` after every N dashboards to release memory
- Set `Content-Type: application/zip` and `Content-Disposition: attachment` in the response header
- Return a `StreamedResponse` that writes chunks directly to PHP's output buffer

### 2. UUID Collision Handling — Safe Default

**Decision:** By default, import assigns fresh UUIDs (`preserveUuids=false`). Opt-in `preserveUuids=true` detects collisions and fails the entire batch.

**Why:** The safe default allows the same export to be imported multiple times without manual intervention. Preserving UUIDs is only necessary for disaster recovery (restore to the exact same state) or cross-instance sync.

**How to implement:**
- In `DashboardImportService::importDashboards()`, before creating any dashboard, check if `preserveUuids=true` and scan for existing UUIDs
- If any collision found, return HTTP 409 with all collisions listed in the error array
- If `preserveUuids=false`, generate fresh UUIDs via `Uuid::uuid4()`

### 3. Metadata Field Collision — Reuse by Key + Type

**Decision:** Detect field collisions by matching `key` + `type`. If match exists, reuse; if type mismatch, skip the dashboard.

**Why:** Metadata fields are reusable across dashboards. If the same field (by key) already exists with the same type, there's no conflict — just reference the existing field ID. If types differ, the dashboard's data shape is incompatible and cannot be imported safely.

**How to implement:**
- Before importing each dashboard, resolve all referenced metadata fields by key
- For each field, call `MetadataFieldRepository::findOneBy(['key' => $fieldKey])`
- If found and type matches, use its ID; if type mismatches, add error and skip the dashboard
- If not found, call `MetadataFieldService::create()` to create a new field with the imported definition

### 4. Asset Filename Collision — Rename, Don't Overwrite

**Decision:** On asset filename collision, rename the imported asset with a collision-suffix (timestamp-based) rather than overwriting.

**Why:** Assets are often unique (dashboard icons, widget images, CSV exports). Overwriting could silently destroy data. Renaming preserves both and lets the admin review.

**How to implement:**
- Extract asset from ZIP to a temp path
- Check if target path exists via `FileService::fileExists()`
- If collision, append `-imported-{ISO8601-timestamp}` before the file extension
- Write the renamed asset and update the dashboard JSON to reference the new path
- Log the collision for admin audit trail

### 5. Atomic Transactions Per Dashboard

**Decision:** Wrap each dashboard import (dashboard + widgets + metadata field assignments) in a database transaction. If any part fails, roll back the entire dashboard.

**Why:** Partial imports (e.g., dashboard created, widgets fail) leave corrupted state. Per-dashboard transactions allow other dashboards in the batch to succeed independently.

**How to implement:**
- Loop over dashboards from the ZIP
- For each dashboard, call `DB::beginTransaction()`
- Create/update the dashboard, its widgets, and field assignments in sequence
- If any write fails, catch the exception, roll back, and add to `errors[]`
- Commit on success
- At the end, return summary: `importedDashboardCount`, `skippedDashboardCount`, `errors[]`

## Seed Data

No seed data — MyDash holds no data-model schemas in OpenRegister; all dashboards are created dynamically at runtime. The exported ZIP files from real instances serve as templates.

For local testing, `tasks.md` includes a task to generate a test ZIP with seed dashboards (3-5 example boards with widgets, metadata fields, and icons).

## Cross-capability references

- **dashboards** — uses Dashboard entity schema (REQ-DASH-001..017); import creates dashboards via the same service
- **widgets** — uses Widget schema; import creates placements via WidgetService
- **metadata-fields** — uses MetadataField schema; collision detection and field creation
- **admin-settings** — export/import endpoints are admin-only (permission check needed)
- **permissions** — imported dashboards respect the importer's ownership/group context (not part of the ZIP snapshot)

## Spec-sizing decision (ADR-032)

This change is a hybrid:

- **Kind:** `code` (backend services) + `config` (ZIP manifest schema in spec)
- **Size:** Medium — two new services, two new controllers, two new CLI commands, ~700 lines of PHP
- **Complexity:** Medium — collision handling (UUIDs, fields, assets), ZIP validation, transaction orchestration
- **Frontend:** Minimal — admin uses API/CLI; optional UI (not in initial scope)

## Mixed-spec rationale

The ZIP manifest format is declarative (JSON schema in spec). The services and API endpoints are imperative (PHP code). Both are required to fulfill the capability — the spec defines the contract, code implements it.

## Alternatives considered

1. **Compress individual dashboard JSONs instead of ZIP container** — Rejected. A single ZIP file is more portable and naturally groups assets. Also, downstream capabilities (like `confluence-html-import`) consume the ZIP shape as a stable contract.

2. **Auto-merge conflicting metadata fields by default** — Rejected. Type mismatches are real incompatibilities (e.g., a field was converted from string to number). Skipping the dashboard is safer than guessing the user's intent.

3. **Preserve dashboard ownership on import** — Rejected. Ownership context (user IDs, group IDs) is instance-specific. Import assigns the importer as the owner (or the admin user), not the original exporter.

4. **Compress the ZIP file itself** — Rejected. ZIP already includes per-file compression. Double-compression adds complexity without significant savings.
