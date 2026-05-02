---
status: implemented
---

# Dashboards Specification

## Purpose

Dashboards are the core organizational unit in MyDash. Each user can create and manage multiple personal dashboards, each acting as a container for widget placements, tiles, and layout configuration. Dashboards define the grid structure, permission level, and active state. Only one dashboard can be active per user at a time, serving as their landing page when they open Nextcloud. Dashboards can also be of type `admin_template`, managed by administrators for distribution to users.

## Data Model

Each dashboard record is stored in the `oc_mydash_dashboards` table with the following fields:
- **id**: Auto-increment integer primary key
- **uuid**: Unique identifier (UUID v4)
- **userId**: Nextcloud user ID of the dashboard owner
- **name**: Human-readable dashboard name
- **description**: Optional description of the dashboard purpose
- **type**: Either `user` (personal) or `admin_template` (admin-managed template)
- **basedOnTemplate**: Nullable integer foreign key to the source admin template dashboard ID (set when a user copy is created from a template)
- **gridColumns**: Number of grid columns (default: 12)
- **permissionLevel**: One of `view_only`, `add_only`, `full` (inherited from template or set by admin)
- **targetGroups**: JSON string of group IDs (used for admin templates)
- **isDefault**: SMALLINT (0/1) flag for admin templates indicating default distribution
- **isActive**: SMALLINT (0/1) flag indicating if this is the user's currently active dashboard
- **createdAt**: Timestamp string (Y-m-d H:i:s)
- **updatedAt**: Timestamp string (Y-m-d H:i:s)

## Requirements

### REQ-DASH-001: Create Personal Dashboard

Users MUST be able to create new personal dashboards with a name, optional description, and default grid configuration.

#### Scenario: Create a dashboard with default settings
- GIVEN a logged-in Nextcloud user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "My Work Dashboard"}`
- THEN the system MUST create a new dashboard with:
  - A generated UUID v4 (via custom `DashboardFactory::generateUuid()`)
  - `userId` set to "alice"
  - `type` set to "user"
  - `isActive` set to 1 (true) -- the newly created dashboard becomes active, and all other user dashboards are deactivated via `deactivateAllForUser()`
  - `gridColumns` set to 12 (hardcoded in `DashboardFactory::create()`)
  - `permissionLevel` set to "full" (hardcoded as `Dashboard::PERMISSION_FULL`)
- AND the response MUST return HTTP 201 with the full dashboard object including the generated id and uuid

#### Scenario: Create a dashboard with custom settings
- GIVEN a logged-in Nextcloud user "bob"
- WHEN he sends POST /api/dashboard with body `{"name": "Analytics", "description": "Data overview"}`
- THEN the system MUST create the dashboard with the specified name and description
- AND `gridColumns` MUST be set to 12 (custom gridColumns is not exposed in the create endpoint)

#### Scenario: Create a dashboard with invalid grid columns
- GIVEN a logged-in Nextcloud user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Test", "grid_columns": 0}`
- THEN the system MUST return HTTP 400 with a validation error
- AND `gridColumns` MUST only accept positive integers (minimum 1, maximum 24)
- NOTE: Grid column validation is NOT currently implemented

#### Scenario: Create a dashboard without a name
- GIVEN a logged-in Nextcloud user "alice"
- WHEN she sends POST /api/dashboard with body `{}`
- THEN the system MUST create a dashboard with the default name "My Dashboard"
- NOTE: The controller defaults name to "My Dashboard" if null. No validation error is returned.

#### Scenario: Dashboard creation creates default placements
- GIVEN user "alice" has no dashboards and no templates apply
- WHEN she accesses MyDash for the first time (triggers `tryCreateFromTemplate()`)
- THEN the system MUST create a "My Dashboard" with two default placements:
  - "recommendations" widget at (0, 0) with size 6x5
  - "activity" widget at (6, 0) with size 6x5
- AND both placements MUST have `showTitle: 1`, `isVisible: 1`, and appropriate sortOrder values

### REQ-DASH-002: List User Dashboards

Users MUST be able to retrieve a list of all their dashboards, scoped to their user ID.

