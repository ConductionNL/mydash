---
status: draft
---

# Dashboard Bulk Operations Specification

## ADDED Requirements

### Requirement: REQ-BULK-001 Bulk Delete Dashboards

Administrators MUST be able to hard-delete multiple dashboards in a single API request, with idempotent handling of already-deleted dashboards. Cascade to child dashboards is opt-in: attempting to delete a parent that has children without `?cascade=true` returns HTTP 409.

NOTE: Delete is a hard delete executed via `$folder->delete()` on the Nextcloud filesystem node. There is no soft-delete flag, no `deleted_at` column, and no grace period. Recovery is only possible through Nextcloud's system-wide trash bin if it is enabled on the installation. Soft-delete is deferred to a future capability.

NOTE: The `cascade` parameter defaults to `false` to prevent accidental recursive deletion of child dashboards. This deliberately diverges from the source implementation (which defaults to `true`) and aligns with the `dashboard-tree` capability's existing opt-in cascade convention (REQ-DASH-016 area).

#### Scenario: Delete three valid dashboards
- GIVEN an admin user with dashboard-admin or full-admin permissions
- WHEN she sends POST /api/admin/dashboards/bulk-delete with body `{"dashboardUuids": ["uuid-1", "uuid-2", "uuid-3"]}`
- THEN the system MUST hard-delete each dashboard by calling `delete()` on the Nextcloud filesystem node for each dashboard
- AND the response MUST return HTTP 200 with `{deletedCount: 3, skippedCount: 0, errors: []}`
- AND the deleted dashboards MUST NOT appear in GET /api/dashboards list for any user
- AND no Nextcloud Activity event for each individual dashboard deletion — only ONE bulk operation event MUST be emitted

#### Scenario: Bulk delete with one already-deleted dashboard
- GIVEN admin has permission to delete all three dashboards, but "uuid-2" is already deleted
- WHEN she sends the same bulk-delete request
- THEN the system MUST skip the already-deleted dashboard (hard delete on a non-existent row is absorbed silently)
- AND the response MUST return `{deletedCount: 2, skippedCount: 1, errors: [{uuid: "uuid-2", reason: "already_deleted"}]}`

#### Scenario: Bulk delete parent with children and cascade=false (default)
- GIVEN dashboard "parent-uuid" has 5 child dashboards
- WHEN admin sends POST /api/admin/dashboards/bulk-delete with body `{"dashboardUuids": ["parent-uuid"]}` (no `?cascade=true`)
- THEN the system MUST return HTTP 409 (Conflict)
- AND the response body MUST contain `{error: "has_children", childCount: 5}`
- AND NO mutations MUST occur — the parent and all children MUST remain intact

#### Scenario: Bulk delete parent with children and cascade=true (opt-in)
- GIVEN dashboard "parent-uuid" has 5 child dashboards
- WHEN admin sends POST /api/admin/dashboards/bulk-delete?cascade=true with body `{"dashboardUuids": ["parent-uuid"]}`
- THEN the system MUST hard-delete the parent and all 5 children recursively
- AND the response MUST return HTTP 200 with `{deletedCount: 6, skippedCount: 0, errors: []}`
- AND none of the 6 dashboards MUST appear in GET /api/dashboards list for any user

#### Scenario: Bulk delete with insufficient permissions
- GIVEN admin user "alice" but "uuid-3" belongs to a private dashboard that "alice" cannot delete
- WHEN she sends POST /api/admin/dashboards/bulk-delete with all three uuids
- THEN the system MUST return HTTP 403 (Forbidden)
- AND NO mutations MUST occur (all-or-nothing permission check)
- AND error response MUST indicate which uuid(s) caused the permission denial

#### Scenario: Bulk delete request exceeds size cap
- GIVEN the configured cap is 500 dashboards per request
- WHEN admin sends POST /api/admin/dashboards/bulk-delete with 501 uuids
- THEN the system MUST return HTTP 400 with error message "Request contains 501 dashboards; maximum is 500"
- AND NO mutations MUST occur

#### Scenario: Dry-run bulk delete
- GIVEN admin sends POST /api/admin/dashboards/bulk-delete?dryRun=true with three valid uuids
- THEN the system MUST NOT hard-delete any dashboard
- AND the response MUST return `{wouldDeleteCount: 3, wouldSkipCount: 0, errors: []}`
- AND GET /api/dashboards MUST still list all three dashboards

