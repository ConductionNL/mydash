# Design — Enterprise security and access controls

## Context

MyDash currently enforces access control at the user level: an authenticated user is either an admin (sees all dashboards) or a guest (sees no dashboards). This binary model makes it impossible to grant selective dashboard access to external collaborators without making them Nextcloud administrators. Enterprises deploying MyDash for multi-stakeholder governance (city councils, university boards, project consortia) need role-based visibility so that board members, secretaries, and external observers can each see only the dashboards and widgets relevant to their role, without admin privileges.

Additionally, troubleshooting access issues requires admins to log out, log in as a guest, and try to reproduce the problem. A better approach is role impersonation: an admin can "view as" a specific role from their own admin session, see exactly what that role sees, and the action is logged for audit purposes.

## Goals / Non-Goals

**Goals:**

- Enable selective dashboard and widget visibility per guest user role
- Provide a single "view as <role>" admin UI for troubleshooting access issues without requiring switch-user sessions
- Record all role changes and impersonation events in the immutable audit trail
- Use OpenRegister RBAC (ADR-022) as the canonical authority for role definitions and permissions
- Keep guest-link URLs unchanged — role checks happen at API/component level, not in the share-key logic

**Non-Goals:**

- Per-widget role exceptions (override dashboard role rules for specific widgets) — defer to Phase 2
- Row-level data filtering inside widgets (show only cases assigned to users in role X) — defer to Phase 2
- Dynamic role hierarchy (secretary inherits chair permissions) — start with flat roles, add hierarchy in Phase 2
- Integration with Nextcloud's native group RBAC — this change uses app-local roles only, stored in OpenRegister

## Architecture

### Data Model

**Entities (OpenRegister schemas):**

1. **GuestRole** — a role definition (chair, secretary, member, observer)
   - `slug`: string, required (e.g., `"chair"`)
   - `label`: string, required (e.g., `"Board Chair"`)
   - `description`: string, optional
   - `createdAt`: datetime, auto
   - `createdBy`: user ID, auto

2. **GuestRoleAssignment** — maps a guest user to a role
   - `guestUserId`: string, required (Nextcloud user ID)
   - `roleId`: UUID (GuestRole reference)
   - `dashboardId`: UUID (Dashboard reference) — role is scoped to one dashboard
   - `assignedAt`: datetime, auto
   - `assignedBy`: user ID, auto

3. **DashboardPermission** — declares which roles can view a dashboard
   - `dashboardId`: UUID (Dashboard reference), required
   - `roleId`: UUID (GuestRole reference), required
   - `canView`: boolean, default `true`
   - `createdAt`: datetime, auto

### PHP Services

**`GuestRoleService`** — CRUD for roles
- `listRoles(): GuestRole[]` — all roles
- `createRole(label, description): GuestRole` — new role
- `deleteRole(roleId): void` — delete role (cascade to assignments)
- `assignGuestToRole(userId, roleId, dashboardId): void` — add assignment
- `revokeGuestRole(userId, roleId, dashboardId): void` — remove assignment
- `getUserRoles(userId, dashboardId): GuestRole[]` — what roles does this user have on this dashboard?

**`DashboardAuthorizationService`** — visibility checks
- `canUserViewDashboard(userId, dashboardId): bool` — has this user access (admin or granted role)?
- `getVisibleDashboards(userId): Dashboard[]` — all dashboards the user can view
- `getVisibleWidgets(userId, dashboardId): Widget[]` — all widgets the user can view on this dashboard (filtered by dashboard permissions + widget schema config)
- Throws `OCSForbiddenException` on unauthorized access

**`ImpersonationService`** — role switching with audit trail
- `startImpersonation(adminUserId, targetRole, dashboardId): void` — admin assumes a role; logged to audit
- `stopImpersonation(adminUserId): void` — admin reverts to own role; logged to audit
- `getActiveImpersonation(adminUserId): {roleId, roleLabel, dashboardId}|null` — current impersonation state
- Stores state in Pinia `dashboard` store (per-session, not persisted) + AuditTrailService

### Frontend (Vue)