#### Scenario: List dashboards for a user with multiple dashboards
- GIVEN user "alice" has 3 dashboards: "Work" (active), "Personal", "Analytics"
- WHEN she sends GET /api/dashboards
- THEN the system MUST return HTTP 200 with an array containing all 3 dashboards
- AND each dashboard object MUST include: id, uuid, name, description, type, basedOnTemplate, gridColumns, permissionLevel, targetGroups, isDefault, isActive, createdAt, updatedAt
- AND the active dashboard MUST have `isActive: 1`

#### Scenario: List dashboards for a user with no dashboards
- GIVEN user "bob" has never created a dashboard and no template has been distributed to him
- WHEN he sends GET /api/dashboards
- THEN the system MUST return HTTP 200 with an empty array

#### Scenario: Dashboards are user-scoped
- GIVEN user "alice" has 3 dashboards and user "bob" has 1 dashboard
- WHEN "alice" sends GET /api/dashboards
- THEN the response MUST contain only alice's 3 dashboards
- AND bob's dashboard MUST NOT be included
- AND admin templates (type: "admin_template") MUST NOT be included

### REQ-DASH-003: Get Active Dashboard

Users MUST be able to retrieve their currently active dashboard along with its placements and effective permission level in a single request.

#### Scenario: Get the active dashboard
- GIVEN user "alice" has dashboard "Work" marked as active
- WHEN she sends GET /api/dashboard
- THEN the system MUST return HTTP 200 with an object containing:
  - `dashboard`: the "Work" dashboard object (with `isActive: 1`)
  - `placements`: array of all widget placements on this dashboard
  - `permissionLevel`: the effective permission level string (resolved via `PermissionService::getEffectivePermissionLevel()`)

#### Scenario: No active dashboard exists but user has dashboards
- GIVEN user "bob" has 2 dashboards but none is marked as active
- WHEN he sends GET /api/dashboard
- THEN the system MUST activate the first existing dashboard via `DashboardResolver::tryActivateExistingDashboard()`
- AND return that dashboard as the active one

#### Scenario: First-time user triggers template distribution
- GIVEN user "carol" has no dashboards
- AND an admin template exists targeting carol's group
- WHEN she sends GET /api/dashboard
- THEN the system MUST create a personal copy of the matching template via `TemplateService::createDashboardFromTemplate()`
- AND the copy MUST be set as her active dashboard
- AND the response MUST return the newly created dashboard with its placements

#### Scenario: First-time user with no template gets default dashboard
- GIVEN user "dave" has no dashboards
- AND no admin template matches dave's groups
- AND `allowUserDashboards` is true
- WHEN he sends GET /api/dashboard
- THEN the system MUST create a default "My Dashboard" with recommendations and activity widgets
- AND the response MUST return the newly created dashboard

#### Scenario: First-time user with dashboards disabled and no template
- GIVEN user "eve" has no dashboards
- AND no admin template matches eve's groups
- AND `allowUserDashboards` is false
- WHEN she sends GET /api/dashboard
- THEN the system MUST return null (no dashboard available)
- AND the response MUST return HTTP 404 or an empty result

### REQ-DASH-004: Update Dashboard

Users MUST be able to update the name, description, and grid configuration of their dashboards.

#### Scenario: Update dashboard name and description
- GIVEN user "alice" has dashboard with id 5
- WHEN she sends PUT /api/dashboard/5 with body `{"name": "Updated Work", "description": "New desc"}`
- THEN the system MUST update the name and description
- AND set `updatedAt` to the current timestamp
- AND return HTTP 200 with the updated dashboard object

#### Scenario: Update another user's dashboard
- GIVEN user "alice" has dashboard with id 5
- WHEN user "bob" sends PUT /api/dashboard/5 with body `{"name": "Hacked"}`
- THEN the system MUST return HTTP 403 (via ownership check)
- AND the dashboard MUST NOT be modified

#### Scenario: Update grid columns on a dashboard with existing widgets
- GIVEN user "alice" has dashboard id 5 with `gridColumns: 12` and 4 widget placements
- WHEN she sends PUT /api/dashboard/5 with body `{"gridColumns": 6}`
- THEN the system MUST update `gridColumns` to 6
- AND widget placements that exceed the new column count SHOULD be repositioned or flagged for re-layout
- NOTE: Grid reflow is NOT currently implemented. Widgets exceeding the new column count remain at their positions.

