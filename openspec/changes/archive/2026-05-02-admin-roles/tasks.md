# Admin Roles — Implementation Tasks

## Data Model & Persistence

### Task 1: Create RoleAssignment Entity & Mapper
- [x] **File:** `lib/Db/RoleAssignment.php`
  - Fields: `id` (int), `userId` (varchar 64, nullable), `groupId` (varchar 64, nullable), `role` (enum: admin|editor|viewer), `assignedBy` (varchar 64), `assignedAt` (timestamp)
  - Getters/setters for all fields
  - Adds `getTarget()`, `isUserAssignment()`, `isGroupAssignment()` predicates
  - Adds role-rank table + source-prefix constants for the resolver

- [x] **File:** `lib/Db/RoleAssignmentMapper.php`
  - `findAll()`, `findByUser`, `findByGroup`, `findByGroupIds`, `findById`, `findUserRole`, `findGroupRole`, `insert` (inherited), `update` (inherited), `deleteById`, `deleteByUserId`, `deleteByGroupId`
  - All methods follow Nextcloud mapper conventions

### Task 2: Create Database Migration
- [x] **File:** `lib/Migration/Version001009Date20260502120000.php` + `lib/Migration/RoleAssignmentTableBuilder.php`
- Table: `oc_mydash_role_assignments`
  - Columns: `id`, `user_id` (nullable), `group_id` (nullable), `role`, `assigned_by`, `assigned_at`
  - Indexes: per-column lookup indexes + composite UNIQUE on `(user_id, role)` and `(group_id, role)`
  - XOR constraint enforced at the service layer (RoleService::validateTarget) — DB-level CHECK is not portable across the SQL dialects Nextcloud supports

## Business Logic & Services

### Task 3: Create RoleService
- [x] **File:** `lib/Service/RoleService.php`
  - `__construct(RoleAssignmentMapper, IUserManager, IGroupManager, AdminTemplateService)` — `AdminTemplateService::getUserGroupIdsFor` is the routing-resolver helper required by REQ-TMPL-013
  - **Role Resolution:** `getEffectiveRole`, `getRoleSource`
  - **Validation & Assignment:** `validateRole`, `validateTarget`, `assignRole`, `removeRole`, `listAssignments`
  - **Cascade:** `deleteByUserId`, `deleteByGroupId`
  - **Authorization Helpers:** `isAdmin`, `isEditorOrHigher`, `isViewerOrHigher`, `isViewer`, `canMutate`

## API Endpoints

### Task 4: Create AdminController Endpoints
- [x] **File:** `lib/Controller/AdminController.php` (extended existing controller)
  - `listRoles()` → GET — NC-admin-gated
  - `createRole(?userId, ?groupId, ?role)` → POST — 201/400/409
  - `deleteRole(int id)` → DELETE — 204/404
  - `getMyRole()` → GET `/api/me/role` — any authenticated user

### Task 5: Register Routes
- [x] **File:** `appinfo/routes.php`
  - `admin#listRoles`, `admin#createRole`, `admin#deleteRole`, `admin#getMyRole` registered

## Authorization Integration

### Task 6: Extend PermissionService
- [x] **File:** `lib/Service/PermissionService.php`
  - Inject `RoleService`
  - Viewer role short-circuits `canEditDashboard`, `canAddWidget`, `canRemoveWidget`, `canStyleWidget`, `canCreateDashboard`
  - Admin role overrides `canEditDashboard` and the group-membership check inside `resolveAccessLevel`
  - Editor role grants full access on `group_shared` dashboards through the existing membership path inside `getEffectivePermissionLevel`
  - Viewer + Editor role taken into account in `canCreateDashboard` (Editor always allowed; Viewer always blocked)

### Task 7: Implement User Deletion Listener
- [x] **File:** `lib/Listener/UserDeletedListener.php`
  - Existing share-cleanup listener extended to also call `RoleService->deleteByUserId()`. Best-effort with try/catch + logger so a role-cleanup failure never aborts the share-cascade pipeline.

### Task 8: Implement Group Deletion Listener
- [x] **File:** `lib/Listener/GroupDeletedListener.php` (new)
  - Listens to `OCP\Group\Events\GroupDeletedEvent`
  - Calls `RoleService->deleteByGroupId()` with try/catch + logger

### Task 9: Register Event Listeners
- [x] **File:** `lib/AppInfo/Application.php`
  - `UserDeletedEvent` listener wiring already present (extended). New `GroupDeletedEvent` → `GroupDeletedListener` registration added.

## Localization
- [x] Added 11 new translatable strings to `l10n/en.{json,js}` and `l10n/nl.{json,js}` (role names, validation errors, duplicate / not-found / unknown messages).

## Testing
- [x] `tests/Unit/Service/RoleServiceTest.php` — 19 cases covering NC-admin override, direct vs. group resolution, highest-rank winner, validation, CRUD, duplicates, cascades, auth helpers
- [x] Updated `tests/Unit/Listener/UserDeletedListenerTest.php` to inject the new RoleService dependency
- [x] Updated `tests/Unit/Controller/AdminControllerGroupOrderTest.php` to inject the new RoleService dependency

## Verification
- [x] `composer check:strict` — ALL CHECKS PASSED (lint, lint:initial-state, phpcs, phpmd, psalm, phpstan, test:all)
- [x] PHPUnit: 433 tests, 1067 assertions
- [x] Vitest: 27 files, 299 tests
- [x] `npm run build` — compiled with warnings only (asset-size advisories unrelated to this change)
- [x] `openspec validate admin-roles --strict` — valid

## Notes

- All dates use ISO-8601 (`'c'` format) for consistency with the dashboard-share table.
- Role validation: explicit enum check, not schema-enforced, for forward compatibility.
- No background cleanup job needed; assignments are permanent until explicitly deleted or cascaded.
- Pre-existing PHPMD CouplingBetweenObjects warning on `DashboardApiController` covered with a documented `@SuppressWarnings` annotation while resolving the same warning class on the newly extended `AdminController`.
- The grep guard `AdminTemplateServiceGrepGuardTest` (REQ-TMPL-013) is preserved — `RoleService` consumes `AdminTemplateService::getUserGroupIdsFor()` rather than calling `IGroupManager::getUserGroupIds` directly.
