# Tasks — dashboard-bulk-operations

## 1. Service layer — Bulk Operations Orchestration

- [ ] 1.1 Create `lib/Service/BulkOperationService.php` with dependencies: `DashboardMapper`, `PermissionService`, `IConfig`, `DashboardTreeService` (cycle check), `DashboardStatusService` (status enum validation), `ILogger`
- [ ] 1.2 Add `BulkOperationService::bulkDelete(array $dashboardUuids, string $userId, bool $dryRun = false): array` — main orchestration
  - [ ] 1.2a Validate request size: if count($dashboardUuids) > config('mydash.bulk_operation_max_per_request', 500), return HTTP 400 error
  - [ ] 1.2b Check permissions: call `PermissionService::canDeleteDashboard($userId, $uuid)` for EACH uuid; if any fail, return 403 immediately (no mutations)
  - [ ] 1.2c Iterate each uuid, call mapper's soft-delete (or mark isDeleted flag if schema supports); collect `deletedCount`, `skippedCount`, per-uuid errors
  - [ ] 1.2d For already-deleted dashboard: catch exception, count as `skippedCount`, add to errors array with reason "already_deleted"
  - [ ] 1.2e If `dryRun=true`, build response with `wouldDeleteCount`, `wouldSkipCount` and NO mutations
  - [ ] 1.2f Return `{deletedCount: N, skippedCount: M, errors: [{uuid, reason}]}`
- [ ] 1.3 Add `BulkOperationService::bulkMove(array $dashboardUuids, ?string $parentUuid, string $userId, bool $dryRun = false): array`
  - [ ] 1.3a Validate request size (same as bulk-delete)
  - [ ] 1.3b Check permissions on all dashboards (all-or-nothing)
  - [ ] 1.3c If `parentUuid` is not null, validate that it is a valid dashboard uuid and exists
  - [ ] 1.3d For EACH uuid, check if new parent IS current parent; if so, count as `skippedCount`, no-op
  - [ ] 1.3e For EACH uuid, call `DashboardTreeService::validateNoCycle($uuid, $parentUuid)` to check for cycles; if cycle detected, add to errors with reason "cycle_detected"
  - [ ] 1.3f Update parent via mapper; collect `movedCount`, `skippedCount`, errors
  - [ ] 1.3g If `dryRun=true`, return `wouldMoveCount` and NO mutations
  - [ ] 1.3h Return `{movedCount: N, skippedCount: M, errors: [{uuid, reason, detail?}]}`
- [ ] 1.4 Add `BulkOperationService::bulkStatus(array $dashboardUuids, string $publicationStatus, ?string $publishAt, string $userId, bool $dryRun = false): array`
  - [ ] 1.4a Validate request size
  - [ ] 1.4b Check permissions on all dashboards (all-or-nothing)
  - [ ] 1.4c Validate `$publicationStatus` is one of 'draft', 'published', 'scheduled' via `DashboardStatusService::validateStatus()`
  - [ ] 1.4d If `$publicationStatus === 'scheduled'` and `$publishAt` is null, return error (required for scheduled)
  - [ ] 1.4e For EACH uuid, check if current status IS target status; if so, count as `skippedCount`, no-op
  - [ ] 1.4f Update status via mapper; collect `updatedCount`, `skippedCount`, errors
  - [ ] 1.4g If `dryRun=true`, return `wouldUpdateCount` and NO mutations
  - [ ] 1.4h Return `{updatedCount: N, skippedCount: M, errors: [{uuid, reason}]}`
- [ ] 1.5 Add `BulkOperationService::bulkReindex(array $dashboardUuids, string $userId, bool $dryRun = false): array`
  - [ ] 1.5a Validate request size
  - [ ] 1.5b Check permissions on all dashboards (all-or-nothing)
  - [ ] 1.5c For EACH uuid, call search provider's reindex method (delegate to `nc-unified-search-integration` capability)
  - [ ] 1.5d Collect `reindexedCount` and errors (if reindex fails for specific uuid, log but continue batch)
  - [ ] 1.5e If `dryRun=true`, return `wouldReindexCount` and NO mutations
  - [ ] 1.5f Return `{reindexedCount: N, errors: [{uuid, reason}]}`

## 2. Controller + routes

- [ ] 2.1 Create or extend `lib/Controller/AdminBulkController.php` extending `ApiController` (or extend existing `AdminController.php`)
- [ ] 2.2 Add `AdminBulkController::bulkDelete()` mapped to `POST /api/admin/dashboards/bulk-delete` (`#[AdminRequired]`)
  - [ ] 2.2a Extract body `dashboardUuids` (array) and query param `dryRun` (bool, default false)
  - [ ] 2.2b Call `BulkOperationService::bulkDelete($dashboardUuids, $this->userId, $dryRun)`
  - [ ] 2.2c Return HTTP 200 with response JSON
  - [ ] 2.2d If request size exceeds limit, catch HTTP 400 from service and re-throw with appropriate message
