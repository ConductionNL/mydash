---
status: draft
---

# Admin Roles Specification

## Purpose

Admin Roles provides a built-in role system scoped entirely within MyDash. Organization administrators can delegate dashboard management, widget installation, metadata field configuration, and other MyDash operations to trusted users without granting full Nextcloud system administration rights. Three roles (Dashboard Admin, Dashboard Editor, Dashboard Viewer) map to real organizational needs, and role assignments persist in a new table with support for both individual user and group-based delegation. Effective role resolution ensures the highest privilege wins when a user has multiple group memberships.

## Data Model

Each role assignment is stored in the `oc_mydash_role_assignments` table with the following fields:

- **id**: Auto-increment integer primary key
- **userId**: VARCHAR(64), Nextcloud user ID; NULL if this is a group assignment
- **groupId**: VARCHAR(64), Nextcloud group ID; NULL if this is a user assignment
- **role**: VARCHAR(10), one of: "admin", "editor", "viewer"
- **assignedBy**: VARCHAR(64), Nextcloud user ID of the admin who created this assignment (for audit trail)
- **assignedAt**: DATETIME, when the assignment was created

**Constraint**: Exactly one of `userId` or `groupId` MUST be set (mutually exclusive XOR).

**Unique Constraint**: Per-target uniqueness — a user or group may have at most one assignment of each role type. (Implementation: UNIQUE(userId, role) for user assignments; UNIQUE(groupId, role) for group assignments; OR composite unique index with IS NOT NULL logic if the database supports it.)

## ADDED Requirements

### Requirement: REQ-ROLE-001 Dashboard Admin Role Definition

A Dashboard Admin MUST have full administrative access to MyDash, equivalent to Nextcloud admin status but scoped to MyDash only. A Dashboard Admin MUST be able to manage all dashboards, install demo data, edit organization-level navigation, and define new metadata fields.

NOTE: Admin-roles is a net-new MyDash capability with no source counterpart. The source app has no named-role concept and resolves all permissions from the GroupFolder ACL bitmask alone. The three role names echo the source app's seeded group names for customer recognition, but the underlying mechanism is entirely different — MyDash does not read from those groups at runtime.

#### Scenario: NC admin automatically has Dashboard Admin role
- GIVEN a Nextcloud admin user (admin flag = true)
- WHEN the system resolves the user's effective MyDash role
- THEN the user's effective role MUST be "admin"
- AND the role source MUST be "nc-admin"
- NOTE: NC admin status is an implicit grant — no explicit assignment record is created in `oc_mydash_role_assignments`

#### Scenario: Non-admin user assigned Dashboard Admin role
- GIVEN a non-admin Nextcloud user "alice"
- WHEN an NC admin creates a role assignment with `userId = "alice"`, `role = "admin"`
- THEN alice's effective role becomes "admin"
- AND the role source MUST be "user-assigned"
- AND alice gains access to MyDash admin section: `/admin/settings`, `/admin/demo`, `/admin/metadata`, `/admin/roles`

#### Scenario: Dashboard Admin can edit any dashboard
- GIVEN a user with effective role "admin"
- WHEN the user attempts to edit any dashboard (personal, group_shared, or org-level)
- THEN the permission check MUST return true
- AND no group membership check is required

#### Scenario: Dashboard Admin can manage metadata field definitions
- GIVEN a user with role "admin"
- WHEN the user calls `POST /api/admin/metadata-fields` with a new field definition
- THEN the system MUST allow the creation
- AND the field is available for use on all dashboards (not scoped to a group)

#### Scenario: Dashboard Admin can install demo data
- GIVEN a user with role "admin"
- WHEN the user calls `POST /api/admin/demo/install` with a demo package ID
- THEN the system MUST allow the installation
- AND demo dashboards appear in the appropriate scope

#### Scenario: Dashboard Admin can edit organization navigation
- GIVEN a user with role "admin"
- WHEN the user calls `PUT /api/admin/org-navigation` with new navigation structure
- THEN the system MUST allow the update
- AND the changes are persisted and visible to all users

### Requirement: REQ-ROLE-002 Dashboard Editor Role Definition

