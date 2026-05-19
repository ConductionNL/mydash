---
capability: dashboard-sharing
delta: true
status: draft
---

# Dashboard Sharing — Delta from change `2026-04-30-dashboard-sharing-followups`

## ADDED Requirements

### Requirement: REQ-SHARE-008 Notify recipient on share add and on level upgrade

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

### Requirement: REQ-SHARE-009 Bulk replace shares

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

### Requirement: REQ-SHARE-010 Revoke all shares granted to a recipient

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

### Requirement: REQ-SHARE-011 Notifier renders share and ownership-transfer subjects

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

### Requirement: REQ-SHARE-012 Cascade and admin retention on user deletion

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

### Requirement: REQ-SHARE-013 Deterministic new-owner selection

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