- [ ] 2.3 Add `AdminBulkController::bulkMove()` mapped to `POST /api/admin/dashboards/bulk-move` (`#[AdminRequired]`)
  - [ ] 2.3a Extract body `dashboardUuids`, `parentUuid` (nullable string)
  - [ ] 2.3b Call `BulkOperationService::bulkMove($dashboardUuids, $parentUuid, $this->userId, $dryRun)`
  - [ ] 2.3c Return HTTP 200 with response JSON
- [ ] 2.4 Add `AdminBulkController::bulkStatus()` mapped to `POST /api/admin/dashboards/bulk-status` (`#[AdminRequired]`)
  - [ ] 2.4a Extract body `dashboardUuids`, `publicationStatus`, optional `publishAt` (ISO string)
  - [ ] 2.4b Call `BulkOperationService::bulkStatus($dashboardUuids, $publicationStatus, $publishAt, $this->userId, $dryRun)`
  - [ ] 2.4c Return HTTP 200 with response JSON
- [ ] 2.5 Add `AdminBulkController::bulkReindex()` mapped to `POST /api/admin/dashboards/bulk-reindex` (`#[AdminRequired]`)
  - [ ] 2.5a Extract body `dashboardUuids`
  - [ ] 2.5b Call `BulkOperationService::bulkReindex($dashboardUuids, $this->userId, $dryRun)`
  - [ ] 2.5c Return HTTP 200 with response JSON
- [ ] 2.6 Register all four routes in `appinfo/routes.php` with correct requirements and methods
- [ ] 2.7 Confirm methods carry `#[AdminRequired]` attribute (or equivalent permission check)
- [ ] 2.8 All endpoints MUST return 403 with descriptive error if any dashboard is unauthorized (fail-safe)

## 3. Activity & Audit

- [ ] 3.1 Create `lib/Activity/BulkOperationActivityProvider.php` implementing Nextcloud Activity provider (if not already present)
- [ ] 3.2 Add helper in `BulkOperationService::emitAuditEvent(string $operation, int $dashboardCount, string $userId, int $durationMs): void`
  - [ ] 3.2a Emit single Nextcloud Activity event with type `dashboard_bulk_operation`
  - [ ] 3.2b Activity data: `{operation: 'delete'|'move'|'status'|'reindex', dashboardCount: N, byUserId: "...", durationMs: ...}`
  - [ ] 3.2c Use high-resolution timer (microtime) to measure operation duration
- [ ] 3.3 Call `emitAuditEvent()` at the END of each bulk operation (after all mutations complete or after dry-run)
- [ ] 3.4 Log operation start, errors, and completion to `ILogger` for troubleshooting

## 4. Frontend — Multi-select Dashboard List

- [ ] 4.1 Extend `src/views/AdminDashboardList.vue` to add multi-select checkbox column
  - [ ] 4.1a Add `<th><input type="checkbox" @change="toggleSelectAll" v-model="allSelected"></th>` at start of table header
  - [ ] 4.1b Add `<td><input type="checkbox" v-model="selectedUuids" :value="dashboard.uuid"></td>` in each row
  - [ ] 4.1c Track `selectedUuids` as array of selected uuids in component data
  - [ ] 4.1d Track `allSelected` as computed property (true if selectedUuids.length === dashboards.length)
- [ ] 4.2 Add "Actions" dropdown button, enabled only when `selectedUuids.length >= 1`
  - [ ] 4.2a Options: "Delete", "Move to...", "Set status", "Reindex"
  - [ ] 4.2b Show visual feedback when dropdown is open (e.g., highlight selected rows)
- [ ] 4.3 Implement "Delete" action
  - [ ] 4.3a Show confirmation modal: "Delete N dashboards? This cannot be undone."
  - [ ] 4.3b Add toggle for "Dry run (preview only)"
  - [ ] 4.3c POST /api/admin/dashboards/bulk-delete with `{dashboardUuids: [...], dryRun: boolean}`
  - [ ] 4.3d On 200 response, show summary: "Deleted N, skipped M. Errors: ..."
  - [ ] 4.3e If dryRun, show "Would delete N, would skip M" (no actual deletion)
  - [ ] 4.3f Refresh dashboard list after successful (non-dry-run) operation
- [ ] 4.4 Implement "Move to..." action
  - [ ] 4.4a Show modal with hierarchical parent selection (tree picker)
  - [ ] 4.4b Add dry-run toggle
  - [ ] 4.4c POST /api/admin/dashboards/bulk-move with `{dashboardUuids: [...], parentUuid: null|uuid, dryRun: boolean}`
  - [ ] 4.4d On 200 response, show summary: "Moved N, skipped M. Errors: ..."
  - [ ] 4.4e If dryRun, show "Would move N, would skip M"
  - [ ] 4.4f Refresh list after successful operation
