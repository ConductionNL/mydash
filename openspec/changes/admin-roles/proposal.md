# Admin Roles

## Why

Today MyDash assigns permissions based on Nextcloud groups and a binary admin flag. Organization administrators must choose between granting full Nextcloud admin rights (which includes system-level powers outside MyDash) or no admin rights at all. This creates a delegation bottleneck: delegating MyDash operations requires delegating all Nextcloud administration. A built-in role system scoped to MyDash enables fine-grained intranet governance, allowing org admins to delegate dashboard management, widget installation, and metadata configuration to trusted users without exposing system administration.

## What Changes

- Add a new table `oc_mydash_role_assignments` with `id`, `userId`, `groupId`, `role`, `assignedBy`, `assignedAt` fields. Either `userId` or `groupId` is set (mutually exclusive).
- Define three new roles scoped entirely within MyDash:
  - **Dashboard Admin** — full access to MyDash admin section, can edit any dashboard, can install demo data, can edit org-navigation, can manage metadata field definitions.
  - **Dashboard Editor** — can create new `group_shared` dashboards, can edit `group_shared` dashboards in groups they belong to, can create personal dashboards, can edit metadata field values (but not definitions) on dashboards they edit.
  - **Dashboard Viewer** — read-only access to visible dashboards; personal-dashboard creation and all mutations rejected; useful for demo and visitor accounts.
- Effective role resolution: Nextcloud admins always have MyDash "admin" role. Non-admins resolve their highest-privilege role from direct user assignments or group memberships. Default (no assignment) = no role, falls back to existing `permissions` capability.
- Add four new API endpoints:
  - `GET /api/admin/roles` — lists all role assignments. NC-admin only.
  - `POST /api/admin/roles` — creates a role assignment for a user or group. NC-admin only. 400 if both/neither of userId/groupId set. 409 on duplicate.
  - `DELETE /api/admin/roles/{id}` — removes an assignment. NC-admin only.
  - `GET /api/me/role` — returns the calling user's effective MyDash role and source. Available to any authenticated user.
- Extend authorization: `permissions` capability resolver consults role system before falling back to default permissions. "Editor" and "Viewer" roles are enforced at mutation endpoints.
- Cascade: deleting a Nextcloud user removes their direct user assignments. Deleting a group removes its group assignments. No cross-deletion.

## Capabilities

### New Capabilities

- `admin-roles`: All role definition, assignment CRUD, effective-role resolution, and authorization enforcement within MyDash.

### Modified Capabilities

- `permissions` (ref: `openspec/specs/permissions/spec.md`): Extended to consult role system during authorization checks. No changes to the permission model itself; roles provide an alternative delegation layer above it.

## Impact

**Affected code:**

- `lib/Db/RoleAssignment.php` — new entity with `id`, `userId`, `groupId`, `role`, `assignedBy`, `assignedAt` fields
- `lib/Db/RoleAssignmentMapper.php` — new mapper with `findAll()`, `findByUser()`, `findByGroup()`, `findById()`, `insert()`, `update()`, `delete()`, `deleteByUserId()`, `deleteByGroupId()` methods
- `lib/Service/RoleService.php` — new service layer with `getEffectiveRole()` (per-user resolution), `getRoleSource()` (tracking source), `assignRole()`, `removeRole()`, role validation
- `lib/Controller/AdminController.php` — four new endpoints as above
- `appinfo/routes.php` — register the four new routes
- `lib/Migration/VersionXXXXDate2026...php` — schema migration creating `oc_mydash_role_assignments` table
- `lib/Service/PermissionService.php` (existing) — extend `canEdit()`, `canCreate()`, `canDelete()` to consult `RoleService::getEffectiveRole()`
- `lib/Listener/PreDeleteUser.php` (existing or new) — call `RoleService::deleteByUserId()` on user deletion
- `lib/Listener/PreDeleteGroup.php` (existing or new) — call `RoleService::deleteByGroupId()` on group deletion

**Affected APIs:**

- 4 new routes (no existing routes changed; existing permission checks enhanced non-breaking)

**Dependencies:**

- `OCP\IUserManager` — for user lookups in role assignment
- `OCP\IGroupManager` — for group lookups in role assignment
- No new composer or npm dependencies

**Migration:**

- Zero-impact: new table only, no changes to existing schema
- No seed data required
- Default behavior (no assignments) preserves existing permissions model

## Front-end Concern (Not in Scope)

The admin UI MUST provide a role-assignment manager in the MyDash admin section, allowing NC admins to view, create, and delete role assignments per user and group. The `GET /api/me/role` endpoint allows the frontend to display the current user's role and source in account/settings views.