A Dashboard Editor MUST be able to create and edit dashboards within their group memberships, install widgets, manage their own personal dashboards, and edit metadata field VALUES (but not create new field definitions). Dashboard Editors MUST NOT perform system-level operations like installing demo data or creating metadata field definitions.

#### Scenario: Dashboard Editor creates a new group_shared dashboard
- GIVEN a user "bob" with role "editor" in group "engineering"
- WHEN bob calls `POST /api/dashboards` with `scope = "group_shared"`, `groupId = "engineering"`
- THEN the system MUST allow the creation
- AND bob becomes the owner of the new dashboard

#### Scenario: Dashboard Editor can edit group_shared dashboard in their group
- GIVEN a "group_shared" dashboard in group "engineering"
- WHEN bob (with role "editor", member of "engineering") calls `PUT /api/dashboards/{uuid}` with edits
- THEN the system MUST allow the update
- AND the changes are persisted

#### Scenario: Dashboard Editor cannot edit group_shared dashboard outside their groups
- GIVEN a "group_shared" dashboard in group "sales"
- WHEN bob (with role "editor", member of "engineering" only) calls `PUT /api/dashboards/{uuid}` with edits
- THEN the system MUST return HTTP 403 (Forbidden)
- AND the dashboard remains unchanged

#### Scenario: Dashboard Editor can create personal dashboard
- GIVEN a user "bob" with role "editor"
- WHEN bob calls `POST /api/dashboards` with `scope = "personal"`
- THEN the system MUST allow the creation
- AND the dashboard is owned by bob and not visible to others

#### Scenario: Dashboard Editor can edit metadata field VALUES, not definitions
- GIVEN a dashboard with a metadata field "Priority" (definition created by admin)
- WHEN bob (with role "editor") calls `PUT /api/dashboards/{uuid}` with `metadata = {Priority: "High"}`
- THEN the system MUST allow the update
- AND the field value is persisted
- NOTE: bob CANNOT call `POST /api/admin/metadata-fields` to create a new field definition; that requires "admin" role

#### Scenario: Dashboard Editor cannot install demo data
- GIVEN a user "bob" with role "editor"
- WHEN bob calls `POST /api/admin/demo/install`
- THEN the system MUST return HTTP 403
- AND no demo is installed

#### Scenario: Dashboard Editor cannot add metadata field definitions
- GIVEN a user "bob" with role "editor"
- WHEN bob calls `POST /api/admin/metadata-fields` with a new field definition
- THEN the system MUST return HTTP 403
- AND the field is not created

### Requirement: REQ-ROLE-003 Dashboard Viewer Role Definition

A Dashboard Viewer MUST have read-only access to all visible dashboards. Dashboard Viewers MUST NOT be able to create, edit, or delete any dashboards, and personal-dashboard creation MUST be explicitly rejected. Dashboard Viewers MAY still interact with features like reactions and comments (if enabled) that do not mutate the dashboard structure.

#### Scenario: Dashboard Viewer can read dashboards
- GIVEN a user "charlie" with role "viewer"
- AND a "group_shared" dashboard in a group charlie belongs to
- WHEN charlie calls `GET /api/dashboards/{uuid}`
- THEN the system MUST return the dashboard content
- AND charlie can view all widgets and data

#### Scenario: Dashboard Viewer cannot create dashboard
- GIVEN a user "charlie" with role "viewer"
- WHEN charlie calls `POST /api/dashboards` with any scope
- THEN the system MUST return HTTP 403
- AND no dashboard is created

#### Scenario: Dashboard Viewer cannot edit dashboard
- GIVEN a "group_shared" dashboard in a group charlie belongs to
- WHEN charlie (with role "viewer") calls `PUT /api/dashboards/{uuid}` with edits
- THEN the system MUST return HTTP 403
- AND the dashboard remains unchanged

#### Scenario: Dashboard Viewer cannot delete dashboard
- GIVEN a dashboard charlie can read
- WHEN charlie (with role "viewer") calls `DELETE /api/dashboards/{uuid}`
- THEN the system MUST return HTTP 403
- AND the dashboard is not deleted