- [ ] 4.5 Implement "Set status" action
  - [ ] 4.5a Show modal with status selector: Draft / Published / Scheduled
  - [ ] 4.5b If Scheduled selected, show date/time picker for `publishAt`
  - [ ] 4.5c Add dry-run toggle
  - [ ] 4.5d POST /api/admin/dashboards/bulk-status with `{dashboardUuids: [...], publicationStatus, publishAt, dryRun: boolean}`
  - [ ] 4.5e On 200 response, show summary
  - [ ] 4.5f Refresh list after successful operation
- [ ] 4.6 Implement "Reindex" action
  - [ ] 4.6a Show simple confirmation modal: "Reindex N dashboards for search?"
  - [ ] 4.6b Add dry-run toggle
  - [ ] 4.6c POST /api/admin/dashboards/bulk-reindex with `{dashboardUuids: [...], dryRun: boolean}`
  - [ ] 4.6d On 200 response, show summary: "Reindexed N. Errors: ..."
  - [ ] 4.6e Show loading state during operation (disable actions, show spinner)
- [ ] 4.7 Clear selection after each successful operation

## 5. Configuration

- [ ] 5.1 Add default config entry for `mydash.bulk_operation_max_per_request = 500` in app initialization (e.g., `AppConfig` or `config.php`)
- [ ] 5.2 Document admin-tunable setting in comments (admins can override via OCC command or web config)

## 6. PHPUnit tests

- [ ] 6.1 `BulkOperationServiceTest::bulkDelete` — valid batch, already-deleted skip, permissions denied on one uuid (403), request size exceeds limit (400), dryRun returns `would*` counts
- [ ] 6.2 `BulkOperationServiceTest::bulkMove` — valid batch, cycle detection, parent IS current parent (skip), dryRun, permissions denied
- [ ] 6.3 `BulkOperationServiceTest::bulkStatus` — valid batch, status IS current status (skip), invalid status enum (400), scheduled without publishAt (400), dryRun
- [ ] 6.4 `BulkOperationServiceTest::bulkReindex` — valid batch, reindex failure on one uuid (error reported, batch continues), dryRun
- [ ] 6.5 `AdminBulkControllerTest::bulkDelete` — valid request returns 200, invalid size returns 400, permission denied returns 403
- [ ] 6.6 `AdminBulkControllerTest::bulkMove` — valid request, cycle error in response, dryRun flag respected
- [ ] 6.7 `AdminBulkControllerTest::bulkStatus` — valid request, status validation, dryRun
- [ ] 6.8 `AdminBulkControllerTest::bulkReindex` — valid request, error handling
- [ ] 6.9 Test audit event emission: verify `emitAuditEvent()` is called once per operation with correct parameters
- [ ] 6.10 Test atomicity per-dashboard: one dashboard fails, batch continues, failure reported in errors array

## 7. Integration tests (E2E)

- [ ] 7.1 Create fixtures: 5 dashboards with varying permissions, 3 different parents (tree structure)
- [ ] 7.2 Test bulk-delete: admin deletes 3 dashboards, verifies 3 deleted + 0 skipped, list refreshes
- [ ] 7.3 Test bulk-move: move 3 dashboards under new parent, verify parent_uuid updated, cycle check prevents circular move
- [ ] 7.4 Test bulk-status: publish 3 draft dashboards, verify status updated, schedule 2 for future date
- [ ] 7.5 Test bulk-reindex: reindex 3 dashboards, verify no errors (assume search provider is mocked/available)
- [ ] 7.6 Test permissions: non-admin user calls bulk endpoint, receives 403
- [ ] 7.7 Test dry-run: call with `dryRun=true`, verify no mutations occur, counts are predicted
- [ ] 7.8 Test request size cap: send 501 uuids, receive HTTP 400
- [ ] 7.9 Test partial failure: batch with one unauthorized dashboard, verify entire batch rejected (403) and no mutations

## 8. Documentation

- [ ] 8.1 Add to `CHANGELOG.md`: "New capability `dashboard-bulk-operations`: admin endpoints for bulk delete, move, status update, and reindex of dashboards; dry-run support and atomic-per-dashboard semantics"
- [ ] 8.2 Document four endpoints in app API docs (if such docs exist) or in new BULK_API.md file
  - [ ] 8.2a POST /api/admin/dashboards/bulk-delete
  - [ ] 8.2b POST /api/admin/dashboards/bulk-move
  - [ ] 8.2c POST /api/admin/dashboards/bulk-status
  - [ ] 8.2d POST /api/admin/dashboards/bulk-reindex
- [ ] 8.3 Document request/response schemas, error codes, dry-run behavior, and audit trail
- [ ] 8.4 Document permission model (all-or-nothing, admin-only endpoints)

