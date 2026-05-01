---
status: draft
---

# Dashboard Locking Specification

## Purpose

Dashboard locking provides a concurrent-edit guard for dashboards. When two users open the same dashboard's edit view, the system MUST prevent the second user from editing until the first releases the lock. The mechanism uses a time-based lease (default 15 minutes) with client-driven heartbeat renewal to tolerate transient network outages and browser crashes without manual intervention.

## Data Model

Each dashboard lock is stored in the `oc_mydash_dashboard_locks` table with the following fields:

- **id**: Auto-increment integer primary key
- **dashboardUuid**: VARCHAR(36) UNIQUE, references dashboard UUID
- **userId**: VARCHAR(64), Nextcloud user ID of the lock owner
- **displayName**: VARCHAR(255), cached display name for UI feedback (avoids second lookup on conflict response)
- **acquiredAt**: TIMESTAMP, when the lock was first acquired (never updated after initial insert)
- **lastHeartbeat**: TIMESTAMP (`updated_at`), bumped on every heartbeat; expiry is computed as `lastHeartbeat + LOCK_TIMEOUT` where `LOCK_TIMEOUT = 15 minutes`

Indexes: PRIMARY on `id`, UNIQUE on `dashboardUuid`, secondary on `userId`, secondary on `lastHeartbeat` (for efficient inline expiry cleanup).

The TTL constant (`LOCK_TIMEOUT = 15 minutes`) lives in `DashboardLockService`, not in the row. There is no stored expiry column — the active/stale state of a lock is always computed at query time from `lastHeartbeat`.

Stale-lock cleanup is performed inline: `DashboardLockService` calls `DashboardLockMapper::deleteExpiredForDashboard(string $uuid)` at the start of both `getLockState()` and `acquireLock()`, deleting any row for that dashboard whose `lastHeartbeat` is older than `now - 15 minutes`. This prevents stale locks from blocking new acquisitions without requiring a background sweeper.

## ADDED Requirements

### Requirement: REQ-LOCK-001 Acquire Dashboard Lock

A user MUST be able to acquire an exclusive write lock on a dashboard. Only one user (or admin) can hold a lock at a time. A second user attempting to acquire MUST receive the existing lock details in a conflict response.

Same-user acquire is re-entrant: if the same user already holds the lock, the acquire call MUST succeed with HTTP 200 and refresh the lock (bump `lastHeartbeat`), rather than returning 409.

#### Scenario: First user acquires lock on unlocked dashboard
- GIVEN dashboard with uuid "d1" has no lock
- WHEN alice sends `POST /api/dashboards/d1/lock`
- THEN the system MUST create a lock record with:
  - `dashboardUuid = "d1"`
  - `userId = "alice"`
  - `displayName = "Alice Smith"` (fetched from Nextcloud user display name)
  - `acquiredAt = now`
  - `lastHeartbeat = now`
- AND the response MUST return HTTP 200 with the full lock object
- AND alice MUST be able to edit the dashboard

#### Scenario: Second user encounters lock held by first user
- GIVEN dashboard "d1" has an active lock owned by alice
- WHEN bob sends `POST /api/dashboards/d1/lock`
- THEN the system MUST NOT create a new lock
- AND the response MUST return HTTP 409 (Conflict)
- AND the response body MUST contain the existing lock object:
  - `userId = "alice"`
  - `displayName = "Alice Smith"`
  - `lastHeartbeat = ...` (current heartbeat timestamp; client computes implied expiry as `lastHeartbeat + 900s`)
- AND bob MUST see a read-only banner on the dashboard with alice's display name and the implied lock expiry time

#### Scenario: Same user with two browser tabs (re-entrant acquire)
- GIVEN alice has tab-1 open on dashboard "d1" and has already acquired the lock
- WHEN alice opens tab-2 on the same dashboard and tab-2 sends `POST /api/dashboards/d1/lock`
- THEN tab-2 MUST receive HTTP 200 (re-entrant refresh)
- AND the lock's `lastHeartbeat` MUST be updated to now
- AND alice's edit session in tab-2 is valid (the lock is hers)
- NOTE: The frontend MAY detect that a pre-existing lock exists (by comparing `acquiredAt` with what tab-1 stored) and display a "You may be editing in another tab" informational notice; this is a UX concern and does not affect the backend contract

#### Scenario: Expired lock is overwritable
- GIVEN dashboard "d1" has a lock owned by alice where `lastHeartbeat` is more than 15 minutes in the past
- WHEN bob sends `POST /api/dashboards/d1/lock`
- THEN the system MUST treat the expired lock as non-existent (inline cleanup removes it first)
- AND bob MUST receive HTTP 200 with a new lock owned by bob
- AND alice's expired lock MUST be deleted before the new lock is created

