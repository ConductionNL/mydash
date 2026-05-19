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

### Requirement: REQ-DASH-015 Single default group-shared dashboard per group

Within each group (including the synthetic `'default'` group), at most one `group_shared` dashboard MAY have `isDefault = 1`. Switching a dashboard to default MUST atomically clear the flag on any other dashboard in the same group. The transition MUST run inside a single database transaction so concurrent calls cannot leave two dashboards with `isDefault = 1` in the same group.

#### Scenario: Setting default flips others off

- GIVEN group "marketing" has 3 group-shared dashboards: `A` (`isDefault=1`), `B`, `C`
- WHEN admin sends `POST /api/dashboards/group/marketing/default` with body `{"uuid": "<C.uuid>"}`
- THEN `C.isDefault` MUST become `1`
- AND `A.isDefault` MUST become `0`
- AND `B.isDefault` MUST remain `0`

#### Scenario: Default cannot be set across groups

- GIVEN dashboard `D1` has `groupId = 'marketing'`
- WHEN admin sends `POST /api/dashboards/group/sales/default` with body `{"uuid": "<D1.uuid>"}`
- THEN the system MUST return HTTP 404
- AND no `isDefault` flag MUST be modified on any dashboard

#### Scenario: Setting non-existent dashboard as default

- GIVEN group "marketing" exists with no dashboards (or no dashboard with the given uuid)
- WHEN admin sends `POST /api/dashboards/group/marketing/default` with a uuid that does not match any dashboard in the group
- THEN the system MUST return HTTP 404

#### Scenario: Non-admin cannot set default

- GIVEN user "alice" who is not an administrator
- WHEN she sends `POST /api/dashboards/group/marketing/default` with any body
- THEN the system MUST return HTTP 403
- AND no `isDefault` flag MUST be modified

#### Scenario: Transaction safety under concurrent calls

- GIVEN group "marketing" has 3 group-shared dashboards `A`, `B`, `C` with `A.isDefault=1`
- WHEN two admins concurrently send `POST /api/dashboards/group/marketing/default` with body `{"uuid": "<B.uuid>"}` and `{"uuid": "<C.uuid>"}` respectively
- THEN exactly one of `B` or `C` MUST end up with `isDefault=1`
- AND the other two dashboards in the group MUST have `isDefault=0`
- AND no row MUST be left with `isDefault=1` for two different uuids in the same group

### Requirement: REQ-DASH-016 New group-shared dashboards default to non-default

When a `group_shared` dashboard is created via `POST /api/dashboards/group/{groupId}`, the system MUST set `isDefault = 0` regardless of any `isDefault` field present in the request body. Promoting a dashboard to default requires an explicit `POST /api/dashboards/group/{groupId}/default` call.

#### Scenario: Create-then-no-default

- GIVEN group "marketing" has no dashboards
- WHEN admin sends `POST /api/dashboards/group/marketing` with body `{"name": "First"}`
- THEN the resulting dashboard MUST have `isDefault = 0`
- AND no other dashboard MUST be created with `isDefault = 1`

#### Scenario: Create payload cannot smuggle isDefault

- GIVEN group "marketing" has no dashboards
- WHEN admin sends `POST /api/dashboards/group/marketing` with body `{"name": "Sneaky", "isDefault": 1}`
- THEN the resulting dashboard MUST have `isDefault = 0`
- AND the `isDefault` field in the request body MUST be ignored by `DashboardService::saveGroupShared`

#### Scenario: First dashboard in a group is not auto-promoted

- GIVEN group "engineering" has zero group-shared dashboards
- WHEN admin creates the first group-shared dashboard `D1` via `POST /api/dashboards/group/engineering`
- THEN `D1.isDefault` MUST be `0`
- AND the active-dashboard resolution chain MUST fall through to "first by sortOrder" semantics rather than implicitly promoting `D1`

### Requirement: REQ-DASH-017 Default flag survives admin edits

Updates to a group-shared dashboard via `PUT /api/dashboards/group/{groupId}/{uuid}` MUST NOT change the `isDefault` flag, regardless of payload contents. The flag is only mutated by the dedicated `POST /api/dashboards/group/{groupId}/default` endpoint.

#### Scenario: PUT cannot flip the default off

- GIVEN dashboard `A` has `isDefault = 1`
- WHEN admin sends `PUT /api/dashboards/group/marketing/<A.uuid>` with body `{"name": "Renamed", "isDefault": 0}`
- THEN `A.name` MUST become "Renamed"
- AND `A.isDefault` MUST remain `1`

#### Scenario: PUT cannot flip the default on

- GIVEN dashboard `B` has `isDefault = 0`
- AND dashboard `A` in the same group has `isDefault = 1`
- WHEN admin sends `PUT /api/dashboards/group/marketing/<B.uuid>` with body `{"name": "Renamed", "isDefault": 1}`
- THEN `B.name` MUST become "Renamed"
- AND `B.isDefault` MUST remain `0`
- AND `A.isDefault` MUST remain `1`

### Requirement: REQ-DASH-018 Active-dashboard resolution chain (multi-scope)

When the workspace page renders for a user, the system MUST resolve which dashboard is "active" by walking the following precedence and stopping at the first match:

1. The dashboard whose UUID equals the user's `active_dashboard_uuid` preference, IF that dashboard is currently visible to the user (per REQ-DASH-013).
2. The `group_shared` dashboard with `isDefault = 1` in the user's primary group (per REQ-DASH-015).
3. The `group_shared` dashboard with `isDefault = 1` in the synthetic `'default'` group.
4. The first `group_shared` dashboard (by `sortOrder` ascending, then `createdAt`) in the user's primary group.
5. The first `group_shared` dashboard in the `'default'` group.
6. The user's first personal `user`-type dashboard (by `sortOrder`, then `createdAt`).
7. `null` — the workspace page MUST then render an empty-state with a "Create your first dashboard" affordance.

