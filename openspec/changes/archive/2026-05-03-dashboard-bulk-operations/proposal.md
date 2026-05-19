# Dashboard Bulk Operations

## Why

MyDash administrators need efficient tools for large-scale dashboard management: cleaning up obsolete dashboards, reorganizing dashboard hierarchies, updating publication status across templates, and maintaining search indices. Today, these operations require either single-dashboard API calls (tedious for hundreds of items) or direct database manipulation (risky). A dedicated bulk operations API with atomic-per-dashboard semantics, dry-run support, and comprehensive audit trails enables safe, traceable large-scale administration.

## What Changes

- Add `POST /api/admin/dashboards/bulk-delete` — soft-delete multiple dashboards by UUID in a single request; returns count of deleted/skipped and per-dashboard error reasons.
- Add `POST /api/admin/dashboards/bulk-move` — re-parent multiple dashboards in the dashboard tree; validates cycles via the `dashboard-tree` capability; returns move counts and errors.
- Add `POST /api/admin/dashboards/bulk-status` — update publication status (draft/published/scheduled) across multiple dashboards; depends on `dashboard-draft-published` capability; supports future publish dates.
- Add `POST /api/admin/dashboards/bulk-reindex` — re-index multiple dashboards for unified search; depends on `nc-unified-search-integration` capability.
- All endpoints accept `?dryRun=true` query param — returns predicted results without mutations.
- Request limit: max 500 dashboardUuids per request; returns HTTP 400 if exceeded (configurable via `mydash.bulk_operation_max_per_request`).
- Idempotency: bulk-delete on already-deleted dashboard = no-op (counted as skipped, listed in errors); bulk-move where parent IS current parent = no-op; bulk-status where status IS current status = no-op.
- Atomicity: each dashboard's DB write is transactional; batch is NOT atomic (partial success is reported, not rolled back).
- Audit: single Nextcloud Activity event per bulk operation with operation type, dashboard count, user ID, and duration; per-dashboard activity NOT emitted (avoid spam).
- Permissions: all endpoints return 403 if the calling user lacks permission on ANY dashboard in the batch (fail-safe; no partial action).
- Frontend: add multi-select checkbox column to dashboard list view + "Actions" dropdown (enabled when ≥1 row selected) with Delete, Move to..., Set status, Reindex options.

## Capabilities

### New Capabilities

- `dashboard-bulk-operations`: provides REQ-BULK-001 through REQ-BULK-011 (bulk-delete, bulk-move, bulk-status, bulk-reindex, atomicity per-dashboard, cap, idempotency, dry-run, audit, front-end multi-select, permissions all-or-nothing).

### Modified Capabilities

- None. Existing dashboard CRUD, dashboard-tree (cycle check delegation), dashboard-draft-published (status enum reuse), and nc-unified-search-integration are unchanged; this capability adds batch endpoints only.

## Impact

**Affected code:**

- `lib/Service/BulkOperationService.php` — orchestration for delete, move, status, reindex with idempotency and dry-run logic.
- `lib/Controller/AdminController.php` — extend existing admin controller or create new `AdminBulkController.php` for four new endpoints.
- `appinfo/routes.php` — register four new POST routes under `/api/admin/dashboards/bulk-*`.
- `lib/Activity/BulkOperationActivityProvider.php` — single activity event per bulk operation.
- `src/views/AdminDashboardList.vue` — add multi-select checkbox column and Actions dropdown to dashboard list.
- `lib/Migration/VersionXXXXDate2026...php` — none required (no new tables, uses existing dashboard schema).

**Affected APIs:**

- 4 new routes (no existing routes modified).
- Existing `GET /api/dashboards`, `POST /api/dashboard`, `PUT /api/dashboard/{uuid}`, `DELETE /api/dashboard/{uuid}` unchanged.

**Dependencies:**

- `OCP\IConfig` — for reading `mydash.bulk_operation_max_per_request` (default 500).
- `OCP\Activity\IManager` — for emitting bulk operation activity events.
- Existing `DashboardMapper`, `PermissionService`, `ActivityService` — no new services.
- Delegation to `dashboard-tree` capability for cycle checking (via `DashboardTreeService`).
- Delegation to `dashboard-draft-published` capability for status enum validation (via `DashboardStatusService` or equivalent).
- Delegation to `nc-unified-search-integration` capability for re-indexing (via search provider).

**Migration:**

- No new tables or schema changes. All bulk operations work with existing `oc_mydash_dashboards` table and related indices.

**Security:**

- Permissions enforced all-or-nothing: if any one dashboard in the batch is unauthorized, the entire batch is rejected (HTTP 403, no mutations).
- Soft-delete only; hard delete never exposed (consistent with existing dashboard deletion model).
- Audit trail: every bulk operation is logged via Nextcloud Activity (no blind admin actions).
- Dry-run flag prevents accidental mutations and supports planning / preview workflows.

