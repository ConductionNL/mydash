# Design — Dashboard Cascade Events

## Context

MyDash dashboards have a rich dependent-data schema: widget placements, comments, reactions, locks, versions, public shares, metadata values, translations, view analytics, and child-tree relationships. When a dashboard is deleted, today's code path is incomplete — some dependent tables are cleaned up inline (via service calls), others are orphaned entirely. Additionally, child dashboards are silently deleted via a recursive operation without firing the delete event for each child, meaning child-level dependent rows (locks, analytics, reactions) are never cleaned up.

Nextcloud core demonstrates the correct pattern: when a user or group entity is deleted, dispatch an event so all interested services can react without tight coupling to the core delete logic. MyDash MUST follow the same pattern for dashboard deletion, user deletion, and group deletion. Each listener is independent, failure-isolated, and idempotent.

## Goals / Non-Goals

**Goals:**

- Make dashboard deletion a complete operation: soft-delete the dashboard row, then dispatch an event so every dependent-data listener can clean up its table.
- Every dependent-data listener MUST run independently — a failure in one (e.g., file I/O error during version cleanup) MUST NOT prevent others from running.
- All listener failures MUST be logged (with full context: listener class, dashboard UUID, exception message) but MUST NOT propagate or cause the delete to fail.
- Every listener MUST be idempotent — running it a second time against already-cleaned data MUST be safe and non-destructive.
- User and group lifecycle cleanup MUST cascade to owned dashboards — deleting a user or group cascades to deleting all their dashboards, which in turn cascade their own dependent data.
- Tree deletion MUST recursively dispatch the event for each child dashboard so all listeners run per-child.
- Non-cascade deletes with children MUST be rejected before any event dispatch, protecting against accidental subtree deletion.
- Response MUST include `cascadeStats` — counts of deleted rows per listener, aggregated across all deleted dashboards (parent + all children in a cascade).

**Non-Goals:**

- Custom failure recovery strategies per listener — all failures use log-and-continue; recovery is via the orphan-cleanup job.
- Partial rollback if some listeners succeed and others fail — the delete is always committed (dashboard is soft-deleted); failures are only in dependent-data cleanup, which is independently retryable.
- Async listener dispatch — all listeners run synchronously in the same request (standard Nextcloud `IEventDispatcher` behaviour); the HTTP response includes final cascade stats.
- Listener priority ordering — all listeners run in registration order and are independent; there is no sequencing constraint.

## Decisions

### D1: Event-listener architecture over inline cleanup

**Decision**: Dispatch `DashboardDeletedEvent` after soft-delete; define independent listeners for each dependent-data class.

**Alternatives considered:**

- Inline all cleanup logic in `DashboardService::delete()` as method calls. Rejected because it tightly couples the service to all dependent-data concerns and makes testing difficult.
- Use a dedicated cleanup orchestrator service. Rejected because Nextcloud's standard pattern is `IEventDispatcher`; using a custom orchestrator diverges from platform conventions.

**Rationale**: Events decouple deletion logic from cleanup implementation. Each listener is independently testable, can be independently deployed (removed/added without touching the service), and failures are isolated. This matches Nextcloud core's user/group deletion pattern and enables the orphan-cleanup job to selectively retry failed listeners.

### D2: Soft-delete before event dispatch

**Decision**: Dashboard row is soft-deleted (marked with `deletedAt` timestamp) BEFORE the event is dispatched. Listeners observe an already-soft-deleted dashboard row and MUST NOT attempt to re-delete the main row.

**Alternatives considered:**

- Hard-delete immediately and dispatch the event before the response. Rejected because if a listener fails catastrophically, auditing is harder and recovery is impossible.
- Dispatch the event BEFORE soft-deleting. Rejected because listeners might query the dashboard and see it as active, risking race conditions when multiple deletions are in flight.

**Rationale**: Soft-delete is idempotent and auditable (the row and timestamp are preserved). Listeners can always query the parent dashboard to confirm its state. Orphan-cleanup jobs and audits can identify incomplete cascades by finding dependent rows whose parent dashboard is soft-deleted.

### D3: Log-and-continue on listener failure

**Decision**: Every listener wraps its cleanup in try/catch, logs failures at WARN level with full context (listener class, dashboard UUID, exception message), and always returns successfully. No separate `oc_mydash_cascade_failures` table.

**Alternatives considered:**

- Propagate the first listener failure back to the caller as an HTTP error. Rejected because it blocks the HTTP response and makes the delete appear to have failed when the main row is already soft-deleted.
- Log at ERROR level. Rejected because listener failures are transient (e.g., file I/O glitches, brief service unavailability) and recoverable via the orphan-cleanup job; ERROR suggests a fatal condition.
- Record each failure in a dedicated `oc_mydash_cascade_failures` table. Rejected because the orphan-cleanup job already identifies stragglers by querying dependent tables directly (e.g., "widget placements whose dashboard is soft-deleted") — an explicit failures table adds migration cost without benefit.

