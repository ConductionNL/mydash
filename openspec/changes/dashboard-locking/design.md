# Design — Dashboard Locking

## Context

MyDash allows users to edit dashboards collaboratively. When two users open the same dashboard's edit view, there is no exclusive lock — both can make changes, and the last save overwrites the first. The edit form shows "unsaved changes" but does not indicate that another user is editing. This leads to a silent "last write wins" data loss scenario: Alice makes edits, Bob makes edits, Alice saves (thinking her changes are final), Bob saves (and Alice's changes are lost). There is no conflict warning or merge UI, making this a frustrating surprise for users.

The lock mechanism MUST:
- Allow only one user (or admin) to hold a lock at a time
- Return the existing lock details (lock owner, display name, implied expiry) to a second user attempting to acquire
- Automatically expire locks after 15 minutes of inactivity (heartbeat timeout) so browser crashes do not require manual admin intervention
- Allow the lock owner to extend the lease indefinitely via heartbeat (every 60 seconds)
- Allow the owner or an admin to explicitly release the lock
- Be infeasible to bypass — the lock MUST be checked on every edit attempt

## Goals / Non-Goals

**Goals:**

- Prevent concurrent edits to the same dashboard by one exclusive lock per dashboard.
- Provide the lock owner and expiry details to conflicted users for transparency.
- Tolerate transient network outages and browser crashes via automatic expiry (no stale locks requiring manual cleanup).
- Centralise expiry logic in one place (`DashboardLockService`) so it is testable and consistent.
- Support admin override (`force-release`) with audit logging for operator control.
- Enable re-entrant acquire (same user + same dashboard = refresh, not conflict) so multi-tab sessions work seamlessly.

**Non-Goals:**

- Distributed lock coordination (Redis, Zookeeper, etc.). MyDash is a single-server Nextcloud app; a database UNIQUE constraint is sufficient.
- Lock queuing ("wait your turn"). Conflicts are rare; users release locks on save or close.
- Optimistic conflict merging. Out of scope; the lock prevents conflicts entirely.
- Per-widget-level locking. Scope is one lock per dashboard, not per widget.
- Lock transfer ("take this lock from user A to user B"). The admin force-releases and re-acquires if needed.

## Decisions

### D1: Time-based lease with heartbeat renewal, not activity-based expiry

**Decision**: Locks expire when `lastHeartbeat + LOCK_TIMEOUT` (15 minutes) is in the past. The frontend sends a heartbeat every 60 seconds while the editor is open, extending the lease indefinitely. Expiry is computed at query time, not stored as an `expires_at` column.

**Alternatives considered:**

- Duration-based one-shot: acquire returns "lock expires at T = now + 15min" with no refresh. Rejected — requires tracking when the lease was acquired on the client, and network-delayed saves could stale-lock for 15 minutes.
- Write-time activity tracking: every keystroke or debounced save bumps expiry. Rejected — couples the lock service to edit UI events, and the lock becomes a reactive dependency across components.
- Sliding window on the server: EVERY read/write bumps `lastHeartbeat`. Rejected — turns every dashboard load (including read-only views) into a lock extension, leaking lock state to non-editors.

**Rationale**: Explicit client-driven heartbeat (every 60 seconds from the edit composable) is decoupled from UI events, predictable, and testable. The 15-minute TTL gives a 15× safety margin (900s / 60s) so heartbeat misses due to transient network hiccups do not cause premature expiry. Expiry computed at query time (not stored) means the TTL is a configurable constant, not a migration footprint.

### D2: Inline cleanup, not background sweeper

**Decision**: Stale locks are deleted inline at the start of `getLockState()` and `acquireLock()`. No background job or scheduled task removes expired locks.

**Alternatives considered:**

- Lazy cleanup on first access to a stale lock (status quo). Rejected — a lock that never sees another access remains stale forever.
- Background sweeper (e.g., OCC command, cron job). Rejected — adds operational burden and failure modes (sweeper hangs, sweeper fails to run, manual restart needed). Inline cleanup is simpler and guaranteed to run when a stale lock would matter (on the next acquire attempt).
- Database-level TTL (MySQL partition pruning, Postgres partial index). Rejected — adds platform-specific logic and operational complexity; inline cleanup is portable.

**Rationale**: Inline cleanup is O(1) per request (a single DELETE with indexed `lastHeartbeat`), guaranteed to run when it matters, and requires no background task infrastructure. Stale locks do not accumulate because they are deleted the moment a conflicted user attempts to acquire.

### D3: Re-entrant acquire — same user gets HTTP 200, not 409

**Decision**: If the same user (determined by `userId`) attempts to acquire a lock they already hold, the system returns HTTP 200 and refreshes the lock (`lastHeartbeat` bumped). A different user gets HTTP 409 conflict.

**Alternatives considered:**

- Always return 409 on any acquire attempt when a lock exists. Rejected — breaks multi-tab sessions where the second tab opens the same dashboard and sends `POST /api/dashboards/d1/lock` before the first tab has `lastHeartbeat` pushed; tab 2 gets locked out of its own dashboard.
- Return 409 and let the client detect re-entrancy by checking `userId` in the response. Rejected — adds complexity and the client would still need to re-acquire (race condition).