The resolver MUST attach a `source` field to the returned dashboard descriptor with one of `'user'`, `'group'`, `'default'`. REQ-DASH-018 supersedes REQ-DASH-009 for the workspace boot path; REQ-DASH-009 remains in force for personal-only callers (e.g. `GET /api/dashboard`).

#### Scenario: Honoured user preference

- GIVEN user "alice" has `active_dashboard_uuid` set to `<X.uuid>`
- AND `X` is a personal dashboard owned by alice
- WHEN she opens the workspace page
- THEN the resolved active dashboard MUST be `X` with `source = 'user'`

#### Scenario: Stale preference is silently cleared

- GIVEN user "alice" has `active_dashboard_uuid` set to `<Y.uuid>`
- AND `Y` has been deleted (or is no longer visible to alice)
- WHEN she opens the workspace page
- THEN the resolver MUST clear her `active_dashboard_uuid` preference (set to empty string or unset)
- AND MUST proceed down the precedence chain
- AND the response MUST NOT raise an error to the user

#### Scenario: Group default wins over default-group default

- GIVEN user "bob" belongs to group "engineering"
- AND group "engineering" has a default dashboard `E`
- AND the `'default'` group also has a default dashboard `D`
- AND bob has no `active_dashboard_uuid` preference
- WHEN he opens the workspace page
- THEN the resolved dashboard MUST be `E` with `source = 'group'`

#### Scenario: Falls through to default group when primary group has no dashboards

- GIVEN user "carol" belongs to group "support" which has zero group-shared dashboards
- AND the `'default'` group has one default dashboard `D`
- WHEN she opens the workspace page
- THEN the resolved dashboard MUST be `D` with `source = 'default'`

#### Scenario: Empty state when no dashboards exist anywhere

- GIVEN a brand-new MyDash install with no dashboards of any type
- WHEN any user opens the workspace page
- THEN the resolver MUST return `null`
- AND the response MUST include `activeDashboardId: ''` in initial state
- AND the page MUST render the empty-state UI

### Requirement: REQ-DASH-019 Persist active-dashboard preference

The system MUST expose `POST /api/dashboards/active` accepting `{uuid: string}`. On success it MUST persist the value to the user's `active_dashboard_uuid` preference (stored in `oc_preferences` via `IConfig::setUserValue`).

#### Scenario: Save preference

- GIVEN user "alice" is logged in
- WHEN she sends `POST /api/dashboards/active` with body `{"uuid": "abc-123"}`
- THEN her `active_dashboard_uuid` preference MUST become `"abc-123"`
- AND the response MUST be HTTP 200 `{status: 'success'}`

#### Scenario: Empty uuid clears the preference

- GIVEN alice has a saved preference
- WHEN she sends `POST /api/dashboards/active` with body `{"uuid": ""}`
- THEN her `active_dashboard_uuid` preference MUST be cleared (next page load falls through the chain from step 2)

#### Scenario: No existence check on write

- GIVEN alice sends `POST /api/dashboards/active` with body `{"uuid": "does-not-exist"}`
- THEN the system MUST accept the write (HTTP 200)
- NOTE: The resolver's stale-preference path (REQ-DASH-018 scenario "stale preference is silently cleared") will silently clear it on next render. We deliberately do not validate on write to keep the endpoint cheap.

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

### Requirement: REQ-DASH-023 Dashboard hierarchy and parent relationship

The system MUST support an optional parent-child hierarchy among dashboards. Each dashboard MAY have a parent dashboard specified by UUID in a nullable `parentUuid` column. Dashboards with no parent are root-level dashboards. A dashboard MAY have unlimited children, but the total depth (root + descendants) MUST NOT exceed 5 levels.

#### Scenario: Create a child dashboard

- GIVEN user "alice" has a root dashboard "Marketing" with UUID `uuid-marketing`
- WHEN she sends POST /api/dashboard with body `{"name": "Q1 Campaigns", "parentUuid": "uuid-marketing"}`
- THEN the system MUST create a dashboard with `parentUuid = "uuid-marketing"`
- AND the dashboard's depth-from-root MUST be 2 (root + 1 child)
- AND the response MUST return HTTP 201 with the new dashboard object

#### Scenario: Root dashboard has null parent

- GIVEN user "alice" creates a dashboard without specifying `parentUuid`
- WHEN she sends POST /api/dashboard with body `{"name": "Marketing"}`
- THEN the system MUST set `parentUuid = null`
- AND the dashboard MUST be a root-level dashboard

#### Scenario: Non-existent parent returns 400

- GIVEN a user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Child", "parentUuid": "uuid-nonexistent"}`
- THEN the system MUST return HTTP 400 with `{error: "Parent dashboard not found"}`
- AND no dashboard MUST be created

#### Scenario: Changing parent moves the subtree

- GIVEN user "alice" has dashboard tree: "Marketing" > "Q1 Campaigns" (child)
- WHEN she sends PUT /api/dashboard/q1-campaigns-id with body `{"parentUuid": "uuid-finance"}`
- THEN the system MUST move "Q1 Campaigns" under "Finance"
- AND the dashboard's `parentUuid` MUST be updated to `uuid-finance`
- AND the computed path MUST change from `/marketing/q1-campaigns` to `/finance/q1-campaigns`

#### Scenario: Reading a dashboard includes parent reference

