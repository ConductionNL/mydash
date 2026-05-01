---
status: draft
---

# Dashboard Locking Specification

## Purpose

Dashboard locking provides a concurrent-edit guard for dashboards. When two users open the same dashboard's edit view, the system MUST prevent the second user from editing until the first releases the lock. The mechanism uses a time-based lease (default 5 minutes) with client-driven heartbeat renewal to tolerate transient network outages and browser crashes without manual intervention.

## Data Model

Each dashboard lock is stored in the `oc_mydash_dashboard_locks` table with the following fields:

- **id**: Auto-increment integer primary key
- **dashboardUuid**: VARCHAR(36) UNIQUE, references dashboard UUID
- **userId**: VARCHAR(64), Nextcloud user ID of the lock owner
- **displayName**: VARCHAR(255), cached display name for UI feedback (avoids second lookup on conflict response)
- **acquiredAt**: TIMESTAMP, when the lock was first acquired
- **expiresAt**: TIMESTAMP, when the lock becomes stale (ignored on read if in the past)
- **clientId**: VARCHAR(64), browser/tab identifier so the same user with two tabs can recognize their own lock

## ADDED Requirements

### Requirement: REQ-LOCK-001 Acquire Dashboard Lock

A user MUST be able to acquire an exclusive write lock on a dashboard. Only one user (or admin) can hold a lock at a time. A second user attempting to acquire MUST receive the existing lock details in a conflict response.

#### Scenario: First user acquires lock on unlocked dashboard
- GIVEN dashboard with uuid "d1" has no lock
- WHEN alice sends `POST /api/dashboards/d1/lock` with body `{"clientId": "tab-abc123"}`
- THEN the system MUST create a lock record with:
  - `dashboardUuid = "d1"`
  - `userId = "alice"`
  - `displayName = "Alice Smith"` (fetched from Nextcloud user display name)
  - `clientId = "tab-abc123"`
  - `acquiredAt = now`
  - `expiresAt = now + 300 seconds` (5 minutes default TTL)
- AND the response MUST return HTTP 200 with the full lock object
- AND alice MUST be able to edit the dashboard

#### Scenario: Second user encounters lock held by first user
- GIVEN dashboard "d1" has an active lock owned by alice
- WHEN bob sends `POST /api/dashboards/d1/lock` with body `{"clientId": "tab-xyz789"}`
- THEN the system MUST NOT create a new lock
- AND the response MUST return HTTP 409 (Conflict)
- AND the response body MUST contain the existing lock object:
  - `userId = "alice"`
  - `displayName = "Alice Smith"`
  - `expiresAt = ...` (current expiry, not extended)
  - `clientId = "tab-abc123"`
- AND bob MUST see a read-only banner on the dashboard with alice's display name and lock expiry time

#### Scenario: Same user with two browser tabs
- GIVEN alice has two tabs open on the same dashboard with different `clientId` values ("tab-1", "tab-2")
- WHEN tab-1 acquires the lock
- AND tab-2 attempts to acquire immediately after
- THEN tab-2 MUST receive HTTP 409 with alice's lock details
- AND the frontend MUST recognize that it is alice's own lock (by matching `userId`) and display a different UI (e.g., "You are editing in another tab")
- NOTE: Both tabs show editing UI, but only the lock owner can commit changes; the second tab's edit attempt fails at save time with a "Lock held by you" error

#### Scenario: Expired lock is overwritable
- GIVEN dashboard "d1" has a lock owned by alice with `expiresAt` in the past
- WHEN bob sends `POST /api/dashboards/d1/lock`
- THEN the system MUST treat the expired lock as non-existent
- AND bob MUST receive HTTP 200 with a new lock owned by bob
- AND alice's expired lock MUST be silently deleted (or left in place for background cleanup)

#### Scenario: clientId is arbitrary string (frontend responsibility)
- GIVEN a user calls `POST /api/dashboards/d1/lock` with `{"clientId": "my-unique-id"}`
- THEN the system MUST store the `clientId` exactly as provided
- AND the `clientId` MUST be usable by the frontend to identify "is this my lock?" on the heartbeat/release calls
- NOTE: The system does NOT generate `clientId`; the frontend is responsible for generating a stable, unique identifier per browser tab (e.g., UUID or tab ID)

### Requirement: REQ-LOCK-002 Heartbeat to Extend Lock

A lock owner (matching `clientId` and optionally `userId`) MUST be able to extend the lock's expiry without releasing and re-acquiring. Heartbeat MUST only succeed if the caller provides the correct `clientId`.

#### Scenario: Lock owner extends lease with heartbeat
- GIVEN alice holds a lock on dashboard "d1" with `expiresAt = T0 + 300s`, acquired at `T0`
- WHEN alice's browser sends `POST /api/dashboards/d1/lock/heartbeat` with `{"clientId": "tab-abc123"}` at time `T1 = T0 + 150s`
- THEN the system MUST update the lock with `expiresAt = T1 + 300s`
- AND the response MUST return HTTP 200 with the updated lock object showing new `expiresAt`
- AND no `acquiredAt` change (remains `T0`)

