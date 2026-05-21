# Design — Dashboard Bulk Operations

## Overview

The `dashboard-bulk-operations` capability addresses admin-scale dashboard management. When an organisation has 100+ dashboards and needs to batch-delete obsolete templates, move entire portfolio subtrees, update publication status across a quarter, or re-index for search, the only option today is per-dashboard calls or out-of-spec scripts. This change introduces four atomic batch endpoints with a unified permission/validation layer, size-capped requests, and a single audit trail entry per operation.

## Goals

- Enable mass administration of dashboards without single-dashboard API call overhead
- Guarantee that permission checks happen all-or-nothing (no partial-success mutations)
- Make batch operations predictable: per-dashboard atomicity with partial-success reporting
- Provide dry-run preview so admins can forecast changes before applying them
- Audit bulk operations with a single event per request (not per-dashboard spam)
- Allow idempotent operations (deleting already-deleted dashboards, moving to the same parent)

## Non-goals

- Automatic retry or rollback of partial failures — the caller is responsible for retrying failed UUIDs
- Per-UUID granular permissions (e.g., "alice can delete this dashboard but not that one") — MyDash assumes admins are trusted
- Workflow or approval gates before execution — all-or-nothing permission check is the control boundary
- Transaction atomicity across the entire batch — per-dashboard atomicity is sufficient

## Architecture

### Request/response envelope

All four endpoints (bulk-delete, bulk-move, bulk-status, bulk-reindex) share a unified request shape and distinguish via path:

```
POST /api/admin/dashboards/bulk-{delete|move|status|reindex}

Request body:
{
  "dashboardUuids": ["uuid-1", "uuid-2", ...],
  "dryRun": false,
  
  // operation-specific:
  "cascade": false        // bulk-delete only, opt-in for recursive subtree deletion
  "parentUuid": "..."     // bulk-move only, target parent UUID (null for root)
  "publicationStatus": "draft|published|scheduled",  // bulk-status only
  "publishAt": "2026-06-15T10:00:00Z"  // bulk-status only, required if status="scheduled"
}

Response (real run):
{
  "deletedCount": 3,    // or movedCount, updatedCount, reindexedCount
  "skippedCount": 1,
  "errors": [
    {
      "uuid": "uuid-2",
      "reason": "already_deleted|parent_already_matches|status_already_matches|cycle_detected|dashboard_has_children|transaction_failed|not_found|reindex_failed|invalid_parent",
      "childCount": 5,  // if reason="dashboard_has_children"
      "detail": "..."   // optional human-readable context
    }
  ],
  "dryRun": false
}

Response (dry-run):
{
  "wouldDeleteCount": 3,    // or wouldMoveCount, wouldUpdateCount, wouldReindexCount
  "wouldSkipCount": 1,
  "errors": [...],
  "dryRun": true
}

Error responses:
- HTTP 403 (not admin or permission denied): { "deniedUuids": ["uuid-3"], "message": "..." }
- HTTP 400 (size exceeded or invalid input): { "message": "..." }
```

### BulkOperationService orchestration

```
BulkOperationService::bulkDelete/Move/Status/Reindex(user, uuids, options)
├─ IGroupManager::isAdmin(user) — short-circuit non-admins with 403
├─ PermissionService::resolveAccessLevel(user, uuid) × N — all-or-nothing check
├─ Request size validation against bulk_operation_max_per_request config
├─ if dryRun:
│   └─ Simulate the operation (collect would-counts and errors)
├─ else:
│   ├─ Per-uuid in try/catch blocks (continue-on-error):
│   │  ├─ Idempotency check (already-deleted, parent-already-matches, etc.)
│   │  ├─ Operation-specific logic:
│   │  │  ├─ bulkDelete: DashboardMapper::delete + WidgetPlacementMapper::deleteByDashboardId (or cascade via DashboardTreeService::deleteSubtree)
│   │  │  ├─ bulkMove: DashboardTreeService::validateParent (cycle check) + update parent_uuid
│   │  │  ├─ bulkStatus: validate status enum, update publicationStatus + publishedAt/publishAt
│   │  │  └─ bulkReindex: touch updated_at to mark dirty for search pipeline
│   │  └─ Collect success or error
│   └─ ActivityPublisher::publish(EVENT_UPDATED, synthetic UUID, {bulkOperation, dashboardCount, durationMs, dryRun})
└─ Return unified response envelope
```

### Frontend component (DashboardBulkOperations.vue)

Mounted in the admin dashboard list view:

- **Header row**: Top-left checkbox selects/deselects all visible rows
- **Per-row checkbox**: Toggles individual dashboard selection
- **Actions dropdown**: Disabled when 0 rows selected; when enabled shows 4 options:
  - Delete
  - Move to...
  - Set status
  - Reindex
- **Confirmation modal** (opened for each action):
  - Lists the selected dashboard count
  - Shows a "Dry run (preview only)" checkbox (unchecked by default)
  - Displays operation-specific inputs (e.g., target parent for move, target status for status)
  - "Cancel" and "OK" buttons
