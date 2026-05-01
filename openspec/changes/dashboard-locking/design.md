# Design — Dashboard editing lock

## Context

The `dashboard-locking` proposal was written with a heartbeat/lease model in mind, including a
frontend-generated `clientId` per browser tab, a 5-minute TTL stored as an explicit `expiresAt`
column, and a `/heartbeat` sub-resource endpoint. Before implementation began, it was flagged that
the IntraVox app in the same monorepo already ships a working page-lock system whose design choices
may differ significantly from those assumptions.

A deep-read of the IntraVox source at tag 0.8.4 was performed to ground-truth the open question.
The findings below drive the final design choices for the MyDash `DashboardLock` feature.

## Goals / Non-Goals

**Goals:**
- Align the MyDash lock model with the proven IntraVox approach where it is architecturally sound.
- Resolve all six open sub-questions (clientId, expiresAt column, heartbeat endpoint, owner check, admin override, background sweeper) with a clear decision backed by source evidence.
- Produce a spec-delta list so REQ-LOCK-001..008 can be updated in a single targeted edit pass.

**Non-Goals:**
- Porting the IntraVox lock system directly — MyDash locks dashboards (UUID-keyed), not wiki pages (uniqueId-keyed). The column names and routes differ.
- Deciding the frontend implementation details beyond what the backend contract requires.

## Decisions

### D1: Lock model — server-side TTL via `updated_at` heartbeat, NOT an `expiresAt` column

**Decision**: Use two timestamp columns — `created_at` (immutable, set on acquire) and `updated_at`
(bumped on every heartbeat) — plus a server-side constant `LOCK_TIMEOUT_MINUTES`. A lock is
considered active if `updated_at > now() - timeout`. There is no `expiresAt` column stored in the
database.

**Alternatives considered:**
- Explicit `expiresAt` column (as written in the spec). Rejected by the IntraVox implementation
  in favour of a rolling `updated_at` timestamp, which is simpler and avoids a field that must be
  recalculated on every heartbeat write.

**Rationale**: The `updated_at`-based model is self-healing: if the application TTL constant is
adjusted, all existing rows in the database re-evaluate correctly against the new constant without a
migration. The `expiresAt` model would require a backfill of every live lock row. Additionally, the
`updated_at` approach produces a natural heartbeat record (last-seen timestamp) that doubles as
diagnostic information.

**Source evidence:**
- `intravox-source/lib/Service/PageLockService.php:17` — `private const LOCK_TIMEOUT_MINUTES = 15;`
- `intravox-source/lib/Service/PageLockService.php:171-180` — `cleanExpiredLock()` computes
  `$expiry = new \DateTime(); $expiry->modify('-' . self::LOCK_TIMEOUT_MINUTES . ' minutes');`
  and deletes rows where `updated_at < $expiry`, confirming there is no stored `expiresAt`.
- `intravox-source/lib/Migration/Version001001Date20260307000000.php:54-60` — the CREATE TABLE
  statement has columns `created_at` and `updated_at`, with NO `expires_at` column.

**MyDash implication**: The `expiresAt` field in the spec's data model and scenario steps must be
replaced with a `lastHeartbeat` field (= `updated_at`) plus an implicit expiry rule
(`lastHeartbeat + TTL`). The TTL constant lives in `DashboardLockService`, not in the row.

---

### D2: clientId — absent from the lock table; owner check is by `userId` alone

**Decision**: There is no `clientId` column in the lock table. Owner identity is determined solely
by `userId`. The same user opening a second tab does NOT get a separate lock slot; the second
`acquireLock` call for the same user on the same resource succeeds (re-entrant) and refreshes the
existing lock rather than creating a conflict.

**Alternatives considered:**
- Frontend-generated `clientId` per tab (as written in the spec). Rejected by the IntraVox
  implementation: no such column exists in the schema or in any query.
- Server-generated token returned to the frontend. Not present in the IntraVox implementation.

**Rationale**: Tab-level isolation creates usability friction (the spec itself acknowledges that
tab-2 would be blocked by tab-1 for the same user). The re-entrant `userId`-only model is simpler
and matches how users actually think: "I'm editing this page" regardless of how many tabs they have.
The trade-off is that heartbeat authority cannot be scoped to a single tab, but in practice this
edge case is negligible: any of the user's tabs can extend the lease.

**Source evidence:**
- `intravox-source/lib/Migration/Version001001Date20260307000000.php:39-65` — full column list:
  `id`, `page_unique_id`, `user_id`, `display_name`, `created_at`, `updated_at`. No `client_id`.