### Requirement: REQ-BULK-002 Bulk Move Dashboards in Tree

Administrators MUST be able to re-parent multiple dashboards in the dashboard hierarchy, with cycle detection and idempotent handling of no-op moves.

#### Scenario: Move three dashboards under a new parent
- GIVEN three dashboards "child-1", "child-2", "child-3" currently under parent "old-parent"
- WHEN admin sends POST /api/admin/dashboards/bulk-move with body `{"dashboardUuids": ["child-1", "child-2", "child-3"], "parentUuid": "new-parent"}`
- THEN the system MUST update each dashboard's parent_uuid to "new-parent"
- AND the response MUST return HTTP 200 with `{movedCount: 3, skippedCount: 0, errors: []}`
- AND GET /api/dashboards/{uuid} MUST show the new parent for each dashboard

#### Scenario: Move dashboards to root (null parent)
- GIVEN three dashboards currently under parent "some-parent"
- WHEN admin sends POST /api/admin/dashboards/bulk-move with body `{"dashboardUuids": [...], "parentUuid": null}`
- THEN each dashboard MUST be re-parented to root (parent_uuid = null)
- AND the response MUST return `{movedCount: 3, skippedCount: 0, errors: []}`

#### Scenario: Bulk move detects cycle (would create circular parent-child)
- GIVEN dashboard A is parent of B, B is parent of C
- WHEN admin sends POST /api/admin/dashboards/bulk-move with `{"dashboardUuids": ["A"], "parentUuid": "C"}` (trying to make C parent of A)
- THEN the system MUST validate via `dashboard-tree` capability's cycle-check service
- AND the response MUST return HTTP 200 (but with error in batch) `{movedCount: 0, skippedCount: 0, errors: [{uuid: "A", reason: "cycle_detected", detail: "..."}]}`
- AND dashboard A's parent MUST NOT be updated

#### Scenario: Bulk move with no-op (parent already matches target)
- GIVEN dashboard "child" currently under parent "target-parent"
- WHEN admin sends POST /api/admin/dashboards/bulk-move with `{"dashboardUuids": ["child"], "parentUuid": "target-parent"}`
- THEN the system MUST recognize this as a no-op
- AND the response MUST return `{movedCount: 0, skippedCount: 1, errors: [{uuid: "child", reason: "parent_already_matches"}]}`
- AND no database update MUST occur

#### Scenario: Bulk move with insufficient permissions
- GIVEN admin user cannot delete or move dashboard "uuid-2"
- WHEN she sends bulk-move request with that uuid
- THEN the system MUST return HTTP 403
- AND NO mutations MUST occur (all-or-nothing)

#### Scenario: Dry-run bulk move
- GIVEN admin sends POST /api/admin/dashboards/bulk-move?dryRun=true with three valid uuids and new parent
- THEN the system MUST NOT update any parent_uuid
- AND the response MUST return `{wouldMoveCount: 3, wouldSkipCount: 0, errors: []}`
- AND GET /api/dashboards/{uuid} MUST still show the old parent for each dashboard

### Requirement: REQ-BULK-003 Bulk Update Publication Status

Administrators MUST be able to update publication status (draft, published, scheduled) across multiple dashboards, with idempotent handling of dashboards already at target status.

#### Scenario: Publish three draft dashboards
- GIVEN three dashboards with publicationStatus = "draft"
- WHEN admin sends POST /api/admin/dashboards/bulk-status with body `{"dashboardUuids": [...], "publicationStatus": "published"}`
- THEN the system MUST update each dashboard's publicationStatus to "published"
- AND the response MUST return HTTP 200 with `{updatedCount: 3, skippedCount: 0, errors: []}`
- AND the dashboards MUST now appear in public dashboard listings (if applicable)

#### Scenario: Bulk status with idempotency (dashboards already published)
- GIVEN three dashboards already with publicationStatus = "published"
- WHEN admin sends the same bulk-status request to "published"
- THEN the system MUST recognize all three as no-ops
- AND the response MUST return `{updatedCount: 0, skippedCount: 3, errors: [{uuid: "...", reason: "status_already_matches"}, ...]}`
- AND no database updates MUST occur

