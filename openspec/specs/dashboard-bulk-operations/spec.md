---
status: implemented
---

# Dashboard Bulk Operations Specification

## Purpose

Dashboard bulk operations expose four batch admin endpoints for large-scale management of MyDash dashboards: bulk delete, bulk re-parent, bulk publication-status update, and bulk re-index. The endpoints provide all-or-nothing permission pre-checks, per-dashboard atomic mutations with continue-on-error semantics, dry-run preview support, and a single audit Activity event per request. The design closes the misclick gap of the source implementation (which defaulted `cascade=true` on bulk delete) by requiring an explicit opt-in for recursive deletion, and pins a 500-dashboard per-request cap that is admin-tunable via the `bulk_operation_max_per_request` app config key.

## API Surface

| Method | Path                                       | Purpose |
|--------|--------------------------------------------|---------|
| POST   | `/api/admin/dashboards/bulk-delete`        | Hard-delete a list of dashboards (REQ-BULK-001). |
| POST   | `/api/admin/dashboards/bulk-move`          | Re-parent a list of dashboards in the tree (REQ-BULK-002). |
| POST   | `/api/admin/dashboards/bulk-status`        | Update publication status across dashboards (REQ-BULK-003). |
| POST   | `/api/admin/dashboards/bulk-reindex`       | Re-index dashboards for unified search (REQ-BULK-004). |

All four endpoints accept `?dryRun=true` (or `dryRun: true` in the JSON body) and require Nextcloud admin privileges. Bulk-delete additionally accepts `?cascade=true` (or `cascade: true` in the body) for opt-in subtree deletion.


@e2e exclude pure backend — all scenarios are PHP/service/API/data-layer; no UI surface

### Request envelope (all endpoints)

```json
{
  "dashboardUuids": ["uuid-1", "uuid-2"],
  "dryRun": false
}
```

### Response envelopes

Real run:
```json
{
  "deletedCount": 2,
  "skippedCount": 0,
  "errors": [],
  "dryRun": false
}
```

Dry-run:
```json
{
  "wouldDeleteCount": 2,
  "wouldSkipCount": 0,
  "errors": [],
  "dryRun": true
}
```

The counter key reflects the operation: `deletedCount`/`movedCount`/`updatedCount`/`reindexedCount` for real runs; `wouldDeleteCount`/`wouldMoveCount`/`wouldUpdateCount`/`wouldReindexCount` for dry-runs. Each per-uuid `errors` entry carries a stable `reason` (`already_deleted`, `parent_already_matches`, `status_already_matches`, `cycle_detected`, `transaction_failed`, `reindex_failed`, `not_found`, `dashboard_has_children`, `invalid_parent`).

## Requirements

### Requirement: REQ-BULK-001 Bulk Delete Dashboards

Administrators MUST be able to hard-delete multiple dashboards in a single API request, with idempotent handling of already-deleted dashboards. Cascade to child dashboards is opt-in: attempting to delete a parent that has children without `?cascade=true` rejects only that dashboard with `dashboard_has_children` and a `childCount` field; the rest of the batch continues.

NOTE: Delete is a hard delete via `DashboardMapper::delete()` plus `WidgetPlacementMapper::deleteByDashboardId()`. There is no soft-delete flag, no `deleted_at` column, and no grace period. Recovery is only possible through Nextcloud's system-wide trash bin if it is enabled. Soft-delete is deferred to a future capability.

NOTE: The `cascade` parameter defaults to `false` to prevent accidental recursive deletion of child dashboards. This deliberately diverges from the source implementation (which defaults to `true`) and aligns with the `dashboards` capability's existing opt-in cascade convention (REQ-DASH-030). When `cascade=true`, `BulkOperationService::bulkDelete()` delegates to `DashboardTreeService::deleteSubtree()` so the single source of truth for tree invariants stays in one place.