#### Scenario: Update permission_level on a user dashboard
- GIVEN user "alice" has a personal dashboard with `permissionLevel: full`
- WHEN she sends PUT /api/dashboard/5 with body `{"permissionLevel": "view_only"}`
- THEN the system MUST ignore the `permissionLevel` field
- AND the permissionLevel MUST remain "full"
- NOTE: `applyDashboardUpdates()` does not handle `permissionLevel` -- it only processes `name`, `description`, `gridColumns`, and `placements`.

#### Scenario: Batch update placement positions via dashboard update
- GIVEN user "alice" has dashboard id 5 with 4 widget placements
- WHEN she sends PUT /api/dashboard/5 with body containing a `placements` array of updated positions
- THEN the system MUST update all placement positions via `placementMapper->updatePositions()`
- AND this enables efficient grid saves after drag-and-drop rearrangement

### REQ-DASH-005: Delete Dashboard

Users MUST be able to delete their own dashboards with proper cascade deletion of associated data.

#### Scenario: Delete a dashboard
- GIVEN user "alice" has dashboard id 5 with 3 widget placements
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST delete the dashboard
- AND all associated widget placements MUST be cascade-deleted via `placementMapper->deleteByDashboardId()`
- AND the response MUST return HTTP 200

#### Scenario: Delete the active dashboard
- GIVEN user "alice" has dashboard id 5 marked as active and dashboard id 6 as inactive
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST delete dashboard 5
- AND the system does NOT automatically activate dashboard 6
- NOTE: Auto-activation after delete is NOT currently implemented. The user will have no active dashboard until the next GET /api/dashboard triggers `tryActivateExistingDashboard()`.

#### Scenario: Delete another user's dashboard
- GIVEN user "alice" has dashboard id 5
- WHEN user "bob" sends DELETE /api/dashboard/5
- THEN the system MUST return HTTP 403
- AND the dashboard MUST NOT be deleted

#### Scenario: Delete the last remaining dashboard
- GIVEN user "alice" has only 1 dashboard (id 5)
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST delete the dashboard
- AND subsequent GET /api/dashboards MUST return an empty array

#### Scenario: Delete does not check permission level
- GIVEN user "alice" has a view-only dashboard id 5 (based on a template with `permissionLevel: "view_only"`)
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST allow the deletion
- AND users MUST always have the right to remove dashboards from their account regardless of permission level

### REQ-DASH-006: Activate Dashboard

Users MUST be able to set one of their dashboards as the active dashboard, ensuring only one is active at a time.

#### Scenario: Activate a dashboard
- GIVEN user "alice" has dashboard "Work" (id 5, active) and "Personal" (id 6, inactive)
- WHEN she sends POST /api/dashboard/6/activate
- THEN dashboard 6 MUST have `isActive: 1`
- AND dashboard 5 MUST have `isActive: 0` (via `DashboardMapper::setActive()` which deactivates all others first)
- AND the response MUST return HTTP 200 with the newly activated dashboard

#### Scenario: Activate an already active dashboard
- GIVEN user "alice" has dashboard "Work" (id 5, active)
- WHEN she sends POST /api/dashboard/5/activate
- THEN the system MUST return HTTP 200 (idempotent operation)
- AND dashboard 5 MUST remain active

#### Scenario: Activate another user's dashboard
- GIVEN user "alice" has dashboard id 5
- WHEN user "bob" sends POST /api/dashboard/5/activate
- THEN the system MUST return HTTP 403

#### Scenario: Only one active dashboard per user
- GIVEN user "alice" has 5 dashboards
- WHEN she activates dashboard id 8
- THEN exactly one dashboard (id 8) MUST have `isActive: 1`
- AND all other 4 dashboards MUST have `isActive: 0`

### REQ-DASH-007: Dashboard Name Validation

Dashboard names MUST be validated for length and content.

#### Scenario: Name length validation
- GIVEN a logged-in user
- WHEN they create a dashboard with a name exceeding 255 characters
- THEN the system MUST return HTTP 400 with a validation error
- AND dashboard names MUST be between 1 and 255 characters
- NOTE: Name length validation is NOT currently implemented