#### Scenario: Schedule dashboards for future publish date
- GIVEN three draft dashboards
- WHEN admin sends POST /api/admin/dashboards/bulk-status with body `{"dashboardUuids": [...], "publicationStatus": "scheduled", "publishAt": "2026-06-15T10:00:00Z"}`
- THEN the system MUST update publicationStatus to "scheduled" and set publishAt to the provided ISO 8601 timestamp
- AND the response MUST return `{updatedCount: 3, skippedCount: 0, errors: []}`
- AND the dashboards MUST NOT be publicly visible until the scheduled time
- NOTE: The `dashboard-draft-published` capability provides the publishAt scheduler; this bulk endpoint reuses its status validation

#### Scenario: Bulk status to scheduled without publishAt date
- GIVEN admin sends POST /api/admin/dashboards/bulk-status with `{"publicationStatus": "scheduled"}` (no publishAt)
- THEN the system MUST return HTTP 400 with error message "publishAt is required when publicationStatus is 'scheduled'"
- AND NO mutations MUST occur

#### Scenario: Invalid publication status value
- GIVEN admin sends POST /api/admin/dashboards/bulk-status with `{"publicationStatus": "invalid"}`
- THEN the system MUST return HTTP 400 with error message "publicationStatus must be one of: draft, published, scheduled"
- AND NO mutations MUST occur

#### Scenario: Bulk status with insufficient permissions
- GIVEN admin cannot modify status on one dashboard
- WHEN she sends bulk-status request with that uuid
- THEN the system MUST return HTTP 403
- AND NO mutations MUST occur (all-or-nothing)

#### Scenario: Dry-run bulk status
- GIVEN admin sends POST /api/admin/dashboards/bulk-status?dryRun=true with three draft dashboards and target status "published"
- THEN the system MUST NOT update any publicationStatus
- AND the response MUST return `{wouldUpdateCount: 3, wouldSkipCount: 0, errors: []}`
- AND GET /api/dashboards/{uuid} MUST still show publicationStatus = "draft" for each

### Requirement: REQ-BULK-004 Bulk Re-index Dashboards for Search

Administrators MUST be able to re-index multiple dashboards for unified search in a single request, with error reporting for individual re-index failures.

#### Scenario: Re-index three valid dashboards
- GIVEN three dashboards with existing search index entries
- WHEN admin sends POST /api/admin/dashboards/bulk-reindex with body `{"dashboardUuids": ["uuid-1", "uuid-2", "uuid-3"]}`
- THEN the system MUST trigger re-indexing for each dashboard via the `nc-unified-search-integration` capability's search provider
- AND the response MUST return HTTP 200 with `{reindexedCount: 3, errors: []}`

#### Scenario: Bulk re-index with one failing dashboard
- GIVEN three dashboards, but "uuid-2" has a corrupted search index that fails to re-index
- WHEN admin sends POST /api/admin/dashboards/bulk-reindex with all three uuids
- THEN the system MUST attempt to re-index all three
- AND the response MUST return `{reindexedCount: 2, errors: [{uuid: "uuid-2", reason: "reindex_failed", detail: "..."}]}`
- AND the batch MUST continue after the failure (not atomic; partial success is reported)

#### Scenario: Bulk re-index with insufficient permissions
- GIVEN admin cannot access dashboard "uuid-3"
- WHEN she sends bulk-reindex request with that uuid
- THEN the system MUST return HTTP 403
- AND NO re-indexing MUST occur (all-or-nothing permission check)

#### Scenario: Dry-run bulk re-index
- GIVEN admin sends POST /api/admin/dashboards/bulk-reindex?dryRun=true with three valid uuids
- THEN the system MUST NOT invoke the search provider's re-index method
- AND the response MUST return `{wouldReindexCount: 3, errors: []}`

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
- THEN the system MUST commit the transaction for "uuid-1", skip "uuid-2" with error, and attempt "uuid-3"
- AND the response MUST return `{deletedCount: 2, skippedCount: 0, errors: [{uuid: "uuid-2", reason: "transaction_failed"}]}`
- AND "uuid-1" and "uuid-3" MUST be deleted; "uuid-2" MUST NOT be deleted
- AND the caller MUST treat partial success as valid (no automatic rollback)

