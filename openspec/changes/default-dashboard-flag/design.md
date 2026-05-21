# Design — Default-dashboard flag per group

## Entity Schemas

### Dashboard (Reused)

The existing `Dashboard` entity in `lib/Db/Dashboard.php` already carries the required `isDefault SMALLINT` column and property (added by the `admin-templates` capability). This change **reuses** the column without adding a new property.

**Relevant fields:**

| Field | Type | Scope | Usage |
|-------|------|-------|-------|
| `uuid` | `VARCHAR(36)` | PK | Unique dashboard identifier (auto-generated per dashboard) |
| `type` | `VARCHAR(32)` | Enum | One of `'admin_template'`, `'user'`, `'group_shared'` |
| `groupId` | `VARCHAR(255)` NULL | FK to Nextcloud group | Populated only when `type = 'group_shared'`; literal `'default'` is the "visible to all users" sentinel |
| `isDefault` | `SMALLINT` | Invariant | 0 or 1; enforced unique per `(type, groupId)` when `type = 'group_shared'` |
| `name` | `VARCHAR(255)` NULL | User data | Dashboard display name |
| `description` | `TEXT` NULL | User data | Rich description |
| `gridColumns` | `INT` | Layout | Grid column count (default 12) |
| `isActive` | `SMALLINT` | User preference (REQ-DASH-006) | Tracks which *personal* dashboard the user has open (distinct from group defaults) |
| `createdAt` | `DATETIME` | Audit | ISO-8601 creation timestamp |
| `updatedAt` | `DATETIME` | Audit | ISO-8601 update timestamp |

**Note:** The `isDefault` column is NOT NULL; `Dashboard::__construct()` defaults it to `0` (line 374). The enum constraint is implemented in `DashboardService`, not at the DB level.

## Data Model Constraints

### Single-default invariant (REQ-DASH-015)

At any moment in time, for each `(type='group_shared', groupId=X)` tuple, at most one row MUST have `isDefault = 1`. Concurrent writes are protected by a transaction that:

1. Sets all other rows with matching `(type, groupId)` to `isDefault = 0`
2. Sets the target row to `isDefault = 1`
3. Commits atomically (or rolls back if the target does not exist)

**Database constraint:** The unique index on `(type, groupId, isDefault=1)` is handled via the transactional service layer, not a database constraint. (PostgreSQL can enforce this with a partial unique index; MySQL cannot, so we rely on the service transaction.)

### Creation default (REQ-DASH-016)

New `group_shared` dashboards are created with `isDefault = 0`, regardless of any `isDefault` field in the request body. The field is stripped by `DashboardService::saveGroupShared()` before persistence.

### Update immutability (REQ-DASH-017)

The `isDefault` field is read-only when updating a dashboard via `PUT /api/dashboards/group/{groupId}/{uuid}`. The field is stripped from the patch by `DashboardService::updateGroupShared()` before applying updates.

## Seed Data

### Example Group-Shared Dashboards

Three sample dashboards per group, illustrating the invariant in action:

#### Group: "marketing"

```php
[
    'uuid'         => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
    'type'         => Dashboard::TYPE_GROUP_SHARED,
    'groupId'      => 'marketing',
    'name'         => 'Marketing Campaigns',
    'description'  => 'Overview of active campaigns, budgets, and KPIs',
    'isDefault'    => 1,  // ← This is the group default
    'gridColumns'  => 12,
    'createdAt'    => '2026-05-21T10:00:00Z',
    'updatedAt'    => '2026-05-21T10:00:00Z',
],
[
    'uuid'         => 'f47ac10b-58cc-4372-a567-0e02b2c3d480',
    'type'         => Dashboard::TYPE_GROUP_SHARED,
    'groupId'      => 'marketing',
    'name'         => 'Social Media Metrics',
    'description'  => 'Real-time engagement and reach metrics across channels',
    'isDefault'    => 0,
    'gridColumns'  => 12,
    'createdAt'    => '2026-05-21T10:15:00Z',
    'updatedAt'    => '2026-05-21T10:15:00Z',
],
[
    'uuid'         => 'f47ac10b-58cc-4372-a567-0e02b2c3d481',
    'type'         => Dashboard::TYPE_GROUP_SHARED,
    'groupId'      => 'marketing',
    'name'         => 'Content Calendar',
    'description'  => 'Editorial calendar and scheduled content',
    'isDefault'    => 0,
    'gridColumns'  => 12,
    'createdAt'    => '2026-05-21T10:30:00Z',
    'updatedAt'    => '2026-05-21T10:30:00Z',
],
```

