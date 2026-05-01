# Admin Roles — Implementation Tasks

## Data Model & Persistence

### Task 1: Create RoleAssignment Entity & Mapper
- **File:** `lib/Db/RoleAssignment.php`
  - Fields: `id` (int), `userId` (varchar 64, nullable), `groupId` (varchar 64, nullable), `role` (enum: admin|editor|viewer), `assignedBy` (varchar 64), `assignedAt` (timestamp)
  - Getters/setters for all fields
  - Add method `getTarget()` to return `userId` if set, else `groupId` (for UI convenience)
  - Add method `isUserAssignment()` / `isGroupAssignment()` predicates

- **File:** `lib/Db/RoleAssignmentMapper.php`
  - `findAll(): RoleAssignment[]` — all assignments, no filtering
  - `findByUser(userId: string): RoleAssignment[]` — all assignments for a user (both user and group)
  - `findByGroup(groupId: string): RoleAssignment[]` — all assignments for a group
  - `findById(id: int): RoleAssignment` — single assignment by ID
  - `insert(RoleAssignment): RoleAssignment` — return with ID populated
  - `update(RoleAssignment): void`
  - `delete(int $id): int` — return affected rows
  - `deleteByUserId(userId: string): int` — cascade on user deletion
  - `deleteByGroupId(groupId: string): int` — cascade on group deletion
  - All methods follow Nextcloud mapper conventions (throw DoesNotExistException, MultipleObjectsReturnedException)

### Task 2: Create Database Migration
- **File:** `lib/Migration/VersionXXXXDate2026...php` (YYYYMMDDHHMMSS timestamp in classname)
- Table: `oc_mydash_role_assignments`
  - Columns:
    - `id INT(11) PRIMARY KEY AUTO_INCREMENT`
    - `userId VARCHAR(64) DEFAULT NULL`
    - `groupId VARCHAR(64) DEFAULT NULL`
    - `role VARCHAR(10)` (ENUM is not portable; use VARCHAR + check constraint or app-level validation)
    - `assignedBy VARCHAR(64) NOT NULL`
    - `assignedAt DATETIME NOT NULL`
  - Indexes:
    - Composite unique index on `(userId, groupId, role)` OR separate unique constraints: UNIQUE(userId, role) for user assignments, UNIQUE(groupId, role) for group assignments — **decision**: use UNIQUE((userId IS NOT NULL), (groupId IS NOT NULL), role) to enforce "one assignment per target+role"; fallback for MySQL 5.7: add both indexes separately
    - Index on `userId` (for `deleteByUserId`, `findByUser`)
    - Index on `groupId` (for `deleteByGroupId`, `findByGroup`)
  - Constraints: CHECK `(userId IS NOT NULL AND groupId IS NULL) OR (userId IS NULL AND groupId NOT NULL)` (XOR)

## Business Logic & Services

### Task 3: Create RoleService
- **File:** `lib/Service/RoleService.php`
  - `__construct(RoleAssignmentMapper, IUserManager, IGroupManager, IUserSession)`

  - **Role Resolution:**
    - `getEffectiveRole(userId: string): string|null` — returns "admin"|"editor"|"viewer"|null
      - If user is Nextcloud admin: return "admin"
      - Fetch direct user assignment (highest role if multiple — should be unique, but be defensive)
      - Fetch all group assignments for user's groups: collect all roles
      - Return highest role (admin > editor > viewer) from all assignments
      - Return null if no assignment and not NC admin

    - `getRoleSource(userId: string): string|null` — returns role source as string
      - "nc-admin" if user is Nextcloud admin
      - "user-assigned" if direct user assignment exists
      - "group-assigned:{groupId}" if highest role comes from a group assignment
      - null if no role

  - **Validation & Assignment:**
    - `validateRole(role: string): void` — throws InvalidArgumentException if role not in ["admin", "editor", "viewer"]
    - `validateTarget(userId: ?string, groupId: ?string): void` — throws InvalidArgumentException if both null, both set, or (if user) user doesn't exist, or (if group) group doesn't exist
    - `assignRole(userId: ?string, groupId: ?string, role: string, assignedBy: string): RoleAssignment` — creates new assignment
      - Validate role and target
      - Check for duplicate: if userId + role already assigned, throw DuplicateException (let controller return 409)
      - If groupId + role already assigned, throw DuplicateException
      - Create entity, set `assignedAt = now`, `assignedBy = currentUserId`
      - Insert via mapper, return result

    - `removeRole(id: int): void` — deletes assignment by ID
      - Mapper->delete(id); if affected rows == 0, throw DoesNotExistException

  - **Cascade:**
    - `deleteByUserId(userId: string): int` — delegates to mapper, returns affected rows
    - `deleteByGroupId(groupId: string): int` — delegates to mapper, returns affected rows

  - **Authorization Helpers:**
    - `isAdmin(userId: string): bool` — returns true if effective role is "admin"
    - `isEditorOrHigher(userId: string): bool` — returns true if effective role is "editor" or "admin"
    - `isViewerOrHigher(userId: string): bool` — always true (viewer is lowest); useful for readability