- GIVEN dashboard "Q1 Campaigns" with `parentUuid = "uuid-marketing"`
- WHEN the dashboard is fetched via GET /api/dashboard/{id}
- THEN the response MUST include `parentUuid: "uuid-marketing"`

#### Scenario: Null parent is preserved in serialization

- GIVEN a root dashboard with `parentUuid = null`
- WHEN the dashboard is serialized
- THEN the JSON MUST include `parentUuid: null`

### Requirement: REQ-DASH-024 Slug uniqueness and path resolution

Each dashboard MUST have a `slug` field — a URL-safe string unique among its siblings (dashboards sharing the same parent). Slugs are auto-generated from the dashboard name if not supplied, and MAY be manually overridden. Slugs are used to form human-readable paths like `/marketing/campaigns/q1`.

#### Scenario: Slug auto-generation from name

- GIVEN a user "alice" creates a dashboard with name "Q1 Campaigns" and no explicit slug
- WHEN she sends POST /api/dashboard with body `{"name": "Q1 Campaigns"}`
- THEN the system MUST auto-generate `slug = "q1-campaigns"` (lowercased, spaces to dashes, max 128 chars)
- AND the slug MUST be stored in the database

#### Scenario: Slug uniqueness among siblings

- GIVEN user "alice" has a parent dashboard "Marketing" with child "Q1 Campaigns" (slug `q1-campaigns`)
- WHEN she sends POST /api/dashboard with body `{"name": "Q1 Campaigns", "parentUuid": "uuid-marketing"}` (attempting to create a second sibling with the same slug)
- THEN the system MUST return HTTP 400 with `{error: "Slug must be unique among siblings"}`
- AND no dashboard MUST be created

#### Scenario: Custom slug override

- GIVEN a user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Q1 Campaigns", "slug": "q1-custom"}`
- THEN the system MUST use the supplied slug `q1-custom`
- AND NOT auto-generate one from the name

#### Scenario: Slug validation characters

- GIVEN a user "alice"
- WHEN she sends POST /api/dashboard with body `{"slug": "q1 campaigns!"}`
- THEN the system MUST reject the slug and return HTTP 400
- AND slugs MUST only allow alphanumeric, dash, and underscore characters

#### Scenario: Reading a dashboard includes slug

- GIVEN a dashboard with `slug = "q1-campaigns"`
- WHEN the dashboard is fetched via GET /api/dashboard/{id}
- THEN the response MUST include `slug: "q1-campaigns"`

#### Scenario: Slug update does not auto-regenerate on name change

- GIVEN a dashboard with `name = "Q1 Campaigns"` and `slug = "q1"`
- WHEN user sends PUT /api/dashboard/{id} with body `{"name": "Q2 Campaigns"}` (name change, no slug supplied)
- THEN the system MUST preserve `slug = "q1"`
- AND MUST NOT auto-regenerate the slug to `q2-campaigns`

### Requirement: REQ-DASH-025 Computed path and breadcrumb navigation

The system MUST compute a `path` field on demand (not stored) by joining the slug chain from root to the target dashboard. The system MUST also compute `breadcrumbs` — an ordered list of `{uuid, name, slug}` objects from root to the target dashboard, used for navigation UI.

#### Scenario: Compute path for root dashboard

- GIVEN a root dashboard with `slug = "marketing"`
- WHEN the path is computed
- THEN the path MUST equal `/marketing`

#### Scenario: Compute path for nested dashboard

- GIVEN a dashboard tree: "Marketing" (slug `marketing`) > "Campaigns" (slug `campaigns`) > "Q1" (slug `q1`)
- WHEN the path is computed for "Q1"
- THEN the path MUST equal `/marketing/campaigns/q1`

#### Scenario: Path updates when parent changes

- GIVEN dashboard "Q1" with computed path `/marketing/campaigns/q1`
- WHEN the parent of "Q1" is changed to "Finance" (slug `finance`)
- THEN on next read, the path MUST equal `/finance/q1`

#### Scenario: Breadcrumbs from root to target

- GIVEN dashboard "Q1" in tree "Marketing" > "Campaigns" > "Q1"
- WHEN breadcrumbs are computed
- THEN the breadcrumbs MUST be:
  - `{uuid: "uuid-marketing", name: "Marketing", slug: "marketing"}`
  - `{uuid: "uuid-campaigns", name: "Campaigns", slug: "campaigns"}`
  - `{uuid: "uuid-q1", name: "Q1", slug: "q1"}`
- AND the list MUST be ordered from root to leaf

#### Scenario: Root dashboard has single-item breadcrumbs

- GIVEN a root dashboard with `uuid = "uuid-root"`, `name = "Marketing"`, `slug = "marketing"`
- WHEN breadcrumbs are computed
- THEN the breadcrumbs MUST be a single-item array: `{uuid: "uuid-root", name: "Marketing", slug: "marketing"}`

#### Scenario: Breadcrumbs accessible via API

- GIVEN a dashboard is returned via any GET endpoint
- WHEN the response is inspected
- THEN it SHOULD include a computed `breadcrumbs` field (optional per endpoint; at minimum available via `/api/dashboards/by-path/...`)

### Requirement: REQ-DASH-026 Tree API endpoint

The system MUST expose `GET /api/dashboards/tree` returning the full visible tree of dashboards as a nested structure `{uuid, name, slug, children: [...]}`, allowing the frontend to render collapsible hierarchies.

#### Scenario: Tree endpoint returns nested structure