#### Scenario: Delete three valid dashboards
- GIVEN an admin user with full admin permissions
- WHEN she sends POST /api/admin/dashboards/bulk-delete with body `{"dashboardUuids": ["uuid-1", "uuid-2", "uuid-3"]}`
- THEN the system MUST hard-delete each dashboard (and its placements) via the existing `DashboardMapper`/`WidgetPlacementMapper`
- AND the response MUST return HTTP 200 with `{deletedCount: 3, skippedCount: 0, errors: [], dryRun: false}`
- AND the deleted dashboards MUST NOT appear in `GET /api/dashboards` list for any user
- AND exactly ONE bulk operation Activity event MUST be emitted (not three)

#### Scenario: Bulk delete with one already-deleted dashboard
- GIVEN admin has permission to delete all three dashboards, but "uuid-2" is already deleted
- WHEN she sends the same bulk-delete request
- THEN the system MUST skip the already-deleted dashboard (hard delete on a non-existent row is absorbed silently)
- AND the response MUST return `{deletedCount: 2, skippedCount: 1, errors: [{uuid: "uuid-2", reason: "already_deleted"}]}`

#### Scenario: Bulk delete parent with children and cascade=false (default)
- GIVEN dashboard "parent-uuid" has 5 child dashboards
- WHEN admin sends POST /api/admin/dashboards/bulk-delete with body `{"dashboardUuids": ["parent-uuid"]}` (no `cascade=true`)
- THEN the system MUST skip the parent and report `{uuid: "parent-uuid", reason: "dashboard_has_children", childCount: 5}` in the per-uuid errors
- AND the parent and all children MUST remain intact
- AND `deletedCount` MUST be 0 and `skippedCount` MUST be 1

#### Scenario: Bulk delete parent with children and cascade=true (opt-in)
- GIVEN dashboard "parent-uuid" has 5 child dashboards
- WHEN admin sends POST /api/admin/dashboards/bulk-delete?cascade=true with body `{"dashboardUuids": ["parent-uuid"]}`
- THEN the system MUST hard-delete the parent and all 5 children recursively via `DashboardTreeService::deleteSubtree()`
- AND the response MUST return `{deletedCount: 6, skippedCount: 0, errors: []}`

#### Scenario: Bulk delete with insufficient permissions
- GIVEN admin user "alice" but "uuid-3" belongs to a private dashboard that "alice" cannot delete
- WHEN she sends POST /api/admin/dashboards/bulk-delete with all three uuids
- THEN the system MUST return HTTP 403 (Forbidden)
- AND NO mutations MUST occur (all-or-nothing permission check)
- AND error response MUST list the offending UUID(s) under `deniedUuids`

#### Scenario: Bulk delete request exceeds size cap
- GIVEN the configured cap is 500 dashboards per request
- WHEN admin sends POST /api/admin/dashboards/bulk-delete with 501 uuids
- THEN the system MUST return HTTP 400 with error message `"Request contains 501 dashboards; maximum is 500 (configured by admin)"`
- AND NO mutations MUST occur

#### Scenario: Dry-run bulk delete
- GIVEN admin sends POST /api/admin/dashboards/bulk-delete?dryRun=true with three valid uuids
- THEN the system MUST NOT hard-delete any dashboard
- AND the response MUST return `{wouldDeleteCount: 3, wouldSkipCount: 0, errors: [], dryRun: true}`
- AND `GET /api/dashboards` MUST still list all three dashboards

### Requirement: REQ-BULK-002 Bulk Move Dashboards in Tree

Administrators MUST be able to re-parent multiple dashboards in the dashboard hierarchy, with cycle detection delegated to `DashboardTreeService::validateParent()` and idempotent handling of no-op moves.

#### Scenario: Move three dashboards under a new parent
- GIVEN three dashboards "child-1", "child-2", "child-3" currently under parent "old-parent"
- WHEN admin sends POST /api/admin/dashboards/bulk-move with body `{"dashboardUuids": ["child-1", "child-2", "child-3"], "parentUuid": "new-parent"}`
- THEN the system MUST update each dashboard's `parent_uuid` to "new-parent"
- AND the response MUST return `{movedCount: 3, skippedCount: 0, errors: []}`