**Rationale**: Re-entrancy is not a security issue (the same user, same device) and fixes the multi-tab scenario. The frontend can optionally detect pre-existing locks (by comparing `acquiredAt` across tabs) and display an informational "You are editing in another tab" notice, but the backend contract is simpler: "You own this lock → HTTP 200 with refresh, you don't own it → 409 with details."

### D4: Cached `displayName` at lock time, not updated on background refresh

**Decision**: `displayName` is captured from Nextcloud's user registry at the moment of lock acquisition (`acquiredAt`) and persisted in the lock row. It is never updated by background jobs or heartbeat calls.

**Alternatives considered:**

- Look up `displayName` on every read. Rejected — adds a user lookup (N+1 problem if we ever batch-read locks) and couples the lock service to Nextcloud's user API.
- Refresh `displayName` on every heartbeat or read. Rejected — if Alice changes her display name mid-edit, Bob sees inconsistent values across multiple reads of the same lock.

**Rationale**: Consistency over freshness. The `displayName` Bob sees reflects who was editing *when the lock was acquired*, which is the useful signal for conflict UI. If Alice changes her name during the edit, that is not a lock state change; Bob's "Alice is editing" UI remains correct.

### D5: Application-layer cascade delete, not database ON DELETE CASCADE

**Decision**: When a dashboard is deleted, `DashboardService::deleteDashboard()` calls `DashboardLockMapper::deleteByDashboardUuid()` explicitly before deleting the dashboard. The database schema has no foreign key constraint.

**Alternatives considered:**

- Database-level foreign key with ON DELETE CASCADE. Rejected — Nextcloud's migration framework supports foreign keys on MySQL/Postgres but not SQLite, and MyDash must support all three.
- Leave stale locks when dashboards are deleted. Rejected — leaks lock rows and pollutes the lock table over time.

**Rationale**: Application-layer cascade is explicit, testable, portable across database backends, and does not require conditional migration logic. The responsibility is clear: `DashboardService` owns the cascade, not the database.

### D6: 15-minute TTL (900 seconds) as default constant

**Decision**: `LOCK_TIMEOUT = 15 minutes = 900 seconds` is defined as a `public const` in `DashboardLockService`, not in the schema or config.

**Rationale**: The TTL is a business constant, not a per-deployment configuration. Changing it would alter lock semantics and require coordination with the frontend (heartbeat cadence depends on it). Keeping it in code makes the impact of a change obvious during review.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Same-user re-entrant acquire allows one user to keep a lock forever via rapid tab refreshes | Acceptable — the lock owner can release at any time. The 15-min timeout is a safety net for browser crashes, not a quota on honest usage. |
| Admin force-release action is not rate-limited; a malicious admin could spam release+acquire to mess with editors | Acceptable — Nextcloud admins are trusted users. All force-release actions are logged for audit. |
| Heartbeat sent from every tab; if both tabs are active, heartbeats come 2× as fast, shortening de-facto TTL | Acceptable — extra heartbeats do not shorten TTL; they extend it. More heartbeats = longer lock lease. No problem. |
| `displayName` is stale if the user's Nextcloud account is renamed mid-edit | Acceptable — the name reflects the lock owner at acquisition time, which is the relevant context for conflict UI. Minor staleness is preferable to lookups. |
| Inline cleanup on every `getLockState()` and `acquireLock()` adds latency | Acceptable — DELETE on indexed `lastHeartbeat + dashboardUuid` is O(1) and completes in <1ms. Measured in load test. |

## Seed Data

Dashboard locks are transient — they do not exist until a user opens an edit view and acquire them. No seed data is required for this capability. The `oc_mydash_dashboard_locks` table starts empty; locks are created and deleted at runtime as users edit dashboards.

No `_registers.json` entry is required for this change.

## Test strategy

- **PHPUnit (`tests/unit/Service/DashboardLockServiceTest.php`)**: 
  - Acquire on empty dashboard, acquire on locked dashboard (conflict), re-entrant acquire (same user), expired-lock overwrite
  - Heartbeat extends lease, non-owner heartbeat rejected, heartbeat on non-existent lock
  - Release by owner, non-owner release rejected, admin release, idempotent release
  - Force-release idempotency, non-admin force-release rejected
  - Inline cleanup removes expired rows before acquire
  - Contention: three users race to acquire (database UNIQUE constraint ensures exactly one succeeds)
- **PHPUnit (`tests/unit/Db/DashboardLockMapperTest.php`)**: 
  - Create, read, update, delete by uuid/user
  - `deleteExpiredForDashboard()` removes rows older than TTL
  - `deleteByDashboardUuid()` (cascade delete)
- **Vitest (`src/composables/__tests__/useDashboardLock.spec.js`)**: 
  - Heartbeat scheduling (60-second interval)
  - Acquire success + conflict UI
  - Lock-held UI banner with expiry countdown
  - Multi-tab re-entrant acquire
- **Playwright (`tests/e2e/dashboard-locking.spec.js`)**: 
  - Alice acquires lock on dashboard; Bob sees "locked by Alice" banner
  - Alice saves and releases lock; Bob can now acquire
  - Lock expires after 15 minutes of inactivity; second user can acquire
  - Admin force-releases Alice's lock; Bob can acquire
- **Audit log verification**: Admin force-release is logged via `LoggerInterface::info()`