- `intravox-source/lib/Service/PageLockService.php:63-100` — `acquireLock()` checks
  `if ($existing['userId'] === $userId) { $this->refreshLock(); return ['success' => true]; }`,
  explicitly making same-user acquire re-entrant.
- `intravox-source/lib/Service/PageLockService.php:106-113` — `refreshLock()` WHERE clause uses
  only `page_unique_id` + `user_id`; no `client_id` predicate.
- `intravox-source/lib/Controller/PageLockController.php:48-64` — `acquireLock()` passes only
  `$user->getUID()` and `$user->getDisplayName()` to the service; no request body is parsed.

**MyDash implication**: Remove the `clientId` column from the data model. Remove `clientId` from
all request bodies (acquire, heartbeat, release). Update REQ-LOCK-001 scenario "Same user with two
browser tabs" — tab-2's acquire MUST succeed (re-entrant refresh), not return 409. Remove
REQ-LOCK-002 "Wrong clientId on alice's own lock" scenario. Update heartbeat and release to use
`userId` as the sole ownership predicate.

---

### D3: Heartbeat endpoint — uses PUT on the same lock URL, cadence is 60 seconds

**Decision**: The heartbeat is a `PUT /api/pages/{pageId}/lock` (same resource, different verb)
rather than a dedicated `/heartbeat` sub-resource. The frontend fires this every 60 seconds.
Returns 409 if the lock was lost; 200 on success.

**Alternatives considered:**
- Dedicated `POST /api/dashboards/{uuid}/lock/heartbeat` sub-resource (as written in the spec).
  Rejected by the IntraVox implementation, which collapses acquire, heartbeat (refresh), and
  release onto GET/POST/PUT/DELETE of a single resource URL.

**Rationale**: Using the REST verb matrix on a single URL (GET=query, POST=acquire, PUT=refresh,
DELETE=release) is clean and avoids route proliferation. The 60-second heartbeat against a
15-minute TTL gives a 15× safety margin on transient network failures, which is generous.

**Source evidence:**
- `intravox-source/appinfo/routes.php:27-30` — the four verbs on `/api/pages/{pageId}/lock`:
  GET=`getLock`, POST=`acquireLock`, PUT=`refreshLock`, DELETE=`releaseLock`.
- `intravox-source/src/App.vue:777-792` — `setInterval(async () => { axios.put(url); }, 60 * 1000)`
  — heartbeat every 60 seconds confirmed in a comment: `// Every 60 seconds`.
- `intravox-source/src/App.vue:779-780` — PUT target is the same lock URL (no `/heartbeat` suffix).

**MyDash implication**: Replace `POST /api/dashboards/{uuid}/lock/heartbeat` with
`PUT /api/dashboards/{uuid}/lock`. Update REQ-LOCK-002 route reference. Update the proposal's
endpoint table and `appinfo/routes.php` impact section accordingly. The heartbeat cadence note in
REQ-LOCK-002 and the proposal should change from "every 3 minutes" to "every 60 seconds".

---

### D4: Admin override — force-release only (not force-acquire); no activity-log integration

**Decision**: The admin action is `POST /api/pages/{pageId}/lock/force-release`, which deletes the
lock regardless of owner, returning the dashboard to an unlocked state. There is no
`force-acquire` variant (the spec's `POST /api/.../lock/force-acquire` that simultaneously steals
the lock for the admin). Admin override is logged to the application logger only, not to the
Nextcloud activity system.

**Alternatives considered:**
- `force-acquire` stealing the lock for the admin (as written in the spec). Not present in
  IntraVox — admins release the lock but do not automatically enter edit mode.
- Nextcloud `IManager` activity logging (as in the spec). Not present in IntraVox — the
  `forceReleaseLock()` call uses `$this->logger->info(...)` (PSR logger) only.

**Rationale**: Force-release is the appropriate admin action: the admin unlocks the dashboard so
the user can retry or so the admin can then acquire it themselves in a separate step. This matches
what an intranet admin expects ("unlock for maintenance") rather than a lock-stealing semantic.
PSR logger is sufficient for audit in a single-instance deployment; `IActivity` integration can be
added later if compliance requirements surface.

**Source evidence:**
- `intravox-source/appinfo/routes.php:31` — `'pageLock#forceReleaseLock'` at
  `/api/pages/{pageId}/lock/force-release` (POST). No `force-acquire` route exists.
- `intravox-source/lib/Controller/PageLockController.php:114-126` — `forceReleaseLock()` calls
  `$this->lockService->forceReleaseLock($pageId, $user->getUID())` and checks
  `$this->permissionService->isAdmin()` for the guard.
