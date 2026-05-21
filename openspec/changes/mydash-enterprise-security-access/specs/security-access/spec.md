---
capability: security-access
delta: false
status: draft
---

# Security and Access — specification for change `mydash-enterprise-security-access`

## NEW Capability: security-access

Enterprise deployments of MyDash require fine-grained role-based access control for guest users and admin troubleshooting via impersonation. This capability adds guest role management, per-role dashboard visibility, and audit-logged impersonation.

## NEW Requirements

### Requirement: REQ-SEC-001 Guest role management

The system MUST support creating and managing guest user roles independent of Nextcloud's global group system. A role is a named permission level (e.g., "Chair", "Secretary") that grants visibility to specific dashboards. A guest user assigned to a role on a dashboard sees only dashboards/widgets marked for that role; an unassigned user sees nothing.

#### Scenario: Admin creates a new role

- **GIVEN** an admin on the MyDash settings panel
- **WHEN** they click "Add role" and enter label "Board Chair"
- **THEN** the role MUST be persisted in OpenRegister schema `GuestRole`
- **AND** subsequent calls to `GET /api/roles` MUST include the new role

#### Scenario: Guest user with assigned role can view dashboard

- **GIVEN** a guest user "alice@example.org" assigned the "Chair" role on the "Strategic Planning" dashboard
- **WHEN** alice loads the dashboard
- **THEN** the dashboard visibility check (`DashboardAuthorizationService::canUserViewDashboard()`) MUST return `true`
- **AND** alice MUST see all widgets marked as visible to "Chair"
- **AND** widgets NOT marked for "Chair" visibility MUST be hidden (not rendered)

#### Scenario: Guest user without assignment sees nothing

- **GIVEN** a guest user "bob@example.org" with no role assignments on any dashboard
- **WHEN** bob tries to access a dashboard
- **THEN** the API MUST return 403 Forbidden
- **AND** the frontend MUST show "Access denied" instead of the dashboard

#### Scenario: Role deletion cascades to assignments

- **GIVEN** a role "Observer" with 3 active guest assignments
- **WHEN** an admin deletes the "Observer" role
- **THEN** all assignments MUST be deleted
- **AND** guests previously assigned to "Observer" MUST lose access to affected dashboards
- **AND** the action MUST be logged to the audit trail

### Requirement: REQ-SEC-002 Admin impersonation with audit trail

Admins MUST be able to "view as" a specific guest role from their admin session to preview what that role sees. The impersonation MUST be logged to the immutable audit trail with start/stop timestamps. Impersonation is session-only: reloading the page reverts the admin to their own role.

#### Scenario: Admin starts impersonation

- **GIVEN** an admin on a dashboard
- **WHEN** they click "View as" → select "Chair"
- **THEN** the API call `POST /api/impersonate` MUST succeed
- **AND** a banner MUST appear: "You are viewing as Chair — [Exit]"
- **AND** the dashboard MUST re-render showing only widgets visible to "Chair"
- **AND** an audit-trail entry MUST be created: `{type: 'impersonation_start', actor: admin_id, role: 'Chair', timestamp}`

#### Scenario: Impersonation is cleared on page reload

- **GIVEN** an admin currently impersonating "Secretary"
- **WHEN** they reload the page (F5 or Cmd+R)
- **THEN** the page MUST load showing the admin's own role, not "Secretary"
- **AND** the Pinia store MUST show `activeImpersonation: null`
- **AND** an audit-trail entry MUST be created: `{type: 'impersonation_stop', actor: admin_id, timestamp}`

#### Scenario: Admin exits impersonation

- **GIVEN** an admin impersonating "Member"
- **WHEN** they click the "Exit" button in the banner
- **THEN** the API call `DELETE /api/impersonate` MUST succeed
- **AND** the banner MUST disappear
- **AND** the dashboard MUST re-render showing all widgets (admin view)
- **AND** an audit-trail entry MUST be created: `{type: 'impersonation_stop', actor: admin_id, timestamp}`

#### Scenario: Non-admin cannot impersonate

- **GIVEN** a guest user (non-admin) on the dashboard
- **WHEN** they call `POST /api/impersonate` with a role ID
- **THEN** the API MUST return 403 Forbidden
- **AND** no impersonation MUST occur
- **AND** no audit-trail entry MUST be created for this failed attempt

### Requirement: REQ-SEC-003 Dashboard permission mapping

Each dashboard MUST declare which roles can view it. This declaration is independent of individual guest assignments — a role can exist without being assigned to anyone, and a guest can be assigned a role without that role being enabled on that dashboard.