#### Scenario: Move dashboards to root (null parent)
- GIVEN three dashboards currently under parent "some-parent"
- WHEN admin sends POST /api/admin/dashboards/bulk-move with body `{"dashboardUuids": [...], "parentUuid": null}`
- THEN each dashboard MUST be re-parented to root (`parent_uuid = null`)
- AND the response MUST return `{movedCount: 3, skippedCount: 0, errors: []}`

#### Scenario: Bulk move detects cycle (would create circular parent-child)
- GIVEN dashboard A is parent of B, B is parent of C
- WHEN admin sends POST /api/admin/dashboards/bulk-move with `{"dashboardUuids": ["A"], "parentUuid": "C"}` (trying to make C parent of A)
- THEN the system MUST validate via `DashboardTreeService::validateParent()`
- AND the response MUST return `{movedCount: 0, skippedCount: 0, errors: [{uuid: "A", reason: "cycle_detected", detail: "..."}]}`
- AND dashboard A's `parent_uuid` MUST NOT be updated

#### Scenario: Bulk move with no-op (parent already matches target)
- GIVEN dashboard "child" currently under parent "target-parent"
- WHEN admin sends POST /api/admin/dashboards/bulk-move with `{"dashboardUuids": ["child"], "parentUuid": "target-parent"}`
- THEN the system MUST recognise this as a no-op
- AND the response MUST return `{movedCount: 0, skippedCount: 1, errors: [{uuid: "child", reason: "parent_already_matches"}]}`
- AND no database update MUST occur

#### Scenario: Bulk move with insufficient permissions
- GIVEN admin user cannot delete or move dashboard "uuid-2"
- WHEN she sends bulk-move request with that uuid
- THEN the system MUST return HTTP 403
- AND NO mutations MUST occur (all-or-nothing)

#### Scenario: Dry-run bulk move
- GIVEN admin sends POST /api/admin/dashboards/bulk-move?dryRun=true with three valid uuids and new parent
- THEN the system MUST NOT update any `parent_uuid`
- AND the response MUST return `{wouldMoveCount: 3, wouldSkipCount: 0, errors: [], dryRun: true}`

### Requirement: REQ-BULK-003 Bulk Update Publication Status

Administrators MUST be able to update publication status (`draft`, `published`, `scheduled`) across multiple dashboards, with idempotent handling of dashboards already at the target status. The status enum mirrors the canonical `Dashboard::STATUS_DRAFT|STATUS_PUBLISHED|STATUS_SCHEDULED` triple shipped with the `dashboards` capability (REQ-DASH-031..034).

#### Scenario: Publish three draft dashboards
- GIVEN three dashboards with `publicationStatus = "draft"`
- WHEN admin sends POST /api/admin/dashboards/bulk-status with body `{"dashboardUuids": [...], "publicationStatus": "published"}`
- THEN the system MUST update each dashboard's `publicationStatus` to `"published"` and stamp `publishedAt` on first publish
- AND the response MUST return `{updatedCount: 3, skippedCount: 0, errors: []}`

#### Scenario: Bulk status with idempotency (dashboards already published)
- GIVEN three dashboards already with `publicationStatus = "published"`
- WHEN admin sends the same bulk-status request to `"published"`
- THEN the system MUST recognise all three as no-ops
- AND the response MUST return `{updatedCount: 0, skippedCount: 3, errors: [{uuid: "...", reason: "status_already_matches"}, ...]}`

#### Scenario: Schedule dashboards for future publish date
- GIVEN three draft dashboards
- WHEN admin sends POST /api/admin/dashboards/bulk-status with body `{"dashboardUuids": [...], "publicationStatus": "scheduled", "publishAt": "2026-06-15T10:00:00Z"}`
- THEN the system MUST update `publicationStatus` to `"scheduled"` and set `publishAt` to the parsed timestamp (formatted as `Y-m-d H:i:s`)
- AND the response MUST return `{updatedCount: 3, skippedCount: 0, errors: []}`