- `intravox-source/lib/Service/PageLockService.php:137-153` — `forceReleaseLock()` deletes the
  row and logs via `$this->logger->info(...)`. No `OCP\Activity\IManager` import in the file.
- `intravox-source/src/App.vue:828-835` — frontend calls `axios.post(url)` to the
  `force-release` endpoint; the admin then sees the lock indicator clear from the UI, after which
  they may enter edit mode themselves via the normal acquire path.

**MyDash implication**: Rename the admin endpoint from `force-acquire` to `force-release` in all
spec scenarios, the proposal, and the routes impact list. Remove the scenario "Admin steals a lock"
(simultaneous acquire) and replace with "Admin releases lock, then can acquire normally". Downgrade
the `OCP\Activity\IManager` dependency to optional/future; use `LoggerInterface` only for the
initial implementation. Remove REQ-LOCK-006's `dashboard_lock_override` activity event requirement
(or mark it as a future enhancement).

---

### D5: Stale-lock cleanup — inline per-resource on every read/write, no background sweeper

**Decision**: Expired locks are cleaned up inline (before every `getLock` and `acquireLock` call)
via a `cleanExpiredLock(pageId)` helper that deletes only the row for the requested resource. There
is no global background sweeper or Nextcloud background job.

**Alternatives considered:**
- A Nextcloud `IJob` background sweeper (not present in IntraVox, though an `updated_at` index
  exists that would make such a sweep efficient).
- Leaving stale rows in place and filtering them by timestamp on SELECT (hybrid — IntraVox does
  clean them inline rather than relying on filter-only).

**Rationale**: Inline cleanup is safe and predictable: the resource is clean by the time any user
sees a response. It also prevents the table from growing unboundedly on pages that are locked and
then crashed without release. The `updated_at` index ensures the DELETE is fast even if many
stale rows accumulate between accesses.

**Source evidence:**
- `intravox-source/lib/Service/PageLockService.php:30-33` — `getLock()` calls
  `$this->cleanExpiredLock($pageUniqueId)` as its first line.
- `intravox-source/lib/Service/PageLockService.php:63-64` — `acquireLock()` also calls
  `$this->cleanExpiredLock($pageUniqueId)` before checking for an existing lock.
- `intravox-source/lib/Service/PageLockService.php:171-180` — `cleanExpiredLock()` issues a
  targeted `DELETE WHERE page_unique_id = ? AND updated_at < ?` (not a full-table sweep).
- `intravox-source/lib/Migration/Version001001Date20260307000000.php:65` — `addIndex(['updated_at'],
  'iv_pl_updated')` — the index exists to keep the cleanup DELETE fast.
- No `IJob` or `BackgroundJob` subclass found anywhere in the IntraVox `lib/` tree.

**MyDash implication**: The spec's current note "no background sweeper required for correctness,
though a separate orphaned-data-cleanup spec may purge them" is directionally correct but should be
updated to document the inline-cleanup approach. The `DashboardLockMapper` must expose a
`deleteExpiredForDashboard(string $uuid)` method that `DashboardLockService` calls at the start of
both `getLockState()` and `acquireLock()`.

---

### D6: TTL constant — 15 minutes, not 5 minutes

**Decision**: Align the default TTL with the IntraVox implementation: 15 minutes, not the 5 minutes
written in the spec.

**Rationale**: A 60-second heartbeat against a 5-minute TTL gives only a 5× safety margin; a
single slow-loading page or 2G network blip could cause accidental expiry. The IntraVox choice of
15 minutes against 60-second heartbeats (15× margin) is more robust for real users. This also
reduces false-positive lock-lost alerts in the UI.

**Source evidence:**
- `intravox-source/lib/Service/PageLockService.php:17` — `private const LOCK_TIMEOUT_MINUTES = 15;`
- `intravox-source/src/App.vue:768` — comment `// Best effort — lock will auto-expire after 15 min`

**MyDash implication**: Change all spec scenario times from "5 minutes / 300 seconds" to
"15 minutes / 900 seconds". Update the heartbeat cadence note to "every 60 seconds (15× safety
margin)".

---

## Schema — Resolved Column Set

Based on the IntraVox migration (ground-truth), the `oc_mydash_dashboard_locks` table MUST have:

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `dashboard_uuid` | VARCHAR(36) UNIQUE | References `oc_mydash_dashboards.uuid`; UNIQUE enforces one-lock-per-dashboard atomically |
| `user_id` | VARCHAR(64) | Lock owner |
| `display_name` | VARCHAR(255) | Cached display name at acquire time |
| `created_at` | DATETIME | Set on first acquire; never updated |
| `updated_at` | DATETIME | Bumped on every heartbeat; expiry computed as `updated_at + 15min` |