#### Scenario: Dashboard Viewer can react and comment (if enabled)
- GIVEN a "group_shared" dashboard in charlie's group with reactions and comments enabled
- WHEN charlie calls `POST /api/dashboards/{uuid}/reactions` with `{emoji: "👍"}`
- THEN the system MUST allow the reaction (if the dashboard-reactions capability is present)
- NOTE: Reactions and comments are NOT mutations of the dashboard structure; they are additive and scoped to the viewer. The "viewer" role prevents dashboard structure mutations only.

#### Scenario: Dashboard Viewer cannot create personal dashboard
- GIVEN a user "charlie" with role "viewer"
- WHEN charlie calls `POST /api/dashboards` with `scope = "personal"`
- THEN the system MUST return HTTP 403
- AND no personal dashboard is created
- NOTE: Even if the user "owns" a personal dashboard, a "viewer" role assignment blocks personal-dashboard creation at the API level

### Requirement: REQ-ROLE-004 Persist Role Assignments

Role assignments MUST be stored durably in the `oc_mydash_role_assignments` table with audit trail information.

#### Scenario: Role assignment is stored with audit fields
- GIVEN an admin "admin-user" assigns role "editor" to user "bob"
- WHEN the assignment is created via `POST /api/admin/roles`
- THEN a row MUST be inserted with:
  - `userId = "bob"`, `groupId = NULL`
  - `role = "editor"`
  - `assignedBy = "admin-user"`
  - `assignedAt = now`
  - `id = auto-generated integer`

#### Scenario: Group role assignment stores group ID
- GIVEN an admin assigns role "editor" to group "engineering"
- WHEN the assignment is created via `POST /api/admin/roles` with `groupId = "engineering"`
- THEN a row MUST be inserted with:
  - `userId = NULL`, `groupId = "engineering"`
  - `role = "editor"`
  - `assignedBy = "admin-user"`
  - `assignedAt = now`

#### Scenario: One assignment per target and role
- GIVEN a user "bob" already has an assignment with `role = "editor"`
- WHEN an admin attempts to create another assignment with `userId = "bob"`, `role = "editor"`
- THEN the system MUST return HTTP 409 (Conflict)
- AND the new assignment is NOT inserted
- NOTE: The same user/group can have multiple assignments if the roles differ (e.g., user is assigned both "editor" in one context and something else; current spec assumes one per role, but the implementation must enforce this uniqueness)

#### Scenario: User and group assignments are distinct
- GIVEN a role assignment with `userId = "bob"`, `role = "editor"`
- WHEN the system queries for assignments where `groupId = "engineering"`, `role = "editor"`
- THEN the user assignment MUST NOT appear in results
- AND the group assignment (if it exists) MUST appear separately

### Requirement: REQ-ROLE-005 Resolve Effective Role Per User

The system MUST resolve a user's effective MyDash role using the following deterministic algorithm:

1. If the user is a Nextcloud admin → effective role is "admin", source is "nc-admin". No assignment lookup required.
2. Otherwise, check for a direct user assignment (`userId = <user>`). If one exists, it is used as-is — group assignments are NOT consulted, regardless of their rank.
3. If no direct user assignment exists, collect all group assignments for groups the user is a member of. Map roles to numeric rank: "admin"=2, "editor"=1, "viewer"=0. Effective role is the assignment with the highest rank ("highest privilege wins").
4. If no assignments exist → effective role is null; the user falls back to `permissions` capability behavior.

NOTE: Direct user assignment is the canonical explicit override — if an admin assigns a user "viewer" directly, that intent is honored and is not silently overridden by group memberships. Group assignments only participate in resolution when no direct user assignment is present.

#### Scenario: NC admin always has admin role
- GIVEN a Nextcloud admin user "alice"
- WHEN the system calls `getEffectiveRole("alice")`
- THEN the return value MUST be "admin"
- AND the role source MUST be "nc-admin"
- AND no assignment lookup is performed (NC admin is an unconditional override)

#### Scenario: Non-admin with direct user assignment
- GIVEN a non-admin user "bob" with assignment `userId = "bob"`, `role = "editor"`
- WHEN the system calls `getEffectiveRole("bob")`
- THEN the return value MUST be "editor"
- AND the role source MUST be "user-assigned"
- AND group assignments are NOT consulted