#### Scenario: Bulk status to scheduled without publishAt date
- GIVEN admin sends POST /api/admin/dashboards/bulk-status with `{"publicationStatus": "scheduled"}` (no publishAt)
- THEN the system MUST return HTTP 400 with error message `"publishAt is required when publicationStatus is \"scheduled\""`
- AND NO mutations MUST occur

#### Scenario: Invalid publication status value
- GIVEN admin sends POST /api/admin/dashboards/bulk-status with `{"publicationStatus": "invalid"}`
- THEN the system MUST return HTTP 400 with error message `"publicationStatus must be one of: draft, published, scheduled"`
- AND NO mutations MUST occur

#### Scenario: Bulk status with insufficient permissions
- GIVEN admin cannot modify status on one dashboard
- WHEN she sends bulk-status request with that uuid
- THEN the system MUST return HTTP 403
- AND NO mutations MUST occur (all-or-nothing)

#### Scenario: Dry-run bulk status
- GIVEN admin sends POST /api/admin/dashboards/bulk-status?dryRun=true with three draft dashboards and target status `"published"`
- THEN the system MUST NOT update any `publicationStatus`
- AND the response MUST return `{wouldUpdateCount: 3, wouldSkipCount: 0, errors: [], dryRun: true}`

### Requirement: REQ-BULK-004 Bulk Re-index Dashboards for Search

Administrators MUST be able to re-index multiple dashboards for unified search in a single request, with error reporting for individual re-index failures.

NOTE: The unified-search integration is provided by a sibling capability. `BulkOperationService::bulkReindex()` marks each dashboard dirty by touching `updated_at` and persisting via `DashboardMapper::update()`; the downstream search-indexer pipeline (cron / queue) is expected to pick up the row on its next pass. Per-uuid failures are captured in the `errors` array as `reindex_failed`.

#### Scenario: Re-index three valid dashboards
- GIVEN three dashboards with existing search index entries
- WHEN admin sends POST /api/admin/dashboards/bulk-reindex with body `{"dashboardUuids": ["uuid-1", "uuid-2", "uuid-3"]}`
- THEN the system MUST mark each dashboard dirty for re-indexing
- AND the response MUST return `{reindexedCount: 3, errors: [], dryRun: false}`

#### Scenario: Bulk re-index with one failing dashboard
- GIVEN three dashboards, but the persistence layer rejects the update for "uuid-2"
- WHEN admin sends POST /api/admin/dashboards/bulk-reindex with all three uuids
- THEN the system MUST attempt to re-mark all three
- AND the response MUST return `{reindexedCount: 2, errors: [{uuid: "uuid-2", reason: "reindex_failed", detail: "..."}]}`
- AND the batch MUST continue after the failure (partial success is reported)

#### Scenario: Bulk re-index with insufficient permissions
- GIVEN admin cannot access dashboard "uuid-3"
- WHEN she sends bulk-reindex request with that uuid
- THEN the system MUST return HTTP 403
- AND NO re-indexing MUST occur (all-or-nothing permission check)

#### Scenario: Dry-run bulk re-index
- GIVEN admin sends POST /api/admin/dashboards/bulk-reindex?dryRun=true with three valid uuids
- THEN the system MUST NOT touch the database
- AND the response MUST return `{wouldReindexCount: 3, errors: [], dryRun: true}`

#### Scenario: Re-index request exceeds size cap
- GIVEN cap is 500 dashboards per request
- WHEN admin sends POST /api/admin/dashboards/bulk-reindex with 501 uuids
- THEN the system MUST return HTTP 400
- AND NO re-indexing MUST occur

### Requirement: REQ-BULK-005 Atomicity Per Dashboard, Not Across Batch

