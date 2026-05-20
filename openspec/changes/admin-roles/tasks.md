# Admin Roles — Implementation Tasks

## Data Model & Persistence

### Task 1: Create RoleAssignment Entity & Mapper

- [ ] **File:** `lib/Db/RoleAssignment.php`
  - Fields: `id` (int), `userId` (varchar 64, nullable), `groupId` (varchar 64, nullable), `role` (enum: admin|editor|viewer), `assignedBy` (varchar 64), `assignedAt` (timestamp)
  - Getters/setters for all fields
  - Add `getTarget()`, `isUserAssignment()`, `isGroupAssignment()` predicates
  - Add role-rank table + source-prefix constants for the resolver

- [ ] **File:** `lib/Db/RoleAssignmentMapper.php`
  - `findAll()`, `findByUser()`, `findByGroup()`, `findByGroupIds()`, `findById()`, `findUserRole()`, `findGroupRole()`, `insert()` (inherited), `update()` (inherited), `deleteById()`, `deleteByUserId()`, `deleteByGroupId()`
  - All methods follow Nextcloud mapper conventions

### Task 2: Create Database Migration

- [ ] **File:** `lib/Migration/Version001009Date20260502120000.php`
- [ ] **File:** `lib/Migration/RoleAssignmentTableBuilder.php`
- Table: `oc_mydash_role_assignments`
  - Columns: `id`, `user_id` (nullable varchar 64), `group_id` (nullable varchar 64), `role` (varchar 10), `assigned_by` (varchar 64), `assigned_at` (datetime)
  - Indexes: per-column lookup indexes on `user_id`, `group_id`
  - Composite UNIQUE on `(user_id, role)` and `(group_id, role)`
  - XOR constraint enforced at service layer (`RoleService::validateTarget`) — DB-level CHECK not portable

## Business Logic & Services

### Task 3: Create RoleService

- [ ] **File:** `lib/Service/RoleService.php`
  - Constructor: `__construct(RoleAssignmentMapper, IUserManager, IGroupManager, AdminTemplateService)`
  - Use `AdminTemplateService::getUserGroupIdsFor()` for group membership lookups (not `IGroupManager::getUserGroupIds` directly)
  - **Role Resolution:** `getEffectiveRole(string $userId): ?string`, `getRoleSource(string $userId): ?string`
  - Resolution algorithm:
    1. NC admin check → return "admin" / "nc-admin" immediately
    2. Direct user assignment → return as-is, skip group lookup
    3. Collect group assignments for user's groups → return highest rank
    4. No assignment → return null
  - **Validation & Assignment:** `validateRole(string $role): void`, `validateTarget(?string $userId, ?string $groupId): void`, `assignRole(?string $userId, ?string $groupId, string $role, string $assignedBy): RoleAssignment`, `removeRole(int $id): void`, `listAssignments(): array`
  - **Cascade:** `deleteByUserId(string $userId): void`, `deleteByGroupId(string $groupId): void`
  - **Authorization Helpers:** `isAdmin(string $userId): bool`, `isEditorOrHigher(string $userId): bool`, `isViewerOrHigher(string $userId): bool`, `isViewer(string $userId): bool`, `canMutate(string $userId): bool`

## API Endpoints

### Task 4: Create AdminController Endpoints

- [ ] **File:** `lib/Controller/AdminController.php` (extend existing controller)
  - `listRoles(): JSONResponse` → `GET /api/admin/roles` — NC-admin-gated, returns all assignments
  - `createRole(?string $userId, ?string $groupId, string $role): JSONResponse` → `POST /api/admin/roles` — returns 201 on success, 400 if both/neither userId/groupId set, 409 on duplicate
  - `deleteRole(int $id): JSONResponse` → `DELETE /api/admin/roles/{id}` — returns 204 on success, 404 if not found
  - `getMyRole(): JSONResponse` → `GET /api/me/role` — any authenticated user, returns `{role, source}` (nullable)

### Task 5: Register Routes

- [ ] **File:** `appinfo/routes.php`
  - Register: `GET /api/admin/roles` → `admin#listRoles`
  - Register: `POST /api/admin/roles` → `admin#createRole`
  - Register: `DELETE /api/admin/roles/{id}` → `admin#deleteRole`
  - Register: `GET /api/me/role` → `admin#getMyRole`

## Authorization Integration

### Task 6: Extend PermissionService