## API Endpoints

### Task 4: Create AdminController Endpoints
- **File:** `lib/Controller/AdminController.php` (new or extend existing)
  - Inject: `RoleService`, `RoleAssignmentMapper`, `IRequest`

  - **GET /api/admin/roles** → `listRoles()`
    - Requires: Nextcloud admin
    - Returns: JSON array of all role assignments: `[{id, userId, groupId, role, assignedBy, assignedAt}, ...]`
    - Status: 200 on success, 403 if not admin

  - **POST /api/admin/roles** → `createRole()`
    - Requires: Nextcloud admin
    - Body: `{userId?: string, groupId?: string, role: string}`
    - Returns: newly created assignment with `id`
    - Status: 201 on success
    - Status: 400 if both/neither of userId/groupId provided (use `validateTarget`)
    - Status: 400 if role invalid
    - Status: 409 if duplicate (user+role or group+role already assigned) — catch DuplicateException from RoleService
    - Status: 403 if not admin

  - **DELETE /api/admin/roles/{id}** → `deleteRole(id: int)`
    - Requires: Nextcloud admin
    - Returns: empty JSON object `{}`
    - Status: 204 on success
    - Status: 404 if assignment not found (DoesNotExistException)
    - Status: 403 if not admin

  - **GET /api/me/role** → `getMyRole()`
    - Requires: authenticated user (no admin check)
    - Returns: `{role: string|null, source: string|null}`
    - Status: 200
    - Example: `{role: "editor", source: "group-assigned:engineering"}`

### Task 5: Register Routes
- **File:** `appinfo/routes.php`
  - Routes (prefix `/api`):
    - `POST /admin/roles` → `AdminController->createRole()`
    - `GET /admin/roles` → `AdminController->listRoles()`
    - `DELETE /admin/roles/{id}` → `AdminController->deleteRole(id)`
    - `GET /me/role` → `AdminController->getMyRole()`
  - Ensure admin routes are CORS-enabled if necessary (check existing patterns in codebase)

## Authorization Integration

### Task 6: Extend PermissionService
- **File:** `lib/Service/PermissionService.php` (existing)
  - Inject: `RoleService` (new dependency)
  
  - Extend `canEdit(userId, dashboardUuid): bool`
    - If role is "viewer": return false immediately
    - Continue existing permission checks
    - For "editor" role and `group_shared` dashboard: verify `dashboard.groupId` is in user's group memberships, else return false
    - For "admin" role: return true (skip existing checks as admin override)

  - Extend `canCreate(userId): bool`
    - If role is "viewer": return false immediately
    - Continue existing checks

  - Extend `canDelete(userId, dashboardUuid): bool`
    - If role is "viewer": return false immediately
    - Continue existing checks

  - **OR** create new `canMutate(userId): bool` method and call it first in all mutation endpoints (DRY; see existing codebase pattern)

### Task 7: Implement User Deletion Listener
- **File:** `lib/Listener/PreDeleteUser.php` (new or extend existing)
  - Listen to `OCP\User\Events\PreDeleteUserEvent`
  - Inject: `RoleService`
  - On event: call `RoleService->deleteByUserId($event->getUser()->getUID())`

### Task 8: Implement Group Deletion Listener
- **File:** `lib/Listener/PreDeleteGroup.php` (new or extend existing)
  - Listen to `OCP\Group\Events\PreDeleteGroupEvent`
  - Inject: `RoleService`
  - On event: call `RoleService->deleteByGroupId($event->getGroup()->getGID())`

### Task 9: Register Event Listeners
- **File:** `appinfo/app.php` or bootstrap (see existing codebase)
  - Register `PreDeleteUser` listener for `OCP\User\Events\PreDeleteUserEvent`
  - Register `PreDeleteGroup` listener for `OCP\Group\Events\PreDeleteGroupEvent`

## Testing (Out of Scope for Spec, But Guidance)

- Unit tests for `RoleService`: effective-role resolution, source tracking, validation
- Unit tests for `RoleAssignmentMapper`: CRUD, cascade, constraints
- API tests for all four endpoints: happy path, 400/403/404/409 scenarios
- Integration test: user deletion cascades, group deletion cascades
- Authorization test: viewer can't mutate, editor can edit group_shared, admin overrides all

## Notes

- All dates use Nextcloud's `\DateTime` or comparable ORM support
- Role validation: explicit enum check, not schema-enforced, for forward compatibility
- No background cleanup job needed; expired locks and stale assignments are OK (locking is separate, roles are permanent until deleted)
- Nextcloud i18n: all error messages must be translatable; add to `l10n/en.json` and `l10n/nl.json`