#### Scenario: Duplicate dashboard names allowed
- GIVEN user "alice" already has a dashboard named "Work"
- WHEN she creates another dashboard named "Work"
- THEN the system MUST allow this (dashboard names are not unique per user)
- AND the two dashboards MUST be distinguishable by their id and uuid

#### Scenario: Empty name defaults to "My Dashboard"
- GIVEN a logged-in user
- WHEN they create a dashboard without providing a name
- THEN the system MUST use the default name "My Dashboard"
- AND the dashboard MUST be created successfully

### REQ-DASH-008: Dashboard Type Enforcement

The `type` field MUST distinguish between user-created dashboards and admin templates, with appropriate access controls.

#### Scenario: Users cannot create admin_template type dashboards
- GIVEN a non-admin user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Fake Template", "type": "admin_template"}`
- THEN the system MUST ignore the `type` field (defaulting to "user" via `DashboardFactory::create()`)
- AND the created dashboard MUST have `type: user`

#### Scenario: Admin creates a template dashboard
- GIVEN a Nextcloud admin user
- WHEN they send POST /api/admin/templates with template data
- THEN the system MUST create a dashboard with `type: admin_template`
- AND the template dashboard MUST NOT appear in regular users' GET /api/dashboards responses

#### Scenario: Template-derived dashboards have type "user"
- GIVEN an admin template "Company Dashboard" is distributed to user "alice"
- WHEN the system creates a copy for alice via `TemplateService::createDashboardFromTemplate()`
- THEN the copy MUST have `type: "user"` (NOT "admin_template")
- AND `basedOnTemplate` MUST reference the source template's ID

### REQ-DASH-009: Dashboard Resolution Chain

The system MUST resolve the effective dashboard through a defined chain when GET /api/dashboard is called.

#### Scenario: Active dashboard found immediately
- GIVEN user "alice" has an active dashboard
- WHEN GET /api/dashboard is called
- THEN `DashboardResolver::tryGetActiveDashboard()` MUST find and return it immediately
- AND no template distribution or default creation logic MUST be triggered

#### Scenario: No active dashboard but existing dashboards
- GIVEN user "alice" has dashboards but none is active
- WHEN GET /api/dashboard is called
- THEN `DashboardResolver::tryActivateExistingDashboard()` MUST activate the first found dashboard
- AND return it as the active dashboard

#### Scenario: No dashboards at all with template available
- GIVEN user "alice" has no dashboards
- AND a matching admin template exists
- WHEN GET /api/dashboard is called
- THEN `DashboardService::tryCreateFromTemplate()` MUST be called
- AND a template copy MUST be created and set as active

### REQ-DASH-010: Dashboard Serialization

Dashboard objects MUST be consistently serialized across all API responses.

#### Scenario: Dashboard object includes all fields
- GIVEN a dashboard exists
- WHEN it is returned via any API endpoint
- THEN the serialized object MUST include all fields: id, uuid, userId, name, description, type, basedOnTemplate, gridColumns, permissionLevel, targetGroups, isDefault, isActive, createdAt, updatedAt

#### Scenario: Null fields are included in serialization
- GIVEN a dashboard with `description: null` and `basedOnTemplate: null`
- WHEN the dashboard is serialized
- THEN both `description` and `basedOnTemplate` MUST be present in the JSON with null values

#### Scenario: Timestamp format consistency
- GIVEN a dashboard with `createdAt` and `updatedAt` set
- WHEN the dashboard is serialized
- THEN timestamps MUST be in "Y-m-d H:i:s" format (e.g., "2026-03-20 14:30:00")

### Requirement: REQ-DASH-011 Group-shared dashboard type

The system MUST support a third dashboard type `group_shared` in addition to `user` and `admin_template`. A group-shared dashboard is owned by an administrator, scoped to one Nextcloud group via a `groupId` field, and rendered live (not copied) to every member of that group. Edits made by an administrator MUST be visible to all group members on their next page load.

#### Scenario: Create a group-shared dashboard