Dashboard bulk operations MUST guarantee atomicity at the per-dashboard level (each dashboard's database write is transactional), but NOT across the entire batch. Partial failure is reported and safe.

NOTE: The permission pre-check (REQ-BULK-011) is all-or-nothing and runs before any mutation begins. Database-level atomicity (per-dashboard transactions) only applies after the permission pre-check passes. These are two distinct layers: the permission layer is batch-wide and fail-fast; the execution layer is per-dashboard with continue-on-error semantics.

#### Scenario: One dashboard transaction fails in a batch of three
- GIVEN three dashboards, each in its own database transaction for bulk-delete
- WHEN the transaction for "uuid-2" fails (e.g., foreign key constraint)
- THEN the system MUST commit the transaction for "uuid-1", skip "uuid-2" with a `transaction_failed` error, and attempt "uuid-3"
- AND the response MUST return `{deletedCount: 2, skippedCount: 0, errors: [{uuid: "uuid-2", reason: "transaction_failed", detail: "..."}]}`
- AND "uuid-1" and "uuid-3" MUST be deleted; "uuid-2" MUST NOT be deleted

#### Scenario: Batch not atomic means caller must retry manually
- GIVEN bulk-delete of 10 dashboards, 5 succeed, 5 fail
- WHEN caller receives `{deletedCount: 5, skippedCount: 0, errors: [5 errors]}`
- THEN the caller (admin) MUST be responsible for retrying the 5 failed dashboards
- AND the system MUST NOT offer automatic rollback of the 5 successful deletions

### Requirement: REQ-BULK-006 Request Size Cap (Max 500 Dashboards per Request)

All bulk endpoints MUST enforce a maximum number of `dashboardUuids` per request, configurable via the `bulk_operation_max_per_request` app config key.

#### Scenario: Request within cap is accepted
- GIVEN admin sends bulk-delete with 500 uuids (exactly at cap)
- THEN the system MUST accept the request and proceed
- AND no HTTP 400 error MUST be returned

#### Scenario: Request exceeds cap is rejected
- GIVEN admin sends bulk-delete with 501 uuids (exceeds default cap of 500)
- THEN the system MUST return HTTP 400 with the error message indicating the current cap value
- AND NO mutations MUST occur

#### Scenario: Admin modifies bulk operation cap
- GIVEN admin sets app config `mydash.bulk_operation_max_per_request = 1000` via OCC command or web settings
- WHEN admin sends bulk-delete with 750 uuids
- THEN the system MUST accept the request (750 <= 1000)
- AND the new cap MUST apply immediately to all subsequent requests

#### Scenario: Cap of zero or negative is invalid
- GIVEN admin accidentally sets `mydash.bulk_operation_max_per_request = 0`
- WHEN the system reads the config
- THEN the system MUST apply the safe default (500) as fallback
- AND log a warning that the configured cap is invalid

### Requirement: REQ-BULK-007 Idempotency for Delete, Move, Status Operations

Bulk operations MUST handle idempotent cases gracefully: deleting already-deleted dashboards, moving to the same parent, and setting to the same status result in skip entries in the response, not errors that abort the batch.

#### Scenario: Delete already-deleted dashboard
- GIVEN dashboard "uuid-1" is already deleted (row no longer exists in `oc_mydash_dashboards`)
- WHEN admin sends bulk-delete with that uuid
- THEN the system MUST count it as `skippedCount`
- AND the response MUST include `{uuid: "uuid-1", reason: "already_deleted"}` in the errors array (for auditability)

#### Scenario: Move to same parent
- GIVEN dashboard "child" with `parent_uuid = "parent"`
- WHEN admin sends bulk-move with the same `parent_uuid`
- THEN the system MUST recognise the no-op and skip the update
- AND the response MUST include `{uuid: "child", reason: "parent_already_matches"}` in errors
- AND `movedCount` MUST be 0, `skippedCount` MUST be 1

#### Scenario: Set to same status
- GIVEN dashboard "dash" with `publicationStatus = "published"`
- WHEN admin sends bulk-status with `publicationStatus = "published"`
- THEN the system MUST recognise the no-op and skip the update
- AND the response MUST include `{uuid: "dash", reason: "status_already_matches"}` in errors
- AND `updatedCount` MUST be 0, `skippedCount` MUST be 1

### Requirement: REQ-BULK-008 Dry-Run Mode for Preview Without Mutation

All bulk endpoints MUST support `?dryRun=true` (or `dryRun: true` in the body), which returns predicted results without performing any database mutations.

#### Scenario: Dry-run returns wouldX counts instead of X counts
- GIVEN admin sends POST /api/admin/dashboards/bulk-delete?dryRun=true with three valid uuids
- THEN the system MUST validate permissions, size, and idempotency logic
- AND the response MUST return `{wouldDeleteCount: 3, wouldSkipCount: 0, errors: [], dryRun: true}`
- AND no database mutations MUST occur

#### Scenario: Dry-run validation is identical to real run
- GIVEN admin sends bulk-delete with one unauthorised dashboard
- WHEN she sends with `?dryRun=true`
- THEN the system MUST still return HTTP 403 (validation is the same)
- AND no mutations MUST occur

#### Scenario: dryRun defaults to false
- GIVEN admin sends POST /api/admin/dashboards/bulk-delete without `dryRun` parameter
- THEN the system MUST treat it as `dryRun=false` (real mutations occur)

### Requirement: REQ-BULK-009 Single Audit Event Per Bulk Operation

Each bulk operation MUST emit exactly ONE Activity event per request via `ActivityPublisher::publish()`, with the operation type, dashboard count, duration in milliseconds, and `dryRun` flag in the event parameters.

NOTE: Per-dashboard Activity events are intentionally NOT emitted by the bulk pipeline — they would spam the activity log and make audit-time review unusable. The single summary event preserves the audit trail without the noise. Activity emission failures MUST NOT roll back the bulk operation: `ActivityPublisher` swallows and logs.

#### Scenario: Bulk delete emits one activity event
- GIVEN admin performs bulk-delete of 10 dashboards
- WHEN the operation completes
- THEN the system MUST emit exactly ONE Activity event (not 10)
- AND the event MUST contain `bulkOperation`, `dashboardCount`, `durationMs`, and `dryRun` parameters

#### Scenario: Activity event includes operation duration
- GIVEN admin sends bulk-move request
- WHEN the operation completes
- THEN the activity event MUST include `durationMs` measured from `microtime(true)` start
- AND admins can use this to monitor performance trends

#### Scenario: Dry-run also emits audit event
- GIVEN admin sends bulk-delete?dryRun=true with 10 uuids
- WHEN the dry-run completes
- THEN the system MUST still emit ONE activity event
- AND the activity payload MUST indicate it was a dry-run (`dryRun: true`)

### Requirement: REQ-BULK-010 Frontend Multi-Select Checkbox Column and Actions Dropdown

The admin dashboard list view MUST provide a multi-select checkbox interface and an Actions dropdown for bulk operations. Implemented in `src/components/admin/DashboardBulkOperations.vue`, mounted from `src/components/admin/AdminSettings.vue`.

#### Scenario: Administrator selects multiple dashboards via checkboxes
- GIVEN the admin dashboard list view is loaded with N dashboards
- WHEN the admin clicks the checkbox in the first column header
- THEN all dashboard rows MUST be selected (checkmarks visible)
- AND clicking an individual dashboard row's checkbox MUST toggle only that row

#### Scenario: Actions dropdown enabled only when rows are selected
- GIVEN the admin dashboard list view with 0 dashboards selected
- THEN the Actions dropdown MUST be disabled
- AND clicking on a dashboard row MUST enable the dropdown
- AND the dropdown MUST show 4 options: Delete, Move to..., Set status, Reindex

#### Scenario: Each action shows a confirmation modal with dry-run toggle
- GIVEN admin selects N dashboards and picks an action
- WHEN the confirmation modal opens
- THEN a "Dry run (preview only)" checkbox MUST be visible (unchecked by default)
- AND clicking OK MUST call the matching bulk endpoint with the chosen options
- AND the modal MUST surface the per-uuid result summary returned by the backend

#### Scenario: Response summary after action completes
- GIVEN admin performs a bulk action of N dashboards
- WHEN the response is received
- THEN a summary line MUST display the changed and skipped counts
- AND any per-uuid errors MUST be enumerated
- AND the dashboard list MUST refresh (and the selection cleared) after a successful (non-dry-run) operation

#### Scenario: Dry-run response shows would-counts, no list refresh
- GIVEN admin performs a bulk action with `dryRun=true`
- WHEN the response is received
- THEN the summary MUST display "PREVIEW: would change N dashboards."
- AND the dashboard list MUST NOT refresh (no mutations occurred)

### Requirement: REQ-BULK-011 All-or-Nothing Permission Enforcement

Every bulk endpoint MUST enforce an all-or-nothing permission model: if the calling user is not a Nextcloud admin, or if they lack permission to operate on ANY dashboard in the batch, the entire request is rejected with HTTP 403 and NO mutations occur.

#### Scenario: Admin has full permission on all dashboards
- GIVEN admin user "alice" is a Nextcloud admin
- WHEN she sends bulk-delete request with 5 uuids she can act on
- THEN the system MUST proceed with mutations and return HTTP 200

#### Scenario: Admin lacks permission on one dashboard
- GIVEN admin user "alice" cannot act on dashboard "uuid-3"
- WHEN she sends bulk-delete request with 5 uuids including "uuid-3"
- THEN the system MUST return HTTP 403 with the offending UUID(s) under `deniedUuids`
- AND NO dashboards MUST be deleted (even those that would have been authorised)

#### Scenario: Non-admin user cannot call bulk endpoints
- GIVEN user "bob" with no admin privileges
- WHEN he sends POST /api/admin/dashboards/bulk-delete
- THEN the system MUST return HTTP 403 with `Administrator privileges required.`

#### Scenario: Permission check happens before size validation
- GIVEN admin user with permission denied on one dashboard in a batch of 501 dashboards
- WHEN she sends the bulk-delete request
- THEN the system MUST check permissions first and return HTTP 403
- AND the size cap validation is not performed (fail-fast on permission)

#### Scenario: Dry-run respects same permission model
- GIVEN admin user lacks permission on one dashboard
- WHEN she sends bulk-delete?dryRun=true including that uuid
- THEN the system MUST return HTTP 403 (same validation as a real run)

## Implementation Notes

- `BulkOperationService` (`lib/Service/BulkOperationService.php`) owns the orchestration: per-uuid idempotency, all-or-nothing permission pre-check, request size cap, dry-run isolation, and audit event emission.
- `PermissionDeniedException` (`lib/Service/PermissionDeniedException.php`) carries the offending UUID list back to the controller for the 403 envelope.
- `AdminBulkController` (`lib/Controller/AdminBulkController.php`) exposes the four POST endpoints and maps `PermissionDeniedException` → 403, `InvalidArgumentException` → 400, success → 200.
- `applyStatusChange()` is a private helper extracted from `bulkStatus()` to keep cyclomatic complexity below the project's PHPMD threshold of 15.
- `countKey()` is a private helper that picks the correct payload key (`deletedCount` vs `wouldDeleteCount`, etc.) based on `$dryRun` — extracted because the project's PHPCS rules disallow inline ternaries.
- Permission resolution delegates to `PermissionService::resolveAccessLevel()` for each dashboard; `IGroupManager::isAdmin()` is the short-circuit for the "non-admin caller" rejection.
- Cycle detection on bulk-move delegates to `DashboardTreeService::validateParent()` — the same single source of truth used by single-dashboard updates (REQ-DASH-028).
- Cascade-delete on bulk-delete delegates to `DashboardTreeService::deleteSubtree()` (REQ-DASH-030) — the BFS walker handles the placement-cascade and transaction wrapping.
- Audit events use `ActivityPublisher::publish()` with `Extension::EVENT_UPDATED` and a synthetic `bulk-{op}` UUID; per-uuid Activity rows are intentionally not emitted (REQ-BULK-009).
- The frontend client in `src/services/api.js` exposes `bulkDeleteDashboards`, `bulkMoveDashboards`, `bulkStatusDashboards`, and `bulkReindexDashboards`. The Vue component `DashboardBulkOperations.vue` is mounted into the admin settings page next to the existing Export/Import section.
