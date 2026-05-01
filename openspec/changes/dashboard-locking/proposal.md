# Dashboard Locking

## Why

Today MyDash allows multiple users to simultaneously edit the same dashboard without any coordination mechanism. This creates a "last-write-wins" conflict scenario where concurrent edits overwrite each other silently, leading to data loss and user confusion. A concurrent-edit guard is essential to prevent accidental overwrites when two users open a dashboard for editing in overlapping time windows.

## What Changes

- Add a new table `oc_mydash_dashboard_locks` with `id`, `dashboardUuid`, `userId`, `displayName`, `acquiredAt`, and `lastHeartbeat` fields.
- Default lock TTL: 15 minutes, computed at query time as `lastHeartbeat + 15 min`. No stored expiry column.
- Add four verbs on a single lock resource URL, plus one admin action:
  - `POST /api/dashboards/{uuid}/lock` — acquires a lock. Returns 200 if granted (re-entrant for same user); 409 with existing lock details if held by another user.
  - `PUT /api/dashboards/{uuid}/lock` — heartbeat: extends the lock by bumping `lastHeartbeat`. Owner-only (by `userId`). Returns 404 if no lock; 403 if a different user owns it.
  - `DELETE /api/dashboards/{uuid}/lock` — releases the lock. Owner-only or admin. Returns 204.
  - `GET /api/dashboards/{uuid}/lock` — returns current lock state. Returns 404 if none.
- Admin override: `POST /api/dashboards/{uuid}/lock/force-release` releases the lock for whoever holds it (returning the dashboard to unlocked state); logged via `LoggerInterface::info()`.
- Expired locks are cleaned up inline: `DashboardLockService` calls `DashboardLockMapper::deleteExpiredForDashboard()` at the start of `getLockState()` and `acquireLock()` — no background sweeper required for correctness.

## Capabilities

### New Capabilities

- `dashboard-locking`: All dashboard lock management, acquisition, heartbeat, release, admin override, and expiry semantics.

### Modified Capabilities

(none — dashboard locking is fully orthogonal to existing dashboard CRUD)

## Impact

**Affected code:**

- `lib/Db/DashboardLock.php` — new entity with `id`, `dashboardUuid`, `userId`, `displayName`, `acquiredAt`, `lastHeartbeat` fields
- `lib/Db/DashboardLockMapper.php` — new mapper with `findByDashboardUuid()`, `findActive()` (filter by `lastHeartbeat`), `insert()`, `update()`, `delete()`, `deleteExpiredForDashboard(string $uuid)` methods
- `lib/Service/DashboardLockService.php` — new service layer with `acquireLock()`, `heartbeat()` (PUT), `releaseLock()`, `getLockState()`, `forceRelease()` (admin); inline expiry cleanup via `deleteExpiredForDashboard()`; ownership by `userId` only
- `lib/Controller/DashboardController.php` — four new lock endpoints + one admin endpoint as above
- `appinfo/routes.php` — register the four verbs on `lock` resource + `force-release` route
- `lib/Migration/VersionXXXXDate2026...php` — schema migration creating `oc_mydash_dashboard_locks` table
- `src/views/DashboardEdit.vue` (frontend concern, out of scope) — call heartbeat every ~3 minutes while active, DELETE on close

**Affected APIs:**

- 5 new routes (4 verbs on lock resource + 1 admin action; no existing routes changed)

**Dependencies:**

- `Psr\Log\LoggerInterface` — for audit logging on `force-release` (already available in Nextcloud; no new dependencies required)
- No new composer or npm dependencies

**Migration:**

- Zero-impact: new table only, no changes to existing schema
- No seed data required

## Front-end Concern (Not in Scope)

The editor UI MUST call `PUT /api/dashboards/{uuid}/lock` (heartbeat) every 60 seconds while the user is actively editing, and `DELETE /api/dashboards/{uuid}/lock` when the user closes the edit view or navigates away. This ensures stale locks expire after the default 15-minute TTL if the client crashes without releasing. The 60-second cadence gives a 15× safety margin against the TTL.