#### Scenario: Non-owner cannot heartbeat
- GIVEN alice holds a lock on dashboard "d1" with `clientId = "tab-abc123"`
- WHEN bob sends `POST /api/dashboards/d1/lock/heartbeat` with `{"clientId": "tab-xyz789"}` (different `clientId`)
- THEN the system MUST return HTTP 403 (Forbidden)
- AND the lock MUST NOT be modified

#### Scenario: Wrong clientId on alice's own lock
- GIVEN alice holds a lock on dashboard "d1" with `clientId = "tab-abc123"`
- WHEN alice sends `POST /api/dashboards/d1/lock/heartbeat` from a different browser tab with `{"clientId": "tab-different"}`
- THEN the system MUST return HTTP 403
- AND the response MUST indicate the mismatch (e.g., error message "Lock held by you in another tab")
- NOTE: This forces the user to close the first tab before editing in the second

#### Scenario: Heartbeat on non-existent lock
- GIVEN dashboard "d1" has no lock
- WHEN alice sends `POST /api/dashboards/d1/lock/heartbeat` with `{"clientId": "tab-abc123"}`
- THEN the system MUST return HTTP 404 (Not Found)
- AND the response MUST indicate "Lock not found; call acquire first"

#### Scenario: Frequent heartbeat prevents expiry
- GIVEN alice acquires a lock at `T0` with 5-minute TTL
- WHEN alice's browser sends heartbeat every 3 minutes
- THEN the lock MUST never expire while the browser remains open
- AND alice MUST retain exclusive edit access indefinitely
- NOTE: Frontend is responsible for calling heartbeat ~3 minutes; server does NOT force a heartbeat frequency

### Requirement: REQ-LOCK-003 Release Dashboard Lock

A lock owner or administrator MUST be able to release a lock, returning the dashboard to an unlocked state. Non-owners MUST NOT be able to release another user's lock unless they are admins.

#### Scenario: Lock owner releases lock
- GIVEN alice holds a lock on dashboard "d1"
- WHEN alice sends `DELETE /api/dashboards/d1/lock` with `{"clientId": "tab-abc123"}`
- THEN the system MUST delete the lock record
- AND the response MUST return HTTP 204 (No Content)
- AND bob can now acquire the lock immediately

#### Scenario: Non-owner cannot release another user's lock
- GIVEN alice holds a lock on dashboard "d1" with `clientId = "tab-abc123"`
- WHEN bob sends `DELETE /api/dashboards/d1/lock` with `{"clientId": "tab-any"}`
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

#### Scenario: clientId mismatch on release
- GIVEN alice holds a lock on dashboard "d1" with `clientId = "tab-abc123"`
- WHEN alice sends `DELETE /api/dashboards/d1/lock` with `{"clientId": "tab-different"}`
- THEN the system MUST return HTTP 403
- AND the lock MUST remain intact

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
  - `expiresAt = ...` (future timestamp)
  - `clientId = "tab-abc123"` (may or may not be exposed to non-owner; backend MUST include it for debugging)
- AND bob can display "Dashboard is being edited by Alice Smith (expires in 4m 30s)"

#### Scenario: Get lock when none exists
- GIVEN dashboard "d1" has no lock
- WHEN bob sends `GET /api/dashboards/d1/lock`
- THEN the system MUST return HTTP 404 (Not Found)
- OR return HTTP 200 with `null` (if API design prefers null over 404)
- AND the frontend MUST interpret this as "dashboard is unlocked"

#### Scenario: Get lock when it has expired
- GIVEN dashboard "d1" has an expired lock (expiresAt in the past)
- WHEN bob sends `GET /api/dashboards/d1/lock`
- THEN the system MUST return HTTP 404 or treat the lock as non-existent
- AND the response MUST NOT leak expired lock details
- AND bob MUST be able to acquire a new lock immediately

#### Scenario: Cached displayName is stale
- GIVEN alice acquires a lock on dashboard "d1"
- AND alice's display name is later changed in Nextcloud (e.g., "Alice Smith" → "Alice S.")
- WHEN bob queries the lock at `GET /api/dashboards/d1/lock`
- THEN bob MUST see the cached name from the time of lock acquisition ("Alice Smith")
- NOTE: The cached `displayName` MUST NOT be updated by background jobs; it reflects the name at acquire time for consistency

### Requirement: REQ-LOCK-005 Lock Expiry and Stale State

Locks MUST automatically become stale when `expiresAt` is in the past. Stale locks MUST be silently ignored on read and overwritable on acquire without conflict.

#### Scenario: Stale lock is invisible on read
- GIVEN dashboard "d1" has a lock with `expiresAt = now - 1 minute`
- WHEN bob sends `GET /api/dashboards/d1/lock`
- THEN the system MUST return HTTP 404 (or null if API design prefers)
- AND the response MUST NOT include the stale lock
- NOTE: Stale locks remain in the database until a background cleanup job runs (out of scope of this spec)

