# Tasks — mydash-enterprise-security-access

## Tasks

### Database & Schema

- [ ] Task 1: Create OpenRegister schema definitions in `lib/Settings/mydash_register.json` with `GuestRole`, `GuestRoleAssignment`, `DashboardPermission` using schema.org vocabulary (string properties for role labels, references via register+schema+objectId pattern)
- [ ] Task 2: Add repair step `OCA\MyDash\Migration\Version0200Date...::execute()` to import the `mydash_register.json` schema definitions via `ConfigurationService::importFromApp('mydash', data, version, force: true)` on install

### PHP Services

- [ ] Task 3: Create `OCA\MyDash\Service\GuestRoleService` with methods: `listRoles()`, `createRole(label, description)`, `deleteRole(roleId)`, `assignGuestToRole(userId, roleId, dashboardId)`, `revokeGuestRole(userId, roleId, dashboardId)`, `getUserRoles(userId, dashboardId)`. All methods MUST use `ObjectService` to persist roles in OpenRegister. Include `@spec openspec/changes/mydash-enterprise-security-access/tasks.md#task-3` PHPDoc tag on class and each public method.
- [ ] Task 4: Create `OCA\MyDash\Service\DashboardAuthorizationService` with methods: `canUserViewDashboard(userId, dashboardId): bool`, `getVisibleDashboards(userId): Dashboard[]`, `getVisibleWidgets(userId, dashboardId): Widget[]`. Implementation: (1) check if user is admin → true; (2) fetch dashboard permissions via `ObjectService`; (3) fetch user's assigned roles; (4) return true if any user role is in dashboard's allowed roles. Throws `OCSForbiddenException` on unauthorized access. Include spec tag on class and methods.
- [ ] Task 5: Create `OCA\MyDash\Service\ImpersonationService` with methods: `startImpersonation(adminUserId, roleId, dashboardId)`, `stopImpersonation(adminUserId)`, `getActiveImpersonation(adminUserId)`. Inject `AuditTrailService` and log start/stop events. Store session state in Pinia (not persisted). Methods do NOT check admin status — caller (controller) is responsible. Include spec tag on class and methods.

### Controllers & Routes

- [ ] Task 6: Create `OCA\MyDash\Controller\RoleController` with methods: `list()` returns user's roles on current dashboard via `GuestRoleService::getUserRoles()`, `create(label, description)` creates new role (admin-only), `delete(roleId)` deletes role (admin-only), `assign(userId, roleId, dashboardId)` assigns guest (admin-only), `revoke(userId, roleId, dashboardId)` revokes guest (admin-only). Annotate with `@spec` per method. Routes in `appinfo/routes.php`: `GET /roles`, `POST /roles`, `DELETE /roles/{id}`, `POST /roles/assign`, `POST /roles/revoke`.
- [ ] Task 7: Create `OCA\MyDash\Controller\ImpersonationController` with methods: `start(roleId, dashboardId)` calls `ImpersonationService::startImpersonation()`, returns `{active_impersonation}` JSON (admin-only via `#[AuthorizedAdminSetting]`); `stop()` calls `stopImpersonation()`, returns `{active_impersonation: null}` (admin-only). Routes: `POST /impersonate`, `DELETE /impersonate`.
- [ ] Task 8: Update `OCA\MyDash\Controller\DashboardController::index()` to inject `DashboardAuthorizationService`, check `canUserViewDashboard()` before returning dashboard state, throw 403 if unauthorized. Include spec tag on modified method.

### Frontend Store (Pinia)

