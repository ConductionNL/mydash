# Default-dashboard flag per group

## Why

The `multi-scope-dashboards` change introduced `group_shared` dashboards but left an open question: when a user belongs to a group with multiple shared dashboards (or has no `active_dashboard` preference yet, or that preference points to a deleted dashboard), which dashboard should they land on first? Today an admin has no way to tell the system "this is the canonical entry-point dashboard for the marketing group". This change formalises a single default per group by reusing the existing `isDefault SMALLINT` column (already in use for `admin_template` records per REQ-TMPL-008) and adds the API needed to flip it. Personal `user`-type dashboards are deliberately excluded — they already have `isActive` (REQ-DASH-006) for the same purpose.

## What Changes

- Reuse the existing `isDefault SMALLINT` column on `oc_mydash_dashboards`; do not add a new column or a `defaultDashboardId` field on a parent table.
- Add `DashboardService::setGroupDefault(string $groupId, string $uuid): void` that runs in a single DB transaction (UPDATE all other dashboards in the group to `isDefault=0`, then UPDATE the target to `isDefault=1`) so concurrent calls cannot leave the group in a two-default state.
- Add admin-only endpoint `POST /api/dashboards/group/{groupId}/default` with body `{"uuid": "..."}` that invokes the service.
- Reject the call with HTTP 404 when the target uuid does not belong to the given groupId; HTTP 403 for non-admins.
- New `group_shared` dashboards are created with `isDefault = 0` regardless of payload contents — promotion is always explicit.
- `PUT /api/dashboards/group/{groupId}/{uuid}` MUST NOT mutate `isDefault` even if the payload contains the field; the dedicated `POST .../default` endpoint is the only mutation path.
- Personal `user` dashboards are unaffected — they continue to use `isActive` (REQ-DASH-006) to track which one the user has open.

## Capabilities

### New Capabilities

(none — the feature folds into the existing `dashboards` capability)

### Modified Capabilities

- `dashboards`: adds REQ-DASH-015 (single default per group, transactional flip), REQ-DASH-016 (created dashboards default to `isDefault=0`), REQ-DASH-017 (PUT cannot mutate `isDefault`). Existing REQ-DASH-001..014 are untouched.

The `admin-templates` capability is intentionally not modified — REQ-TMPL-008 already enforces a single default for templates and is left as-is. This change formalises the same invariant for `group_shared` records.

## Impact

**Affected code:**

- `lib/Db/Dashboard.php` — already has `isDefault SMALLINT`; reuse for `group_shared` scope (no schema change required)
- `lib/Db/DashboardMapper.php` — add helper for the bulk UPDATE that clears defaults inside the group
- `lib/Service/DashboardService.php` — `setGroupDefault(string $groupId, string $uuid)` enforces the single-default invariant; also harden `saveGroupShared` to ignore any incoming `isDefault` field, and harden `updateGroupShared` to drop any `isDefault` from the patch
- `lib/Controller/DashboardController.php` — new `setGroupDefault` action mapped to `POST /api/dashboards/group/{groupId}/default`
- `appinfo/routes.php` — register the one new route
- `src/views/AdminApp.vue` — "Set default" action button per dashboard row, plus a "Default" badge on the row where `isDefault === 1`
- `src/stores/dashboards.js` — optimistic update on success, rollback on 4xx/5xx

**Affected APIs:**

- 1 new route: `POST /api/dashboards/group/{groupId}/default`
- No existing routes change behaviour, but `POST` and `PUT` on the existing group endpoints are tightened to ignore the `isDefault` field in payloads (this is a documentation/serialisation change, not a contract break — the field was previously silently writable, which was the bug)

**Dependencies:**

- `OCP\IGroupManager` — already injected; used for the admin guard
- `OCP\IDBConnection` — already injected; the transaction is wrapped via `beginTransaction()` / `commit()` / `rollBack()` on the existing connection
- No new composer or npm dependencies

**Migration:**

- Zero schema migration required — `isDefault SMALLINT` already exists on `oc_mydash_dashboards` per the original `admin-templates` change.
- No data backfill required; existing rows already have `isDefault = 0` by default.

**Build order:**

- This change MUST be applied after `multi-scope-dashboards` lands (depends on REQ-DASH-011 / REQ-DASH-014 endpoints existing).