- GIVEN a logged-in administrator "admin" and a Nextcloud group "marketing"
- WHEN admin sends `POST /api/dashboards/group/marketing` with body `{"name": "Marketing Overview"}`
- THEN the system MUST create a dashboard record with `type = 'group_shared'`, `groupId = 'marketing'`, and `userId = null`
- AND the response MUST return HTTP 201 with the new dashboard

#### Scenario: Non-admin cannot create a group-shared dashboard

- GIVEN a logged-in user "alice" who is not an administrator
- WHEN she sends `POST /api/dashboards/group/marketing` with any body
- THEN the system MUST return HTTP 403

#### Scenario: Group-shared dashboard appears for every group member

- GIVEN admin has created a group-shared dashboard `D1` with `groupId = 'marketing'`
- AND user "bob" is a member of group "marketing"
- WHEN bob calls `GET /api/dashboards/visible`
- THEN the response MUST include `D1`

#### Scenario: Group-shared dashboards are read-only for non-admins

- GIVEN bob (non-admin) is viewing group-shared dashboard `D1`
- WHEN he sends `PUT /api/dashboards/group/marketing/{D1.uuid}` with any body
- THEN the system MUST return HTTP 403
- AND the dashboard MUST NOT be modified

#### Scenario: Direct mutation via personal endpoint is rejected

- GIVEN bob (non-admin) is viewing group-shared dashboard `D1` (owner type `group_shared`)
- WHEN he sends `PUT /api/dashboard/{D1.id}` (the personal endpoint)
- THEN the system MUST return HTTP 403 (ownership check fails — `D1.userId` is null, not bob)

#### Scenario: Invariant — `group_shared` requires `groupId`

- GIVEN any caller attempts to insert a dashboard row with `type='group_shared'` and `groupId IS NULL`
- THEN the system MUST throw `\InvalidArgumentException` (enforced by `DashboardFactory::create()`)
- AND no row MUST be persisted

#### Scenario: Invariant — non-`group_shared` types must not have a `groupId`

- GIVEN any caller attempts to insert a dashboard with `type='user'` and `groupId='marketing'`
- THEN the system MUST throw `\InvalidArgumentException`
- AND no row MUST be persisted

### Requirement: REQ-DASH-012 Default-group sentinel

The system MUST recognise the literal `groupId = 'default'` as a synthetic group meaning "visible to all users", regardless of their actual group membership. Group-shared dashboards with `groupId = 'default'` MUST be returned by every user's `/api/dashboards/visible` query in addition to the dashboards from groups they belong to.

#### Scenario: Default-group dashboard visible to user with no matching groups

- GIVEN admin has created group-shared dashboards: `D-default` with `groupId='default'` and `D-eng` with `groupId='engineering'`
- AND user "carol" belongs only to group "support"
- WHEN she calls `GET /api/dashboards/visible`
- THEN the response MUST include `D-default`
- AND MUST NOT include `D-eng`

#### Scenario: 'default' is not a real Nextcloud group

- GIVEN admin sends `POST /api/dashboards/group/default` with body `{"name": "Welcome"}`
- THEN the system MUST accept the request even when no Nextcloud group with id "default" exists
- AND the dashboard MUST be created with `groupId = 'default'`

#### Scenario: Default-group dashboard carries `source: 'default'` not `source: 'group'`

- GIVEN a default-group dashboard `D-default` exists
- AND user "alice" is also a member of a real group (so `D-default` could in theory be tagged either way)
- WHEN she calls `GET /api/dashboards/visible`
- THEN `D-default` MUST appear in the response with `source: 'default'`
- AND MUST NOT appear with `source: 'group'`

### Requirement: REQ-DASH-013 Visible-to-user resolution

The system MUST expose `GET /api/dashboards/visible` that returns the union of three dashboard sets, deduplicated by UUID, in this priority order:

1. Personal `user`-type dashboards owned by the current user
2. `group_shared` dashboards whose `groupId` matches one of the user's Nextcloud groups
3. `group_shared` dashboards whose `groupId = 'default'`

Each returned dashboard MUST carry an additional `source` field with values `'user'`, `'group'`, or `'default'` so the frontend can route subsequent edits to the correct endpoint.

#### Scenario: Source field discriminates origin