### Requirement: REQ-LOCK-002 Heartbeat to Extend Lock

A lock owner MUST be able to extend the lock by sending a heartbeat. Ownership is determined by `userId` alone. The heartbeat MUST only succeed if the caller is the current lock owner. The recommended heartbeat cadence is every 60 seconds, giving a 15× safety margin against the 15-minute TTL.

#### Scenario: Lock owner extends lease with heartbeat
- GIVEN alice holds a lock on dashboard "d1" with `lastHeartbeat = T0`, acquired at `T0`
- WHEN alice's browser sends `PUT /api/dashboards/d1/lock` at time `T1 = T0 + 60s`
- THEN the system MUST update the lock with `lastHeartbeat = T1`
- AND the response MUST return HTTP 200 with the updated lock object showing the new `lastHeartbeat`
- AND `acquiredAt` MUST remain unchanged (still `T0`)

#### Scenario: Non-owner cannot heartbeat
- GIVEN alice holds a lock on dashboard "d1"
- WHEN bob (a different user) sends `PUT /api/dashboards/d1/lock`
- THEN the system MUST return HTTP 403 (Forbidden)
- AND the lock MUST NOT be modified

#### Scenario: Heartbeat on non-existent lock
- GIVEN dashboard "d1" has no lock
- WHEN alice sends `PUT /api/dashboards/d1/lock`
- THEN the system MUST return HTTP 404 (Not Found)
- AND the response MUST indicate "Lock not found; call acquire first"

#### Scenario: Frequent heartbeat prevents expiry
- GIVEN alice acquires a lock at `T0` with 15-minute TTL
- WHEN alice's browser sends `PUT /api/dashboards/d1/lock` every 60 seconds
- THEN the lock MUST never expire while the browser remains open
- AND alice MUST retain exclusive edit access indefinitely
- NOTE: The recommended heartbeat cadence is every 60 seconds (15× safety margin against the 15-minute TTL); the server does not enforce a minimum frequency

### Requirement: REQ-LOCK-003 Release Dashboard Lock

A lock owner or administrator MUST be able to release a lock, returning the dashboard to an unlocked state. Non-owners MUST NOT be able to release another user's lock unless they are admins. Ownership is determined by `userId` alone.

#### Scenario: Lock owner releases lock
- GIVEN alice holds a lock on dashboard "d1"
- WHEN alice sends `DELETE /api/dashboards/d1/lock`
- THEN the system MUST delete the lock record
- AND the response MUST return HTTP 204 (No Content)
- AND bob can now acquire the lock immediately

#### Scenario: Non-owner cannot release another user's lock
- GIVEN alice holds a lock on dashboard "d1"
- WHEN bob sends `DELETE /api/dashboards/d1/lock`
- THEN the system MUST return HTTP 403 (Forbidden)
- AND the lock MUST remain intact
- AND the response MUST indicate "Only the lock owner or an admin can release this lock"

#### Scenario: Admin can release any lock
- GIVEN alice holds a lock on dashboard "d1"
- AND charlie is a Nextcloud admin
- WHEN charlie sends `DELETE /api/dashboards/d1/lock`
- THEN the system MUST delete alice's lock
- AND the response MUST return HTTP 204
- AND the action MUST be audit-logged separately (via REQ-LOCK-006)

#### Scenario: Release non-existent lock
- GIVEN dashboard "d1" has no lock
- WHEN alice sends `DELETE /api/dashboards/d1/lock`
- THEN the system MUST return HTTP 204 (idempotent — successfully deleted or already gone is the same outcome)
- OR the system MAY return HTTP 404 (Not Found) with a message "Lock not found"
- NOTE: Frontend should not rely on 404; a 204 response is preferred for idempotency

### Requirement: REQ-LOCK-004 Query Lock State

Any logged-in user MUST be able to query the current lock state of a dashboard to display lock status UI.

#### Scenario: Get active lock
- GIVEN dashboard "d1" has an active lock owned by alice
- WHEN bob sends `GET /api/dashboards/d1/lock`
- THEN the system MUST return HTTP 200 with the lock object:
  - `userId = "alice"`
  - `displayName = "Alice Smith"`
  - `lastHeartbeat = ...` (timestamp of the most recent heartbeat)
- AND bob can display "Dashboard is being edited by Alice Smith" with an implied expiry of `lastHeartbeat + 900s` computed client-side

