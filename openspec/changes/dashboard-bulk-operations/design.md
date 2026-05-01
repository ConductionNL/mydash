# Design — Dashboard Bulk Operations

## Context

This change adds four batch-mutation endpoints (`bulk-delete`, `bulk-move`, `bulk-status`, `bulk-reindex`) and a matching frontend multi-select UI to the MyDash admin panel. Without these, administrators managing hundreds of dashboards must issue single-item API calls or resort to direct database manipulation — neither of which leaves a usable audit trail.

The source implementation performs bulk delete as a **hard delete** via the Nextcloud Files API (`$folder->delete()` on the NC filesystem node). There is no soft-delete flag, no `deleted_at` column, and no grace period. Recovery is only possible through Nextcloud's system-wide trash bin, which may or may not be enabled on a given installation.

The most consequential design question is what happens when a bulk-delete request targets a **parent dashboard that has children**. The source accepts a `deleteChildren` boolean and defaults it to `true`, silently cascading a hard delete across an entire sub-tree. This is the most likely vector for an admin misclick causing irrecoverable data loss at scale: "Delete 50 dashboards" that turns out to have deleted 300 because several were parents.

A related question is whether to introduce a **soft-delete grace period** at this point. Soft delete trades implementation complexity and storage growth for a 30-day undo window, which would be valuable for exactly the "I deleted the wrong sub-tree" scenario. The decision below addresses both.

## Goals / Non-Goals

**Goals:**
- Provide four batch endpoints covering the most common admin operations (delete, re-parent, set status, reindex).
- Protect against the most common misclick scenario: hard-deleting a parent dashboard and cascading to its entire child tree without an explicit opt-in.
- Align cascade semantics with `dashboard-tree`'s existing `?cascade=true` convention (REQ-DASH-016 area).
- Support dry-run preview on all endpoints so admins can inspect impact before committing.
- Provide a single audit event per request rather than per-dashboard log spam.
- Enforce an all-or-nothing permission pre-check to prevent partial-permission-leak scenarios.

**Non-Goals:**
- Soft-delete or undo/restore functionality (deferred to a future capability if customers request it).
- Bulk operations for non-admin users (all four endpoints require admin role).
- Cross-app bulk operations (scoped to `oc_mydash_dashboards` only).
- Automatic retry on partial failure (caller is responsible for retrying failed UUIDs).

## Decisions

### D1: Delete strategy — hard delete, `cascade=false` default, `?cascade=true` opt-in

**Decision:** Bulk delete removes rows directly from `oc_mydash_dashboards` via the Nextcloud Files API (hard delete, matching the source). Cascade to child dashboards is **not** the default: attempting to delete a parent that has children without `?cascade=true` returns **HTTP 409** with a body indicating how many children block the operation. Passing `?cascade=true` opts in to recursive deletion and the caller bears explicit responsibility for the cascade.

**Alternatives considered:**

- **Option A — match source exactly (`cascade=true` default):** The source defaults `deleteChildren=true`. This is fast and simple, but it turns a misclick on "Delete Section A" into a silent recursive hard delete with no recovery path unless NC's trash bin is enabled (which is not guaranteed). Rejected because the risk-to-convenience ratio is unfavourable in a multi-admin production deployment.

- **Option B — soft delete with `deleted_at` column + 30-day grace period:** Would allow undo within 30 days, mirroring how most modern collaborative tools handle bulk deletes. Requires a new column, a sweeper job, and filter changes on every query that reads dashboard rows. Rejected for v1 because it is a non-trivial schema change and the immediate priority is parity-plus-safety, not a full recovery workflow. Revisit if customers report misclick incidents.

**Rationale:** Hard delete keeps the implementation lean and matches the proven source behaviour, while flipping the dangerous default to `cascade=false` eliminates the most common catastrophic misclick. This is consistent with `dashboard-tree`'s existing pattern where operations that recurse into children require an explicit `?cascade=true` signal. The HTTP 409 response gives the admin a clear pause point before proceeding with a broader operation.

**Source evidence:**
- `intravox-source/lib/Service/BulkOperationService.php:97-130` — `bulkDelete()` iterates IDs and delegates to `deletePage()` per dashboard.
- `intravox-source/lib/Service/PageService.php:1675,1712` — `deletePage()` calls `$result['folder']->delete()` on the NC filesystem node; hard delete with no soft-delete flag.
- `intravox-source/lib/Controller/BulkController.php:100-135` — `deletePages()` accepts `deleteChildren` (default `true`); this default is the risky behaviour we are changing.

---

### D2: Cap on per-request batch size — 500

**Decision:** Each bulk endpoint accepts at most 500 `dashboardUuids` per request. Requests exceeding the cap return HTTP 400 immediately with no mutations. The cap is admin-tunable via `mydash.bulk_operation_max_per_request` (OCC config or web settings); invalid values (zero, negative) fall back to 500 with a warning log.

**Rationale:** A cap of 500 is generous enough for any sane organisation-wide operation but prevents an errant admin script from issuing a single request that locks the dashboard table for minutes. The config escape hatch avoids breaking large-org deployments that genuinely need higher limits.

---

### D3: Per-dashboard atomicity, batch reporting

