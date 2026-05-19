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

### REQ-SHARE-008: Notify recipient on share add and on level upgrade

When a share is created OR its `permission_level` is **upgraded** (`view_only → add_only|full`, or `add_only → full`), the system MUST publish a Nextcloud notification to each affected recipient via `OCP\Notification\IManager`. The notification MUST use:

- `app: 'mydash'`, `subject: 'dashboard_shared'`
- `objectType: 'dashboard'`, `objectId: <dashboardId as string>`
- `subjectParameters: [sharerUserId, dashboardName, permissionLevel]`

For `share_type='group'` shares, the system MUST fan out one notification per current group member at publish time, excluding the sharer.

The system MUST NOT publish notifications when:
- a share is **removed** (revocation is silent)
- a share's level is **downgraded** (`full → add_only|view_only`, or `add_only → view_only`)
- a re-share writes the same `(dashboardId, shareType, shareWith, permissionLevel)` tuple (no-op upsert)

#### Scenario: User share publishes one notification

- GIVEN alice owns dashboard `5` ("Q3 Plan"), and bob has no existing share on it
- WHEN alice sends `POST /api/dashboard/5/shares` with `{shareType: "user", shareWith: "bob", permissionLevel: "view_only"}`
- THEN exactly one `INotification` MUST be published with `user='bob'`, `app='mydash'`, `subject='dashboard_shared'`, parameters `["alice", "Q3 Plan", "view_only"]`, `objectType='dashboard'`, `objectId='5'`

#### Scenario: Group share fans out per current member

- GIVEN dashboard `5` has no shares
- AND group `marketing` has members `bob`, `carol`, `dave`
- WHEN alice (not a member of `marketing`) sends `POST /api/dashboard/5/shares` with `{shareType: "group", shareWith: "marketing", permissionLevel: "full"}`
- THEN exactly 3 notifications MUST be published — one each to bob, carol, dave
- AND alice MUST NOT receive a notification even if she is a member of `marketing`

#### Scenario: Level upgrade publishes a notification

- GIVEN dashboard `5` is shared with bob at `view_only`
- WHEN alice updates the share to `permissionLevel: "full"` via `POST /api/dashboard/5/shares` with the same `(shareType, shareWith)`
- THEN one `INotification` MUST be published to bob with `subjectParameters[2] = "full"`

#### Scenario: Level downgrade is silent

- GIVEN dashboard `5` is shared with bob at `full`
- WHEN alice updates the share to `permissionLevel: "view_only"`
- THEN no `INotification` MUST be published

#### Scenario: No-op upsert is silent

- GIVEN dashboard `5` is shared with bob at `view_only`
- WHEN alice re-sends `POST /api/dashboard/5/shares` with `{shareType: "user", shareWith: "bob", permissionLevel: "view_only"}`
- THEN the share row MUST remain unchanged (same `created_at`)
- AND no `INotification` MUST be published

#### Scenario: Revocation is silent

- GIVEN dashboard `5` is shared with bob at `view_only` (share id 17)
- WHEN alice sends `DELETE /api/dashboard/share/17`
- THEN the share row MUST be deleted
- AND no `INotification` MUST be published

### REQ-SHARE-009: Bulk replace shares

The system MUST support `PUT /api/dashboard/{id}/shares` accepting a JSON body `{"shares": Share[]}` that replaces the entire share list for the dashboard atomically. Only the dashboard owner MUST be allowed to call this endpoint. The operation MUST run in a single DB transaction.

After the transaction commits, the system MUST publish notifications per REQ-SHARE-008 only for entries that are newly added or upgraded; entries that are unchanged, removed, or downgraded MUST NOT trigger a notification.

#### Scenario: Replace adds, upgrades, and removes in one call

- GIVEN dashboard `5` (owned by alice) has shares: `(user, bob, view_only)`, `(user, carol, view_only)`, `(group, sales, view_only)`
- WHEN alice sends `PUT /api/dashboard/5/shares` with body `{"shares": [{"shareType": "user", "shareWith": "bob", "permissionLevel": "full"}, {"shareType": "user", "shareWith": "dave", "permissionLevel": "view_only"}]}`
- THEN after commit, the share rows for dashboard `5` MUST be exactly: `(user, bob, full)` and `(user, dave, view_only)`
- AND notifications MUST be published to bob (level upgrade) and dave (new share)
- AND no notification MUST be published to carol (removed) or sales members (removed)

