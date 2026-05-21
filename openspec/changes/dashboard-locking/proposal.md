# Dashboard Locking

## Why

Dashboard locking provides a concurrent-edit guard for dashboards. When two users open the same dashboard's edit view, the system MUST prevent the second user from editing until the first releases the lock. The mechanism uses a time-based lease (default 15 minutes) with client-driven heartbeat renewal to tolerate transient network outages and browser crashes without manual intervention. This prevents the silent "last write wins" data loss scenario where two editors save conflicting versions and neither sees the overwrite.

## Affected code units

- `lib/Db/DashboardLock.php` — entity mapping the lock row
- `lib/Db/DashboardLockMapper.php` — database access layer for lock CRUD + expiry cleanup
- `lib/Service/DashboardLockService.php` — business logic: acquire, heartbeat, release, force-release, get state
- `lib/Controller/DashboardLockApiController.php` — HTTP endpoints for lock operations
- `lib/Migration/Version001021Date20260502130000.php` — schema migration creating `oc_mydash_dashboard_locks` table
- `lib/Exception/LockConflictException.php`, `LockNotFoundException.php`, `LockForbiddenException.php` — typed exceptions
- `appinfo/routes.php` — REST routes for lock endpoints
- `src/composables/useDashboardLock.js` — frontend lock state management and heartbeat scheduling
- `src/services/api.js` — API client methods for lock operations
- `l10n/en.json`, `l10n/nl.json` — English and Dutch error messages

## Capabilities

**New Capabilities:**

- `dashboard-locking` — Exclusive write locks on dashboards with automatic expiry (15 minutes) and client-driven heartbeat renewal. Prevents concurrent-edit conflicts without manual intervention or locks lasting beyond transient network outages.

## Notes

- The lock TTL is 15 minutes by default (900 seconds), computed from `lastHeartbeat` rather than `acquiredAt`, allowing heartbeat renewal to extend the lease indefinitely while the editor is active.
- Stale-lock cleanup is inline: `DashboardLockService` deletes expired rows at the start of both `getLockState()` and `acquireLock()`, preventing stale locks from blocking new acquisitions without a background sweeper.
- Same-user acquire is re-entrant: if the same user already holds the lock (e.g., a second browser tab), the acquire call refreshes the lock (`lastHeartbeat` bumped) and returns HTTP 200 rather than 409 conflict.
- Admin override via `force-release` is logged for audit trail.
- The dashboard deletion cascade is handled at the application layer (`DashboardService::deleteDashboard()` calls `DashboardLockMapper::deleteByDashboardUuid()`) to maintain compatibility with SQLite (which does not support foreign key constraints by default in Nextcloud's migration framework).