#### Scenario: Stale lock does not block new acquisition
- GIVEN dashboard "d1" has a lock with `expiresAt = now - 1 minute`
- WHEN bob sends `POST /api/dashboards/d1/lock` with valid `clientId`
- THEN the system MUST NOT return HTTP 409 (conflict)
- AND bob MUST receive HTTP 200 with a new lock
- AND the old stale lock MAY remain in the database or be silently deleted
- NOTE: No explicit error on conflict; the stale lock is simply transparent

#### Scenario: Network outage does not prevent lock release
- GIVEN alice acquires a lock and then closes her browser (crash or network cut)
- AND the lock expires after 5 minutes of inactivity
- WHEN bob attempts to acquire after expiry
- THEN bob MUST succeed immediately (no manual admin intervention required for correctness)
- NOTE: Background cleanup MAY later delete the stale row, but correctness does not depend on it

#### Scenario: Default TTL is 5 minutes
- GIVEN alice acquires a lock on dashboard "d1" at time `T0`
- THEN the lock's `expiresAt` MUST be set to `T0 + 300 seconds` (5 minutes)
- WHEN 301 seconds have passed without a heartbeat
- THEN the lock MUST be considered stale
- NOTE: Callers MAY specify a custom TTL (e.g., 600 seconds for batch operations), but the default is 5 minutes

### Requirement: REQ-LOCK-006 Admin Override and Audit Logging

A Nextcloud administrator MUST be able to steal a lock from any user via `POST /api/dashboards/{uuid}/lock/force-acquire`. This action MUST be audit-logged via Nextcloud's activity system with the original owner's name and the admin's action.

#### Scenario: Admin steals a lock
- GIVEN alice holds a lock on dashboard "d1"
- AND charlie is a Nextcloud admin
- WHEN charlie sends `POST /api/dashboards/d1/lock/force-acquire` with `{"clientId": "admin-tab-123"}`
- THEN the system MUST delete alice's lock and create a new one owned by charlie:
  - `userId = "charlie"`
  - `displayName = "Charlie Admin"`
  - `clientId = "admin-tab-123"`
  - `expiresAt = now + 300 seconds`
- AND the response MUST return HTTP 200 with the new lock object
- AND the action MUST be logged to Nextcloud activity log with type `dashboard_lock_override` (or similar), subject containing alice's name and the dashboard UUID

#### Scenario: Non-admin cannot force-acquire
- GIVEN alice holds a lock on dashboard "d1"
- WHEN bob (non-admin) sends `POST /api/dashboards/d1/lock/force-acquire` with `{"clientId": "tab-xyz"}`
- THEN the system MUST return HTTP 403 (Forbidden)
- AND alice's lock MUST remain intact
- AND the action MUST NOT be logged to activity (or logged as a failed override attempt)

#### Scenario: Force-acquire succeeds even if lock is expired
- GIVEN dashboard "d1" has an expired lock
- AND charlie is admin
- WHEN charlie sends `POST /api/dashboards/d1/lock/force-acquire`
- THEN charlie MUST receive HTTP 200 with a new lock
- AND the old expired lock MUST be silently replaced

#### Scenario: Activity log message includes context
- GIVEN alice holds a lock on dashboard "d1" ("My Dashboard")
- WHEN charlie (admin) force-acquires the lock
- THEN the activity log entry MUST include:
  - Original lock owner: "Alice Smith"
  - Dashboard uuid: "d1"
  - Action: "force-acquire" or "Dashboard lock override" (user-facing string)
  - Timestamp: when the override occurred
- AND admins reviewing activity logs MUST understand who stole the lock from whom and on which dashboard

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
- AND the lock's `expiresAt` MUST be updated consistently
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
- AND the cascade MUST be enforced by a foreign key constraint or application logic
- NOTE: If the delete request comes from a different user or admin, the same cascade applies

#### Scenario: Lock is not a blocker for dashboard deletion
- GIVEN bob holds a lock on dashboard "d1" owned by alice
- WHEN alice sends `DELETE /api/dashboard/d1`
- THEN the system MUST allow alice to delete her own dashboard
- AND bob's lock MUST be automatically released
- AND bob's next save attempt on the (now non-existent) dashboard MUST fail with a "Dashboard not found" error

## Non-Functional Requirements

- **Performance**: `POST /api/dashboards/{uuid}/lock` MUST complete within 100ms (single INSERT + potential UPDATE). `GET /api/dashboards/{uuid}/lock` MUST complete within 50ms (single SELECT with index on `dashboardUuid`).
- **Atomicity**: Only one lock holder per dashboard MUST be enforced by database UNIQUE constraint + application logic.
- **Audit trail**: All admin `force-acquire` actions MUST be logged to Nextcloud activity for compliance and debugging.
- **Expiry grace period**: No grace period — locks expire at exactly `expiresAt` timestamp; queries treat past timestamps as stale immediately.
- **Localization**: All error messages and activity log entries MUST support English and Dutch.

### Current Implementation Status

**Not yet implemented** — this is a new capability.
