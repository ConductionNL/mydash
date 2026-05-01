# Tasks — dashboard-locking

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddDashboardLocksTable.php` creating `oc_mydash_dashboard_locks` table with columns: `id INT AUTO_INCREMENT PRIMARY KEY`, `dashboardUuid VARCHAR(36) UNIQUE NOT NULL`, `userId VARCHAR(64) NOT NULL`, `displayName VARCHAR(255) NOT NULL`, `acquiredAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP`, `expiresAt TIMESTAMP NOT NULL`, `clientId VARCHAR(64) NOT NULL`
- [ ] 1.2 Same migration adds index on `(dashboardUuid)` for fast lookups and unique constraint on `dashboardUuid`
- [ ] 1.3 Confirm migration is reversible (drop table in `postSchemaChange` rollback path)
- [ ] 1.4 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Create `lib/Db/DashboardLock.php` entity with fields: `id`, `dashboardUuid`, `userId`, `displayName`, `acquiredAt`, `expiresAt`, `clientId` and corresponding getters/setters (Entity `__call` pattern — no named args)
- [ ] 2.2 Add methods to check expiry: `public function isExpired(): bool` and `public function expiresIn(): int` (remaining seconds)
- [ ] 2.3 Implement `jsonSerialize()` to return lock state in API responses (include all fields except `id`, or include `id` if needed for internal tracking)

## 3. Mapper layer

- [ ] 3.1 Create `lib/Db/DashboardLockMapper.php` extending `ORM\Mapper` with methods:
  - `findByDashboardUuid(string $uuid): DashboardLock` — returns active (non-expired) lock or throws `DoesNotExistException`
  - `findActive(string $uuid): ?DashboardLock` — returns active lock or null (silent on expired)
  - `insert(DashboardLock $lock): DashboardLock`
  - `update(DashboardLock $lock): DashboardLock`
  - `delete(DashboardLock $lock): void`
  - `deleteExpired(): int` — deletes and returns count of expired locks
  - `findByUserId(string $userId): array` — returns all (active and expired) locks for a user for auditing
- [ ] 3.2 Add PHPUnit test covering: active lock retrieval, expired lock ignored, empty case, unique constraint on `dashboardUuid`

## 4. Service layer

- [ ] 4.1 Create `lib/Service/DashboardLockService.php` with:
  - `acquireLock(string $dashboardUuid, string $userId, string $displayName, string $clientId, int $ttlSeconds = 300): DashboardLock` — creates new lock if none exists or current is expired; throws `ConflictException` if a different user holds an active lock
  - `renewLock(string $dashboardUuid, string $clientId, int $ttlSeconds = 300): DashboardLock` — extends `expiresAt` by TTL; throws 404 if no lock exists, 403 if `clientId` mismatch
  - `releaseLock(string $dashboardUuid, string $clientId, ?string $userId): void` — deletes lock; owner (matching `clientId`) or admin (any `$userId` allowed) can release; throws 403 if mismatch on non-admin
  - `getLockState(string $dashboardUuid): ?DashboardLock` — returns current (non-expired) lock or null (silent on expired)
  - `forceAcquire(string $dashboardUuid, string $adminUserId, string $displayName, string $clientId, int $ttlSeconds = 300): DashboardLock` — steals lock from any user; must log activity via `IManager`; throws 403 if caller is not admin
- [ ] 4.2 Inject `OCP\Activity\IManager` for audit logging on `forceAcquire`
- [ ] 4.3 All methods check expiry internally before reading; expired locks behave as if they don't exist
- [ ] 4.4 Add PHPUnit test covering: acquire, heartbeat, release, conflict on second user, admin override, expiry edge cases

## 5. Controller + routes

- [ ] 5.1 Add `DashboardController::acquireLock(string $uuid)` mapped to `POST /api/dashboards/{uuid}/lock` (logged-in user, `#[NoAdminRequired]`)
  - Request body: `{"clientId": "..."}`
  - Response 200: `{"id": ..., "dashboardUuid": "...", "userId": "...", "displayName": "...", "acquiredAt": "...", "expiresAt": "...", "clientId": "..."}`
  - Response 409 (conflict): same lock object body if held by another user