- GIVEN user "alice" has 1 personal dashboard, 2 group-shared dashboards in groups she belongs to, and 1 default-group dashboard exists
- WHEN she calls `GET /api/dashboards/visible`
- THEN the response MUST contain 4 dashboards
- AND each MUST carry exactly one of `source: 'user' | 'group' | 'default'`
- AND the personal dashboard MUST have `source: 'user'`

#### Scenario: Deduplication by UUID

- GIVEN a group-shared dashboard exists where the user is a member of the targeted group AND that same dashboard's UUID also appears in another result set (rare edge case from a future multi-group support or a misconfigured fixture)
- WHEN she calls `GET /api/dashboards/visible`
- THEN it MUST appear only once in the response

#### Scenario: User with no personal dashboards still gets visible result

- GIVEN user "dave" has zero personal dashboards
- AND he is a member of group "engineering" which has 1 group-shared dashboard
- AND 1 default-group dashboard exists
- WHEN he calls `GET /api/dashboards/visible`
- THEN the response MUST contain 2 dashboards (the engineering one with `source='group'`, the default one with `source='default'`)

#### Scenario: User with no groups and no defaults gets only personal

- GIVEN user "eve" has 1 personal dashboard
- AND she belongs to no groups
- AND no default-group dashboards exist
- WHEN she calls `GET /api/dashboards/visible`
- THEN the response MUST contain exactly 1 dashboard with `source='user'`

#### Scenario: Admin gets group-shared dashboards as `source='group'` even though they own them

- GIVEN admin "root" created group-shared dashboard `D1` in group "marketing"
- AND admin "root" is a member of group "marketing"
- WHEN admin "root" calls `GET /api/dashboards/visible`
- THEN `D1` MUST appear with `source='group'` (not `source='user'`)
- NOTE: ownership of `group_shared` dashboards is admin-collective, not per-user — the `userId` column is null on these rows

### Requirement: REQ-DASH-014 Group-shared dashboard mutation endpoints

The system MUST expose CRUD endpoints scoped to a group:

- `GET /api/dashboards/group/{groupId}` — list group-shared dashboards in that group (any logged-in user can list)
- `POST /api/dashboards/group/{groupId}` — create a new one (admin only)
- `GET /api/dashboards/group/{groupId}/{uuid}` — get one (any logged-in user)
- `PUT /api/dashboards/group/{groupId}/{uuid}` — update name/layout/icon (admin only)
- `DELETE /api/dashboards/group/{groupId}/{uuid}` — remove (admin only)

#### Scenario: Update propagates immediately

- GIVEN admin updates the layout of group-shared dashboard `D1`
- WHEN any group member next loads the workspace page
- THEN the new layout MUST be served (no per-user copy interferes)

#### Scenario: Group-shared dashboard cannot be deleted while it is the last one in the group

- GIVEN group "marketing" has exactly one group-shared dashboard `D1`
- WHEN admin sends `DELETE /api/dashboards/group/marketing/D1.uuid`
- THEN the system MUST return HTTP 400 with `{error: 'Cannot delete the only dashboard in the group'}`
- NOTE: Personal dashboards do NOT have this guard — REQ-DASH-005 deletion remains unrestricted for `user`-type

#### Scenario: Default group is exempt from the last-in-group delete guard

- GIVEN the `default` group has exactly one group-shared dashboard `D-default`
- WHEN admin sends `DELETE /api/dashboards/group/default/D-default.uuid`
- THEN the system MUST delete the dashboard
- AND return HTTP 200
- NOTE: the default group is curated, not user-bound — admins can intentionally clear it

#### Scenario: Update on a group-shared dashboard rejects userId field changes

- GIVEN admin sends `PUT /api/dashboards/group/marketing/D1.uuid` with body `{"userId": "alice"}`
- THEN the system MUST ignore the `userId` field
- AND `D1.userId` MUST remain null
- AND `D1.type` MUST remain `'group_shared'`

#### Scenario: GroupId mismatch between path and record returns 404

- GIVEN dashboard `D1` has `groupId='marketing'`
- WHEN admin sends `GET /api/dashboards/group/engineering/D1.uuid`
- THEN the system MUST return HTTP 404 (the dashboard does not belong to the group named in the path)

