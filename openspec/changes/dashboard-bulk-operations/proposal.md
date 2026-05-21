# Dashboard Bulk Operations

Administrators need to manage large numbers of dashboards efficiently in a single operation. Today, modifying 500+ dashboards requires individual API calls or ad-hoc scripts. This change introduces four batch admin endpoints: bulk-delete (with optional cascade), bulk-move (re-parent in tree), bulk-status (update publication status), and bulk-reindex (mark for search re-indexing). All endpoints enforce permission pre-checks (all-or-nothing), support dry-run preview, cap batch size at 500 dashboards (admin-configurable), emit a single audit event per request, and handle per-dashboard atomic mutations with continue-on-error semantics.

## Affected code units

- `lib/Controller/AdminBulkController.php` (new) — exposes four POST endpoints
- `lib/Service/BulkOperationService.php` (new) — orchestrates all four operations with idempotency, permissions, size caps, dry-run, and audit emission
- `lib/Exception/PermissionDeniedException.php` (new) — carries offending UUID list for 403 responses
- `src/services/api.js` — adds `bulkDeleteDashboards`, `bulkMoveDashboards`, `bulkStatusDashboards`, `bulkReindexDashboards`
- `src/components/admin/DashboardBulkOperations.vue` (new) — multi-select checkboxes and Actions dropdown in admin dashboard list
- `src/components/admin/AdminSettings.vue` — mounts the new bulk operations component
- App config key `bulk_operation_max_per_request` (default 500) — tunable size cap
- Delegates to existing `DashboardTreeService::validateParent()` (cycle detection), `DashboardTreeService::deleteSubtree()` (cascade), `PermissionService::resolveAccessLevel()`, `ActivityPublisher::publish()`

## Why a new capability

The bulk operations are a cohesive, admin-only feature set that:
1. Bridges the gap between single-dashboard updates and mass administration
2. Introduces new architectural patterns (all-or-nothing permission checks, per-dashboard atomicity with batch continue-on-error)
3. Requires a dedicated UI component (multi-select + Actions dropdown)
4. Owns a configurable system boundary (size cap per request)

This merits a dedicated capability rather than scattered changes across existing dashboard management endpoints.

## Approach

- **Backend**: `BulkOperationService` owns orchestration — permission pre-check (fail-fast if any UUID denied), size validation, per-dashboard transaction wrapping, idempotency logic (already-deleted, parent-already-matches, status-already-matches, cycle-detected), dry-run isolation, and single audit event emission.
- **Frontend**: `DashboardBulkOperations.vue` provides a multi-select checkbox interface and Actions dropdown with confirmation modals showing dry-run toggles and result summaries.
- **Permission model**: All-or-nothing — if the user lacks permission on any dashboard in the batch, the entire request returns 403 with `deniedUuids` and no mutations occur.
- **Atomicity**: Per-dashboard (each dashboard's write is transactional), not across the batch. Partial failure is reported and safe; the caller retries failed dashboards.
- **Idempotency**: Delete, move, and status operations treat no-ops as skipped entries (already-deleted, parent-already-matches, status-already-matches) rather than errors.
- **Dry-run**: All endpoints support `?dryRun=true` to preview results without mutations. Validation is identical to real runs (same permission checks, size validation).
- **Audit**: One Activity event per request with operation type, dashboard count, duration, and dryRun flag.