- GIVEN user "alice" has dashboards: "Marketing" (root) with child "Campaigns", and "Finance" (root) with child "Budget"
- WHEN she sends GET /api/dashboards/tree
- THEN the response MUST contain two root objects in the `children` array:
  - `{uuid: "uuid-marketing", name: "Marketing", slug: "marketing", children: [{uuid: "uuid-campaigns", ...}]}`
  - `{uuid: "uuid-finance", name: "Finance", slug: "finance", children: [{uuid: "uuid-budget", ...}]}`
- AND each node MUST include `uuid`, `name`, `slug`, and `children` (empty array if no children)

#### Scenario: Tree endpoint respects user ownership

- GIVEN user "alice" has 3 root dashboards and user "bob" has 2 root dashboards
- WHEN alice sends GET /api/dashboards/tree
- THEN the response MUST include only alice's 3 root dashboards (and their subtrees)
- AND bob's dashboards MUST NOT be included

#### Scenario: Tree endpoint includes sort order

- GIVEN user "alice" has root dashboards with `sortOrder = 10, 5, 20` and same parent
- WHEN she sends GET /api/dashboards/tree
- THEN the `children` array MUST be sorted by `sortOrder` (5, 10, 20)
- AND ties MUST be broken alphabetically by `name`

#### Scenario: Empty tree returns empty children array

- GIVEN user "bob" has no dashboards
- WHEN he sends GET /api/dashboards/tree
- THEN the response MUST be an empty array (or `{children: []}` depending on schema)

### Requirement: REQ-DASH-027 Path-based dashboard resolution

The system MUST expose `GET /api/dashboards/by-path/{*path}` to resolve a slug chain (e.g., `/marketing/campaigns/q1`) to the dashboard at that location, returning the dashboard object with computed breadcrumbs and path.

#### Scenario: Resolve path to dashboard

- GIVEN user "alice" has dashboard tree "Marketing" > "Campaigns" > "Q1"
- WHEN she sends GET /api/dashboards/by-path/marketing/campaigns/q1
- THEN the response MUST return the "Q1" dashboard object with computed path `/marketing/campaigns/q1` and breadcrumbs array

#### Scenario: Path not found returns 404

- GIVEN user "alice" has dashboard tree "Marketing" > "Campaigns" but no "Q2"
- WHEN she sends GET /api/dashboards/by-path/marketing/campaigns/q2
- THEN the system MUST return HTTP 404 with `{error: "Dashboard not found at path"}`

#### Scenario: User cannot access other user's dashboard via path

- GIVEN user "alice" has dashboard "Marketing" (slug `marketing`) and user "bob" has a different dashboard with the same slug
- WHEN alice sends GET /api/dashboards/by-path/marketing
- THEN the response MUST return only alice's "Marketing" dashboard
- AND bob's dashboard MUST NOT be accessible to alice

#### Scenario: Path is case-insensitive

- GIVEN user "alice" has dashboard with `slug = "marketing"`
- WHEN she sends GET /api/dashboards/by-path/MARKETING
- THEN the system MUST resolve to the dashboard (slugs are stored lowercase, comparison must be case-insensitive or stored-case-matching)

#### Scenario: Trailing slash is optional

- GIVEN user "alice" has dashboard at path `/marketing/campaigns/q1`
- WHEN she sends GET /api/dashboards/by-path/marketing/campaigns/q1/ (with trailing slash)
- THEN the system MUST resolve to the dashboard (trailing slash must be ignored or normalized)

### Requirement: REQ-DASH-028 Cycle prevention and depth validation

The system MUST prevent setting a dashboard's parent to any of its own descendants (cycle prevention) and MUST enforce a maximum tree depth of 5 levels (root + 4 descendants).

#### Scenario: Cycle detection on parent update

- GIVEN user "alice" has dashboard tree "A" > "B" > "C"
- WHEN she sends PUT /api/dashboard/a-id with body `{"parentUuid": "uuid-c"}`
- THEN the system MUST return HTTP 400 with `{error: "Setting this parent would create a cycle"}`
- AND the dashboard MUST NOT be updated

#### Scenario: Self-parenting is rejected

- GIVEN dashboard "Marketing" with UUID `uuid-marketing`
- WHEN user "alice" sends PUT /api/dashboard/marketing-id with body `{"parentUuid": "uuid-marketing"}`
- THEN the system MUST return HTTP 400
- AND the dashboard MUST NOT be updated (cannot be its own parent)

#### Scenario: Max depth exceeded on create

- GIVEN user "alice" has a 5-level tree: A > B > C > D > E (depth = 5)
- WHEN she sends POST /api/dashboard with body `{"name": "F", "parentUuid": "uuid-e"}` (attempting to add a 6th level)
- THEN the system MUST return HTTP 400 with `{error: "Cannot exceed maximum tree depth of 5 levels"}`
- AND no dashboard MUST be created

#### Scenario: Max depth exceeded on parent update

- GIVEN user "alice" has two trees: A > B > C > D (4 levels) and X > Y > Z (3 levels)
- WHEN she sends PUT /api/dashboard/x-id with body `{"parentUuid": "uuid-d"}` (attempting to nest X > Y > Z under the 4-level tree, creating 7 levels total)
- THEN the system MUST return HTTP 400 with `{error: "Cannot exceed maximum tree depth of 5 levels"}`
- AND the parent MUST NOT be updated

#### Scenario: Exactly 5 levels is allowed

- GIVEN user "alice" creates a 5-level tree: A > B > C > D > E (depth = 5)
- WHEN she sends POST /api/dashboard with body `{"name": "NewRoot"}`
- THEN the system MUST allow creating the new root dashboard
- AND multiple independent 5-level trees MAY coexist

