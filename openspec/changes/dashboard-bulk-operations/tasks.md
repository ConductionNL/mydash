# Tasks — dashboard-bulk-operations

## Backend Implementation

- [ ] Task 1: Create `lib/Exception/PermissionDeniedException.php` with constructor accepting `$deniedUuids: array` and `$message: string`, exposing getter `getDeniedUuids()`. Include a unit test.
- [ ] Task 2: Create `lib/Service/BulkOperationService.php` with constructor injection of `IAppConfig`, `DashboardMapper`, `WidgetPlacementMapper`, `PermissionService`, `IGroupManager`, `ActivityPublisher`, `DashboardTreeService`. Add `private const DEFAULT_MAX_PER_REQUEST = 500`.
- [ ] Task 3: Implement `BulkOperationService::bulkDelete(IUser, array $uuids, bool $dryRun = false, bool $cascade = false): array` with all-or-nothing permission check (REQ-BULK-011), size validation (REQ-BULK-006), and per-uuid try/catch mutation loop (REQ-BULK-005). Return response envelope with `deletedCount`, `skippedCount`, `errors: [{uuid, reason, childCount?, detail?}]`, `dryRun`.
- [ ] Task 4: In `bulkDelete`, implement dry-run simulation (no mutations, predict counts and idempotency skips). For cascade, delegate to `DashboardTreeService::deleteSubtree()` (REQ-BULK-001). For non-cascade parents with children, add error `{reason: "dashboard_has_children", childCount: ...}` and skip (REQ-BULK-001).
- [ ] Task 5: Implement `BulkOperationService::bulkMove(IUser, array $uuids, ?string $parentUuid, bool $dryRun = false): array` with all-or-nothing permission check, size validation, per-uuid cycle detection via `DashboardTreeService::validateParent()`, and parent-already-matches idempotency skip (REQ-BULK-002, REQ-BULK-007).
- [ ] Task 6: Implement `BulkOperationService::bulkStatus(IUser, array $uuids, string $status, ?string $publishAt, bool $dryRun = false): array` with all-or-nothing permission check, size validation, status enum validation (draft|published|scheduled), publishAt requirement when status=scheduled (REQ-BULK-003), and status-already-matches idempotency skip (REQ-BULK-007).
- [ ] Task 7: Implement `BulkOperationService::bulkReindex(IUser, array $uuids, bool $dryRun = false): array` with all-or-nothing permission check, size validation, touch `updated_at` for each dashboard via `DashboardMapper::update()`, and continue-on-error semantics capturing `reindex_failed` errors (REQ-BULK-004, REQ-BULK-005).
- [ ] Task 8: For all four methods, implement request size cap validation using `IAppConfig::getAppValueInt('bulk_operation_max_per_request', 500)` with fallback to 500 if config is <= 0 (REQ-BULK-006).
- [ ] Task 9: For all four methods, extract permission pre-check into private `validateAllPermissions(IUser, array $uuids): array` returning `deniedUuids` on first failure (REQ-BULK-011). Throw `PermissionDeniedException($deniedUuids, ...)` if any UUID is denied.
- [ ] Task 10: For all four methods, implement unified dry-run flag. In dry-run mode: validate (same permission/size checks), predict counts and errors (same idempotency logic), but do NOT mutate database. Return response envelope with `wouldX` keys instead of `X` keys (REQ-BULK-008).
- [ ] Task 11: For all four methods, emit exactly ONE Activity event at the end via `ActivityPublisher::publish(Extension::EVENT_UPDATED, ..., {bulkOperation: 'delete|move|status|reindex', dashboardCount: count($uuids), durationMs: elapsed, dryRun: $dryRun})` and log any ActivityPublisher failures (REQ-BULK-009). Measure duration with `microtime(true)` start/end.
- [ ] Task 12: Create `lib/Controller/AdminBulkController.php` inheriting `OCSController` with four route handlers: `bulkDelete()`, `bulkMove()`, `bulkStatus()`, `bulkReindex()`. Each maps JSON request body to `BulkOperationService` method call, catches `PermissionDeniedException` → HTTP 403 with `{deniedUuids, message}`, catches `InvalidArgumentException` → HTTP 400 with `{message}`, and returns HTTP 200 with unified response envelope (REQ-BULK-001..004).
- [ ] Task 13: Add OpenAPI route annotations to all four handlers in `AdminBulkController` with request/response schemas, operation tags (admin-only), and status codes (200, 400, 403).
- [ ] Task 14: Create `tests/Unit/Service/BulkOperationServiceTest.php` with unit tests covering (per operation): all-or-nothing permission failure (HTTP 403, no mutations), size cap exceeded (HTTP 400), dry-run (no mutations, wouldX counts), idempotency skips (already-deleted, parent-already-matches, status-already-matches), cycle detection (bulkMove), cascade vs non-cascade (bulkDelete), publishAt requirement (bulkStatus).
- [ ] Task 15: Create `tests/Integration/Controller/AdminBulkControllerTest.php` with integration tests covering: request routing, response envelope shape, error handling (permission denied, size exceeded, invalid input), dry-run isolation.
- [ ] Task 16: Add migration or repair step to ensure app config key `bulk_operation_max_per_request` has a sensible default (500) on first install and during repair.

