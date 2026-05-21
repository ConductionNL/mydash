# Tasks — dashboard-locking

## Tasks

- [ ] Task 1: Create database migration `lib/Migration/Version001021Date20260502130000.php` creating the `oc_mydash_dashboard_locks` table with columns: `id` (auto-increment primary key), `dashboardUuid` (VARCHAR 36, UNIQUE), `userId` (VARCHAR 64), `displayName` (VARCHAR 255), `acquiredAt` (TIMESTAMP), `lastHeartbeat` (TIMESTAMP); add indexes on `dashboardUuid` (unique), `userId`, and `lastHeartbeat`
- [ ] Task 2: Create entity `lib/Db/DashboardLock.php` mapping the lock row with properties `$id`, `$dashboardUuid`, `$userId`, `$displayName`, `$acquiredAt`, `$lastHeartbeat` and getter/setter methods per Nextcloud convention
- [ ] Task 3: Create mapper `lib/Db/DashboardLockMapper.php` extending `QBMapper` with methods:
  - `create(DashboardLock $lock): DashboardLock` — insert new lock
  - `findActiveByDashboardUuid(string $uuid): ?DashboardLock` — fetch lock by uuid (returns null if none or expired)
  - `updateHeartbeat(string $uuid, DateTime $now): void` — atomic update of `lastHeartbeat`
  - `deleteByDashboardUuid(string $uuid): int` — cascade delete for dashboard deletion
  - `deleteExpiredForDashboard(string $uuid): int` — inline cleanup, deletes rows where `lastHeartbeat < now - 15min`
  - Test fixture: create a lock, heartbeat it, query it, verify expiry logic
- [ ] Task 4: Create exception classes:
  - `lib/Exception/LockConflictException.php` — maps to HTTP 409, carries lock object details for conflict response
  - `lib/Exception/LockNotFoundException.php` — maps to HTTP 404
  - `lib/Exception/LockForbiddenException.php` — maps to HTTP 403
- [ ] Task 5: Create service `lib/Service/DashboardLockService.php` with `LOCK_TIMEOUT = 900` (15 minutes) constant and public methods:
  - `acquireLock(string $dashboardUuid, IUser $user): DashboardLock` — inline cleanup first, then try INSERT; on UNIQUE violation throw `LockConflictException` with existing lock; on re-entrant (same user) return HTTP 200 with heartbeat refresh
  - `heartbeat(string $dashboardUuid, IUser $user): DashboardLock` — verify caller owns lock, inline cleanup, update `lastHeartbeat`, return updated lock; throw `LockForbiddenException` if non-owner, `LockNotFoundException` if not found
  - `releaseLock(string $dashboardUuid, IUser $user, bool $isAdmin = false): void` — verify caller is owner or admin, inline cleanup, delete lock; idempotent (204 on success or not found); throw `LockForbiddenException` if non-owner non-admin
  - `forceRelease(string $dashboardUuid, IUser $admin): void` — verify caller is admin, inline cleanup, delete lock, log action via `LoggerInterface::info()`; idempotent
  - `getLockState(string $dashboardUuid): ?DashboardLock` — inline cleanup first, then SELECT; return lock or null
- [ ] Task 6: Create controller `lib/Controller/DashboardLockApiController.php` extending `OCSController` with routes:
  - `POST /api/v1/dashboards/{uuid}/lock` → `acquireLock()` — call service, catch `LockConflictException` return 409 with lock in response body, return 200 on success
  - `PUT /api/v1/dashboards/{uuid}/lock` → `heartbeat()` — call service, catch exceptions, return 200 with lock; throw exceptions map to 403/404
  - `DELETE /api/v1/dashboards/{uuid}/lock` → `releaseLock()` — call service, return 204 on success, map exceptions to 403/404
  - `GET /api/v1/dashboards/{uuid}/lock` → `getLockState()` — call service, return 200 with lock or 404 if none
  - `POST /api/v1/dashboards/{uuid}/lock/force-release` → `forceRelease()` — verify admin, call service, return 204; throw 403 if non-admin
  - All responses use `JsonResponse` with `{userId, displayName, acquiredAt, lastHeartbeat}` serialization
  - All error responses use consistent format: `{error: 'error_code', message: 'localized message'}`