- [ ] **File:** `lib/Service/PermissionService.php`
  - Inject `RoleService` into constructor
  - Viewer role short-circuits `canEditDashboard()`, `canAddWidget()`, `canRemoveWidget()`, `canStyleWidget()`, `canCreateDashboard()` → return false immediately
  - Admin role overrides `canEditDashboard()` and the group-membership check inside `resolveAccessLevel()` (admin can edit any dashboard without group membership)
  - Editor role grants full access on `group_shared` dashboards through the existing membership path inside `getEffectivePermissionLevel()`
  - `canCreateDashboard()`: Editor always allowed (regardless of `allow_user_dashboards` flag); Viewer always blocked
  - Ensure viewer short-circuit is checked before any other permission logic

### Task 7: Implement User Deletion Listener

- [ ] **File:** `lib/Listener/UserDeletedListener.php`
  - Extend existing share-cleanup listener to also call `RoleService->deleteByUserId()` after share cleanup
  - Wrap call in try/catch with logger — role-cleanup failure must NOT abort the share-cascade pipeline

### Task 8: Implement Group Deletion Listener

- [ ] **File:** `lib/Listener/GroupDeletedListener.php` (new file)
  - Listen to `OCP\Group\Events\GroupDeletedEvent`
  - Call `RoleService->deleteByGroupId($event->getGroup()->getGid())`
  - Wrap in try/catch with logger
  - Register in Application.php (see Task 9)

### Task 9: Register Event Listeners

- [ ] **File:** `lib/AppInfo/Application.php`
  - Verify `UserDeletedEvent` → `UserDeletedListener` wiring is present (extend if needed)
  - Add `GroupDeletedEvent` → `GroupDeletedListener` registration

## Localization

- [ ] Add translatable strings to `l10n/en.json` and `l10n/nl.json`:
  - Role names: "Dashboard Admin", "Dashboard Editor", "Dashboard Viewer"
  - Validation errors: invalid role value, missing userId/groupId, both userId/groupId set
  - HTTP error messages: duplicate assignment (409), assignment not found (404)
- [ ] Mirror strings in `l10n/en.js` and `l10n/nl.js`

## Testing

- [ ] **File:** `tests/Unit/Service/RoleServiceTest.php`
  - NC admin override (REQ-ROLE-001, REQ-ROLE-005)
  - Direct user assignment returned as-is (REQ-ROLE-005, REQ-ROLE-009)
  - Highest group role wins (REQ-ROLE-005, REQ-ROLE-009)
  - Direct assignment beats higher-ranked group assignment (REQ-ROLE-009)
  - Null result when no assignments (REQ-ROLE-005)
  - `validateTarget` rejects both/neither set
  - `assignRole` inserts row with correct audit fields (REQ-ROLE-004)
  - Duplicate assignment returns 409 (REQ-ROLE-004)
  - `deleteByUserId` removes user assignments only (REQ-ROLE-010)
  - `deleteByGroupId` removes group assignments only (REQ-ROLE-011)
  - Auth helpers: `isAdmin`, `isEditorOrHigher`, `canMutate`

- [ ] **File:** `tests/Unit/Controller/AdminControllerTest.php`
  - `listRoles` returns all assignments
  - `createRole` user assignment → 201
  - `createRole` group assignment → 201
  - `createRole` duplicate → 409
  - `createRole` both userId+groupId → 400
  - `deleteRole` success → 204
  - `deleteRole` not found → 404
  - `getMyRole` returns role + source for authenticated user

- [ ] **File:** `tests/Unit/Service/PermissionServiceTest.php` (update)
  - Viewer role blocks `canEditDashboard`, `canAddWidget`, `canCreateDashboard`
  - Admin role bypasses group-membership check in `canEditDashboard`
  - Editor role with matching group → `canEditDashboard` returns true
  - Editor role without group → `canEditDashboard` returns false (REQ-ROLE-007)

- [ ] Update `tests/Unit/Listener/UserDeletedListenerTest.php` to inject `RoleService` dependency
- [ ] Create `tests/Unit/Listener/GroupDeletedListenerTest.php`

## Verify

- [ ] Run `composer check:strict` — lint, phpcs, phpmd, psalm, phpstan must all pass
- [ ] Run `phpunit` — all existing tests still pass; new tests pass
- [ ] Run `npm run build` — no new errors
- [ ] Confirm `GET /api/me/role` returns correct role and source for: NC admin, direct user assignment, group assignment, no assignment
- [ ] Confirm `POST /api/admin/roles` with duplicate returns 409
- [ ] Confirm viewer cannot call `PUT /api/dashboards/{uuid}` (403)
- [ ] Confirm admin can call `PUT /api/dashboards/{uuid}` on any scope without group membership