## Frontend Implementation

- [ ] Task 17: Create `src/components/admin/DashboardBulkOperations.vue` (new Vue component) with: (a) table header checkbox selecting/deselecting all visible rows; (b) per-row checkbox for individual selection; (c) Actions dropdown (disabled when 0 rows, 4 options: Delete, Move to..., Set status, Reindex) that opens confirmation modals; (d) confirmation modal showing dashboard count, operation-specific inputs (target parent for move, target status for status), dry-run checkbox (unchecked by default); (e) result summary showing changed/skipped counts and per-uuid errors.
- [ ] Task 18: In `DashboardBulkOperations.vue`, implement delete action modal with cascade checkbox (default false) per REQ-BULK-001. On OK, call `api.bulkDeleteDashboards(selectedUuids, {cascade, dryRun})` and handle response.
- [ ] Task 19: In `DashboardBulkOperations.vue`, implement move action modal with parent selector (dropdown/search, showing available parents or root option) per REQ-BULK-002. On OK, call `api.bulkMoveDashboards(selectedUuids, {parentUuid, dryRun})` and handle response.
- [ ] Task 20: In `DashboardBulkOperations.vue`, implement status action modal with status enum dropdown (draft, published, scheduled) and conditional publishAt date picker (visible only when status=scheduled, required) per REQ-BULK-003. On OK, call `api.bulkStatusDashboards(selectedUuids, {publicationStatus, publishAt, dryRun})` and handle response.
- [ ] Task 21: In `DashboardBulkOperations.vue`, implement reindex action modal (minimal UI, just confirmation) per REQ-BULK-004. On OK, call `api.bulkReindexDashboards(selectedUuids, {dryRun})` and handle response.
- [ ] Task 22: In `DashboardBulkOperations.vue`, on successful non-dry-run response: refresh the dashboard list (fetch from `GET /api/dashboards` or emit event for parent component to refresh), clear the selection, and display success summary. On dry-run response: show "PREVIEW: Would change N dashboards." without refreshing list (REQ-BULK-010).
- [ ] Task 23: On error response (HTTP 403, 400, etc.), display user-friendly error message. For 403, show "You don't have permission to perform this action on one or more dashboards:" and list the denied UUIDs. For 400, show the error message from the backend (REQ-BULK-011).
- [ ] Task 24: Update `src/services/api.js` (or create new bulk operations client) with four methods: `bulkDeleteDashboards(uuids, options)`, `bulkMoveDashboards(uuids, options)`, `bulkStatusDashboards(uuids, options)`, `bulkReindexDashboards(uuids, options)`. Each calls the matching endpoint, handles dryRun flag in request body.
- [ ] Task 25: Mount `DashboardBulkOperations.vue` in `src/components/admin/AdminSettings.vue` next to the existing Export/Import section. Pass the dashboard list (or a ref to it) so the component can refresh it after non-dry-run operations.
- [ ] Task 26: Create `tests/Frontend/AdminBulkOperations.spec.js` (Vitest) testing: checkbox state management (select all, deselect all, toggle individual), actions dropdown enable/disable logic, modal open/close, dry-run toggle, API call parameters (correct endpoint, correct body shape), response handling (list refresh on success, no refresh on dry-run, error display).
- [ ] Task 27: Create `tests/e2e/admin-bulk-operations.spec.ts` (Playwright) testing: admin loads dashboard list, selects 3 dashboards, performs bulk-delete with dry-run (verifies preview summary, no list refresh), then performs real delete (verifies list refresh, dashboards gone), tests move operation with parent selector, tests status operation with date scheduling.