#### Scenario: Idempotent re-PUT publishes nothing

- GIVEN dashboard `5` has shares `(user, bob, full)` and `(user, dave, view_only)`
- WHEN alice sends `PUT /api/dashboard/5/shares` with the exact same payload
- THEN no rows MUST change
- AND no notifications MUST be published

#### Scenario: Non-owner is denied

- GIVEN dashboard `5` is owned by alice and shared with bob at `full`
- WHEN bob sends `PUT /api/dashboard/5/shares` with any payload
- THEN the system MUST return HTTP 403
- AND no rows MUST be modified

### REQ-SHARE-010: Revoke all shares granted to a recipient

The system MUST support `DELETE /api/sharees/{shareType}/{shareWith}` for an authenticated user. The operation MUST delete every share row where:

- `share_type = $shareType AND share_with = $shareWith`, AND
- the share's `dashboard_id` references a dashboard whose `user_id` equals the calling user's id

It MUST NOT touch shares on dashboards owned by other users, even if the calling user has a `full`-level share on them.

The response MUST include the count of removed share rows. No notifications MUST be published.

#### Scenario: Owner revokes a recipient across all their dashboards

- GIVEN alice owns dashboards `5` and `7`
- AND both are shared with bob at various levels
- AND alice is also a member of group `marketing`, and dashboard `9` (owned by carol) is shared with `marketing` at `full`
- WHEN alice sends `DELETE /api/sharees/user/bob`
- THEN the share rows on dashboards `5` and `7` referencing bob MUST be deleted
- AND any share row on dashboard `9` MUST remain unchanged (alice is not the owner of `9`)
- AND the response MUST report the number of rows actually deleted (2 in this scenario)

### REQ-SHARE-011: Notifier renders share and ownership-transfer subjects

The app MUST register an `OCP\Notification\INotifier` implementation with `id = 'mydash'` that handles two subjects: `dashboard_shared` and `dashboard_ownership_transferred`. For any other subject the notifier MUST throw `\OCP\Notification\UnknownNotificationException` so that other notifiers may handle it.

For `dashboard_shared`, the rendered notification MUST include:
- A rich subject "{sharerDisplayName} shared **{dashboardName}** with you"
- A parsed message naming the permission level (e.g. "Full access", "Add-only access", "View-only access")
- A primary link to `/apps/mydash/?dashboard={dashboardUuid}`

For `dashboard_ownership_transferred`, the rendered notification MUST include:
- A rich subject "**{dashboardName}** is now yours"
- A parsed message stating ownership was transferred because the previous owner was removed
- A primary link to the same deep link

Localisation MUST be performed via `IFactory::get('mydash')` so the existing Dutch and English translation files are used.

#### Scenario: Notifier renders share notification in English

- GIVEN an `INotification` with `app='mydash'`, `subject='dashboard_shared'`, `subjectParameters=['alice','Q3 Plan','full']`, `objectType='dashboard'`, `objectId='5'`
- WHEN the notifier prepares it for `languageCode='en'`
- THEN the rich subject MUST be `"alice shared **Q3 Plan** with you"`
- AND the parsed message MUST be `"Full access"`
- AND the link MUST resolve via `IURLGenerator::linkToRouteAbsolute('mydash.page.index') . '?dashboard=' . <uuid of dashboard 5>`

#### Scenario: Unknown subject throws

- GIVEN an `INotification` with `app='mydash'`, `subject='something_else'`
- WHEN the notifier prepares it
- THEN `\OCP\Notification\UnknownNotificationException` MUST be thrown

### REQ-SHARE-012: Cascade and admin retention on user deletion

The app MUST listen to `OCP\User\Events\UserDeletedEvent`. On every event for user `X`, in a single DB transaction per affected dashboard, the system MUST:

1. **Recipient cleanup**: delete every share row where `share_type='user' AND share_with=X`. Group shares are unaffected.

2. **Owned-dashboard handling**: for every dashboard whose `user_id = X`:
   - **Compute the admin pool**: every user reachable via a share row on that dashboard with `permission_level = 'full'`. `user`-type shares contribute their target uid; `group`-type shares contribute the group's current member list (resolved live via `IGroupManager`). Already-deleted users (resolved through `IUserManager::get(uid)`) MUST be excluded.
   - **If the admin pool is non-empty**: the dashboard MUST be retained. Pick the new owner via REQ-SHARE-013, transfer ownership, and delete only the share row that previously granted that new owner access. All other shares MUST be retained.
   - **If the admin pool is empty**: the dashboard, its widget placements, and all its share rows MUST be deleted (existing cascade behaviour from REQ-SHARE-007).