### Requirement: REQ-DASH-029 Sibling ordering

Dashboards sharing the same parent MUST be ordered by a `sortOrder INT` column. Ties in `sortOrder` MUST be broken alphabetically by `name`. Ordering MUST be reflected in all tree and list responses.

#### Scenario: Default sort order on create

- GIVEN user "alice" creates dashboard "Marketing" without specifying `sortOrder`
- WHEN the dashboard is created
- THEN `sortOrder` MUST default to 0

#### Scenario: Custom sort order on create

- GIVEN user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Marketing", "sortOrder": 100}`
- THEN the dashboard MUST be created with `sortOrder = 100`

#### Scenario: Sort order respected in tree

- GIVEN user "alice" has three root dashboards with `sortOrder = 20, 5, 15` respectively
- WHEN she sends GET /api/dashboards/tree
- THEN the `children` array MUST be ordered as: sortOrder 5, 15, 20

#### Scenario: Tie-breaking by name

- GIVEN user "alice" has two sibling dashboards both with `sortOrder = 0`, named "Zebra" and "Alice"
- WHEN she sends GET /api/dashboards/tree
- THEN the `children` array MUST be ordered as: "Alice" then "Zebra" (alphabetically)

#### Scenario: Update sort order via PUT

- GIVEN user "alice" has dashboard with `sortOrder = 10`
- WHEN she sends PUT /api/dashboard/{id} with body `{"sortOrder": 50}`
- THEN the dashboard MUST be updated to `sortOrder = 50`
- AND tree responses MUST reflect the new order

### Requirement: REQ-DASH-030 Cascade deletion with guard

Deleting a dashboard with children MUST require an explicit `?cascade=true` query parameter. Without it, the system MUST return HTTP 409 with the count of children, preventing accidental loss of subtrees.

#### Scenario: Delete parent without cascade returns 409

- GIVEN user "alice" has dashboard "Marketing" with 3 child dashboards
- WHEN she sends DELETE /api/dashboard/marketing-id (without `?cascade=true`)
- THEN the system MUST return HTTP 409 with `{error: "Dashboard has 3 children. Use ?cascade=true to delete the subtree."}`
- AND the dashboard MUST NOT be deleted

#### Scenario: Delete parent with cascade deletes subtree

- GIVEN user "alice" has dashboard "Marketing" > "Campaigns" > "Q1" (3 total)
- WHEN she sends DELETE /api/dashboard/marketing-id?cascade=true
- THEN the system MUST delete all 3 dashboards
- AND all associated placements MUST be cascade-deleted per REQ-DASH-005
- AND the response MUST return HTTP 200

#### Scenario: Delete childless dashboard has no guard

- GIVEN user "alice" has a root dashboard with no children
- WHEN she sends DELETE /api/dashboard/{id} (with or without `?cascade=true`)
- THEN the system MUST delete the dashboard (no cascade guard needed)
- AND the response MUST return HTTP 200

#### Scenario: Cascade parameter is case-insensitive

- GIVEN user "alice" has a dashboard with children
- WHEN she sends DELETE /api/dashboard/{id}?cascade=TRUE or ?cascade=Cascade
- THEN the system MUST interpret it as true and delete the subtree

#### Scenario: User cannot delete another user's dashboard subtree

- GIVEN user "alice" has a dashboard tree
- WHEN user "bob" sends DELETE /api/dashboard/alice-dashboard-id?cascade=true
- THEN the system MUST return HTTP 403 (ownership check fails)
- AND alice's dashboards MUST NOT be deleted

### Requirement: REQ-DASH-031 Publication-state schema

The system MUST track dashboard publication state via three new database columns: `publication_status` (string enum), `publish_at` (nullable datetime), and `published_at` (nullable datetime). These columns enable the draft → published → scheduled workflow on top of the existing `oc_mydash_dashboards` table without breaking pre-existing rows.

#### Scenario: Schema addition and migration backfill

- GIVEN a MyDash instance with existing dashboards before the publication-state migration
- WHEN migration `Version001011Date20260502130000` is applied
- THEN the schema MUST add three columns to `oc_mydash_dashboards`:
  - `publication_status VARCHAR(20) NOT NULL DEFAULT 'published'`
  - `publish_at DATETIME NULL`
  - `published_at DATETIME NULL`
- AND all existing dashboard rows MUST acquire `publication_status = 'published'` automatically via the column default (no explicit UPDATE statement is needed — design D1)
- AND a composite index `mydash_dash_user_pubstatus` on `(user_id, publication_status)` MUST be created
- NOTE: New dashboards created after the migration default to `'draft'` via application logic in `DashboardFactory::create()`, NOT via the column default. The column default exists only to backfill pre-existing rows safely.

#### Scenario: Timestamp formats

- GIVEN a dashboard with `publishAt` or `publishedAt` set
- WHEN the dashboard is serialized to JSON via `Dashboard::jsonSerialize()`
- THEN both timestamps MUST be returned as `Y-m-d H:i:s` strings (the canonical storage format used elsewhere on the entity)
- AND null timestamps MUST be present in the JSON envelope with the value `null`

#### Scenario: Scheduled state requires publishAt

- GIVEN a dashboard with `publicationStatus = 'scheduled'`
- THEN `publishAt` MUST be a non-null timestamp strictly greater than `now()` at the moment of the schedule call
- AND attempting to schedule a dashboard with a past or null `publishAt` MUST raise the canonical `InvalidArgumentException` mapped to HTTP 400

### Requirement: REQ-DASH-032 Publish action