- [ ] Task 7: Wire routes into `appinfo/routes.php` — add REST routes for lock endpoints (POST/PUT/DELETE/GET `/dashboards/{uuid}/lock`, POST `/dashboards/{uuid}/lock/force-release`)
- [ ] Task 8: Update `lib/Service/DashboardService.php` — in `deleteDashboard(string $uuid)` method, call `DashboardLockMapper::deleteByDashboardUuid($uuid)` BEFORE deleting the dashboard row to ensure cascade delete
- [ ] Task 9: Create frontend composable `src/composables/useDashboardLock.js` exporting `useDashboardLock()` hook with:
  - `const lockState = ref<DashboardLock | null>(null)` — stores fetched lock
  - `const isLocked = computed(() => lockState.value !== null)` — true if dashboard has active lock
  - `const lockOwner = computed(() => lockState.value?.userId)` — owner of current lock
  - `const isOwnLock = computed(() => lockOwner.value === currentUser.uid)` — true if current user owns lock
  - `const impliedExpiry = computed(() => lockState.value?.lastHeartbeat + 900000)` — milliseconds
  - `const acquire(dashboardUuid): Promise` — call `POST /api/dashboards/{uuid}/lock`, store in `lockState`, handle 409 conflict, throw 403/404
  - `const release(dashboardUuid): Promise` — call `DELETE /api/dashboards/{uuid}/lock`, clear `lockState`, return void
  - `const startHeartbeat(dashboardUuid)` — set interval to call `PUT /api/dashboards/{uuid}/lock` every 60 seconds while dashboard edit view is open; call `stopHeartbeat()` on cleanup
  - `onMounted` calls `acquire(dashboardUuid)`; `onBeforeUnmount` calls `stopHeartbeat()` and `release(dashboardUuid)`
- [ ] Task 10: Add API client methods to `src/services/api.js`:
  - `api.acquireLock(dashboardUuid): Promise<DashboardLock>` → `POST /api/dashboards/{uuid}/lock`
  - `api.heartbeat(dashboardUuid): Promise<DashboardLock>` → `PUT /api/dashboards/{uuid}/lock`
  - `api.releaseLock(dashboardUuid): Promise<void>` → `DELETE /api/dashboards/{uuid}/lock`
  - `api.getLockState(dashboardUuid): Promise<DashboardLock | null>` → `GET /api/dashboards/{uuid}/lock`
  - `api.forceReleaseLock(dashboardUuid): Promise<void>` → `POST /api/dashboards/{uuid}/lock/force-release` (admin only)
- [ ] Task 11: Add internationalization keys for English (`l10n/en.json`) and Dutch (`l10n/nl.json`):
  - `error_lock_conflict` → "Dashboard is being edited by {displayName}. Try again after {expiry}."
  - `error_lock_forbidden` → "Only the lock owner or an admin can release this lock."
  - `error_lock_not_found` → "Lock not found; call acquire first."
  - `error_invalid_svg` → (if needed) "The uploaded SVG is invalid or contains disallowed content."
  - `lock_acquired` → "Lock acquired successfully."
  - `lock_released` → "Lock released."
  - `lock_force_released` → "Lock released by administrator."
  - Ensure both languages have all keys; parity is required
- [ ] Task 12: Wire composable into edit-view component (`src/components/DashboardEdit.vue` or equivalent):
  - Import `useDashboardLock()` and call hook with current dashboard UUID
  - Display read-only banner UI when `isLocked && !isOwnLock`, showing lock owner's `displayName` and countdown to `impliedExpiry`
  - Disable edit controls (form inputs, toolbar buttons) when `isLocked && !isOwnLock`
  - On save success, call `release()` to drop the lock
  - Handle lock-expired scenario: if heartbeat returns 404, display "Lock expired; someone else may have acquired it" and redirect to dashboard view
