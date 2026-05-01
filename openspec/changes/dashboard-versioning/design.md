# Design — Dashboard Versioning

## Context

This capability provides version history and restore for dashboard content. The spec
(`dashboard-versioning`) pins the dual-backend model: `groupfolder` mode delegates to
`\OCP\Files\Versions\IVersionManager`; `db` mode persists snapshots in `oc_mydash_dashboard_versions`.
The `contentBackend` column routes to the correct strategy at runtime.

Source confirms the NC interface: `intravox-source/lib/Service/PageService.php` lazy-loads
`OCA\Files_Versions\Versions\IVersionManager`. Sibling spec `dashboard-draft-published` intersects
on PUT triggers — draft-to-published transitions do NOT trigger versioning; only content edits do.
This design documents strategy dispatch, debounce, restore semantics, and soft-failure handling.

## Goals / Non-Goals

**Goals:**
- Document strategy pattern dispatch at runtime
- Specify debounce implementation (APCu, 60 s)
- Define restore-as-reverse-snapshot semantics
- Specify retention limit enforcement point (DB mode)
- Document soft-failure contract when GroupFolder versioning is unavailable

**Non-Goals:**
- Content diffing / delta storage (snapshots are full blobs)
- Version comparison UI (frontend concern)
- Version export (separate spec if needed)

## Decisions

### D1: Strategy pattern dispatch
**Decision**: `VersioningService` reads `dashboard->getContentBackend()` and instantiates either
`DbVersionBackend` or `GroupFolderVersionBackend` at call time. Both implement
`IVersionBackend` interface defined in MyDash.
**Alternatives considered**:
- Single code path with conditionals — rejected; grows unwieldy as backends diverge
- DI container tagging — rejected; backend is per-dashboard, not per-service-lifetime
**Rationale**: Strategy pattern keeps backend logic isolated and testable. The interface exposes
`list()`, `create()`, `restore()`, and `isSupported()` methods.

### D2: Debounce implementation
**Decision**: APCu key `mydash_ver_debounce_{dashboardUuid}` set to `1` with TTL 60 s on every
PUT. Version creation is skipped if the key exists at the start of the request.
**Source evidence**: `intravox-source/lib/Service/PageService.php:~220` — debounce via APCu for
page-content saves, TTL 60 s.
**Alternatives considered**:
- DB-side `last_versioned_at` timestamp check — rejected; adds DB write on every PUT even when
skipping, and has clock-skew issues in multi-node setups
**Rationale**: APCu is node-local and TTL-managed. Acceptable because version loss during a 60 s
window is low-impact; explicit POST `/versions` always bypasses debounce.

### D3: Restore-as-reverse-snapshot
**Decision**: Before restore, `VersioningService` snapshots current content (labelled `pre-restore`),
then applies the chosen version. Net effect: restore is itself reversible.
**Alternatives considered**: No pre-restore snapshot — rejected; destructive with no undo.
**Rationale**: Mirrors NC core file-restore behaviour. Adds one row per restore, well within 50-limit.

### D4: Retention limit enforcement (DB mode)
**Decision**: After every version write in DB mode, `DbVersionBackend::prune()` deletes the oldest
rows beyond 50 (ordered by `created_at` ASC) for that `dashboard_uuid`.
**Alternatives considered**:
- Background job pruning — rejected; delays cleanup, allows table to grow unbounded between runs
**Rationale**: Inline pruning keeps the table bounded without a background job dependency. Pruning
≤50-row deletes per PUT is negligible overhead.

### D5: Soft-failure for unavailable GroupFolder versioning
**Decision**: If `IVersionManager` is unavailable (Files_Versions disabled or no GroupFolder backing),
`isSupported()` returns `false` and the API returns HTTP 200 `{"versions": [], "modeSupported": false}`.
**Alternatives considered**:
- HTTP 501 — rejected; breaks clients that don't guard on status code
- Silent fallback to DB backend — rejected; creates version rows in wrong backend
**Rationale**: Transparency over silent fallback; re-enabling restores versioning without migration.

### D6: Version trigger events
**Decision**: Triggers: (1) PUT to content endpoint (debounced 60 s), (2) explicit POST to
`/dashboards/{id}/versions`, (3) admin-manual. Draft saves do NOT trigger versioning.
**Alternatives considered**: Trigger on all saves including drafts — rejected; pollutes history.
**Rationale**: Aligns with `dashboard-draft-published` boundary; versioning tracks published lineage.

## Risks / Trade-offs

- **APCu node-locality** → debounce is per-node; user hitting different node within 60 s creates a second version. Acceptable — deduplication is best-effort
- **IVersionManager stability** → metadata format may change across NC major versions; pin to OCP namespace, not OCA

## Open follow-ups

- Evaluate `\OCP\IMemcache` (Redis) for cluster-aware debounce
- Add `?label=` param to explicit version POST for user annotations
- Check whether `IVersionManager::rollback()` can replace custom restore logic in GroupFolder mode
