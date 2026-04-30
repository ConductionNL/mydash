---
status: implemented
---

# Dashboard Sharing Specification

## Purpose

Dashboard sharing lets a dashboard owner grant read or edit access on a personal (`type: 'user'`) dashboard to specific Nextcloud users or groups. It complements the existing dashboard scopes (`user`, `admin_template`, `group_shared`) by enabling **ad-hoc peer-to-peer collaboration** without needing administrator involvement: any user can decide to share their own dashboard with a colleague or a group, with view, add, or full access. The recipient sees the shared dashboard alongside their own in the dashboard switcher and can act on it according to the share's permission level. Only the owner can manage shares; only the owner can rename, change description, or delete the dashboard.

## Concepts

A **share** grants a single recipient (user or group) one permission level on one dashboard. Recipients can be reached via direct user shares OR via group membership. When a user matches multiple shares for the same dashboard (e.g. a direct user share AND a group share), the most permissive level wins, ranked `view_only < add_only < full`.

| Field | Type | Notes |
|-------|------|-------|
| `dashboardId` | int | The shared dashboard. Owner-side reference. |
| `shareType` | enum `'user' \| 'group'` | Recipient kind. |
| `shareWith` | string | Nextcloud user id or group id. |
| `permissionLevel` | enum `'view_only' \| 'add_only' \| 'full'` | What the recipient can do. Reuses the existing permission enum from `permissions/spec.md`. |
| `createdAt` | datetime | Audit trail; not exposed in UI. |

The unique tuple `(dashboardId, shareType, shareWith)` enforces that a recipient gets exactly one share row per dashboard — re-sharing the same recipient updates the existing row instead of inserting.

## Requirements

### REQ-SHARE-001: Owner-only share management

Only the owner of a dashboard MUST be allowed to list, create, update, or delete shares on that dashboard. All share-management endpoints MUST return HTTP 403 for any caller that is not the dashboard owner, including users who themselves have a `full`-level share on the dashboard.

#### Scenario: Owner adds a share

- GIVEN a logged-in user "alice" who owns dashboard id `5`
- WHEN she sends `POST /api/dashboard/5/shares` with body `{"shareType": "user", "shareWith": "bob", "permissionLevel": "view_only"}`
- THEN the system MUST insert a row in `oc_mydash_dashboard_shares` with the four fields plus `createdAt = now()`
- AND respond with HTTP 201 and the new share's id, displayName, and serialized fields

#### Scenario: Recipient cannot manage shares

- GIVEN dashboard `5` is owned by "alice" and shared with "bob" at `full` level
- WHEN bob sends `POST /api/dashboard/5/shares` to add another recipient
- THEN the system MUST return HTTP 403
- AND no share row MUST be created

#### Scenario: Updating an existing share replaces, does not duplicate

- GIVEN alice has shared dashboard `5` with "bob" at `view_only`
- WHEN she sends `POST /api/dashboard/5/shares` with the same `shareType` and `shareWith` but `permissionLevel: "full"`
- THEN the system MUST update the existing share row, not create a second one
- AND only one share row MUST exist for `(dashboardId=5, shareType=user, shareWith=bob)`

### REQ-SHARE-002: Listing dashboards visible to a user

`GET /api/dashboards` MUST return both dashboards owned by the caller AND dashboards the caller has access to via a direct or group share. Each entry MUST be decorated with `isOwner: bool`, `sharedBy: string|null` (the owner's userId when not the caller's own dashboard), and `effectivePermissionLevel: 'view_only'|'add_only'|'full'`.

#### Scenario: Recipient sees a shared dashboard in their list

- GIVEN dashboard `5` ("My Dashboard", owned by "alice") is shared with "bob" at `add_only`
- WHEN bob fetches `GET /api/dashboards`
- THEN the response MUST include an entry with `id: 5`, `isOwner: false`, `sharedBy: "alice"`, `effectivePermissionLevel: "add_only"`
- AND bob's own dashboards MUST also be present, each with `isOwner: true`, `sharedBy: null`

#### Scenario: Group share grants visibility to all group members

- GIVEN dashboard `5` is shared with group `marketing` at `view_only`
- AND user "carol" is a member of group `marketing`
- WHEN carol fetches `GET /api/dashboards`
- THEN the entry for dashboard `5` MUST be present with `effectivePermissionLevel: "view_only"`

#### Scenario: Most-permissive level wins when a user matches multiple shares