- [ ] Task 9: Update `src/store/modules/dashboard.js` (Pinia) to add state: `userRoles: GuestRole[] = []`, `activeImpersonation: {roleId, roleLabel, dashboardId}|null = null`. Add computed `effectiveRole()` that returns `activeImpersonation?.roleId ?? null` (null means user's own role, not guest role). Add actions: `fetchUserRoles(dashboardId)` calls `GET /api/roles` and commits `setUserRoles`, `setImpersonation(roleId, dashboardId)` calls `POST /api/impersonate` and commits `setActiveImpersonation`, `clearImpersonation()` calls `DELETE /api/impersonate` and commits `setActiveImpersonation(null)`. Mutations: `setUserRoles(roles)`, `setActiveImpersonation(impersonation)`.
- [ ] Task 10: Update `src/store/store.js` to call `dashboard.fetchUserRoles(currentDashboardId)` on app init (in `initializeStores()`) so user's roles are loaded before rendering any widgets.

### Vue Components

- [ ] Task 11: Create `src/components/RoleSelector.vue` (self-contained, no wrapping card needed). Props: `modelValue: string|null` (current effective role), `roles: GuestRole[]`, `disabled: boolean`. Emits: `update:modelValue`. Render: select dropdown with role label + icon, banner when impersonation active ("Viewing as [role] — [Exit] button"), "Exit" button calls `@update:modelValue(null)`. Accessible per ADR-010: keyboard navigation, ARIA labels. Include SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`.
- [ ] Task 12: Update `src/views/Dashboard.vue` to (1) inject `dashboard` store via Pinia, (2) show RoleSelector in header (admin-only) if user has ≥2 roles OR user is admin on the dashboard, (3) bind `:effectiveRole="dashboard.effectiveRole"` as prop to RoleSelector, (4) call `@update:modelValue` to trigger store `setImpersonation()/clearImpersonation()`, (5) pass `effectiveRole` prop to all widget children so widgets can skip rendering if unauthorized. Include spec tag in file docblock.
- [ ] Task 13: Update all widget components (`src/components/WidgetXyz.vue`) to accept `effectiveRole: string|null` prop. Before rendering widget content, check if `widget.configuration.linkedRoles` exists. If it does AND `effectiveRole` is not null AND `effectiveRole not in widget.configuration.linkedRoles`, return empty/hidden (do not mount child content). Log a console warning if hidden: `console.warn('Widget hidden due to role restriction')`. Include spec tag in file docblock.

### API & Permissions

- [ ] Task 14: Add to `lib/Controller/DashboardController` a method `getPermissions(dashboardId)` (admin-only, `#[AuthorizedAdminSetting]`) that returns `{can_view: [{role_id, role_label}]}`. Route: `GET /permissions/{id}`.
- [ ] Task 15: Update all existing dashboard API endpoints to check `DashboardAuthorizationService::canUserViewDashboard()` and return 403 Forbidden if the user (in their effective role) cannot access. Affected: `GET /dashboards`, `GET /dashboards/{id}`, `GET /dashboards/{id}/widgets`. Include spec tag on each modified method.

### Settings & Admin UI

- [ ] Task 16: Create admin settings panel (`src/views/AdminSettings/RoleManagement.vue`) using `CnIndexPage` + `CnFormDialog` pattern from `@conduction/nextcloud-vue`. Render: table of roles (columns: label, description, created_at, actions). Actions: create (plus button → form dialog), edit, delete. Form dialog fields: label (required), description (optional). On save: call `POST /api/roles` or `PUT /api/roles/{id}`. Delete triggers cascade cleanup via backend. Include SPDX header.
- [ ] Task 17: Create admin panel for dashboard permissions (`src/views/AdminSettings/DashboardPermissions.vue`) using same component pattern. Render: dropdown to select a dashboard, then checkboxes for each role (check = can view). Save button calls `PUT /api/dashboards/{id}/permissions`. Include SPDX header.
- [ ] Task 18: Add admin settings routes to `src/router/index.js`: `/admin/roles` → RoleManagement.vue, `/admin/permissions` → DashboardPermissions.vue. Verify corresponding PHP routes exist in `appinfo/routes.php`.

### Tests

- [ ] Task 19: PHPUnit — `tests/Unit/Service/DashboardAuthorizationServiceTest.php` — test canUserViewDashboard() with admin user (always true), guest with matching role (true), guest with no role (false), guest with non-matching role (false), no permissions set (defaults true for backward compat).
- [ ] Task 20: PHPUnit — `tests/Unit/Service/ImpersonationServiceTest.php` — test startImpersonation() stores state in Pinia store, stopImpersonation() clears state, audit trail entries created with correct event type + actor + role.
- [ ] Task 21: PHPUnit — `tests/Unit/Service/GuestRoleServiceTest.php` — test listRoles(), createRole(), deleteRole() with cascade cleanup, assignGuestToRole(), revokeGuestRole(), getUserRoles().
- [ ] Task 22: Vitest — `src/store/__tests__/dashboard.spec.js` — test store state initialization, mutations (setUserRoles, setActiveImpersonation), computed effectiveRole(), actions (fetchUserRoles, setImpersonation, clearImpersonation).
- [ ] Task 23: Vitest — `src/components/__tests__/RoleSelector.spec.js` — test rendering with roles, dropdown interaction, banner visibility when impersonation active, "Exit" button trigger.
- [ ] Task 24: Vitest — `src/views/__tests__/Dashboard.spec.js` — test RoleSelector visibility (admin-only), widget filtering based on effectiveRole + linkedRoles schema config.
- [ ] Task 25: Playwright — e2e test `tests/e2e/role-based-access.spec.js` — (1) admin creates a role "Chair"; (2) admin assigns guest to role; (3) guest logs in, sees only authorized dashboard; (4) guest without role gets 403; (5) admin starts impersonation, sees guest view, banner shows, audit logged; (6) admin exits impersonation, reverts to admin view; (7) page reload clears impersonation.

### Documentation & i18n

- [ ] Task 26: Admin docs — `docs/admin-roles.md` — how to create roles, assign guests, manage dashboard permissions, test via impersonation. Screenshots of settings panels. Include troubleshooting section: "Guest can't see dashboard" → check assignment + check permissions.
- [ ] Task 27: User docs — `docs/user-roles.md` — "What are roles?", "I'm viewing as a guest role", "Exiting guest view", "I don't have access". Screenshot of impersonation banner.
- [ ] Task 28: i18n — add keys to `l10n/en.json`: "Role", "Board chair", "Secretary", "Board member", "View as", "You are viewing as {role}", "Exit viewing as", "Role management", "Create role", "Dashboard permissions", "Only the following roles can view this dashboard", "Access denied", "You do not have permission to view this dashboard". Add Dutch translations to `l10n/nl.json` (matching key set exactly). Verify via `grep -c '"' l10n/en.json l10n/nl.json` that both files have the same key count.

### Quality Checks

- [ ] Task 29: PHPCS/PHPMD/PHPStan/Psalm — run `composer check:strict` on all modified/new PHP files; zero new issues. Run `npm run lint` on all Vue/JS files; zero new issues.
- [ ] Task 30: Deduplication check — verify no role management already exists in OpenRegister, no duplicate RBAC logic in other apps. Document findings in spec.md "Reuse Analysis" section even if "no overlap found".
- [ ] Task 31: SPDX headers — verify all new PHP files have `@license EUPL-1.2` + `@copyright 2026 Conduction B.V.` + `@spec openspec/changes/mydash-enterprise-security-access/...` PHPDoc tags. Verify all new Vue/JS files have `<!-- SPDX-License-Identifier: EUPL-1.2 -->` or `// SPDX-License-Identifier: EUPL-1.2` as first line.
- [ ] Task 32: Backwards compatibility — verify existing dashboards without permission records default to visible (no regression). Verify guest-link URLs unchanged.

## Verification

`openspec validate` exits clean. All requirements (REQ-SEC-001 through REQ-SEC-005) tested and passing. Role-based access enforced at API level (returns 403 Forbidden) and frontend level (widgets hidden). Impersonation logged, session-only, clearable.

## Tests (company-wide ADR-008)

- PHPUnit: 5 test files covering services + edge cases (Task 19–21)
- Vitest: 3 test files covering store + components (Task 22–24)
- Playwright: 1 e2e test covering user journeys (Task 25)
- Newman/Postman: API collection testing roles + permissions + impersonation endpoints (optional, covered by Playwright)

## Documentation (company-wide ADR-009)

- Admin guide: `docs/admin-roles.md` (Task 26)
- User guide: `docs/user-roles.md` (Task 27)
- Inline code comments on all public methods linking spec REQ-XXX-NNN (Task 3–8)
- Changelog entry describing role-based access + impersonation feature

## i18n (company-wide ADR-007)

- All user-facing strings in English keys, Dutch translations (Task 28)
- Sentence case per ADR-007 rule (e.g., "View as role", not "View As Role")
- All translation keys defined in `l10n/en.json` + `l10n/nl.json` with zero gaps
