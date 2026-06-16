# GroupFolder Storage Backend

## Why

Today MyDash dashboard content (widgets, layout, metadata) is stored exclusively in the database. This creates a scalability ceiling for large deployments and limits the ability to sync dashboard content across multiple Nextcloud instances or to back up dashboards as versioned files. Administrators need the option to store dashboard content in managed, versioned, access-controlled GroupFolders alongside other Nextcloud documents. This change abstracts the storage layer so dashboard content can transparently live in either the database (current default) or a dedicated GroupFolder (new option), with no changes to the dashboard API or user experience.

## What Changes

- Introduce a `DashboardContentStorage` interface with two implementations: `DbContentStorage` (current behaviour, default) and `GroupFolderContentStorage` (new).
- Add an admin setting `mydash.content_storage` with values `db` (default) or `groupfolder`.
- Auto-create a GroupFolder named "MyDash" on first GroupFolder-backed write or via explicit admin trigger, with ACL rules restricting access to intended dashboard members.
- Persist each dashboard's content (widget tree, layout, metadata) as a single JSON file at `<MyDash>/<locale-or-empty>/<dashboard-uuid>.json`.
- Read/write via Nextcloud's `IRootFolder` API — never direct filesystem paths.
- Provide a one-time migration command `mydash:storage:migrate-to-groupfolder` that copies all existing dashboards from the DB to the GroupFolder, idempotent and safe to re-run.
- Fail closed: if the GroupFolder backend is unreachable (deleted, ACL stripped), the API returns HTTP 503 with a clear error, never silently falling back.
- Require the `groupfolders` Nextcloud app to be installed; surface a clear admin error if not available.

## Capabilities

### New Capabilities

- `groupfolder-storage-backend`: abstraction layer for pluggable dashboard content storage, supporting database and managed-folder backends with automatic failover protection and migration tooling.

### Modified Capabilities

- `admin-settings`: adds one new setting key `mydash.content_storage` (enum: `db` or `groupfolder`, default `db`).
- `dashboards`: no API changes; existing dashboard read/write endpoints transparently use the configured storage backend.

## Impact

**Affected code:**

- `lib/Service/DashboardContentStorage/DashboardContentStorageInterface.php` — new interface defining `read()`, `write()`, `delete()` operations
- `lib/Service/DashboardContentStorage/DbContentStorage.php` — refactored current DB logic into storage layer
- `lib/Service/DashboardContentStorage/GroupFolderContentStorage.php` — new implementation for GroupFolder persistence
- `lib/Service/DashboardContentStorageFactory.php` — factory that instantiates the correct backend based on admin setting
- `lib/Service/DashboardService.php` — inject `DashboardContentStorageFactory`, call `getStorage()->read/write/delete()` instead of direct queries
- `lib/Db/Dashboard.php` — content field becomes virtual (not persisted in DB for GroupFolder-backed dashboards); `jsonSerialize()` still returns it
- `lib/Migration/VersionXXXXDate2026...php` — nullable migration: adds `groupfolder` app dependency check; does NOT alter existing schema
- `lib/Command/MigrateStorageToGroupFolder.php` — new CLI command for one-time migration
- `lib/Command/ToggleStorageSetting.php` — optional admin helper to change `mydash.content_storage` setting with validation
- `appinfo/info.xml` — add `groupfolders` as a (soft) dependency; version bump
- `src/stores/dashboards.js` — no changes (store already handles serialized content from API)

**Affected APIs:**

- `POST /api/dashboard` — transparently writes to the configured storage backend
- `GET /api/dashboard/{uuid}` — transparently reads from the configured storage backend
- `PUT /api/dashboard/{uuid}` — transparently updates the configured storage backend
- `DELETE /api/dashboard/{uuid}` — transparently deletes from the configured storage backend
- `GET /api/dashboards/visible` (if implemented from multi-scope-dashboards) — transparently reads from the configured backend

**New CLI commands:**

- `mydash:storage:migrate-to-groupfolder` — idempotent bulk migration from DB to GroupFolder
- `mydash:storage:toggle-backend {db|groupfolder}` — change the admin setting (optional, for convenience)

**Dependencies:**

- `OCP\Files\IRootFolder` — already available in Nextcloud
- `groupfolders` Nextcloud app — must be installed (checked at runtime; graceful error if missing)
- No new composer or npm dependencies

**Migration:**

- Zero-impact by default — all existing dashboards remain in the DB (`mydash.content_storage = 'db'` is default).
- Operators opt-in via the admin setting or the CLI migration command.
- Idempotent: re-running the migration command is safe; it skips dashboards already in the GroupFolder.
- Rollback: if an operator switches `mydash.content_storage` back to `db`, the DB records are still present (migration does not delete them).

**Breaking changes:**

- None — existing code and clients work unchanged.