#### Scenario: GET /api/dashboards remains backward-compatible

- GIVEN user "alice" has 2 personal dashboards
- AND admin has created 3 group-shared dashboards visible to her
- WHEN she sends `GET /api/dashboards` (the legacy listing endpoint)
- THEN the response MUST contain only her 2 personal dashboards
- AND MUST NOT contain any of the group-shared ones
- NOTE: clients wanting the union must call `GET /api/dashboards/visible`; this preserves REQ-DASH-002 semantics for older API consumers

#### Scenario: Group-shared dashboard serialisation includes `groupId`

- GIVEN a group-shared dashboard `D1` is returned via any endpoint
- WHEN the JSON payload is inspected
- THEN it MUST contain `groupId` equal to the dashboard's group ID (a non-null string)
- AND personal / admin_template dashboards in any payload MUST contain `groupId: null`

### Requirement: REQ-DASH-020 Fork any visible dashboard as a personal copy

The system MUST expose `POST /api/dashboards/{uuid}/fork` that creates a new `user`-type dashboard owned by the calling user, deep-copying all widget placements from the source. The new dashboard MUST become the user's active dashboard. Forking MUST be gated on the admin setting `allow_user_dashboards = '1'`; otherwise the endpoint MUST return HTTP 403.

#### Scenario: Fork a group-shared dashboard

- GIVEN admin setting `allow_user_dashboards = '1'`
- AND user "alice" can read group-shared dashboard `S` (groupId='marketing') containing 4 widget placements
- WHEN she sends `POST /api/dashboards/{S.uuid}/fork` with body `{"name": "My Marketing"}`
- THEN the system MUST create a new dashboard `F` with `userId = 'alice'`, `type = 'user'`, `groupId = null`, `isDefault = 0`, `isActive = 1` (and all other alice-owned dashboards deactivated), `gridColumns = S.gridColumns`, and `name = "My Marketing"`
- AND `F` MUST contain 4 widget placements that are byte-for-byte clones of `S`'s placements (same gridX/Y/W/H, customTitle, styleConfig, tile fields) with new placement IDs and `dashboardId = F.id`
- AND `S` MUST remain unchanged
- AND the response MUST be HTTP 201 with the full `F` payload

#### Scenario: Fork uses a default name when none provided

- GIVEN dashboard `S` has `name = "Marketing Overview"`
- WHEN alice sends `POST /api/dashboards/{S.uuid}/fork` with empty body
- THEN the new dashboard's `name` MUST be `"My copy of Marketing Overview"` (translated string `t('My copy of {name}', {name: S.name})`)

#### Scenario: Fork is gated on admin setting

- GIVEN admin setting `allow_user_dashboards = '0'`
- WHEN alice sends `POST /api/dashboards/{S.uuid}/fork` with any body
- THEN the system MUST return HTTP 403 with the stable error envelope `{status: 'error', error: 'personal_dashboards_disabled', message: 'Personal dashboards are not enabled by your administrator'}` (REQ-ASET-003 cross-reference)

#### Scenario: Cannot fork a dashboard you cannot read

- GIVEN group-shared dashboard `T` exists in group "executives" and alice does NOT belong to that group
- WHEN alice sends `POST /api/dashboards/{T.uuid}/fork`
- THEN the system MUST return HTTP 404 (do not leak existence)

#### Scenario: Forking a personal dashboard creates an independent duplicate

- GIVEN alice already has personal dashboard `P` with 2 placements
- WHEN she sends `POST /api/dashboards/{P.uuid}/fork`
- THEN the system MUST create a new dashboard `P2` that is a deep clone of `P`
- AND mutating `P2` MUST NOT affect `P` (and vice versa)

### Requirement: REQ-DASH-021 Fork is transactional

The fork operation MUST execute inside a single database transaction. If any part fails (placement insert, deactivation of other dashboards, etc.) the entire fork MUST be rolled back and HTTP 500 returned.

#### Scenario: Partial-failure rollback

- GIVEN any database error occurs while inserting cloned placements
- WHEN the fork endpoint catches the error
- THEN the new dashboard row MUST also be removed (transaction rolled back)
- AND `S`'s placements MUST remain visible to alice
- AND alice's previously active dashboard (if any) MUST remain active