**Decision:** Each dashboard's mutation runs in its own database transaction. Partial failure (one dashboard fails) is reported in the response `errors` array and processing continues with the remaining UUIDs. The batch is not all-or-nothing at the mutation level — only the permission pre-check is (see D4).

**Source evidence:**
- `intravox-source/lib/Service/BulkOperationService.php:97-130` — per-page iteration with continue-on-error semantics; no wrapping transaction across the whole batch.

**Rationale:** Continue-on-error semantics let large-batch cleanup operations make progress even when a small number of dashboards have transient errors. All-or-nothing batch transactions would cause large operations to fail silently on any foreign-key hiccup. The caller is responsible for inspecting the `errors` array and retrying failed UUIDs.

---

### D4: Permission — all-or-nothing pre-check, then per-dashboard execution

**Decision:** Before any mutation begins, the system verifies that the calling user has the required permission on every dashboard in the batch. If any dashboard fails the check, the entire request is rejected with HTTP 403 and no mutations occur. Permission failure takes priority over size-cap validation (fail-fast on the security constraint). After a clean pre-check, per-dashboard execution proceeds with the per-dashboard atomicity from D3.

**Rationale:** A partial-permission model would allow an attacker (or misconfigured script) to delete dashboards they do own by bundling them with one they do not, exploiting the continue-on-error execution. The pre-check closes this leak. The 403 response body MUST enumerate the unauthorised UUIDs so the admin can diagnose which dashboard caused the rejection.

---

### D5: Rate limit — 5 requests/60s per user

**Decision:** Match the source. Apply `#[UserRateThrottle(limit: 5, period: 60)]` to all four bulk endpoints.

**Source evidence:**
- `intravox-source/lib/Controller/BulkController.php:100` — `#[UserRateThrottle(limit: 5, period: 60)]`.

**Rationale:** Five batch requests per minute allows rapid interactive use while preventing a runaway script from overwhelming the database with back-to-back 500-item batches.

---

### D6: Single audit event per bulk operation

**Decision:** Each bulk endpoint emits exactly ONE Nextcloud Activity event of type `dashboard_bulk_operation` per request. The payload includes: `operation`, `dashboardCount`, `byUserId`, `durationMs`, and `dryRun` flag. Per-dashboard events are not emitted. Dry-run requests also emit an audit event (with `dryRun: true`) and so do rejected requests (with the failure reason).

**Rationale:** Emitting one event per dashboard in a 500-item batch would spam the activity log and make it unusable for audit purposes. A single summary event preserves the audit trail without the noise.

---

### D7: Dry-run via `?dryRun=true`

**Decision:** All four endpoints accept `?dryRun=true`. In dry-run mode the full validation stack runs (permissions, size cap, idempotency checks, cycle detection for bulk-move) and the response uses `wouldX` counter names instead of `X` counters — but no database mutations occur. Dry-run defaults to `false` when the parameter is absent.

**Rationale:** Dry-run support is essential for bulk operations at scale. Admins preparing a large cleanup should be able to preview exactly which dashboards would be skipped or error before committing. The identical validation path ensures the preview is accurate and not a separate code path that diverges under edge cases.

---

### D8: Comment cascade via existing listener

**Decision:** No new cascade logic is required for bulk delete. The existing `PageDeletedListener` already handles comment cleanup on NC Files API delete events for single-dashboard deletions; bulk delete triggers the same NC Files events, so comment cleanup fires automatically per dashboard.

**Rationale:** Reusing the existing listener keeps bulk delete from duplicating cascade logic and ensures consistency between single and bulk delete semantics. This is consistent with the source's approach.

---

## Spec changes implied

- **REQ-BULK-001 (delete):** Change "soft-delete" wording to "hard delete". Change `cascade` default from `true` to `false`. Document HTTP 409 response when a parent has children and `?cascade=true` is not present. Document `?cascade=true` as the opt-in for recursive deletion.
- **REQ-BULK-005 (atomicity):** Clarify that permission pre-check (D4) is all-or-nothing, but DB-level atomicity (D3) is per-dashboard after the pre-check passes.
- **REQ-BULK-006 (rate limit):** Pin `#[UserRateThrottle(limit: 5, period: 60)]` for all four endpoints.
- **REQ-BULK-007 (idempotency):** For bulk-delete, confirm that attempting to delete an already-deleted dashboard is a no-op counted as `skippedCount`, not an error. (Hard delete on a non-existent row is absorbed silently.)
- **REQ-BULK-009 (audit):** Confirm dry-run and rejected requests also emit the single audit event.

## Open follow-ups

- Whether to add a soft-delete grace period as a follow-up capability if customers request undo after a misclick incident.
- Whether `?cascade=true` on bulk-delete should recursively apply the same 500-item cap check (i.e., total dashboards including children must not exceed 500), or whether the cap applies only to the explicitly listed UUIDs.
- Whether bulk-move into a parent that would create a cycle should be detected at request time using `dashboard-tree`'s cycle-check service — likely yes, and the error should appear in the per-dashboard `errors` array rather than returning HTTP 400 for the whole batch.
- Whether bulk-status should accept a mixed-per-dashboard status array (different statuses per UUID) or only a single target status per request. Current spec assumes one status per request.