Indexes: PRIMARY on `id`, UNIQUE on `dashboard_uuid`, secondary on `user_id`, secondary on
`updated_at` (for efficient inline cleanup DELETE).

Columns REMOVED from the spec's proposed model: `expiresAt`, `clientId`.

---

## Spec Changes Implied

The following changes to `specs/dashboard-locking/spec.md` are implied by these decisions.
**This section is informational — do not edit the spec from this file.**

- **Data model section**: Remove `expiresAt` and `clientId` columns. Add `lastHeartbeat` (= `updated_at`) and document that expiry is computed as `lastHeartbeat + LOCK_TIMEOUT` where `LOCK_TIMEOUT = 15 minutes`.
- **REQ-LOCK-001** "Acquire" scenarios: Remove `clientId` from request body. Change TTL from 300s to 900s. Update "Same user with two browser tabs" scenario: tab-2 acquire MUST now return HTTP 200 (re-entrant refresh), not 409. Update "clientId is arbitrary string" scenario: delete entirely (clientId does not exist).
- **REQ-LOCK-002** "Heartbeat" scenarios: Change endpoint from `POST .../lock/heartbeat` to `PUT .../lock`. Remove `clientId` from request body. Remove "Wrong clientId on alice's own lock" scenario. Change cadence note from "every 3 minutes" to "every 60 seconds". Change TTL references from 5 min / 300s to 15 min / 900s.
- **REQ-LOCK-003** "Release" scenarios: Remove `clientId` from request bodies. The owner check is by `userId` alone (no clientId mismatch scenario needed).
- **REQ-LOCK-004** "Query lock state" scenarios: Replace `expiresAt` field in response with `lastHeartbeat`; note that the client computes the implied expiry as `lastHeartbeat + 900s` for display purposes.
- **REQ-LOCK-005** "Expiry" scenarios: Replace `expiresAt` references with `lastHeartbeat` + TTL. Default TTL changes to 15 minutes.
- **REQ-LOCK-006** "Admin override": Rename endpoint from `POST .../lock/force-acquire` to `POST .../lock/force-release`. Update scenarios to reflect that the admin releases (not acquires) the lock. Remove audit log via `IActivity`; replace with PSR `LoggerInterface` info log. Remove `dashboard_lock_override` activity event requirement (or mark as future).
- **Proposal impact section**: Update five endpoint list — replace `POST .../lock/heartbeat` with `PUT .../lock` (same resource, different verb = 4 verbs, not 5 routes). Remove `OCP\Activity\IManager` dependency. Change default TTL from 5 to 15 minutes.

---

## Open Follow-ups

1. **Re-entrant same-user acquire UX**: With no `clientId`, the second tab for the same user now gets HTTP 200 (re-entrant) — the frontend must still detect "I'm already editing in another tab" and show an appropriate warning. Mechanism: the frontend can store the `acquiredAt` timestamp; if acquire returns 200 but `acquiredAt` is older than what this tab set, another tab must hold it. Or simply: always show "You may be editing in another tab" on acquire-200 if the tab is freshly opened. Needs a frontend UX decision.
2. **`force-release` notification to evicted user**: When an admin force-releases a lock, the locked-out user's editor continues heartbeating. On the next PUT (within 60s), the heartbeat returns 409 (lock gone or held by new owner), triggering the "Your edit lock has expired" alert. This is acceptable but could be improved with a push notification via Nextcloud notification API. Defer to a follow-up.
3. **Background sweeper for orphaned rows**: Inline cleanup handles rows on active dashboards. Locks on dashboards that are never visited after a browser crash will linger until someone opens that dashboard. The `updated_at` index makes a periodic `DELETE WHERE updated_at < now() - 15min` sweep trivially cheap. Wire a Nextcloud background job in a follow-up to prevent unbounded table growth.
4. **Cascade on dashboard delete (REQ-LOCK-008)**: IntraVox does not have a cascade example (pages are not deleted the same way). Confirm whether MyDash's `DashboardMapper::delete()` should call `DashboardLockMapper::deleteByDashboardUuid()` explicitly in the service layer, or whether a DB-level ON DELETE CASCADE should be used. Note that Nextcloud's migration framework supports foreign keys on MySQL/Postgres but not SQLite; an application-layer cascade in `DashboardService::delete()` is safer.
5. **Localization of heartbeat error messages**: The IntraVox frontend uses `this.t(...)` for error messages but the backend returns plain English strings. Ensure MyDash backend returns translatable error codes (not prose) so the frontend can show localized strings matching the i18n spec.
