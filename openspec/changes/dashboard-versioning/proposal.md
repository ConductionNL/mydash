---
kind: code
depends_on: []
chain: []
---

# Dashboard Versioning Specification

## Why

MyDash dashboards are living documents. Users build complex widget arrangements, save them, then iterate. Today, there is no way to recover a dashboard to a prior state if a mass edit goes wrong, a widget is accidentally deleted, or a layout is corrupted. This change adds version history and one-click restoration — enabling users to confidently revert to a known-good state without losing the audit trail.

The feature is backend-agnostic: dashboards stored in Nextcloud Files (via the `groupfolder` backend) delegate to NC's native file versioning; dashboards in the database backend use a dedicated `oc_mydash_dashboard_versions` table. All APIs are uniform across both backends.

## What Changes

Seven new API endpoints + one supporting Nextcloud activity integration:

- `POST /api/dashboards/{uuid}/versions` — Create an explicit snapshot with an optional label (bypasses debounce)
- `GET /api/dashboards/{uuid}/versions` — List version history (newest-first, metadata only)
- `GET /api/dashboards/{uuid}/versions/{versionNumber}` — Fetch a specific version snapshot (full content)
- `POST /api/dashboards/{uuid}/versions/{versionNumber}/restore` — Restore a dashboard to a historical snapshot
- Automatic snapshot capture on `PUT /api/dashboard/{uuid}/content` (debounced, 60-second window per dashboard)
- Automatic retention: keep 50 most recent versions per dashboard in database backend; defer to Nextcloud's policies in file-versioned backend
- Nextcloud activity event emission on every restore (audit trail)

## Affected code units

- `lib/Controller/DashboardApiController.php` — add `captureAutomaticSnapshot()` hook on successful `update()` calls
- `lib/Controller/DashboardVersionApiController.php` — NEW, routes to version endpoints
- `lib/Service/DashboardVersionService.php` — NEW, orchestrates snapshot capture, restore, retention, backend dispatch
- `lib/Db/DashboardVersion.php` — NEW, entity representing a version snapshot
- `lib/Db/DashboardVersionMapper.php` — NEW, persistence layer for versions
- `lib/Migration/Version001015Date20260502130000.php` — NEW, creates `oc_mydash_dashboard_versions` table (database backend only)
- `appinfo/routes.php` — register 4 new routes
- `appinfo/info.xml` — bump version
- `src/views/DashboardVersionsView.vue` — NEW, UI for listing + restoring versions
- NC `IActivityManager` integration — emit activity events on restore

## Backend-aware dispatch

The `DashboardVersionService` queries the dashboard's `contentBackend` field (or equivalent) to dispatch between two strategies:

1. **Database backend (default)** — snapshots stored in `oc_mydash_dashboard_versions` with auto-pruning to 50 versions
2. **GroupFolder backend** — snapshots delegated to `OCP\IVersionManager`, Nextcloud file-versions (no MyDash-side pruning)

Until the sibling `groupfolder-storage-backend` change lands (which adds the `contentBackend` column), all dashboards are treated as database-backed.

## Non-Functional Requirements

- **Performance**: List versions under 500ms for 50-version dashboards; restore within 2 seconds
- **Consistency**: 60-second debounce enforced even under concurrent requests
- **Storage**: Database backend prunes to 50; MEDIUMTEXT can hold up to 16MB per snapshot
- **Auditability**: Every restore emits a Nextcloud activity event with full context
- **Accessibility**: UI keyboard-operable, screen-reader friendly
- **Localization**: Error messages and activity descriptions in English and Dutch