## Quality & Documentation

- [ ] Task 28: Run `composer check:strict` (PHP static analysis) on new backend files; fix all PHPStan/Psalm issues.
- [ ] Task 29: Run ESLint on new Vue files; fix all linting issues. Run Vue template linter if configured.
- [ ] Task 30: Verify i18n strings: all user-facing text in `DashboardBulkOperations.vue` (button labels, modal titles, error messages, placeholders) MUST be wrapped in `$t()` or `i18n.t()` calls. Add translations to both `nl` and `en` locales.
- [ ] Task 31: Add inline comments to `BulkOperationService` methods documenting the all-or-nothing permission model, per-dashboard atomicity, and continue-on-error semantics. Link to REQ-BULK-005 and REQ-BULK-011.
- [ ] Task 32: Update `CHANGELOG.md` with a bullet point summarizing the new bulk operations feature, highlighting admin-only access, dry-run support, and configurable size cap.
- [ ] Task 33: (Optional) Add a brief admin documentation file `docs/admin/bulk-operations.md` explaining the four endpoints, the cascade delete default, the dry-run workflow, and the performance implications of large batches.

## Verification

`openspec validate` exits clean. All four endpoints accept request envelopes correctly, enforce permissions all-or-nothing, cap batch size, handle idempotency, isolate dry-run, emit one audit event per request, and return response envelopes with operation-specific counts (or would-counts for dry-run).

## Tests (company-wide ADR-009)

- **Unit**: `BulkOperationServiceTest` covers permission validation, size capping, dry-run isolation, idempotency, cascade/non-cascade, publishAt validation, cycle detection. ~15–20 test methods.
- **Integration**: `AdminBulkControllerTest` covers endpoint routing, request/response shape, error handling (403, 400), status codes.
- **Frontend (Vitest)**: Checkbox state management, dropdown enable/disable, modal flow, API parameter formatting, response handling (list refresh, summary display, error display).
- **E2E (Playwright)**: Full admin workflow — select dashboards, choose action, run dry-run, verify preview, run real operation, verify mutations.

## Documentation (company-wide ADR-010)

- Inline comments in `BulkOperationService` documenting the orchestration layers (permission, validation, mutation, audit).
- Changelog entry.
- (Optional) Admin docs covering the feature, use cases, and configuration.
- OpenAPI schema in `AdminBulkController` route annotations.

## i18n (company-wide ADR-007)

All user-facing strings in `DashboardBulkOperations.vue` MUST be translatable:
- Button labels: "Delete", "Move to...", "Set status", "Reindex"
- Modal titles: "Delete {n} dashboards?", "Move {n} dashboards", etc.
- Error messages: "You don't have permission...", "Request contains X dashboards; maximum is Y."
- Placeholders: "Select parent", "Choose status", etc.
- Success summary: "Changed X, skipped Y.", "PREVIEW: Would change X dashboards."

Parity in `nl` + `en`.