#### Group: "sales"

```php
[
    'uuid'         => 'f47ac10b-58cc-4372-a567-0e02b2c3d482',
    'type'         => Dashboard::TYPE_GROUP_SHARED,
    'groupId'      => 'sales',
    'name'         => 'Pipeline Overview',
    'description'  => 'Sales pipeline, conversion rates, and forecast',
    'isDefault'    => 1,  // ← This is the group default
    'gridColumns'  => 12,
    'createdAt'    => '2026-05-21T11:00:00Z',
    'updatedAt'    => '2026-05-21T11:00:00Z',
],
[
    'uuid'         => 'f47ac10b-58cc-4372-a567-0e02b2c3d483',
    'type'         => Dashboard::TYPE_GROUP_SHARED,
    'groupId'      => 'sales',
    'name'         => 'Customer Accounts',
    'description'  => 'Account health, expansion opportunities, churn risk',
    'isDefault'    => 0,
    'gridColumns'  => 12,
    'createdAt'    => '2026-05-21T11:15:00Z',
    'updatedAt'    => '2026-05-21T11:15:00Z',
],
```

#### Group: "default" (Visible to all users)

```php
[
    'uuid'         => 'f47ac10b-58cc-4372-a567-0e02b2c3d484',
    'type'         => Dashboard::TYPE_GROUP_SHARED,
    'groupId'      => 'default',
    'name'         => 'Company Overview',
    'description'  => 'High-level company metrics and announcements',
    'isDefault'    => 1,  // ← Every unauthenticated / ungrouped user lands here
    'gridColumns'  => 12,
    'createdAt'    => '2026-05-21T12:00:00Z',
    'updatedAt'    => '2026-05-21T12:00:00Z',
],
```

**Note:** Seed data is fictional but realistic; groups and names match Dutch organizational conventions (e.g., `marketing`, `sales`, `engineering`). The UUIDs are v4 format. Timestamps use ISO-8601 UTC.

## Reuse Analysis

This change consumes the following existing OpenRegister and MyDash abstractions; no new services or abstractions are introduced.

### Backend Services

| Service | Method | Purpose | Reused from |
|---------|--------|---------|------------|
| `IGroupManager` | `isAdmin()` | Authorization check for admin-only endpoints | Nextcloud core |
| `IDBConnection` | `beginTransaction()` / `commit()` / `rollBack()` | Transactional isolation of concurrent default-flips | Nextcloud core |
| `DashboardMapper` | `findAll()`, `update()` | Existing CRUD operations (reused) | App codebase |
| `DashboardService` | (existing pattern) | Service-layer encapsulation for business logic | App codebase |

### Frontend Stores & Services

| Component | Purpose | Reused from |
|-----------|---------|------------|
| `useDashboardStore` | Pinia store for dashboard CRUD | `src/stores/dashboards.js` |
| `axios` | HTTP client with CSRF token auto-attach | `@nextcloud/axios` |
| `t()` / `n()` | i18n translation helpers | `@nextcloud/l10n` + app config |
| `CnDetailCard` | Card wrapper (existing admin list) | `@conduction/nextcloud-vue` |

### No New Abstractions

- No new entities or schemas are introduced
- No new services beyond modifying `DashboardService`
- No new database tables or migrations (reuses existing `isDefault` column)
- No new external dependencies (uses only existing Nextcloud core + app packages)

## API Changes

### New Endpoint