The system MUST expose `POST /api/dashboards/{uuid}/publish` that transitions a dashboard to `published` and stamps `publishedAt = now()` the first time the transition occurs. The action MUST be idempotent and gated to the dashboard owner or a Nextcloud administrator.

#### Scenario: Publish a draft dashboard

- GIVEN user "alice" has a draft dashboard with `uuid = "d123"`
- WHEN alice sends `POST /api/dashboards/d123/publish`
- THEN the system MUST set `publicationStatus = 'published'`
- AND set `publishedAt = now()` (because it was previously null)
- AND clear `publishAt` to `null`
- AND return HTTP 200 with the updated dashboard payload

#### Scenario: Publish is idempotent

- GIVEN user "alice" has an already-published dashboard with `publishedAt = '2026-03-20 14:30:00'`
- WHEN alice sends `POST /api/dashboards/{uuid}/publish` again
- THEN the system MUST return HTTP 200 with the unchanged dashboard
- AND `publishedAt` MUST remain `'2026-03-20 14:30:00'` (not refreshed to the current time)

#### Scenario: Only owner or admin can publish

- GIVEN user "alice" has a draft dashboard
- WHEN user "bob" (non-owner, non-admin) sends `POST /api/dashboards/{alice's-uuid}/publish`
- THEN the system MUST return HTTP 403 with the canonical error message `Forbidden: owner or admin only`
- AND the dashboard MUST remain in draft state
- AND a Nextcloud administrator "root" MUST be able to publish alice's dashboard via the same endpoint

### Requirement: REQ-DASH-033 Unpublish action

The system MUST expose `POST /api/dashboards/{uuid}/unpublish` that returns a dashboard to draft state while preserving `publishedAt` for audit history. Owner-or-admin gated.

#### Scenario: Unpublish a published dashboard

- GIVEN user "alice" has a published dashboard with `publishedAt = '2026-03-20 14:30:00'`
- WHEN alice sends `POST /api/dashboards/{uuid}/unpublish`
- THEN the system MUST set `publicationStatus = 'draft'`
- AND `publishedAt` MUST remain `'2026-03-20 14:30:00'` (preserved for audit)
- AND `publishAt` MUST be cleared to `null`
- AND return HTTP 200 with the updated dashboard

#### Scenario: Unpublish hides dashboard from non-owners

- GIVEN user "alice" had previously published dashboard `D` and bob could see it via `GET /api/dashboards/visible`
- WHEN alice unpublishes `D`
- THEN bob's next `GET /api/dashboards/visible` MUST NOT include `D`
- AND alice MUST still see `D` in her own listing (owner-visibility preserved)

#### Scenario: Unpublish is idempotent

- GIVEN user "alice" has a draft dashboard
- WHEN alice sends `POST /api/dashboards/{uuid}/unpublish` (already draft)
- THEN the system MUST return HTTP 200 with the unchanged dashboard
- AND no state change MUST occur

### Requirement: REQ-DASH-034 Schedule action

The system MUST expose `POST /api/dashboards/{uuid}/schedule` accepting `{publishAt: ISO-8601}` to schedule a dashboard for automatic publication at a future moment. The system MUST treat scheduled dashboards whose `publishAt <= now()` as published on every read (lazy materialisation), with no dependency on a background job for correctness.

#### Scenario: Schedule a draft dashboard

- GIVEN user "alice" has a draft dashboard with `uuid = "d123"`
- AND the current time is `'2026-03-20 10:00:00'`
- WHEN alice sends `POST /api/dashboards/d123/schedule` with body `{"publishAt": "2026-04-01T10:00:00Z"}`
- THEN the system MUST set `publicationStatus = 'scheduled'`
- AND set `publishAt = '2026-04-01 10:00:00'` (normalised to the storage format)
- AND return HTTP 200 with the updated dashboard

#### Scenario: Cannot schedule with past date

- GIVEN the current time is `'2026-03-20 10:00:00'`
- WHEN user "alice" sends `POST /api/dashboards/{uuid}/schedule` with body `{"publishAt": "2026-03-19T10:00:00Z"}`
- THEN the system MUST return HTTP 400 with error message `publishAt must be a future timestamp`
- AND the dashboard state MUST NOT change
- AND the error message MUST be available in both Dutch and English (l10n entries `publishAt must be a future timestamp` registered in `l10n/{en,nl}.{js,json}`)

#### Scenario: Cannot schedule with empty / unparseable publishAt

- GIVEN any logged-in user
- WHEN they send `POST /api/dashboards/{uuid}/schedule` with body `{}` or `{"publishAt": "not-a-date"}`
- THEN the system MUST return HTTP 400 with the same `publishAt must be a future timestamp` message
- AND the dashboard state MUST NOT change

#### Scenario: Scheduled dashboard becomes visible when publishAt passes (lazy materialisation)

- GIVEN user "alice" scheduled a dashboard for `'2026-03-20 14:30:00'`
- AND the current server time is `'2026-03-20 14:35:00'`
- WHEN any user (including non-owners) calls `GET /api/dashboards/visible`
- THEN the dashboard MUST appear in the response with `publicationStatus = 'published'` (materialised at read time)
- AND the database row MAY still carry `publication_status = 'scheduled'` (lazy — no DB write required for correctness)

#### Scenario: Future-scheduled dashboard hidden from non-owners

- GIVEN alice scheduled a dashboard for `'2026-04-01 10:00:00'`
- AND the current server time is `'2026-03-20 10:00:00'`
- WHEN bob calls `GET /api/dashboards/visible`
- THEN bob MUST NOT see the scheduled dashboard
- AND alice (owner) MUST still see it with `publicationStatus = 'scheduled'` and the future `publishAt` timestamp