#### Scenario: Owner deletion preserves dashboard with a full-level user share

- GIVEN alice owns dashboard `5`
- AND dashboard `5` is shared with bob at `full` and with carol at `view_only`
- WHEN the system processes `UserDeletedEvent` for alice
- THEN dashboard `5` MUST still exist
- AND `dashboards.user_id` for dashboard `5` MUST equal `bob`
- AND the share row that granted bob access MUST be deleted
- AND the share row granting carol view-only access MUST remain

#### Scenario: Owner deletion preserves dashboard via group full-level share

- GIVEN alice owns dashboard `5`
- AND dashboard `5` is shared with group `leads` (members `dave`, `eve`) at `full`
- AND no other shares exist
- WHEN the system processes `UserDeletedEvent` for alice
- THEN dashboard `5` MUST still exist
- AND `user_id` for dashboard `5` MUST equal `dave` (alphabetically-first member of the group, see REQ-SHARE-013)
- AND the group share row MUST remain (group shares are not consumed by ownership transfer)

#### Scenario: Owner deletion deletes dashboard when no admin pool

- GIVEN alice owns dashboard `5`
- AND dashboard `5` is shared with bob at `view_only` only
- WHEN the system processes `UserDeletedEvent` for alice
- THEN dashboard `5` MUST be deleted
- AND its widget placements MUST be deleted
- AND its share row to bob MUST be deleted

#### Scenario: Recipient deletion cleans up that user's shares

- GIVEN dashboards `5`, `7`, and `9` each have a share `(user, bob, view_only)`
- AND bob owns no dashboards himself
- WHEN the system processes `UserDeletedEvent` for bob
- THEN all three of those share rows MUST be deleted
- AND no other state MUST change

#### Scenario: Group share where every member is gone falls through to delete

- GIVEN alice owns dashboard `5`
- AND dashboard `5` is shared with group `ghosts` at `full`
- AND every member of `ghosts` is a deleted user (none resolve via `IUserManager::get`)
- WHEN the system processes `UserDeletedEvent` for alice
- THEN dashboard `5` MUST be deleted (admin pool is empty after filtering)

### REQ-SHARE-013: Deterministic new-owner selection

When REQ-SHARE-012 calls for picking a new owner from the admin pool, the system MUST apply this ordering rule:

1. Among `share_type='user'` shares with `permission_level='full'`, pick the one with the smallest `created_at`.
2. If none, expand the alphabetically-first `share_type='group'` share with `permission_level='full'`. From that group's still-existing members (per `IUserManager::get`), pick the alphabetically-first uid.
3. If both branches yield no candidate, fall back to the deletion path (REQ-SHARE-012, empty-pool branch).

The selected new owner MUST receive a notification with `subject='dashboard_ownership_transferred'`, `subjectParameters=[dashboardName]`, `objectType='dashboard'`, `objectId=<dashboardId>`.

#### Scenario: User share earliest created wins

- GIVEN dashboard `5` has full-level user shares: `(bob, created_at='2026-01-15')`, `(carol, created_at='2026-02-01')`, `(dave, created_at='2025-12-10')`
- WHEN ownership transfer is required
- THEN dave MUST be selected (earliest `created_at`)

#### Scenario: Falls back to alphabetic group member when no user shares

- GIVEN dashboard `5` has only group full-level shares: `(zeta-team, full)`, `(alpha-team, full)`
- AND `alpha-team` members are `victor`, `bob`, `alex`; `zeta-team` is `mark`, `lily`
- WHEN ownership transfer is required
- THEN alex MUST be selected (alphabetically-first member of alphabetically-first group)

#### Scenario: Ownership-transfer notification is published to the new owner only

- GIVEN dashboard `5` ("Q3 Plan") undergoes ownership transfer to bob
- WHEN the listener completes
- THEN exactly one `INotification` MUST be published with `user='bob'`, `subject='dashboard_ownership_transferred'`, `subjectParameters=['Q3 Plan']`, `objectType='dashboard'`, `objectId='5'`