- [ ] Task 13: Add backend tests (`tests/unit/Service/DashboardLockServiceTest.php`):
  - Acquire on unlocked dashboard → creates lock, returns 200
  - Acquire on locked dashboard by different user → throws `LockConflictException` with lock details
  - Re-entrant acquire (same user) → refreshes `lastHeartbeat`, returns 200
  - Expired lock overwrite → inline cleanup deletes stale lock, new user acquires, no conflict
  - Heartbeat extends lease → `lastHeartbeat` bumped, `acquiredAt` unchanged
  - Non-owner heartbeat → throws `LockForbiddenException`
  - Heartbeat on non-existent lock → throws `LockNotFoundException`
  - Release by owner → deletes lock, idempotent
  - Non-owner release → throws `LockForbiddenException`
  - Admin release → deletes lock, logs action, no exception
  - Force-release non-existent lock → idempotent, still logs
  - Non-admin force-release → throws `LockForbiddenException`
  - Get lock state (active) → returns lock
  - Get lock state (none) → returns null
  - Get lock state (expired) → inline cleanup runs, returns null
  - Contention: three acquire requests on same uuid within 100ms → one succeeds with 200, two get 409 with same lock details
  - Cascade delete: delete dashboard with active lock → lock deleted first
- [ ] Task 14: Add backend tests (`tests/unit/Db/DashboardLockMapperTest.php`):
  - Create and read lock
  - Update heartbeat atomically
  - Delete by uuid
  - Delete expired rows (where `lastHeartbeat < now - 900s`)
  - UNIQUE constraint enforced: two INSERT attempts on same uuid → second fails
- [ ] Task 15: Add frontend tests (`src/composables/__tests__/useDashboardLock.spec.js`):
  - Vitest: Mock `api.acquireLock`, `api.heartbeat`, `api.releaseLock`, `api.getLockState`
  - `useDashboardLock()` on mount → calls `acquire()`, stores result in `lockState`
  - `isLocked` computed → true when `lockState` is not null
  - `isOwnLock` computed → true when `lockOwner === currentUser.uid`
  - `impliedExpiry` computed → `lastHeartbeat + 900000`
  - `acquire()` on conflict (409) → throws `LockConflictException`; frontend handles and shows banner
  - `startHeartbeat()` → sends heartbeat every 60s while modal is open; `stopHeartbeat()` clears interval
  - `release()` → calls API, clears `lockState`
  - On unmount → stops heartbeat, releases lock
- [ ] Task 16: Add E2E tests (`tests/e2e/dashboard-locking.spec.js`):
  - Playwright: Alice opens dashboard edit view → lock acquired, banner hidden (is own lock)
  - Bob opens same dashboard → lock acquired indicator hides edit controls, shows "Alice is editing" banner with expiry countdown
  - Alice saves dashboard → lock released; Bob can now acquire
  - Alice acquires lock, closes tab without releasing (no explicit release call) → heartbeat stops; lock expires after 15 minutes; Bob can acquire
  - Admin loads dashboard with Alice's lock → sees admin toolbar button "Force Release" → click force-releases lock and logs action; Bob can now acquire
  - Alice in tab 1, opens tab 2 on same dashboard → tab 2 acquires lock (re-entrant), `lastHeartbeat` refreshed, both tabs remain enabled
  - Heartbeat every 60s prevents expiry → lock stays active indefinitely while editor is open
- [ ] Task 17: Add audit logging test — verify `LoggerInterface::info()` is called on admin force-release with userId, dashboardUuid, admin userId
- [ ] Task 18: Quality checks:
  - ESLint clean on `src/composables/useDashboardLock.js`, `src/services/api.js`, touched Vue components
  - PHPStan/Psalm clean on new PHP files (`lib/Db/DashboardLock.php`, mapper, service, controller, exceptions)
  - No new i18n parity violations — all keys in `en.json` have matching keys in `nl.json`
  - Migration runs without errors on MySQL, Postgres, SQLite (via test suite)
  - Cascade delete tested: dashboard deletion cleans up locks

## Verification

`openspec validate` exits clean. Dashboard locks are acquired exclusively, extended via heartbeat, released by owner or admin, and cleaned up on expiry or dashboard deletion. The grep guard (if present) prevents any direct lock operations outside the service.

## Tests (company-wide ADR-009)

PHPUnit per Task 13–17; Vitest per Task 15; Playwright per Task 16.

## Documentation (company-wide ADR-010)

Inline service comment per Task 5 linking REQ-LOCK-001 through REQ-LOCK-008; changelog entry covering the new lock endpoints and heartbeat mechanism.

## i18n (company-wide ADR-007)

User-facing strings: error messages and lock state UI as per Task 11. Parity required for all keys in `en.json` and `nl.json`.