#### Scenario: Multiple group memberships, highest role wins
- GIVEN a user "charlie" who is a member of groups "engineering" and "sales"
- AND an assignment `groupId = "engineering"`, `role = "editor"`
- AND an assignment `groupId = "sales"`, `role = "viewer"`
- AND no direct user assignment for charlie
- WHEN the system calls `getEffectiveRole("charlie")`
- THEN the return value MUST be "editor"
- AND the role source MUST be "group-assigned:engineering"
- NOTE: "editor" (rank 1) is higher privilege than "viewer" (rank 0); highest-rank group assignment wins. If sources are equally ranked, implementation may return any; spec does not mandate specific tie-breaking.

#### Scenario: Direct user assignment used as-is, group assignments skipped
- GIVEN a user "david" who is a member of group "engineering"
- AND an assignment `userId = "david"`, `role = "admin"`
- AND an assignment `groupId = "engineering"`, `role = "editor"`
- WHEN the system calls `getEffectiveRole("david")`
- THEN the return value MUST be "admin"
- AND the role source MUST be "user-assigned"
- NOTE: The direct user assignment is used as-is. Group assignments are not consulted when a direct user assignment exists.

#### Scenario: No assignment, user has no role
- GIVEN a user "eve" with no role assignment and not an NC admin
- WHEN the system calls `getEffectiveRole("eve")`
- THEN the return value MUST be null
- AND the role source MUST be null
- AND the user falls back to existing `permissions` capability behavior

#### Scenario: Admin role is highest in privilege hierarchy
- GIVEN role hierarchy: "admin" (rank 2) > "editor" (rank 1) > "viewer" (rank 0)
- WHEN the system resolves effective role from group assignments ["viewer", "admin", "editor"]
- THEN the effective role MUST be "admin"

### Requirement: REQ-ROLE-006 Get Current User's Role and Source

Any authenticated user MUST be able to query their own effective MyDash role and learn where that role comes from (NC admin, direct assignment, or group assignment).

#### Scenario: User queries their role via GET /api/me/role
- GIVEN an authenticated user "alice" with effective role "editor" from a group assignment
- WHEN alice calls `GET /api/me/role`
- THEN the response MUST be HTTP 200 with body:
  ```json
  {
    "role": "editor",
    "source": "group-assigned:engineering"
  }
  ```

#### Scenario: NC admin queries their role
- GIVEN an NC admin "admin-user"
- WHEN admin-user calls `GET /api/me/role`
- THEN the response MUST be:
  ```json
  {
    "role": "admin",
    "source": "nc-admin"
  }
  ```

#### Scenario: User with no role queries
- GIVEN a user "noone" with no assignment and not an NC admin
- WHEN noone calls `GET /api/me/role`
- THEN the response MUST be:
  ```json
  {
    "role": null,
    "source": null
  }
  ```

#### Scenario: User with direct assignment
- GIVEN a user "bob" with assignment `userId = "bob"`, `role = "viewer"`
- WHEN bob calls `GET /api/me/role`
- THEN the response MUST include `source: "user-assigned"`

#### Scenario: User with multiple group assignments
- GIVEN a user "charlie" with group assignments "engineering" → "editor" and "sales" → "viewer"
- WHEN charlie calls `GET /api/me/role`
- THEN the response MUST include `role: "editor"` and `source: "group-assigned:engineering"`
- OR `source: "group-assigned:sales"` (implementation choice if roles are equal; editor wins here)

### Requirement: REQ-ROLE-007 System MUST Enforce Editor Authorization on group_shared Dashboards

A Dashboard Editor MUST only be able to edit a `group_shared` dashboard if the dashboard's group ID is in the user's group memberships AND the user has role "editor" or higher.

#### Scenario: Editor in correct group can edit
- GIVEN a `group_shared` dashboard with `groupId = "engineering"`
- WHEN a user "bob" with role "editor" and group membership "engineering" calls `PUT /api/dashboards/{uuid}`
- THEN the system MUST allow the edit
- AND the changes are persisted

#### Scenario: Editor without group membership cannot edit
- GIVEN a `group_shared` dashboard with `groupId = "sales"`
- WHEN a user "bob" with role "editor" but NOT a member of "sales" calls `PUT /api/dashboards/{uuid}`
- THEN the system MUST return HTTP 403
- AND the dashboard is not modified