#### Scenario: Batch not atomic means caller must retry manually
- GIVEN bulk-delete of 10 dashboards, 5 succeed, 5 fail
- WHEN caller receives `{deletedCount: 5, skippedCount: 0, errors: [5 errors]}`
- THEN the caller (admin) MUST be responsible for retrying the 5 failed dashboards
- AND the system MUST NOT offer automatic rollback of the 5 successful deletions
- NOTE: This allows for safe cleanup flows where partial success is acceptable and inspectable

### Requirement: REQ-BULK-006 Request Size Cap (Max 500 Dashboards per Request)

All bulk endpoints MUST enforce a maximum number of dashboardUuids per request, configurable via admin settings.

NOTE: All four bulk endpoints MUST also apply `#[UserRateThrottle(limit: 5, period: 60)]` — five requests per 60-second window per user. This is enforced at the controller layer and complements (does not replace) the per-request size cap.

#### Scenario: Request within cap is accepted
- GIVEN admin sends bulk-delete with 500 uuids (exactly at cap)
- WHEN the system checks request size
- THEN the system MUST accept the request and proceed
- AND no HTTP 400 error MUST be returned

#### Scenario: Request exceeds cap is rejected
- GIVEN admin sends bulk-delete with 501 uuids (exceeds default cap of 500)
- WHEN the system checks request size
- THEN the system MUST return HTTP 400 with error message "Request contains 501 dashboards; maximum is 500 (configured by admin)"
- AND NO mutations MUST occur
- AND the error response MUST indicate the current cap value

#### Scenario: Admin modifies bulk operation cap
- GIVEN admin sets config `mydash.bulk_operation_max_per_request = 1000` via OCC command or web settings
- WHEN admin sends bulk-delete with 750 uuids
- THEN the system MUST accept the request (750 <= 1000)
- AND no HTTP 400 error MUST be returned
- AND the new cap MUST apply immediately to all subsequent requests

#### Scenario: Cap of zero or negative is invalid
- GIVEN admin accidentally sets `mydash.bulk_operation_max_per_request = 0`
- WHEN the system reads the config
- THEN the system MUST apply a safe default (e.g., 500) as fallback
- AND log a warning that the configured cap is invalid

### Requirement: REQ-BULK-007 Idempotency for Delete, Move, Status Operations

Bulk operations MUST handle idempotent cases gracefully: deleting already-deleted dashboards, moving to the same parent, and setting to the same status result in no-op entries in the response, not errors.

#### Scenario: Delete already-deleted dashboard
- GIVEN dashboard "uuid-1" is already deleted (row no longer exists in `oc_mydash_dashboards`)
- WHEN admin sends bulk-delete with that uuid
- THEN the system MUST NOT return an error, but rather count it as `skippedCount` (hard delete on a non-existent row is absorbed silently)
- AND the response MUST include `{uuid: "uuid-1", reason: "already_deleted"}` in the errors array (for auditability, not rejection)

#### Scenario: Move to same parent
- GIVEN dashboard "child" with parent_uuid = "parent"
- WHEN admin sends bulk-move with the same parent_uuid
- THEN the system MUST recognize the no-op and skip the update
- AND the response MUST include `{uuid: "child", reason: "parent_already_matches"}` in errors
- AND `movedCount` MUST be 0, `skippedCount` MUST be 1

#### Scenario: Set to same status
- GIVEN dashboard "dash" with publicationStatus = "published"
- WHEN admin sends bulk-status with publicationStatus = "published"
- THEN the system MUST recognize the no-op and skip the update
- AND the response MUST include `{uuid: "dash", reason: "status_already_matches"}` in errors
- AND `updatedCount` MUST be 0, `skippedCount` MUST be 1
- NOTE: This reduces unnecessary database writes and log spam

### Requirement: REQ-BULK-008 Dry-Run Mode for Preview Without Mutation

All bulk endpoints MUST support `?dryRun=true` query parameter, which returns predicted results without performing any database mutations.

#### Scenario: Dry-run returns wouldX counts instead of X counts
- GIVEN admin sends POST /api/admin/dashboards/bulk-delete?dryRun=true with three valid uuids
- THEN the system MUST validate permissions, size, and idempotency logic
- AND the response MUST return `{wouldDeleteCount: 3, wouldSkipCount: 0, errors: []}`
- AND no database mutations MUST occur
- AND GET /api/dashboards MUST still list all three dashboards

