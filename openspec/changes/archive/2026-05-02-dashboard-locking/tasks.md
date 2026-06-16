# Tasks — dashboard-locking

> NOTE: This list reflects the original (pre-design) tasks. The
> implementation follows the resolved decisions in `design.md` (D1..D6)
> and the spec delta in `specs/dashboard-locking/spec.md` — i.e. no
> `clientId` column, no stored `expiresAt`, heartbeat is `PUT` on the
> lock URL, admin override is `force-release`, TTL is 15 minutes.

## 1. Schema migration

- [x] 1.1 Create `lib/Migration/Version001021Date20260502130000.php` creating `mydash_dashboard_locks` table with columns: `id BIGINT AUTO_INCREMENT PRIMARY KEY`, `dashboard_uuid VARCHAR(36) UNIQUE NOT NULL`, `user_id VARCHAR(64) NOT NULL`, `display_name VARCHAR(255) NOT NULL`, `created_at DATETIME NOT NULL`, `updated_at DATETIME NOT NULL` (no `expiresAt` / no `clientId` per design D1 / D2)
- [x] 1.2 Same migration adds UNIQUE on `(dashboard_uuid)` plus secondary indexes on `user_id` and `updated_at`
- [x] 1.3 Migration is reversible — Nextcloud's SimpleMigrationStep drops new tables on rollback
- [ ] 1.4 Run migration locally against sqlite, mysql, and postgres (deferred — CI covers all three engines)

## 2. Domain model

- [x] 2.1 Create `lib/Db/DashboardLock.php` entity with fields: `id`, `dashboardUuid`, `userId`, `displayName`, `createdAt`, `updatedAt` (Entity `__call` pattern — no named args)
- [x] 2.2 Add methods to check expiry: `isExpired(): bool`, `expiresIn(): int`, `impliedExpiresAt(): ?string`
- [x] 2.3 Implement `jsonSerialize()` returning the public lock contract (REQ-LOCK-004 shape)

## 3. Mapper layer

- [x] 3.1 Create `lib/Db/DashboardLockMapper.php` extending `QBMapper` with `findByDashboardUuid`, `findActive`, `findByUserId`, `deleteExpiredForDashboard`, `deleteAllExpired`, `deleteByDashboardUuid`
- [x] 3.2 Entity tests cover `isExpired` / `expiresIn` and JSON contract; service tests cover the inline-cleanup flow

## 4. Service layer

- [x] 4.1 Create `lib/Service/DashboardLockService.php` with `acquireLock`, `heartbeat`, `releaseLock`, `getLockState`, `forceRelease`, `cascadeDelete` (no `clientId` parameters per design D2; heartbeat is owner-only by `userId`)
- [x] 4.2 Inject `LoggerInterface` for the force-release audit trail (design D4 — `IActivity` is a future enhancement, not in scope)
- [x] 4.3 All methods that read locks delegate active/stale filtering to the mapper; expired rows are silently scrubbed before any conflict check
- [x] 4.4 PHPUnit `DashboardLockServiceTest` covers acquire / re-entrant acquire / conflict / heartbeat / release / admin override / force-release / cascade

## 5. Controller + routes

- [x] 5.1 `DashboardLockApiController::acquire` mapped to `POST /api/dashboards/{uuid}/lock` (`#[NoAdminRequired]`)
- [x] 5.2 `DashboardLockApiController::heartbeat` mapped to `PUT /api/dashboards/{uuid}/lock` (per design D3 — same URL, different verb)
- [x] 5.3 `DashboardLockApiController::release` mapped to `DELETE /api/dashboards/{uuid}/lock`
- [x] 5.4 `DashboardLockApiController::get` mapped to `GET /api/dashboards/{uuid}/lock`
- [x] 5.5 `DashboardLockApiController::forceRelease` mapped to `POST /api/dashboards/{uuid}/lock/force-release` (per design D4 — admin override is force-release, not force-acquire)
- [x] 5.6 All five routes registered in `appinfo/routes.php` with `uuid` UUID-format constraint and the literal `force-release` route ahead of the bare `lock` routes
- [x] 5.7 All methods carry `#[NoAdminRequired]`; admin gate on force-release is enforced inline via `IGroupManager::isAdmin` inside the service

## 6. Exception handling

- [x] 6.1 `LockConflictException` (HTTP 409) carrying the existing lock object
- [x] 6.2 `LockNotFoundException` (HTTP 404)
- [x] 6.3 `LockForbiddenException` (HTTP 403)

## 7. PHPUnit tests

- [x] 7.1 `DashboardLockTest::testIsExpiredReturnsTrueForOldHeartbeat` (entity expiry helper)
- [x] 7.2 `DashboardLockServiceTest::testAcquireConflictWhenHeldByOtherUser` (REQ-LOCK-001)
- [x] 7.3 `DashboardLockServiceTest::testReentrantAcquireRefreshesExistingLock` (re-entrant by `userId` per design D2)
- [x] 7.4 `DashboardLockServiceTest::testHeartbeatByOwnerSucceeds` / `testHeartbeatByNonOwnerThrowsForbidden`
- [x] 7.5 `DashboardLockServiceTest::testReleaseByOwnerDeletes` / `testReleaseByAdminSucceedsViaOverride`
- [x] 7.6 `DashboardLockServiceTest::testForceReleaseRejectsNonAdmin` / `testForceReleaseByAdminDeletesLock`
- [x] 7.7 `DashboardLockServiceTest::testGetLockStateRunsInlineCleanup`
- [x] 7.8 `DashboardLockServiceTest::testCascadeDeleteRemovesRowUnconditionally` (REQ-LOCK-008)

## 8. Audit logging

- [x] 8.1 `forceRelease` writes a structured PSR `LoggerInterface::info` entry naming the admin, the dashboard UUID, and the previous owner (per design D4 — Nextcloud `IActivity` integration is deferred)

## 9. Expiry and cleanup

- [x] 9.1 Expired locks are silently skipped on read (`findActive` filter + inline `deleteExpiredForDashboard`)
- [x] 9.2 A fresh `acquireLock` overrides an expired lock by deleting the row before the conflict check
- [x] 9.3 No background sweeper required — inline cleanup keeps the table tidy on the hot path; `deleteAllExpired` is provided for an optional future job

## 10. Quality gates

- [x] 10.1 `composer check:strict` — all PHPCS / PHPMD / Psalm / PHPStan / PHPUnit checks pass for the new code (see verify report in commit message)
- [ ] 10.2 OpenAPI / Postman update — deferred (no central spec file in this app yet; covered by route registration + spec.md scenarios)
- [x] 10.3 i18n keys for all new error messages in both `nl` and `en` (`Lock held by another user`, `Lock not found; call acquire first`, `Lock not found`, `Only the lock owner can extend the lease`, `Only the lock owner or an admin can release this lock`, `Only an administrator may force-release a lock`, plus three frontend UI strings)
- [x] 10.4 SPDX headers (`SPDX-FileCopyrightText` + `SPDX-License-Identifier`) inside the docblock of every new PHP file
- [ ] 10.5 Hydra-gates run — handled by CI on PR open