- **Result summary** (after API response):
  - "PREVIEW: Would change 10 dashboards." (if dryRun=true)
  - "Changed 10, skipped 2." (if dryRun=false)
  - Lists per-uuid errors with reasons and details
  - (If dryRun=false) Auto-refresh the dashboard list and clear selection

## Decisions

### D1: Per-dashboard atomicity, not batch atomicity

**Decision**: Database transactions wrap individual dashboard mutations (each DELETE, UPDATE succeeds or fails independently), but the batch as a whole is not transactional. Partial failure is safe and expected.

**Rationale**: MyDash dashboards are independent entities with no cross-dashboard constraints (except tree parent-child links, handled by cycle validation). Rolling back 5 successful deletes to satisfy 5 failed ones would lose audit events and confuse admins. Instead, the response lists which dashboards failed and why; the admin retries the failures.

### D2: All-or-nothing permission checks before any mutation

**Decision**: Walk all requested UUIDs and check `PermissionService::resolveAccessLevel` before entering the per-uuid mutation loop. If any UUID is denied, return 403 and mutate nothing.

**Rationale**: Admins expect bulk operations to either "work completely" or "fail completely" from the permission perspective. A mixed result (deleting 7 out of 10 dashboards because the user lost access to 3 mid-request) is surprising and hard to audit.

### D3: Cascade delete is opt-in, not default

**Decision**: `bulk-delete` defaults to `cascade=false`. Attempting to delete a parent with children rejects only that dashboard with `reason: "dashboard_has_children"`, and the rest of the batch continues.

**Rationale**: This diverges from the source implementation (which defaulted to cascade=true) because defaults should be safe. An accidental bulk delete of 500+ dashboards due to a cascade=true default is catastrophic. Requiring explicit opt-in prevents the misclick gap.

### D4: Dry-run validation mirrors real-run validation

**Decision**: `dryRun=true` performs the same permission checks, size validation, and idempotency logic as a real run, but does not touch the database.

**Rationale**: Admins rely on dry-run to forecast the real operation. If validation differs, the forecast becomes unreliable. The same HTTP status codes and error messages ensure the admin sees exactly what will happen when they re-run without `dryRun`.

### D5: Single audit event per request, not per-dashboard

**Decision**: One Activity event is emitted at the end of the bulk operation, carrying `{bulkOperation, dashboardCount, durationMs, dryRun}` in the event parameters.

**Rationale**: Per-dashboard events would spam the activity log (500 events for a single bulk-delete request), making audit-time review unusable. A single summary event preserves the audit trail for compliance while keeping logs readable. The admin can drill into `deniedUuids` and `errors` in the response if needed.

### D6: Size cap is configurable and defaults to 500

**Decision**: `bulk_operation_max_per_request` app config (default 500) limits the number of UUIDs per request.

**Rationale**: 500 dashboards is a reasonable size (typical org portfolio), but very large instances may need to adjust. A hard-coded limit that's too small would require code changes for large customers; a default that's too large would slow database queries. Making it configurable + defaulting to 500 balances both.

### D7: Idempotent no-ops are skipped, not errors

**Decision**: Delete already-deleted, move to the same parent, or status-to-the-same-status result in `skippedCount` with a per-uuid error entry (`reason: "already_deleted"`, etc.), not HTTP 400/500.

**Rationale**: These are valid, expected outcomes (e.g., a dashboard was deleted between the time the admin selected it and the time the request was sent). Treating them as errors would abort the batch and require the admin to filter the list manually. Skipping them is cleaner and auditable (the error entry documents why it was skipped).

## Risks / Trade-offs

- **Risk**: Per-dashboard atomicity means admins must retry failures manually. → **Mitigation**: The response lists failed UUIDs and reasons; a follow-up request with just the failed UUIDs is straightforward.
- **Risk**: Cycle detection (bulkMove) delegates to `DashboardTreeService::validateParent()`, which may not detect all cycles in a dirty tree state. → **Mitigation**: The existing tree service is the single source of truth for tree invariants; audit the service's cycle logic separately.
- **Trade-off**: The frontend component is MyDash-specific and does not reuse `CnMassDeleteDialog` or other generic bulk components from `@conduction/nextcloud-vue`. → **Rationale**: MyDash bulk operations are admin-only and include operation-specific UX (cascade checkbox, parent selector, status enum), so a custom component makes sense.

## API Stability

The four endpoints are stable once shipped. Future enhancements (e.g., per-UUID permission granularity, transaction atomicity across batch, scheduled bulk operations) would require new endpoints or backwards-compatible extensions to request/response schemas.

## Security Considerations

- **Permission enforcement**: All-or-nothing model prevents privilege escalation (admin cannot delete a dashboard they shouldn't have access to by bundling it with one they can).
- **Audit trail**: Single event per request preserves a complete record of who did what, when, and whether it was a dry-run.
- **Size cap**: Prevents DoS by query complexity (500 UUIDs per request is a reasonable upper bound).
- **Cascade opt-in**: Prevents accidental data loss from misclicked bulk delete.