| Method | Path | Body | Response | Auth |
|--------|------|------|----------|------|
| POST | `/api/dashboards/group/{groupId}/default` | `{"uuid": "..."}` | 200 (Dashboard) or 404/403 | Admin only |

**Success (HTTP 200):**

Returns the updated Dashboard object with `isDefault: 1`:

```json
{
  "id": 42,
  "uuid": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "type": "group_shared",
  "groupId": "marketing",
  "name": "Marketing Campaigns",
  "isDefault": 1,
  "isActive": 0,
  ...
}
```

**Not Found (HTTP 404):**

When the `uuid` does not belong to the `groupId`, or the uuid is absent from the database:

```json
{
  "message": "Dashboard not found in group"
}
```

**Forbidden (HTTP 403):**

When the authenticated user is not a Nextcloud admin:

```json
{
  "message": "Forbidden"
}
```

### Modified Endpoints

| Method | Path | Change | Reason |
|--------|------|--------|--------|
| POST | `/api/dashboards/group/{groupId}` | Strip `isDefault` from body before save | REQ-DASH-016 |
| PUT | `/api/dashboards/group/{groupId}/{uuid}` | Strip `isDefault` from patch before update | REQ-DASH-017 |

No signature change; the field is silently ignored if present in the request body (defensive programming).

## Request/Response Flow

### Setting a group default (happy path)

```
Admin clicks "Set Default" on dashboard B in group "marketing"
                      ↓
Frontend: optimistic update (B.isDefault=1, A.isDefault=0)
                      ↓
POST /api/dashboards/group/marketing/default
     Body: {"uuid": "<B.uuid>"}
                      ↓
DashboardController::setGroupDefault()
  ├─ IGroupManager::isAdmin() → 403 if false
  ├─ Service::setGroupDefault()
  │   ├─ clearGroupDefaults("marketing", exceptUuid=<B.uuid>)
  │   ├─ setGroupDefaultUuid("marketing", <B.uuid>) → returns 0 if not found
  │   └─ beginTransaction/commit
  └─ Return updated Dashboard (200)
                      ↓
Frontend: confirm optimistic update
                      ↓
UI shows "Default" badge on B, no badge on A/C
```

### Cross-group injection attack (prevented)

```
Admin tries to set D1 (in "marketing") as default for "sales"
                      ↓
POST /api/dashboards/group/sales/default
     Body: {"uuid": "<D1.uuid>"}  [but D1.groupId="marketing"]
                      ↓
DashboardController::setGroupDefault()
  └─ Service::setGroupDefault()
      └─ setGroupDefaultUuid("sales", <D1.uuid>)
         └─ UPDATE ... WHERE type='group_shared' AND groupId='sales' AND uuid=...
            └─ 0 rows affected → throws 404
                      ↓
Return 404
                      ↓
Frontend: roll back optimistic update
```

## Migration & Backwards Compatibility

### Schema

No migration required. The `isDefault SMALLINT` column already exists on `oc_mydash_dashboards` from the `admin-templates` change. Existing rows have `isDefault = 0` by default.

### Existing Behavior

- Personal (`user` type) dashboards are unaffected — they continue to use `isActive` (REQ-DASH-006).
- Admin templates (`admin_template` type) are unaffected — they continue to use `isDefault` to mark the gallery default (REQ-TMPL-008).
- Existing `group_shared` dashboards already have `isDefault` set to 0; this change adds the ability to flip it atomically.

## Implementation Notes

### Transaction scope

The transaction MUST be tight: start → clear others → set target → commit. Do NOT include widget-loading or other I/O inside the transaction.

### Concurrent safety

Two admins concurrently setting different dashboards as default in the same group:
1. Both call `clearGroupDefaults()` → both UPDATE the same rows
2. Both call `setGroupDefaultUuid()` → both UPDATE the target row
3. Database row-level locking (implicit in UPDATE) ensures one wins; the other's UPDATE applies to the now-cleared row, leaving exactly one default

### Frontend store update rollback

The Pinia store mutation flips `isDefault` optimistically. On 4xx/5xx, revert both the target and all siblings. On 200, the server response is the source of truth.