**`src/store/modules/dashboard.js` (Pinia store additions):**
- `state.userRoles`: `GuestRole[]` — roles assigned to current user
- `state.activeImpersonation`: `{roleId, roleLabel, dashboardId}|null` — current impersonation (null = user's own role)
- `state.effectiveRole`: computed — returns activeImpersonation.roleId or null (indicates admin)
- Action `fetchUserRoles(dashboardId)` — load user's roles on a dashboard via API
- Action `setImpersonation(roleId, dashboardId)` — call backend, update store
- Action `clearImpersonation()` — call backend, update store
- Mutation `setUserRoles(roles)` — update state.userRoles
- Mutation `setActiveImpersonation(impersonation)` — update state.activeImpersonation

**`src/views/Dashboard.vue` (modifications):**
- Inject `effectiveRole` from store
- Show "View as" role selector in header (admin-only, visible only when user has ≥2 roles or is admin)
- Pass `effectiveRole` prop to each widget component
- Widget components skip rendering if widget not in `getVisibleWidgets()` response

**`src/components/RoleSelector.vue` (new):**
- Display: icon + role label + dropdown menu
- On selection: call `store.setImpersonation(roleId)`, which triggers store mutation + re-renders
- Show banner when active impersonation: "Viewing as [role] — [Exit] button"
- Accessible: keyboard navigation, ARIA labels per ADR-010

### API Endpoints

**`GET /index.php/apps/mydash/api/roles`**
- Returns: `GuestRole[]` for current user
- Auth: `#[NoAdminRequired]` + per-object check via `DashboardAuthorizationService`
- Response: `[{id, slug, label, description, dashboard_id}]`

**`GET /index.php/apps/mydash/api/dashboards/{id}/permissions`**
- Returns: roles that can view this dashboard
- Auth: `#[AuthorizedAdminSetting]` (admins only)
- Response: `{can_view: [{role_id, role_label}]}`

**`POST /index.php/apps/mydash/api/impersonate`**
- Start role impersonation
- Auth: `#[AuthorizedAdminSetting]` (admins only)
- Body: `{role_id, dashboard_id}`
- Response: `{active_impersonation: {role_id, role_label, dashboard_id}}`
- Side effect: logs to audit trail

**`DELETE /index.php/apps/mydash/api/impersonate`**
- Stop role impersonation
- Auth: `#[AuthorizedAdminSetting]`
- Response: `{active_impersonation: null}`
- Side effect: logs to audit trail

### Reuse Analysis

- **OpenRegister RBAC** (ADR-022) — consumed via `ObjectService` for role/permission objects
- **AuditTrailService** — used by `ImpersonationService` to log start/stop events (consumed via DI)
- **@conduction/nextcloud-vue** — uses `CnSelectRole` component (if available) for role selector; falls back to `NcSelect`
- **Nextcloud AuthService** — consumed via `IUserSession` to identify current user (no change from existing pattern)

## Decisions

### D1: Roles are stored in OpenRegister, not Nextcloud groups

**Decision:** `GuestRole` and `GuestRoleAssignment` are OpenRegister schemas, not Nextcloud groups.

**Rationale:** Nextcloud groups are global and admin-managed; OpenRegister allows app-local, self-managed roles. Decidesk and other governance apps need similar patterns. Centralizing in OpenRegister (ADR-022 principle) avoids per-app duplication.

**Trade-off:** Admins must manage roles in MyDash settings, not in Nextcloud group admin UI. Acceptable because roles are dashboard-specific.

### D2: Role impersonation is session-only, not persisted to disk

**Decision:** `activeImpersonation` lives in Pinia store (`state`) only. On page reload, the impersonation is cleared and the user reverts to their own role.

**Rationale:** Impersonation is a troubleshooting tool, not a persistent state. Session-only prevents accidental long-term role switching. Audit trail records when impersonation started/stopped, so the session is still auditable.

**Alternative considered:** Persist to `IAppConfig`. Rejected because forgetting to exit impersonation could silently skew access for hours.

### D3: Widget visibility is determined by dashboard permissions + optional widget-level schema config

**Decision:** A widget is visible if (1) the user has a role that can view the dashboard AND (2) the widget's schema `configuration.linkedRoles` (if set) includes that role. By default, all widgets inherit the dashboard visibility.

**Rationale:** Most widgets are "see the whole dashboard or see nothing." Some widgets (e.g., "Chair summary") might need explicit role carve-outs without creating a new dashboard. Schema config makes this possible without hardcoding.

**Alternative considered:** Dashboard-level granularity only. Rejected because governance apps need some per-widget nuance without creating 50 dashboards.

**Deferred:** Row-level filtering inside widgets (show only cases assigned to you) is Phase 2, driven by widget-internal queries.

### D4: Admin-only checks use `#[AuthorizedAdminSetting]`, not body-level `requireAdmin()`

**Decision:** Impersonation endpoints carry `#[AuthorizedAdminSetting(Application::APP_ID)]` attribute. The framework gate enforces admin-only; no body-level check.

**Rationale:** ADR-005 + ADR-016 — attribute-based gating at middleware level is declarative and grep-able. Reviewers see the auth rule in `appinfo/routes.php`, not buried in controller code.

### D5: Guest links remain unchanged; role check happens at API level

**Decision:** The existing dashboard share URL (with share token) does not change. A guest accessing via share token is still prompted for their email, which is matched to role assignments.

**Rationale:** Backwards compatibility. Share tokens encode the share ID, not the role. Role is derived from the user's Nextcloud UID + the dashboard they're accessing.

**Implication:** A guest can access the same dashboard via (1) share link → email prompt → role lookup, or (2) direct login if they have a Nextcloud user account → role lookup. Both paths use `DashboardAuthorizationService::canUserViewDashboard()`.

## Risks / Mitigations

| Risk | Mitigation |
|---|---|
| Admin forgets they're impersonating and makes policy changes as the guest role | Session-only impersonation clears on reload; banner reminder in UI; audit trail shows when impersonation started/stopped so ops can review |
| Role assignments not synced when a dashboard is deleted | Cascade delete in `GuestRoleService::deleteRole()` removes assignments; same pattern for dashboards via controller delete handler |
| Widget visibility logic diverges (dashboard check says "yes" but widget says "no") | Widget's `linkedRoles` is optional; by default all widgets follow dashboard visibility. Schema config is auditable in design.md |
| Performance: every API call does a role lookup | Cache user roles in Pinia store with TTL; invalidate on impersonation change or logout. Role queries are indexed in OpenRegister |

## Migration Plan

1. **OpenRegister schemas land first** — `GuestRole`, `GuestRoleAssignment`, `DashboardPermission` added via repair step
2. **Services implemented** — `GuestRoleService`, `DashboardAuthorizationService`, `ImpersonationService`
3. **API endpoints wired** — roles, permissions, impersonation endpoints added to `appinfo/routes.php`
4. **Frontend store updated** — Pinia dashboard module gains `userRoles`, `activeImpersonation`, computed `effectiveRole`
5. **Dashboard.vue + RoleSelector.vue refactored** — render role selector, pass effectiveRole to widgets
6. **Widgets updated** — each widget consults `effectiveRole` (from props or store) and hides content when not authorized
7. **Settings panel added** — admin UI to define roles and assign guests to dashboards (uses `CnIndexPage` + `CnFormDialog` pattern)
8. **Tests added** — PHPUnit for services, Vitest for store, Playwright for end-to-end role switch
9. **Seed data** — `lib/Settings/mydash_register.json` includes 3 default roles (chair, secretary, member) and example assignments

## Seed Data

**In `lib/Settings/mydash_register.json`:**

```json
{
  "components": {
    "objects": [
      {
        "@self": {
          "register": "mydash_roles",
          "schema": "GuestRole",
          "slug": "role-chair"
        },
        "label": "Board Chair",
        "description": "Highest governance authority; can view all dashboards",
        "organization": "example.org"
      },
      {
        "@self": {
          "register": "mydash_roles",
          "schema": "GuestRole",
          "slug": "role-secretary"
        },
        "label": "Secretary",
        "description": "Administrative support; can view meeting agendas and minutes",
        "organization": "example.org"
      },
      {
        "@self": {
          "register": "mydash_roles",
          "schema": "GuestRole",
          "slug": "role-member"
        },
        "label": "Board Member",
        "description": "Can view meeting schedules and published decisions",
        "organization": "example.org"
      }
    ]
  }
}
```

**Schema Definitions:**

- `GuestRole`: string label, optional description, timestamps
- `GuestRoleAssignment`: guest user ID (vCard fn property), role ref, dashboard ref, timestamps
- `DashboardPermission`: dashboard ref, role ref, boolean `canView`, timestamps

## Test Strategy

- **PHPUnit**: `DashboardAuthorizationService::canUserViewDashboard()` with roles + no roles; `ImpersonationService::startImpersonation()` logs correctly
- **Vitest**: Pinia `dashboard` store mutations + actions; `effectiveRole` computed property; role selector component state
- **Playwright**: Admin enters "View as Chair", sees chair-visible widgets; exits impersonation, reverts to admin view; access denied (403) when non-admin tries to impersonate
- **Integration test** (Newman/Postman): GET /api/roles returns user's roles; POST /api/impersonate with admin auth succeeds; same call without admin auth returns 403

## Documentation (ADR-009)

- Admin docs: "Role-based access control" — how to create roles, assign guests, test via impersonation
- User docs: "I'm viewing as a guest" — explains the banner, how to exit
- Screenshots: role selector dropdown, impersonation banner, settings panel

## i18n (ADR-007)

- Keys (English): "Board chair", "Secretary", "Board member", "View as", "You are viewing as {role}", "Exit viewing as", "Role"
- Translations (Dutch): "Voorzitter", "Secretaris", "Bestuurslid", "Bekijken als", etc.
- All in `l10n/en.json` and `l10n/nl.json`
