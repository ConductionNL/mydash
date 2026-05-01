# Dashboard Locking

## Why

Today MyDash allows multiple users to simultaneously edit the same dashboard without any coordination mechanism. This creates a "last-write-wins" conflict scenario where concurrent edits overwrite each other silently, leading to data loss and user confusion. A concurrent-edit guard is essential to prevent accidental overwrites when two users open a dashboard for editing in overlapping time windows.

## What Changes

- Add a new table `oc_mydash_dashboard_locks` with `id`, `dashboardUuid`, `userId`, `displayName`, `acquiredAt`, `expiresAt`, and `clientId` fields.
- Default lock TTL: 5 minutes from acquire. Heartbeat extends by 5 minutes.
- Add four new API endpoints:
  - `POST /api/dashboards/{uuid}/lock` — acquires a lock. Returns 200 if granted; 409 with existing lock details if held by another user.
  - `POST /api/dashboards/{uuid}/lock/heartbeat` — extends the lock expiry. Owner-only (by `clientId`). Returns 404 if no lock; 403 if a different client owns it.
  - `DELETE /api/dashboards/{uuid}/lock` — releases the lock. Owner-only or admin override. Returns 204.
  - `GET /api/dashboards/{uuid}/lock` — returns current lock state. Returns 404 if none.
- Admin override: `POST /api/dashboards/{uuid}/lock/force-acquire` steals the lock from any user and is audit-logged via Nextcloud activity.
- Expired locks are silently ignored on read and overwritable on fresh acquire — no background sweeper required for correctness, though a separate `orphaned-data-cleanup` spec may purge them.

## Capabilities

### New Capabilities

- `dashboard-locking`: All dashboard lock management, acquisition, heartbeat, release, admin override, and expiry semantics.

### Modified Capabilities

(none — dashboard locking is fully orthogonal to existing dashboard CRUD)

## Impact

**Affected code:**

- `lib/Db/DashboardLock.php` — new entity with `id`, `dashboardUuid`, `userId`, `displayName`, `acquiredAt`, `expiresAt`, `clientId` fields
- `lib/Db/DashboardLockMapper.php` — new mapper with `findByDashboardUuid()`, `findActive()` (ignore expired), `insert()`, `update()`, `delete()`, `deleteExpired()` methods
- `lib/Service/DashboardLockService.php` — new service layer with `acquireLock()`, `renewLock()` (heartbeat), `releaseLock()`, `getLockState()`, `forceAcquire()` (admin), expiry checking, and `clientId` matching
- `lib/Controller/DashboardController.php` — five new endpoints as above
- `appinfo/routes.php` — register the five new routes
- `lib/Migration/VersionXXXXDate2026...php` — schema migration creating `oc_mydash_dashboard_locks` table
- `src/views/DashboardEdit.vue` (frontend concern, out of scope) — call heartbeat every ~3 minutes while active, DELETE on close

**Affected APIs:**

- 5 new routes (no existing routes changed)

**Dependencies:**

- `OCP\Activity\IManager` — for audit logging on `force-acquire`
- No new composer or npm dependencies

**Migration:**

- Zero-impact: new table only, no changes to existing schema
- No seed data required

## Front-end Concern (Not in Scope)

The editor UI MUST call heartbeat every ~3 minutes while the user is actively editing, and DELETE the lock when the user closes the edit view or navigates away. This ensures stale locks expire after the default 5-minute TTL if the client crashes without releasing.