**Rationale**: The delete completes with a 2xx response and full cascade stats from successful listeners. Failures appear in logs (searchable, alertable, and replayable by orphan-cleanup). This matches Nextcloud core's listener failure handling: catch, warn, continue.

### D4: Listeners are independently idempotent

**Decision**: Each listener uses idempotent queries (DELETE WHERE ..., UPDATE WHERE ... IS NULL). Running a listener twice against already-cleaned data is a safe no-op.

**Alternatives considered:**

- Have the listener check "is the cleanup already done?" before running. Rejected because it adds a redundant query; idempotent deletes are cheaper.
- Have an orchestrator record "listener X succeeded" and skip re-runs. Rejected because it requires a state table and the orphan-cleanup job would need to clear it; more moving parts than idempotent queries.

**Rationale**: Idempotent queries enable safe replay via the orphan-cleanup job. A listener that fails midway through (e.g., deletes 3 out of 5 rows before throwing) can be retried without worrying about "already deleted" errors. Listeners that silently return 0 affected rows are the normal case on retry.

### D5: Tree listener recursively dispatches, not filesystem-deletes

**Decision**: `TreeListener` queries for child dashboards and dispatches a new `DashboardDeletedEvent` for each one, ensuring the full listener stack runs per child.

**Alternatives considered:**

- Use a recursive filesystem delete as in the reference implementation. Rejected because child-level DB rows (reactions, locks, analytics) are orphaned — the child event never fires so listeners never clean up child data.
- Have the parent delete hard-delete all children in a single loop. Rejected because it's monolithic and error-prone; dispatching per-child is more testable and aligns with the event architecture.

**Rationale**: Each child dashboard deserves its own soft-delete row and its own `DashboardDeletedEvent`. This ensures all dependent-data listeners run for every node in the tree, not just the parent. Auditing and recovery are clearer (each child has a soft-deleted row with timestamp). Tree deletion is fully cascade-aware.

### D6: Non-cascade delete with children is rejected before event dispatch

**Decision**: `DashboardService::delete()` validates children BEFORE soft-delete and event dispatch. If children exist and cascade is not requested, return HTTP 400 and touch nothing.

**Alternatives considered:**

- Always cascade silently (no cascade flag). Rejected because users can accidentally delete entire subtrees without realising.
- Allow the delete and orphan children (set `parentId` to NULL). Rejected because it silently changes the tree structure and users lose expected parent-child relationships.

**Rationale**: The cascade guard protects against accidental subtree deletion. The explicit `?cascade=true` query parameter signals intent. This matches the design principle "explicit is better than implicit" and aligns with REQ-CSC-010 in the context brief.

### D7: User deletion cascades to personal dashboards

**Decision**: `UserDeletedListener` responds to Nextcloud's `UserDeletedEvent`, enumerates all personal dashboards owned by that user, and calls `DashboardService::delete()` for each (which cascades their own dependent data via the full listener stack).

**Alternatives considered:**

- Have the service do a cascading hard-delete in a loop (all rows deleted in a single batch). Rejected because the soft-delete + event pattern is more auditable and aligns with the core delete logic.
- Ignore user deletion — let dashboards linger. Rejected because it violates GDPR (data minimization) and leaves orphaned rows.

**Rationale**: User deletion is rare and the number of owned dashboards per user is typically small. Soft-delete each one and cascade the full cleanup. This ensures audit trails (soft-delete timestamps) and proper orphan identification.

### D8: Group deletion cascades to group-shared dashboards and updates settings

**Decision**: `GroupDeletedListener` calls `DashboardService::delete()` for each group-shared dashboard AND mutates IConfig JSON settings (`mydash.org_navigation_tree`, `mydash.group_order`) to remove group identifiers.

**Alternatives considered:**

- Only delete the dashboards, leave the IConfig settings intact. Rejected because navigation tree and group order become inconsistent (referencing deleted groups).
- Hard-delete the settings entries entirely. Rejected because other settings in the same JSON object are unaffected; surgical removal is safer.

**Rationale**: Deleting a group requires cascading cleanup at multiple levels: dashboards (via delete), and configuration (via JSON mutation). This ensures the app state remains consistent.

### D9: Cascade stats aggregated across all deleted dashboards

**Decision**: `DashboardService::delete()` returns `cascadeStats` with counts: widgetPlacementsDeleted, commentsDeleted, reactionsDeleted, locksDeleted, versionsDeleted, sharesRevoked, metadataValuesDeleted, translationsDeleted, viewsDeleted. Tree deletions aggregate child counts into the parent's response.

**Alternatives considered:**