- [ ] 5.2 Add `DashboardController::heartbeat(string $uuid)` mapped to `POST /api/dashboards/{uuid}/lock/heartbeat` (logged-in)
  - Request body: `{"clientId": "..."}`
  - Response 200: updated lock object
  - Response 404: no lock exists
  - Response 403: `clientId` mismatch
- [ ] 5.3 Add `DashboardController::releaseLock(string $uuid)` mapped to `DELETE /api/dashboards/{uuid}/lock` (logged-in)
  - Request body: `{"clientId": "..."}`
  - Response 204: success (no body)
  - Response 403: `clientId` mismatch (not owner or not admin)
- [ ] 5.4 Add `DashboardController::getLock(string $uuid)` mapped to `GET /api/dashboards/{uuid}/lock` (logged-in)
  - Response 200: lock object if active, or 404 if none/expired
- [ ] 5.5 Add `DashboardController::forceAcquire(string $uuid)` mapped to `POST /api/dashboards/{uuid}/lock/force-acquire` (in-body admin check)
  - Request body: `{"clientId": "..."}`
  - Response 200: new lock object
  - Response 403: caller is not admin
  - Logs activity via `IManager`
- [ ] 5.6 Register all five routes in `appinfo/routes.php` with proper requirements (`uuid` is a valid UUID format)
- [ ] 5.7 Verify all methods carry correct Nextcloud auth (`#[NoAdminRequired]` for all; in-body `IGroupManager::isAdmin()` check for `forceAcquire`)

## 6. Exception handling

- [ ] 6.1 Define or reuse `OCP\AppFramework\OCS\OCSException` (409 conflict) for lock conflicts
- [ ] 6.2 Return HTTP 404 for missing locks
- [ ] 6.3 Return HTTP 403 for permission/ownership mismatches

## 7. PHPUnit tests

- [ ] 7.1 `DashboardLockMapperTest::testAcquireLock` — basic insert, unique constraint on `dashboardUuid`
- [ ] 7.2 `DashboardLockMapperTest::testExpiredLockIgnored` — `findActive()` returns null for expired; `deleteExpired()` removes them
- [ ] 7.3 `DashboardLockServiceTest::testAcquireConflict` — second user acquire returns 409 with existing lock object
- [ ] 7.4 `DashboardLockServiceTest::testHeartbeatClientIdMismatch` — different `clientId` returns 403
- [ ] 7.5 `DashboardLockServiceTest::testHeartbeatExtends` — `expiresAt` advances by 5 minutes
- [ ] 7.6 `DashboardLockServiceTest::testReleaseByOwner` — matching `clientId` deletes lock
- [ ] 7.7 `DashboardLockServiceTest::testAdminForceAcquire` — admin can override any lock; non-admin returns 403
- [ ] 7.8 `DashboardLockControllerTest::testGetLockReturns404OnExpired` — expired locks are transparent to clients
- [ ] 7.9 `DashboardLockControllerTest::testAcquireHeartbeatReleaseFlow` — end-to-end scenario

## 8. Activity logging

- [ ] 8.1 On `forceAcquire`, call `IManager::publish()` with type `dashboard_lock_override` (or similar), subject containing stolen user's name, object uuid
- [ ] 8.2 Add i18n strings for activity log: `dashboard_lock_override` description in both `nl` and `en`

## 9. Expiry and cleanup

- [ ] 9.1 Expired locks are silently skipped on all read operations (mapper and service layer)
- [ ] 9.2 A fresh `acquireLock()` call overrides an expired lock without treating it as a conflict
- [ ] 9.3 Optional background cleanup via separate `orphaned-data-cleanup` spec — this spec does NOT require a background sweeper (correctness does not depend on cleanup)

## 10. Quality gates

- [ ] 10.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered
- [ ] 10.2 Update generated OpenAPI spec / Postman collection with the five new endpoints
- [ ] 10.3 i18n keys for all new error messages in both `nl` and `en`:
  - `Lock held by another user`
  - `Lock not found`
  - `Insufficient permissions to release lock`
  - `Only lock owner or admin can release`
  - `clientId mismatch` (or similar)
- [ ] 10.4 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 10.5 Run all 10 `hydra-gates` locally before opening PR