#### Scenario: Get lock when none exists
- GIVEN dashboard "d1" has no lock
- WHEN bob sends `GET /api/dashboards/d1/lock`
- THEN the system MUST return HTTP 404 (Not Found)
- OR return HTTP 200 with `null` (if API design prefers null over 404)
- AND the frontend MUST interpret this as "dashboard is unlocked"

#### Scenario: Get lock when it has expired
- GIVEN dashboard "d1" has a lock where `lastHeartbeat` is more than 15 minutes in the past
- WHEN bob sends `GET /api/dashboards/d1/lock`
- THEN the system MUST perform inline cleanup and return HTTP 404 (treating the lock as non-existent)
- AND the response MUST NOT leak expired lock details
- AND bob MUST be able to acquire a new lock immediately

#### Scenario: Cached displayName is stale
- GIVEN alice acquires a lock on dashboard "d1"
- AND alice's display name is later changed in Nextcloud (e.g., "Alice Smith" → "Alice S.")
- WHEN bob queries the lock at `GET /api/dashboards/d1/lock`
- THEN bob MUST see the cached name from the time of lock acquisition ("Alice Smith")
- NOTE: The cached `displayName` MUST NOT be updated by background jobs; it reflects the name at acquire time for consistency

### Requirement: REQ-LOCK-005 Lock Expiry and Stale State

Locks MUST automatically become stale when `lastHeartbeat + LOCK_TIMEOUT` (15 minutes) is in the past. Stale locks MUST be silently removed via inline cleanup on read and acquire, and MUST be overwritable without conflict.

#### Scenario: Stale lock is invisible on read
- GIVEN dashboard "d1" has a lock where `lastHeartbeat` is more than 15 minutes in the past
- WHEN bob sends `GET /api/dashboards/d1/lock`
- THEN the system MUST perform inline cleanup and return HTTP 404 (or null)
- AND the response MUST NOT include the stale lock

#### Scenario: Stale lock does not block new acquisition
- GIVEN dashboard "d1" has a lock where `lastHeartbeat` is more than 15 minutes in the past
- WHEN bob sends `POST /api/dashboards/d1/lock`
- THEN the system MUST NOT return HTTP 409 (conflict)
- AND the inline cleanup MUST delete the stale row before inserting the new lock
- AND bob MUST receive HTTP 200 with a new lock

#### Scenario: Network outage does not prevent lock release
- GIVEN alice acquires a lock and then closes her browser (crash or network cut)
- AND the lock expires after 15 minutes of inactivity (no heartbeats received)
- WHEN bob attempts to acquire after expiry
- THEN bob MUST succeed immediately (no manual admin intervention required for correctness)
- NOTE: Inline cleanup removes the stale row at acquire time; no background sweeper is required for correctness

#### Scenario: Default TTL is 15 minutes
- GIVEN alice acquires a lock on dashboard "d1" at time `T0`
- THEN the lock's effective expiry is `T0 + 900 seconds` (15 minutes), computed from `lastHeartbeat`
- WHEN 901 seconds have passed without a heartbeat
- THEN the lock MUST be considered stale and treated as non-existent on the next read or acquire

### Requirement: REQ-LOCK-006 Admin Override and Audit Logging

A Nextcloud administrator MUST be able to release a lock from any user via `POST /api/dashboards/{uuid}/lock/force-release`. This action MUST be logged via `LoggerInterface` (PSR logger) with the original owner's name and the admin's action. The dashboard is returned to an unlocked state; the admin may then acquire a new lock via the normal path if they wish to edit.

#### Scenario: Admin force-releases a lock
- GIVEN alice holds a lock on dashboard "d1"
- AND charlie is a Nextcloud admin
- WHEN charlie sends `POST /api/dashboards/d1/lock/force-release`
- THEN the system MUST delete alice's lock record
- AND the response MUST return HTTP 200 (or HTTP 204)
- AND the action MUST be logged via `LoggerInterface::info()` with the original owner's userId, the dashboard UUID, and the admin's userId
- AND dashboard "d1" MUST be in an unlocked state after this call

#### Scenario: Admin acquires lock after force-release (normal flow)
- GIVEN charlie (admin) has just force-released alice's lock on dashboard "d1"
- WHEN charlie sends `POST /api/dashboards/d1/lock`
- THEN charlie MUST receive HTTP 200 with a new lock owned by charlie
- NOTE: Force-release and acquire are two separate steps; the admin does not automatically take ownership

