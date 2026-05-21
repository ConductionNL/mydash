# Design: dashboard-versioning

## Context

MyDash dashboards are fully editable after creation — users reposition widgets, adjust sizes, update metadata, and create new arrangements. The current system has no undo mechanism: if a PUT request corrupts the layout or a user accidentally deletes widgets and hits save, the prior state is permanently lost. Customers have requested version history ("undo/redo") as a core feature, especially for collaborative scenarios where one user's edits might surprise another.

The versioning system MUST accommodate two backend models that MyDash will support:
1. **Database backend** (current default) — dashboards stored as JSON in `oc_mydash_dashboards.content`
2. **GroupFolder backend** (future, via `groupfolder-storage-backend` change) — dashboards stored as JSON files in Nextcloud Files, with file-versioning provided by Nextcloud's native `IVersionManager`

Both backends MUST expose a uniform API surface to the UI and REST clients — the caller MUST NOT know which backend is in use.

## Reuse Analysis

The versioning feature reuses existing MyDash infrastructure:

- **Dashboard entity and mapper** (`Dashboard`, `DashboardMapper`) — already exist; version snapshots reference dashboards by UUID
- **DashboardApiController** — already wired; we add a private hook to capture snapshots post-PUT
- **ICacheFactory::createDistributed()** — already injected in services; backs the per-dashboard debounce window
- **Nextcloud IActivityManager** — standard Nextcloud audit API; used for restore events
- **OCP\IVersionManager** — standard Nextcloud file-versioning interface (used when the groupfolder backend is active)

No new service classes, no new entities beyond `DashboardVersion` (snapshot metadata) and its mapper. The `DashboardVersionService` orchestrates capture/restore/retention but delegates file-based versioning to Nextcloud itself.

## Backend Dispatch Strategy

The `DashboardVersionService::isGroupfolderBacked()` method is the single dispatch point. It checks the dashboard's `contentBackend` field (added by the sibling `groupfolder-storage-backend` change) and routes accordingly:

**Database backend:**
- Snapshots stored as rows in `oc_mydash_dashboard_versions` (full widget JSON in `snapshot_json`)
- Version numbers are monotonic INTs per dashboard (versionNumber 1, 2, 3, ...)
- Retention: automatic pruning keeps 50 most recent; older versions deleted
- No external API calls

**GroupFolder backend:**
- Snapshots delegated to Nextcloud's `IVersionManager` on the dashboard's JSON file
- Version numbers synthesized from file-version timestamps (if not provided by NC natively)
- Retention: defers to Nextcloud's own retention policies (admins configure in Nextcloud settings)
- Soft-failure handling: if versioning is unavailable, returns `{versions: [], modeSupported: false}`

Until `contentBackend` is added, all dashboards return `isGroupfolderBacked() = false` and use the database backend.

## Seed Data

No seed data required. Dashboard versioning is a meta-feature (history tracking) with no data model to populate. The feature works transparently once a dashboard is created — the first PUT automatically captures a version-1 snapshot, and subsequent PUTs create version-2, version-3, etc.

## Declarative vs. Imperative

The feature is **imperative** (PHP service + controller logic). Unlike `kind: config` changes that declare capability contracts in spec text, versioning is runtime behavior:

- Snapshot capture triggered by PUT success (not declarative)
- Debounce window enforced by distributed cache state (not declarative)
- Backend dispatch via runtime field inspection (not declarative)

`kind: code` per ADR-032. This change includes PHP services, mapper, migration, and controller endpoints. UI components land in follow-up changes.

## Soft-Failure Tolerance (GroupFolder Backend)

When a dashboard is groupfolder-backed and Nextcloud versioning is unavailable (disabled or `IVersionManager` throws), the versioning endpoints MUST NOT raise HTTP 500. Instead:

- `GET /api/dashboards/{uuid}/versions` returns HTTP 200 with `{versions: [], modeSupported: false}`
- `POST /api/dashboards/{uuid}/versions/{versionNumber}/restore` returns HTTP 200 but no-ops (content unchanged)
- The UI MUST display "versioning unavailable" and disable the restore button

