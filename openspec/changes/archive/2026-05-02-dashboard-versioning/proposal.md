# Dashboard Versioning

## Why

MyDash dashboards are living documents — users arrange widgets, adjust grid layouts, and update configurations over time. Without version history, accidental changes or overzealous reorganization become irreversible, forcing users to reconstruct layouts manually. Groupfolder-backed dashboards (via the sibling `groupfolder-storage-backend` change) enable storing dashboards as JSON files in Nextcloud Files, opening the door to leveraging Nextcloud's native versioning system. Database-backed dashboards (the current default) need a parallel versioning table. This change delivers a unified, backend-agnostic version history API: list versions, restore snapshots, and audit restores — all without requiring the UI or caller to know which storage backend is in use.

## What Changes

- Add `oc_mydash_dashboard_versions` table for database-backed snapshots, with per-dashboard retention (50 most recent).
- Capture automatic snapshots on every successful dashboard content PUT (debounced to at most one per 60 seconds per dashboard).
- Expose `GET /api/dashboards/{uuid}/versions` → ordered list of version metadata (newest first).
- Expose `GET /api/dashboards/{uuid}/versions/{versionNumber}` → full snapshot body for restore preview.
- Expose `POST /api/dashboards/{uuid}/versions` → explicit snapshot creation with optional note (bypasses debounce).
- Expose `POST /api/dashboards/{uuid}/versions/{versionNumber}/restore` → revert to a historical snapshot (itself creating a new version capturing pre-restore state).
- For groupfolder-backed dashboards: delegate to `\OCP\IVersionManager` (Nextcloud's file versioning), follow NC retention, graceful degradation if versioning is unavailable.
- For database-backed dashboards: persist snapshots in the table, auto-prune to 50 versions per dashboard.
- Emit Nextcloud activity events (`dashboard_restored`) on every restore for audit trail.
- Permission guard: only owners and admins can list, view, or restore versions.

## Capabilities

### New Capabilities

- `dashboard-versioning` — NEW capability for version history, snapshot management, and restore operations.

### Modified Capabilities

- (None — this is an entirely new capability, orthogonal to existing dashboard features.)

## Impact

**Affected code:**

- `lib/Db/DashboardVersion.php` — NEW entity for version snapshots (database backend only).
- `lib/Db/DashboardVersionMapper.php` — NEW mapper for version CRUD + retention pruning.
- `lib/Service/DashboardVersionService.php` — NEW service for snapshot creation (automatic debounced + explicit), restore, and retention.
- `lib/Service/VersioningStrategy/*.php` — NEW strategy pattern for backend-agnostic versioning (DatabaseVersioningStrategy, FilesVersioningStrategy).
- `lib/Controller/DashboardVersionController.php` — NEW controller for all version endpoints.
- `appinfo/routes.php` — register 4 new routes: `GET|POST /api/dashboards/{uuid}/versions`, `GET|POST /api/dashboards/{uuid}/versions/{versionNumber}/restore`.
- `lib/Migration/VersionXXXXDate2026...AddDashboardVersionsTable.php` — schema migration creating `oc_mydash_dashboard_versions` with retention index.
- `lib/Service/DashboardService.php` — integrate snapshot creation into PUT handler (debounce + call `DashboardVersionService::captureSnapshot()`).
- `lib/Db/Dashboard.php` — add `contentBackend` field or equivalent to track which versioning mode a dashboard uses (database vs. groupfolder).
- `src/views/DashboardDetail.vue` — (deferred to follow-up) UI for version list, restore button, version preview modal.

**Affected APIs:**

- 4 new routes, no existing routes changed
- Existing dashboards remain unaffected until a change is saved (triggering the first automatic snapshot).

**Dependencies:**

- `OCP\IVersionManager` — for groupfolder backend integration (already available in Nextcloud 28+).
- No new composer or npm dependencies.

**Migration:**

- Zero-impact: the migration only adds a new table (no existing tables modified). Existing dashboards do not automatically populate version history — versions are created going forward from the first PUT after the migration.
- Optionally, admins can trigger a backfill of current states as "version 1" for all existing dashboards via a repair step (deferred to follow-up feature).

## Dependencies

This change **DEPENDS ON** the sibling `groupfolder-storage-backend` change for the content-backend abstraction. The versioning feature is neutral to storage backend and adapts dynamically, but the groupfolder backend must be in place to enable file-based versioning for dashboards stored in Nextcloud Files.

Explicit dependency link: `groupfolder-storage-backend` change MUST be implemented and merged before or alongside `dashboard-versioning`.

## Open Questions / Design Notes

1. **Debounce window**: 60 seconds is proposed; tuning may be needed based on typical user interaction patterns (e.g., drag-and-drop saves rapidly vs. deliberate edits). Consider making this configurable in `config.php`.
2. **Retention limit**: 50 versions per dashboard is proposed; storage impact depends on average widget-tree JSON size. Consider monitoring and adjusting after early adoption.
3. **Explicit snapshot note length**: proposed VARCHAR(500) for notes. Longer notes should be supported but are not critical for MVP.
4. **File-version synthesis**: for groupfolder backend, versionNumber will be synthesized from file-version timestamp if NC doesn't provide a sequence number. Order MUST be stable (newest-first) to ensure restore operations are deterministic.
5. **Audit trail scope**: currently only restores emit activity events. Future enhancement: emit lower-severity activity for automatic snapshots if audit completeness is desired.