- GIVEN dashboard `5` is shared with user "carol" at `view_only` AND with group `marketing` at `full`
- AND carol is a member of `marketing`
- WHEN carol fetches `GET /api/dashboards`
- THEN the entry for dashboard `5` MUST report `effectivePermissionLevel: "full"`

### REQ-SHARE-003: Loading a shared dashboard with placements

`GET /api/dashboard/{id}` MUST return the dashboard, its widget placements, and the caller's effective permission level for any dashboard the caller can view (owned or shared). Callers without ownership AND without any matching share MUST receive HTTP 403.

#### Scenario: Recipient loads a shared dashboard

- GIVEN dashboard `5` (with 4 widget placements) is shared with bob at `view_only`
- WHEN bob fetches `GET /api/dashboard/5`
- THEN the response body MUST contain `dashboard`, `placements` (4 entries), `permissionLevel: "view_only"`, `isOwner: false`, `sharedBy: "alice"`

#### Scenario: Non-recipient is denied

- GIVEN dashboard `5` is owned by alice and not shared with carol
- WHEN carol fetches `GET /api/dashboard/5`
- THEN the system MUST return HTTP 403

### REQ-SHARE-004: Per-share permission resolution overrides admin defaults

When a user accesses a dashboard via a share, the system MUST evaluate widget/tile/layout permission checks against **the share's** `permissionLevel` rather than the dashboard's globally-set `permissionLevel` field. The dashboard's own `permissionLevel` continues to apply only to the owner.

#### Scenario: Owner has `view_only`, recipient has `full`

- GIVEN dashboard `5` has `permissionLevel = "view_only"` and is owned by alice
- AND it is shared with bob at `full`
- WHEN bob sends `POST /api/dashboard/5/widgets` to add a widget
- THEN the system MUST allow the operation (HTTP 201)
- AND when alice attempts the same call, the system MUST return HTTP 403

### REQ-SHARE-005: Owner-only metadata and lifecycle operations

Even with a `full`-level share, recipients MUST NOT be able to:

- Rename the dashboard or change its description (`canEditDashboardMetadata` returns false for non-owners)
- Delete the dashboard (`DELETE /api/dashboard/{id}` returns HTTP 403 for non-owners)
- Persist the dashboard as their "active" dashboard (the `is_active` flag is keyed on the owner's row; `POST /api/dashboard/{id}/activate` MUST be a successful no-op for recipients but MUST NOT change DB state)

#### Scenario: `full`-level recipient cannot rename

- GIVEN dashboard `5` is shared with bob at `full`
- WHEN bob sends `PUT /api/dashboard/5` with body `{"name": "New Name"}`
- THEN the system MUST return HTTP 403
- AND the dashboard's name MUST remain unchanged

#### Scenario: `full`-level recipient cannot delete

- GIVEN dashboard `5` is shared with bob at `full`
- WHEN bob sends `DELETE /api/dashboard/5`
- THEN the system MUST return HTTP 403

#### Scenario: Activation by recipient is a soft success

- GIVEN dashboard `5` (owned by alice) is shared with bob at `view_only`
- WHEN bob sends `POST /api/dashboard/5/activate`
- THEN the system MUST return HTTP 200 with the dashboard payload
- AND no row's `is_active` flag MUST change as a result

### REQ-SHARE-006: Sharee autocomplete

`GET /api/sharees?query={q}` MUST return up to 10 matching users and 10 matching groups whose name (display name or id) contains `q`. The endpoint MUST exclude the caller from the user results to prevent self-shares. Recipients are matched server-side via `IUserManager::search` and `IGroupManager::search`.

#### Scenario: Search returns matching users and groups

- GIVEN users `jan.pietersen`, `jan.vandeberg`, `mark.jansen` and groups `marketing`, `sales`
- WHEN alice fetches `GET /api/sharees?query=jan`
- THEN the response MUST include the matching users in the `users` array and any matching groups in the `groups` array
- AND alice MUST NOT appear in the `users` array

### REQ-SHARE-007: Cascade on dashboard delete

When a dashboard is deleted (by its owner), every share row referencing that dashboard MUST be deleted in the same transaction. No orphan share rows MAY remain.

#### Scenario: Shares are removed when the dashboard is deleted

- GIVEN dashboard `5` has 3 share rows
- WHEN alice (owner) sends `DELETE /api/dashboard/5`
- THEN the dashboard row MUST be deleted
- AND all 3 share rows in `oc_mydash_dashboard_shares` referencing `dashboard_id = 5` MUST also be deleted
- AND a query for shares on dashboard `5` MUST return 0 rows