#### Scenario: Dry-run validation is identical to real run
- GIVEN admin sends bulk-delete with one unauthorized dashboard
- WHEN she sends with `?dryRun=true`
- THEN the system MUST still return HTTP 403 (validation is the same)
- AND no mutations MUST occur (as expected)

#### Scenario: Multiple dry-run calls return consistent results
- GIVEN admin sends bulk-delete?dryRun=true twice with the same three uuids
- THEN both responses MUST have identical `wouldDeleteCount`, `wouldSkipCount`, and `errors`
- AND no side effects MUST occur on either call

#### Scenario: dryRun defaults to false
- GIVEN admin sends POST /api/admin/dashboards/bulk-delete without ?dryRun parameter
- WHEN the system processes the request
- THEN the system MUST treat it as `dryRun=false` (real mutations occur)
- AND the response MUST use real counts (deletedCount, not wouldDeleteCount)

### Requirement: REQ-BULK-009 Single Audit Event Per Bulk Operation

Each bulk operation MUST emit exactly ONE Nextcloud Activity event (of type `dashboard_bulk_operation`), not per-dashboard events, to avoid log spam and simplify auditing.

#### Scenario: Bulk delete emits one activity event
- GIVEN admin performs bulk-delete of 10 dashboards
- WHEN the operation completes
- THEN the system MUST emit exactly ONE Nextcloud Activity event (not 10)
- AND the activity event MUST contain: `{operation: "delete", dashboardCount: 10, byUserId: "admin-user-id", durationMs: ...}`
- AND subsequent calls to Activity API MUST show this single event (not 10 per-dashboard events)

#### Scenario: Activity event includes operation duration
- GIVEN admin sends bulk-move request at 2026-05-01T10:00:00Z
- WHEN the operation completes at 2026-05-01T10:00:02.500Z
- THEN the activity event MUST include `durationMs: 2500` (or similar high-resolution timing)
- AND admins can use this to monitor performance trends

#### Scenario: Dry-run also emits audit event
- GIVEN admin sends bulk-delete?dryRun=true with 10 uuids
- WHEN the dry-run completes
- THEN the system MUST still emit ONE activity event
- AND the activity payload MUST indicate it was a dry-run (or include a `dryRun: true` flag)

#### Scenario: Failed bulk operation still emits activity event
- GIVEN bulk-delete fails due to permission denied on one dashboard
- WHEN the system returns HTTP 403
- THEN the system MUST still emit ONE activity event (for auditability)
- AND the event MUST indicate the reason for failure (e.g., `reason: "permission_denied"`)

NOTE: Audit events MUST be emitted for ALL terminal outcomes: successful completion, dry-run completion (with `dryRun: true` in the payload), and rejected requests (with the failure reason). No bulk operation outcome should be silent from an audit perspective.

### Requirement: REQ-BULK-010 Frontend Multi-Select Checkbox Column and Actions Dropdown

The admin dashboard list view MUST provide a multi-select checkbox interface and an Actions dropdown for bulk operations.

#### Scenario: Administrator selects multiple dashboards via checkboxes
- GIVEN the admin dashboard list view is loaded with 5 dashboards
- WHEN the admin clicks the checkbox in the first column header
- THEN all dashboard rows MUST be selected (checkmarks visible)
- AND clicking an individual dashboard row's checkbox MUST deselect only that row
- AND the header checkbox state MUST reflect the current selection (partial if some rows selected)

#### Scenario: Actions dropdown appears only when rows are selected
- GIVEN the admin dashboard list view with 0 dashboards selected
- WHEN the Actions dropdown is visible
- THEN the dropdown MUST be disabled (greyed out, not clickable)
- AND clicking on a dashboard row MUST enable the dropdown
- AND the dropdown MUST show 4 options: Delete, Move to..., Set status, Reindex

#### Scenario: Delete action shows confirmation modal with dry-run toggle
- GIVEN admin selects 3 dashboards and clicks Actions > Delete
- WHEN the Delete confirmation modal opens
- THEN the modal MUST show: "Delete 3 dashboards? This action cannot be undone."
- AND a checkbox for "Dry run (preview only)" MUST be visible (unchecked by default)
- AND an OK and Cancel button MUST be present
- AND clicking OK MUST call POST /api/admin/dashboards/bulk-delete with dryRun flag