#### Scenario: Optional eager materialisation via DashboardService

- GIVEN one or more rows have `publication_status = 'scheduled'` and `publish_at <= now()`
- WHEN any caller invokes `DashboardService::materialiseScheduledDashboards()` (e.g. from a future cron job)
- THEN every due row MUST be flipped to `publication_status = 'published'` in the database
- AND `published_at` MUST be set to the current time when previously null
- AND the method MUST return the number of dashboards materialised
- NOTE: Lazy read-time materialisation remains the correctness contract (REQ-DASH-034 scenario "lazy materialisation"); this method is a cosmetic optimisation for cleaner audit data.

### Requirement: REQ-DASH-035 Migration backfill to published state

The publication-state migration MUST preserve the visibility of every dashboard that existed before the change. Pre-existing rows MUST default to `published` so users continue to see what they saw immediately before the upgrade.

#### Scenario: Existing dashboards default to published after migration

- GIVEN a MyDash instance with N existing dashboards before the migration
- WHEN `Version001011Date20260502130000::changeSchema()` runs
- THEN the `publication_status` column MUST be added with `DEFAULT 'published'`
- AND every existing row MUST acquire `'published'` via the column default — no explicit `UPDATE` statement is required (design D1)

#### Scenario: New dashboards default to draft despite the column default

- GIVEN the migration has run (column default is `'published'`)
- WHEN any user creates a new dashboard via `POST /api/dashboard`
- THEN the new dashboard MUST be persisted with `publicationStatus = 'draft'` because `DashboardFactory::create()` overrides the default before insertion
- AND the dashboard MUST NOT appear in `GET /api/dashboards/visible` for any non-owner non-admin caller until explicitly published

### Requirement: REQ-DASH-036 Draft visibility restrictions

A dashboard in `draft` state MUST be visible only to its owner and to Nextcloud administrators. Draft dashboards MUST NOT appear in any visible-dashboard listing for any other user.

#### Scenario: Draft dashboard hidden from other users

- GIVEN user "alice" has a draft dashboard `D`
- WHEN user "bob" calls `GET /api/dashboards/visible`
- THEN `D` MUST NOT be present in the response

#### Scenario: Draft dashboard visible to owner

- GIVEN user "alice" has a draft dashboard `D`
- WHEN alice calls `GET /api/dashboards/visible`
- THEN `D` MUST be present in the response with `publicationStatus = 'draft'`

#### Scenario: Admin can see draft dashboards of other users

- GIVEN user "alice" has a draft dashboard `D`
- AND "root" is a Nextcloud administrator
- WHEN root calls `GET /api/dashboards/visible`
- THEN `D` MUST be present in the response (admin-override visibility)

### Requirement: REQ-DASH-037 Frontend store mirrors publication state

The Pinia dashboard store MUST track `publicationStatus`, `publishAt`, and `publishedAt` for every dashboard fetched from `/api/dashboards/visible` or `/api/dashboard`. Store actions MUST exist for publish / unpublish / schedule and MUST patch the local copy in place on success so the UI reflects the new state without a full reload.

#### Scenario: Store exposes status constants

- GIVEN the dashboard store module is imported
- THEN it MUST export `STATUS_DRAFT`, `STATUS_PUBLISHED`, and `STATUS_SCHEDULED` constants matching the PHP entity values

#### Scenario: Client-side lazy materialisation hint

- GIVEN a scheduled dashboard with `publishAt` in the past relative to the browser clock
- WHEN any caller invokes `dashboardStore.effectivePublicationStatus(dashboard)`
- THEN the method MUST return `'published'` even if the stored `publicationStatus` is still `'scheduled'`
- NOTE: This is a UX hint only — the backend remains the source of truth and applies the same materialisation server-side.

#### Scenario: Publish / unpublish / schedule actions patch local state