#### Scenario: Viewer cannot edit even in their group
- GIVEN a `group_shared` dashboard with `groupId = "engineering"`
- WHEN a user "charlie" with role "viewer" and group membership "engineering" calls `PUT /api/dashboards/{uuid}`
- THEN the system MUST return HTTP 403
- AND the dashboard is not modified

#### Scenario: Admin can edit any group_shared dashboard
- GIVEN a `group_shared` dashboard with `groupId = "sales"`
- WHEN a user "admin-user" with role "admin" calls `PUT /api/dashboards/{uuid}`
- THEN the system MUST allow the edit
- AND group membership is not required

#### Scenario: Editor can edit personal dashboard within their own context
- GIVEN a personal dashboard owned by "bob"
- WHEN bob with role "editor" calls `PUT /api/dashboards/{uuid}`
- THEN the system MUST allow the edit (editor can edit their own personal dashboards)
- NOTE: Authorization for personal dashboards is owner-based, independent of role; role restricts group_shared dashboards. This scenario clarifies that "editor" does not prevent personal-dashboard editing.

### Requirement: REQ-ROLE-008 Authorize Viewer Read-Only Access

A Dashboard Viewer MUST NOT be able to perform any mutation on dashboards or MyDash operations. All mutation endpoints MUST return HTTP 403 for any user with effective role "viewer".

#### Scenario: Viewer cannot create dashboard
- GIVEN a user "charlie" with role "viewer"
- WHEN charlie calls `POST /api/dashboards`
- THEN the system MUST return HTTP 403
- AND no dashboard is created

#### Scenario: Viewer cannot edit dashboard
- GIVEN a user "charlie" with role "viewer" and read access to a dashboard
- WHEN charlie calls `PUT /api/dashboards/{uuid}`
- THEN the system MUST return HTTP 403
- AND no edit is applied

#### Scenario: Viewer cannot delete dashboard
- GIVEN a user "charlie" with role "viewer"
- WHEN charlie calls `DELETE /api/dashboards/{uuid}`
- THEN the system MUST return HTTP 403
- AND no deletion occurs

#### Scenario: Viewer can read dashboard
- GIVEN a user "charlie" with role "viewer" and read access to a dashboard
- WHEN charlie calls `GET /api/dashboards/{uuid}`
- THEN the system MUST return the dashboard
- AND charlie can view all content

#### Scenario: Viewer cannot create personal dashboard
- GIVEN a user "charlie" with role "viewer"
- WHEN charlie calls `POST /api/dashboards` with `scope = "personal"`
- THEN the system MUST return HTTP 403
- AND no personal dashboard is created
- NOTE: Personal-dashboard creation is explicitly rejected for viewers, even if the user "owns" the creation request

#### Scenario: Viewer cannot install widget
- GIVEN a user "charlie" with role "viewer"
- WHEN charlie calls `POST /api/dashboards/{uuid}/widgets`
- THEN the system MUST return HTTP 403
- AND no widget is added

#### Scenario: Viewer CAN react and comment (if feature enabled)
- GIVEN a dashboard with reactions/comments enabled
- WHEN a user "charlie" with role "viewer" calls `POST /api/dashboards/{uuid}/reactions` with `{emoji: "👍"}`
- THEN the system MUST allow the reaction (if dashboard-reactions capability present)
- OR return 404 if the feature is not enabled
- NOTE: Reactions and comments are NOT dashboard mutations; they are orthogonal to the role model

### Requirement: REQ-ROLE-009 Group vs. User Precedence

When a user has a direct user assignment, that assignment MUST be used as-is and group assignments MUST NOT be consulted. If a user has no direct user assignment but has assignments from multiple groups, the highest-privilege group role MUST be selected.

NOTE: This "direct-assignment-wins" rule is not a rank comparison — a direct "viewer" assignment beats a group "admin" assignment because the direct assignment is the sole source when present. Group assignments only participate in resolution when no direct user assignment exists. See REQ-ROLE-005 for the full resolution algorithm.