#### Scenario: Move action shows hierarchical parent selector
- GIVEN admin selects 3 dashboards and clicks Actions > Move to...
- WHEN the Move modal opens
- THEN a tree picker MUST be shown with all valid parent dashboards
- AND an option for "Root (no parent)" MUST be available
- AND a checkbox for "Dry run (preview only)" MUST be visible
- AND clicking OK MUST call POST /api/admin/dashboards/bulk-move with selected parent

#### Scenario: Set status action shows status and date selector
- GIVEN admin selects 3 dashboards and clicks Actions > Set status
- WHEN the Set status modal opens
- THEN radio buttons for Draft, Published, Scheduled MUST be visible
- AND selecting Scheduled MUST reveal a date/time picker for publishAt
- AND a checkbox for "Dry run (preview only)" MUST be visible
- AND clicking OK MUST call POST /api/admin/dashboards/bulk-status with chosen status and date

#### Scenario: Reindex action shows simple confirmation
- GIVEN admin selects 3 dashboards and clicks Actions > Reindex
- WHEN the Reindex confirmation modal opens
- THEN the modal MUST show: "Re-index 3 dashboards for search?"
- AND a checkbox for "Dry run (preview only)" MUST be visible
- AND clicking OK MUST call POST /api/admin/dashboards/bulk-reindex

#### Scenario: Response summary after action completes
- GIVEN admin performs bulk-delete of 3 dashboards
- WHEN the response is received with `{deletedCount: 2, skippedCount: 1, errors: [...]}`
- THEN a summary toast or modal MUST display: "Deleted 2 dashboards. Skipped 1. Errors: ..."
- AND the dashboard list MUST refresh to reflect deletions (if not a dry-run)
- AND the checkboxes MUST be cleared after the action

#### Scenario: Dry-run response shows would-counts, no list refresh
- GIVEN admin performs bulk-delete?dryRun=true of 3 dashboards
- WHEN the response is received with `{wouldDeleteCount: 2, wouldSkipCount: 1, errors: []}`
- THEN a summary MUST display: "PREVIEW: Would delete 2 dashboards. Would skip 1."
- AND the dashboard list MUST NOT refresh (no mutations occurred)
- AND the checkboxes MUST remain selected (allowing further previews or real action)

### Requirement: REQ-BULK-011 All-or-Nothing Permission Enforcement

Every bulk endpoint MUST enforce an all-or-nothing permission model: if the calling user lacks permission to operate on ANY dashboard in the batch, the entire batch is rejected with HTTP 403 and NO mutations occur.

#### Scenario: Admin has full permission on all dashboards
- GIVEN admin user "alice" is dashboard-admin or full-admin
- WHEN she sends bulk-delete request with 5 uuids
- THEN the system MUST check permission on each of the 5 dashboards
- AND if all 5 are authorized, the request proceeds and mutations occur
- AND no 403 error MUST be returned

#### Scenario: Admin lacks permission on one dashboard
- GIVEN admin user "alice" is dashboard-admin but cannot delete dashboard "uuid-3" (e.g., owned by another admin group)
- WHEN she sends bulk-delete request with 5 uuids including "uuid-3"
- THEN the system MUST return HTTP 403 immediately after detecting the unauthorized dashboard
- AND NO dashboards MUST be deleted (even if "uuid-1" and "uuid-2" were authorized)
- AND the error response MUST indicate which uuid(s) caused the permission denial

#### Scenario: Non-admin user cannot call bulk endpoints
- GIVEN user "bob" with no admin privileges
- WHEN he sends POST /api/admin/dashboards/bulk-delete (even if he owns the dashboards)
- THEN the system MUST return HTTP 403 (bulk endpoints require admin role)
- AND the error MUST indicate insufficient privileges

#### Scenario: Permission check happens before size validation
- GIVEN admin user with permission denied on one dashboard in a batch of 501 dashboards (exceeds cap)
- WHEN she sends the bulk-delete request
- THEN the system MUST check permission first and return HTTP 403 (permission failure has higher priority)
- AND the size cap validation is not performed (fail-fast on permission)

#### Scenario: Dry-run respects same permission model
- GIVEN admin user lacks permission on one dashboard
- WHEN she sends bulk-delete?dryRun=true including that uuid
- THEN the system MUST return HTTP 403 (same validation, even for dry-run)
- AND no mutations occur (as expected for dry-run)