#### Scenario: Non-admin cannot force-release
- GIVEN alice holds a lock on dashboard "d1"
- WHEN bob (non-admin) sends `POST /api/dashboards/d1/lock/force-release`
- THEN the system MUST return HTTP 403 (Forbidden)
- AND alice's lock MUST remain intact

#### Scenario: Force-release on expired or non-existent lock
- GIVEN dashboard "d1" has no active lock (either no lock or an expired one)
- AND charlie is admin
- WHEN charlie sends `POST /api/dashboards/d1/lock/force-release`
- THEN charlie MUST receive HTTP 200 (or HTTP 204) — idempotent
- AND the action MUST still be logged

### Requirement: REQ-LOCK-007 Contention and Consistency

When multiple users attempt to acquire the same lock simultaneously (network race), only one MUST succeed with HTTP 200. All others MUST receive HTTP 409 with the same lock object.

#### Scenario: Three users race to acquire lock
- GIVEN dashboard "d1" has no lock
- WHEN alice, bob, and carol all send `POST /api/dashboards/d1/lock` simultaneously (within the same 100ms)
- THEN exactly one user MUST receive HTTP 200 with a new lock (e.g., alice)
- AND the other two MUST receive HTTP 409 with alice's lock details in the response
- NOTE: Database UNIQUE constraint on `dashboardUuid` enforces this atomically

#### Scenario: Heartbeat race does not cause corruption
- GIVEN alice holds a lock on dashboard "d1"
- WHEN alice's browser sends two heartbeat requests simultaneously (e.g., due to a double-click or retried request)
- THEN both MUST succeed with HTTP 200
- AND the lock's `lastHeartbeat` MUST be updated consistently
- AND no lock duplication or corruption MUST occur
- NOTE: Backend MUST update atomically; SELECT-then-UPDATE is not safe

#### Scenario: Release and acquire race
- GIVEN alice holds a lock on dashboard "d1"
- WHEN alice sends `DELETE /api/dashboards/d1/lock` (releasing)
- AND bob simultaneously sends `POST /api/dashboards/d1/lock` (acquiring)
- THEN one of the following MUST occur (depending on timing):
  - Alice's release succeeds first → bob's acquire succeeds with HTTP 200
  - Bob's acquire succeeds first → alice's release succeeds with HTTP 204 (idempotent; lock is gone but already not hers)
- AND no lock MUST be lost or duplicated

### Requirement: REQ-LOCK-008 Lock Lifecycle on Dashboard Deletion

When a dashboard is deleted, its lock (if any) MUST be automatically deleted as a cascade. No manual cleanup is required.

#### Scenario: Delete dashboard with active lock
- GIVEN alice holds a lock on dashboard "d1"
- AND alice sends `DELETE /api/dashboard/d1` (dashboard deletion, not lock deletion)
- THEN the system MUST:
  - Delete the dashboard record from `oc_mydash_dashboards`
  - Delete the associated lock record from `oc_mydash_dashboard_locks`
- AND the cascade MUST be enforced by application logic in `DashboardService::delete()` calling `DashboardLockMapper::deleteByDashboardUuid()`, or by a DB-level ON DELETE CASCADE (note: Nextcloud's migration framework supports foreign keys on MySQL/Postgres but not SQLite; application-layer cascade is the safer default)
- NOTE: If the delete request comes from a different user or admin, the same cascade applies

#### Scenario: Lock is not a blocker for dashboard deletion
- GIVEN bob holds a lock on dashboard "d1" owned by alice
- WHEN alice sends `DELETE /api/dashboard/d1`
- THEN the system MUST allow alice to delete her own dashboard
- AND bob's lock MUST be automatically released
- AND bob's next save attempt on the (now non-existent) dashboard MUST fail with a "Dashboard not found" error

## Non-Functional Requirements

- **Performance**: `POST /api/dashboards/{uuid}/lock` MUST complete within 100ms (inline cleanup DELETE + INSERT/UPDATE). `GET /api/dashboards/{uuid}/lock` MUST complete within 50ms (cleanup DELETE + SELECT with index on `dashboardUuid`).
- **Atomicity**: Only one lock holder per dashboard MUST be enforced by database UNIQUE constraint + application logic.
- **Audit trail**: All admin `force-release` actions MUST be logged via `LoggerInterface::info()` for audit and debugging purposes.
- **Expiry**: Locks are considered stale when `lastHeartbeat + 900s` is in the past; the system treats them as non-existent on the next read or acquire call (inline cleanup).
- **Localization**: All error messages MUST support English and Dutch.

### Current Implementation Status

**Not yet implemented** — this is a new capability.