- GIVEN any dashboard `D` is loaded in the store
- WHEN `dashboardStore.publishDashboard(D.uuid)` resolves successfully
- THEN the local copy in `dashboards[]` (and `activeDashboard` when matching) MUST receive the updated `publicationStatus`, `publishAt`, and `publishedAt` without a separate `loadDashboards()` round-trip

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
- REQ-DASH-018 (Active-dashboard resolution chain — multi-scope): `DashboardService::resolveActiveDashboard()` + 7-step chain, called from `PageController::index` and mirrored client-side in `useDashboardStore.resolveActive`.
- REQ-DASH-019 (Persist active-dashboard preference): `POST /api/dashboards/active` → `DashboardApiController::setActiveDashboard()` → `DashboardService::setActivePreference()`. Preference stored under `oc_preferences` key `active_dashboard_uuid`.
- REQ-DASH-020 (Fork as Personal): `DashboardService::forkAsPersonal()` wraps `WidgetPlacementMapper::cloneToDashboard()` in a single `IDBConnection::beginTransaction` — gated via `assertPersonalDashboardsAllowed()` (REQ-ASET-003) and resolved against the visible-to-user chain (REQ-DASH-013). Endpoint: `POST /api/dashboards/{uuid}/fork` on `DashboardApiController::fork`.
- REQ-DASH-021 (Fork is transactional): rollback covered by the wide `Throwable` catch in `forkAsPersonal()` — exercised by `DashboardServiceForkTest::testForkRollsBackOnPlacementCloneFailure`.
- REQ-DASH-022 (Shared resource references): `WidgetPlacementMapper::cloneToDashboard()` copies `tileIcon` and other `/apps/mydash/resource/...` URLs verbatim — no resource bytes are duplicated. Cross-references the resource-uploads change.
- REQ-DASH-023 (Hierarchy + parent relationship): `Dashboard.parentUuid` column added by `Version001010Date20260502120000`; `DashboardService::createDashboard()` and `applyTreeUpdates()` route through `DashboardTreeService::validateParent()` for cycle/depth/parent-existence guards.
- REQ-DASH-024 (Slug uniqueness): `Dashboard.slug` column added in the same migration; `SlugGenerator::slugify()` derives slugs from names; `DashboardTreeService::validateSlugUnique()` enforces per-parent uniqueness with self-exclusion.
- REQ-DASH-025 (Computed path + breadcrumbs): `DashboardTreeService::computePath()` and `computeBreadcrumbs()` walk the ancestor chain; the `/api/dashboards/by-path/{path}` endpoint attaches both to the response.
- REQ-DASH-026 (Tree API endpoint): `GET /api/dashboards/tree` → `DashboardApiController::tree()` → `DashboardTreeService::getFullTree()`; nested structure with `{uuid, name, slug, sortOrder, children}`.
- REQ-DASH-027 (Path resolution): `GET /api/dashboards/by-path/{path}` → `DashboardApiController::byPath()` → `DashboardTreeService::resolvePath()`; case-insensitive segment match, trailing slashes ignored.
- REQ-DASH-028 (Cycle + depth): `DashboardTreeService::validateParent()` runs DFS over the moving subtree's descendants AND the proposed parent's ancestors; `assertDepthWithinCap()` enforces `Dashboard::MAX_DEPTH = 5`.
- REQ-DASH-029 (Sibling ordering): `Dashboard.sortOrder` column added in the same migration; `DashboardMapper::findByParent()` sorts `sort_order ASC, name ASC`.
- REQ-DASH-030 (Cascade delete guard): `DashboardService::deleteDashboard()` raises `DashboardHasChildrenException` when children exist and `cascade=false`; the controller maps to HTTP 409 with `{childCount}`. `DashboardTreeService::deleteSubtree()` walks the subtree in a transaction when `cascade=true`.
- REQ-DASH-031 (Publication-state schema): `Dashboard.publicationStatus` / `publishAt` / `publishedAt` columns added by `Version001011Date20260502130000`; entity exposes `STATUS_DRAFT` / `STATUS_PUBLISHED` / `STATUS_SCHEDULED` constants and `jsonSerialize()` includes all three fields.
- REQ-DASH-032 (Publish action): `POST /api/dashboards/{uuid}/publish` → `DashboardApiController::publish()` → `DashboardService::publish()`. Idempotent — `publishedAt` is only stamped on first publish; `publishAt` is cleared.
- REQ-DASH-033 (Unpublish action): `POST /api/dashboards/{uuid}/unpublish` → `DashboardApiController::unpublish()` → `DashboardService::unpublish()`. Preserves `publishedAt` for audit; clears `publishAt`.
- REQ-DASH-034 (Schedule action + lazy materialisation): `POST /api/dashboards/{uuid}/schedule` → `DashboardApiController::schedule()` → `DashboardService::schedule()`. `parseFuturePublishAt()` enforces strictly-future timestamps and raises `InvalidArgumentException` mapped to HTTP 400 (`publishAt must be a future timestamp`). `DashboardService::filterByPublicationState()` materialises scheduled rows whose `publishAt <= now()` as published at read time without a DB write. `DashboardService::materialiseScheduledDashboards()` (+ `DashboardMapper::findDueScheduled()`) provides the optional eager path.
- REQ-DASH-035 (Migration backfill): `DashboardTableBuilder::addPublicationColumns()` declares `publication_status` with `DEFAULT 'published'` so pre-existing rows are backfilled implicitly via the column default; `DashboardFactory::create()` overrides the default to `'draft'` for every new dashboard.
- REQ-DASH-036 (Draft visibility restrictions): `DashboardService::getVisibleToUser()` runs every mapper result through `filterByPublicationState()` which hides `draft` and future-`scheduled` rows from non-owner non-admin viewers; admins receive everything via `safeIsAdmin()`.
- REQ-DASH-037 (Frontend store): `src/stores/dashboard.js` exports `STATUS_DRAFT`/`STATUS_PUBLISHED`/`STATUS_SCHEDULED` constants and `publishDashboard` / `unpublishDashboard` / `scheduleDashboard` / `effectivePublicationStatus` / `applyPublicationPatch` actions backed by `api.publishDashboard` / `api.unpublishDashboard` / `api.scheduleDashboard` HTTP helpers in `src/services/api.js`.
- REQ-DASH-038..044 (Per-language dashboard content variants): the full requirement set lives in the sibling capability `dashboard-language-content` (`openspec/specs/dashboard-language-content/spec.md`). `DashboardService::createDashboard()` calls `DashboardTranslationService::seedPrimaryFor()` after insert; `DashboardService::deleteDashboard()` cascades via `DashboardTranslationService::deleteAllForDashboard()` for both single-row and subtree paths so translation rows never outlive their parent dashboard.

**Not yet implemented:**
- REQ-DASH-001/007 validation: No name or gridColumns validation.
- REQ-DASH-004 grid reflow: Updating gridColumns does not reposition widgets.
- REQ-DASH-005 auto-activate after delete: Not implemented.
- REQ-DASH-005 cascade-delete conditional rules: Not explicitly handled.

### Standards & References
- Nextcloud Controller patterns: `OCP\AppFramework\Controller`, `#[NoAdminRequired]` attribute
- UUID generation: Custom UUID v4 implementation in `DashboardFactory::generateUuid()`
- WCAG 2.1 AA: Dashboard management UI elements should be keyboard-operable