This graceful degradation ensures the dashboard remains readable even if Nextcloud versioning is broken.

## Performance & Storage

**Database backend:**
- `GET /api/dashboards/{uuid}/versions` queries the versions table with `ORDER BY created_at DESC, version_number DESC` and returns metadata only (no `snapshot_json`). Target: <500ms for 50 versions on a typical DB.
- `GET /api/dashboards/{uuid}/versions/{versionNumber}` single-row lookup by composite key `(dashboard_uuid, version_number)`. Target: <100ms.
- `POST .../restore` writes a new row (pre-restore snapshot) + updates the dashboard's `content` and `updatedAt` + (optionally) deletes old versions if > 50. Target: <2 seconds on a 50-version dashboard.
- Storage: MEDIUMTEXT can hold ~16MB per snapshot; most dashboards stay <1MB.

**GroupFolder backend:**
- `GET /api/dashboards/{uuid}/versions` calls `IVersionManager::getVersions(file)`. NC's performance depends on the underlying file storage (local, S3, etc.). We return the result as-is to the UI.
- Retention is Nextcloud's responsibility — MyDash does NOT prune file-versions.

## Alternatives Considered

1. **Store snapshots as a new table with full object relationships (audit-trail-style).**
   Rejected. Versioning is a dashboard-scoped feature; we do not need full audit provenance. The snapshot JSON + basic metadata (who, when) is sufficient.

2. **Use Nextcloud versioning for ALL backends, including database.**
   Rejected. Database-backed dashboards do NOT live in Files — there is no file to version. Creating synthetic "files" for versioning would add overhead and complexity. Database-backed snapshots belong in the database.

3. **Rename the `versionNumber` field to `snapshotId` to align with Nextcloud's terminology.**
   Rejected. The term "version number" is clearer in REQ-VERS-005 scenario ("restore to version 3"); "snapshot ID" implies a UUID, which would conflict with dashboard UUIDs.

4. **Emit Nextcloud activity events on EVERY snapshot, not just restores.**
   Rejected. Activity events are for audit/accountability. Automatic snapshots happen frequently (debounced every 60 seconds) and would flood the activity log. Only restores (user-initiated, high-value) warrant activity events.

5. **Make explicit snapshots also trigger automatic snapshots (double-create).**
   Rejected. Explicit POST calls explicitly bypass debounce; a user might create 10 named snapshots in quick succession to checkpoint different ideas. Creating auto-snapshots on top would clutter the version list.

## Migration Path

**Schema:**
- New table `oc_mydash_dashboard_versions` created via migration `Version001015Date20260502130000`.
- Indexes on `(dashboard_uuid, version_number)` (unique) and `(dashboard_uuid, created_at)` for fast queries.
- No schema changes to `oc_mydash_dashboards` table.

**Data:**
- On upgrade, existing dashboards have zero versions (empty history). This is acceptable — versioning is optional and the feature is only useful going forward.
- Existing shared/personal dashboards remain fully functional; they just lack a prior-version recovery option until the first PUT.

**Compatibility:**
- No breaking changes to existing APIs.
- Four new endpoints; existing endpoints unchanged.
- No impact on existing dashboard or widget logic.

## I18n & Accessibility

**i18n:**
- Error messages: "version not found", "unauthorized", "restore failed"
- Activity event template: "{{ user }} restored dashboard '{{ name }}' to version {{ number }}" (English + Dutch)
- UI copy: version labels, button text, empty states

**Accessibility:**
- Version list rendered as a semantic list with `<ul>/<li>` + `<button>` elements for restore
- Keyboard navigation: Tab through list items, Space/Enter to restore
- ARIA labels on version rows: "Version 5, created 2026-03-15 by alice"
- Screen-reader-friendly restore confirmation: "Restore confirmed: dashboard returned to version 3 state"