#### Scenario: Admin restricts dashboard to specific roles

- **GIVEN** a dashboard "Board Minutes" and roles "Chair", "Secretary", "Member"
- **WHEN** an admin sets permissions: "Only Chair and Secretary can view"
- **THEN** a `DashboardPermission` record MUST be created for each allowed role
- **AND** a guest with "Member" role MUST get 403 when accessing "Board Minutes"
- **AND** a guest with "Chair" role MUST be able to access it

#### Scenario: No permissions set = all authenticated users can view (default)

- **GIVEN** a newly-created dashboard with no permission records
- **WHEN** a guest accesses it
- **THEN** the visibility check MUST default to `canView: true` (backward-compatible with existing dashboards)
- **AND** any authenticated guest MUST be able to see it

#### Scenario: Widget visibility inherits from dashboard, with schema config override

- **GIVEN** a dashboard visible to "Chair" and "Secretary"
- **AND** a widget with `configuration.linkedRoles: ["Chair"]` (Chair only)
- **WHEN** a "Secretary" guest views the dashboard
- **THEN** the dashboard MUST be visible
- **BUT** the Chair-only widget MUST be hidden
- **AND** other widgets MUST be visible

### Requirement: REQ-SEC-004 User role context in API responses

Every API response MUST include the user's effective role context so the frontend can render role-specific UI. The "effective role" is either the user's assigned role(s) on the dashboard, or the impersonated role if in an impersonation session.

#### Scenario: API includes user's roles

- **GIVEN** a guest "alice@example.org" assigned to roles "Chair" and "Member" on different dashboards
- **WHEN** alice calls `GET /api/dashboards/{id}`
- **THEN** the response MUST include (in headers or body): `X-User-Roles: Chair,Member` (or similar)
- **AND** the frontend MUST use this to filter widgets without a second API call

#### Scenario: Impersonation context overrides user's actual role

- **GIVEN** an admin (no guest roles) in an active impersonation as "Secretary"
- **WHEN** they call `GET /api/dashboards/{id}`
- **THEN** the response MUST indicate the effective role is "Secretary" (not admin)
- **AND** the backend MUST use "Secretary" for visibility checks, not admin-level checks

### Requirement: REQ-SEC-005 Role-based widget visibility enforcement

Every widget component MUST respect the user's effective role and MUST NOT render if the user's role is not authorized. The authorization logic is: dashboard allows role AND widget schema allows role (or widget has no role restriction).

#### Scenario: Widget hidden when role is not in schema config

- **GIVEN** a widget with `schema.configuration.linkedRoles: ["Chair"]`
- **AND** a guest with "Member" role viewing the dashboard
- **WHEN** the dashboard Vue component renders
- **THEN** the widget component MUST NOT be mounted in the DOM
- **AND** no API call for the widget's data MUST be made

#### Scenario: Widget visible when role matches

- **GIVEN** the same widget with `linkedRoles: ["Chair", "Member"]`
- **AND** the same guest with "Member" role
- **WHEN** the dashboard renders
- **THEN** the widget MUST be mounted
- **AND** the widget's data API call MUST be made with the user's role context

#### Scenario: Admin sees all widgets regardless of role config

- **GIVEN** a dashboard with multiple widgets, each with different `linkedRoles` restrictions
- **WHEN** an admin accesses the dashboard (not impersonating)
- **THEN** ALL widgets MUST be visible
- **AND** the admin MUST see a visual indicator (icon, label) indicating they are viewing with admin privileges

## Acceptance Criteria Summary

- Guest roles are CRUD-able via PHP service and API
- Dashboard permissions declare allowed roles; API enforces via 403 Forbidden
- Impersonation is session-only, starts/stops logged to audit trail, clearable via page reload or "Exit" button
- Widgets render only when user's effective role is authorized
- All role context flows through API responses so frontend never needs a second lookup
- Non-admin users cannot call impersonation endpoints (403 Forbidden)
- Cascade deletes clean up orphaned assignments when roles or dashboards are deleted

## Notes

- Schema validation: `GuestRole`, `GuestRoleAssignment`, `DashboardPermission` are all defined in OpenRegister via repair-step migration
- Audit trail: all impersonation events (start, stop, role change) logged via `AuditTrailService`
- Performance: user role lookups cached in Pinia store with TTL; cache invalidated on logout or impersonation change
- i18n: all user-facing role labels and UI strings translated to Dutch via `l10n/en.json` and `l10n/nl.json`
- Backwards compatibility: existing dashboards without permission records default to visible (do not break when this feature lands)