#### Scenario: Direct user assignment used as-is regardless of group rank
- GIVEN a user "bob"
- AND assignment `userId = "bob"`, `role = "viewer"`
- AND assignment `groupId = "engineering"`, `role = "admin"` (bob is member of engineering)
- WHEN the system resolves bob's effective role
- THEN the role MUST be "viewer"
- AND the source MUST be "user-assigned"
- NOTE: The direct user assignment is used as-is. The system does NOT compare its rank against group assignments — group assignments are skipped entirely because a direct user assignment is present. A "viewer" direct assignment intentionally overrides a "admin" group assignment.

#### Scenario: Highest group role wins
- GIVEN a user "charlie" who is a member of groups "engineering" and "sales"
- AND assignment `groupId = "engineering"`, `role = "editor"`
- AND assignment `groupId = "sales"`, `role = "viewer"`
- WHEN the system resolves charlie's effective role
- THEN the role MUST be "editor"
- AND the source MUST be "group-assigned:engineering" (or implementation-specific choice if equally ranked)

#### Scenario: NC admin overrides all assignments
- GIVEN a Nextcloud admin "admin-user"
- AND an explicit assignment `userId = "admin-user"`, `role = "viewer"` (if such exists)
- WHEN the system resolves admin-user's effective role
- THEN the role MUST be "admin"
- AND the source MUST be "nc-admin"
- NOTE: NC admin status is the ultimate override; explicit assignments do not restrict NC admins

### Requirement: REQ-ROLE-010 Cascade on User Deletion

When a Nextcloud user is deleted, all role assignments where `userId = <deleted-user>` MUST be automatically removed. No cross-deletion: deleting a role assignment does NOT affect the user or group.

NOTE: This cascade MUST be implemented via a `UserDeletedListener` that extends the listener infrastructure provided by the `dashboard-cascade-events` capability. Implementers MUST reference that capability's listener registration pattern rather than registering a standalone Nextcloud event listener.

#### Scenario: User deletion removes direct assignments
- GIVEN a role assignment `userId = "bob"`, `role = "editor"`
- WHEN bob's Nextcloud account is deleted via Nextcloud user management
- THEN the role assignment MUST be automatically deleted
- AND a query for bob's effective role MUST return null

#### Scenario: User deletion does not affect group assignments
- GIVEN a user "bob" who is a member of group "engineering"
- AND an assignment `groupId = "engineering"`, `role = "editor"`
- WHEN bob is deleted
- THEN the group assignment MUST remain (group is unaffected)
- AND the group still has role "editor"

#### Scenario: User is not deleted when role assignment is deleted
- GIVEN a role assignment `userId = "bob"`, `role = "editor"`
- WHEN an admin calls `DELETE /api/admin/roles/{assignment-id}`
- THEN the assignment is deleted
- AND bob's Nextcloud account MUST remain

### Requirement: REQ-ROLE-011 Cascade on Group Deletion

When a Nextcloud group is deleted, all role assignments where `groupId = <deleted-group>` MUST be automatically removed. No cross-deletion: deleting a role assignment does NOT affect the group or its members.

NOTE: This cascade MUST be implemented via a `GroupDeletedListener` that extends the listener infrastructure provided by the `dashboard-cascade-events` capability. Implementers MUST reference that capability's listener registration pattern rather than registering a standalone Nextcloud event listener.

#### Scenario: Group deletion removes group assignments
- GIVEN a role assignment `groupId = "engineering"`, `role = "editor"`
- WHEN the "engineering" group is deleted via Nextcloud group management
- THEN the role assignment MUST be automatically deleted
- AND the group role no longer affects any user

#### Scenario: Group deletion does not affect member accounts
- GIVEN a user "bob" who is a member of group "engineering"
- AND an assignment `groupId = "engineering"`, `role = "editor"`
- WHEN the "engineering" group is deleted
- THEN bob's Nextcloud account MUST remain
- AND bob is removed from the group (standard Nextcloud behavior)
- AND bob's effective MyDash role MUST be recalculated (likely becomes null if "engineering" was his only role source)

#### Scenario: Deletion is not affected by member state
- GIVEN a group "engineering" with 10 members
- AND a role assignment `groupId = "engineering"`, `role = "editor"`
- WHEN an admin calls `DELETE /api/admin/roles/{assignment-id}`
- THEN the assignment is deleted
- AND the "engineering" group and its members MUST remain
