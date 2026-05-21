# Enterprise security and access controls

MyDash dashboards today support admin-only visibility and read-only guest access, but lack fine-grained role-based permissions and impersonation capabilities needed in enterprise deployments. This change introduces guest user role management, per-role dashboard and widget visibility enforcement, and a "view as" impersonation feature for admins to troubleshoot access issues.

## Affected code units

- `lib/Controller/DashboardController.php` — add role context to dashboard state
- `lib/Service/DashboardAuthorizationService.php` (new) — per-role visibility enforcement
- `lib/Service/GuestRoleService.php` (new) — guest user role management
- `lib/Service/ImpersonationService.php` (new) — audit-logged role impersonation
- `src/store/modules/dashboard.js` — store user's roles + active impersonation
- `src/views/Dashboard.vue` — render "View as" role selector for admins; hide widgets/actions per active role
- `src/components/RoleSelector.vue` (new) — role selection dropdown with visual indicator
- OpenRegister schema: `GuestRole` — define available roles and their permissions per dashboard
- OpenRegister schema: `DashboardPermission` — map role ↔ dashboard/widget visibility
- Adds `security-access` capability to MyDash

## Why a delta

MyDash currently lacks role-based access control. Every user sees either (admin) all dashboards, or (guest) no dashboards. Enterprises with multiple governance bodies, departments, or partner integrations need to grant selective access to external users without promoting them to Nextcloud administrators. ADR-023 establishes the pattern for action-level RBAC; this change applies data-level RBAC to dashboard visibility via the OpenRegister integration.

## Approach

1. **Guest role registration** — admins define roles (chair, secretary, member) and assign guest users to them via settings.
2. **Dashboard permission mapping** — each dashboard declares which roles can view it. Roles and dashboard together determine widget-level visibility via schema definitions.
3. **Authorization service** — every dashboard load / widget fetch checks the user's role + dashboard permissions. Unauthorized access returns 403.
4. **Impersonation service** — admins can "View as <role>" to preview what a guest with that role sees. The active role is tracked per session, persisted in Pinia store, and logged to audit trail.
5. **Frontend enforcement** — Vue components bind visibility to the user's effective role (either their assigned role or the impersonated role). Role changes trigger re-renders via store mutations.

## Capabilities

**New Capabilities:**

- `security-access` — guest role definitions, dashboard permission mappings, per-role widget visibility, impersonation with audit logging

## Notes

- OpenRegister integration: guest roles and dashboard permissions are stored in OpenRegister and consumed via the shared `@conduction/nextcloud-vue` components.
- Audit trails: impersonation start/end and role changes are logged via OpenRegister's immutable audit service (ADR-022 lists audit as a consumed abstraction).
- No change to existing dashboard-sharing URLs — guest links remain valid; role checks happen at the API level, not the link level.
- Phase 2 (future): per-widget role exceptions for granular override; per-role data filtering for linked objects (files, notes, contacts).