- Return only the top-level dashboard's stats. Rejected because it hides the scope of cascade (e.g., "I thought it was one dashboard but the client tells me 5 were deleted").
- Return per-child stats separately. Rejected because it's verbose; the client typically cares about the total.

**Rationale**: Aggregated stats let the API caller understand the full cascade scope in one response. Clients can display "Deleted 1 dashboard and 12 dependent records" in a confirmation message. This matches the user-facing accountability principle.

### D10: Listeners are simple and have no cross-listener dependencies

**Decision**: Each listener reads from `IDBConnection` and injects only the services needed for its own table cleanup (plus `ILogger`). No shared state, no orchestrator, no sequencing.

**Alternatives considered:**

- Have listeners call each other (e.g., `CommentsListener` calls `ReactionsListener`). Rejected because it creates hidden dependencies and makes failures harder to isolate.
- Use a cascade orchestrator that coordinates all cleanup. Rejected because it re-introduces tight coupling.

**Rationale**: Simple listeners are testable in isolation. A listener that throws has zero impact on others. The deletion service can report final stats without worrying about partial failure cascades.

## Risks / Trade-offs

- **Risk:** A listener fails and a dependent row is orphaned. → **Mitigation:** The orphan-cleanup job identifies and retries cleanup. Logs record which listener failed so the team can debug transient issues (file I/O glitches, brief service downtime).
- **Risk:** Multiple listeners dispatch child events concurrently and overwhelm the event dispatcher. → **Mitigation:** Listeners are synchronous and run in the same request (standard Nextcloud pattern). The dashboard is typically a tree with shallow depth (max 10 levels); O(n) listeners per level is acceptable.
- **Trade-off:** Tree deletion aggregates stats from all children, requiring `TreeListener` to collect return values from child events. This is more complex than a simple "delete all rows" approach, but the stats are valuable for debugging partial failures and client-side UX.
- **Trade-off:** Soft-delete before event dispatch means listeners observe a row that is already marked deleted. A listener that tries to soft-delete the dashboard itself (instead of reading `deletedAt` to confirm) will silently fail. Mitigated by listener contracts (design docs) and review.

## Migration Plan

1. **Phase 1 — Event + core listeners land first** — add `DashboardDeletedEvent`, `WidgetPlacementsListener`, `CommentsListener`, and test coverage in one PR. No user-facing changes yet.
2. **Phase 2 — Remaining dependent-data listeners** — add the other eight listeners (`ReactionsListener`, `LocksListener`, `VersionsListener`, etc.) with tests.
3. **Phase 3 — User/Group lifecycle** — add `UserDeletedListener` and `GroupDeletedListener` with IConfig JSON mutation.
4. **Phase 4 — Tree cascade + response** — add `TreeListener`, cascade validation guard, and cascade stats response in `DashboardService::delete()`.
5. **Phase 5 — Register all listeners** — wire all 12 listeners in `Application::register()`.
6. **Testing** — Vitest for each listener in isolation; integration tests verifying full cascade end-to-end; Playwright confirming the API response shape and stats.
7. **Rollback**: pure code change, no schema migration. Reverting the PR restores the previous (incomplete) cascade behaviour with no data loss.

## Seed Data

For design.md, seed data includes example dashboards with varying scenarios:

- **Dashboard D1** — personal dashboard owned by alice, with 3 widget placements, 2 comments, 1 reaction, 1 lock, no children
- **Dashboard P1** — personal dashboard owned by alice, with children C1 and C2, parent has 5 placements
- **Dashboard C1** — child of P1, with 2 placements, 3 reactions
- **Dashboard C2** — child of P1, no dependent data
- **Dashboard G1** — group-shared dashboard owned by group "engineering", with 4 placements, 1 public share (active), 3 translated values
- **Dashboard G2** — group-shared dashboard owned by group "marketing", archived (no deletions yet)

## Reuse Analysis

- `IEventDispatcher` — provided by Nextcloud core, used as the event broadcast mechanism (no custom implementation).
- `ILogger` — provided by Nextcloud core, used by all listeners for failure logging.
- `IDBConnection` — provided by Nextcloud core, used by listeners for direct table cleanup queries.
- `ICommentsManager` — provided by Nextcloud core, called by `CommentsListener` for comment cleanup (reference pattern).
- `IConfig` — provided by Nextcloud core, used by `GroupDeletedListener` to mutate IConfig JSON settings.
- `DashboardService` — existing MyDash service, extended to soft-delete, dispatch event, and return cascade stats.

No new core abstractions are required; all listeners build on Nextcloud core interfaces or existing MyDash services.

## Open Questions

1. Which listener should run first? (Answer: no specific order required; all are independent.)
2. Should we batch multiple children's events into a single IEventDispatcher call? (Answer: no; dispatching per-child simplifies logic and enables per-child failure handling.)
3. Can we use async listeners? (Answer: no; Nextcloud's IEventDispatcher is synchronous; async is a future capability.)