### Requirement: REQ-DASH-022 Fork does not duplicate uploaded resources

When cloned placements reference uploaded resources (e.g. `tileIcon` URLs starting with `/apps/mydash/resource/...`, or widget content fields with similar URLs), the fork MUST keep the same URL — it MUST NOT duplicate the underlying resource bytes. Both dashboards then reference the shared resource record.

#### Scenario: Shared resource reference

- GIVEN dashboard `S` has a tile placement with `tileIcon = '/apps/mydash/resource/abc123.png'`
- WHEN alice forks `S`
- THEN `F`'s corresponding placement MUST have `tileIcon = '/apps/mydash/resource/abc123.png'` (same URL)
- AND no new file MUST be created in app data

## Non-Functional Requirements

- **Performance**: GET /api/dashboards MUST return within 500ms for users with up to 50 dashboards. GET /api/dashboard MUST return within 1 second including template distribution if needed.
- **Data integrity**: The single-active-dashboard invariant MUST be enforced consistently, even under concurrent requests from the same user.
- **Accessibility**: Dashboard management UI elements (create, edit, delete, activate) MUST be operable via keyboard and screen readers.
- **Localization**: All error messages and validation messages MUST support English and Dutch.

### Current Implementation Status

**Fully implemented:**
- REQ-DASH-001 (Create Personal Dashboard): `DashboardService::createDashboard()` delegates to `DashboardFactory::create()`. Default placements created via `createDefaultPlacements()` during first-time access.
- REQ-DASH-002 (List User Dashboards): `DashboardService::getUserDashboards()` calls `DashboardMapper::findByUserId()`. User-scoped, templates filtered out.
- REQ-DASH-003 (Get Active Dashboard): `DashboardService::getEffectiveDashboard()` chains `tryGetActiveDashboard` -> `tryActivateExistingDashboard` -> `tryCreateFromTemplate`.
- REQ-DASH-004 (Update Dashboard): `DashboardService::updateDashboard()` with `applyDashboardUpdates()` handles name, description, gridColumns, placements.
- REQ-DASH-005 (Delete Dashboard): `DashboardService::deleteDashboard()` deletes placements then dashboard.
- REQ-DASH-006 (Activate Dashboard): `DashboardService::activateDashboard()` via `DashboardMapper::setActive()`.
- REQ-DASH-008 (Dashboard Type Enforcement): Admin templates via `AdminController`, user dashboards via `DashboardFactory`.
- REQ-DASH-009 (Dashboard Resolution Chain): Full chain implemented in `DashboardService::getEffectiveDashboard()`.
- REQ-DASH-020 (Fork as Personal): `DashboardService::forkAsPersonal()` wraps `WidgetPlacementMapper::cloneToDashboard()` in a single `IDBConnection::beginTransaction` — gated via `assertPersonalDashboardsAllowed()` (REQ-ASET-003) and resolved against the visible-to-user chain (REQ-DASH-013). Endpoint: `POST /api/dashboards/{uuid}/fork` on `DashboardApiController::fork`.
- REQ-DASH-021 (Fork is transactional): rollback covered by the wide `Throwable` catch in `forkAsPersonal()` — exercised by `DashboardServiceForkTest::testForkRollsBackOnPlacementCloneFailure`.
- REQ-DASH-022 (Shared resource references): `WidgetPlacementMapper::cloneToDashboard()` copies `tileIcon` and other `/apps/mydash/resource/...` URLs verbatim — no resource bytes are duplicated. Cross-references the resource-uploads change.

**Not yet implemented:**
- REQ-DASH-001/007 validation: No name or gridColumns validation.
- REQ-DASH-004 grid reflow: Updating gridColumns does not reposition widgets.
- REQ-DASH-005 auto-activate after delete: Not implemented.
- REQ-DASH-005 cascade-delete conditional rules: Not explicitly handled.

### Standards & References
- Nextcloud Controller patterns: `OCP\AppFramework\Controller`, `#[NoAdminRequired]` attribute
- UUID generation: Custom UUID v4 implementation in `DashboardFactory::generateUuid()`
- WCAG 2.1 AA: Dashboard management UI elements should be keyboard-operable
