---
capability: role-feature-permissions
delta: false
status: draft
---

# Role Feature Permissions Specification

## Purpose

This capability governs which dashboard widgets and features are visible, accessible, and
default-seeded for users based on their Nextcloud group (role). It ensures that staff see only
the tools relevant to their job, that new users receive a role-appropriate starting layout
seeded from evidence rather than a generic blank dashboard, and that attempts to access
restricted features via direct URL are rejected at the API layer with a 403 response and an
audit log entry.

The capability is orthogonal to the existing `permissions` capability (which controls
per-dashboard edit rights) and the `admin-templates` capability (which distributes full
dashboard copies). All three can apply simultaneously.

---

## Data Model

### RoleFeaturePermission

Stored in OpenRegister under register `mydash`, schema `role-feature-permission`.
One object per Nextcloud group ID (enforced via slug convention `rfp-{groupId}`).

- `name` (string, required) — human-readable label
- `description` (string, optional) — purpose notes
- `groupId` (string, required) — Nextcloud group ID this rule applies to
- `allowedWidgets` (array\<string\>, required) — widget IDs the group may use
- `deniedWidgets` (array\<string\>, optional, default `[]`) — widget IDs explicitly denied;
  deny overrides any allow from other groups
- `priorityWeights` (object, optional) — `{ widgetId: integer }` — higher value = higher
  priority in layout seeding and display ordering

### RoleLayoutDefault

Stored in OpenRegister under register `mydash`, schema `role-layout-default`.
One object per (groupId, widgetId) pair (slug `rld-{groupId}-{widgetId}`).

- `name` (string, required) — display label
- `groupId` (string, required) — Nextcloud group ID
- `widgetId` (string, required) — Nextcloud Dashboard widget ID
- `gridX`, `gridY` (integer, required) — 0-based grid position
- `gridWidth`, `gridHeight` (integer, required) — grid span
- `sortOrder` (integer, required, default 0) — rendering order (lower = first)
- `isCompulsory` (boolean, optional, default false) — user cannot remove this widget
- `description` (string, optional) — rationale note

---

## Requirements

### REQ-RFP-001: Feature presence visibility by role

The system MUST render or hide dashboard features based on the authenticated user's effective
role-feature-permissions. A widget not in the user's effective `allowedWidgets` set MUST NOT
appear anywhere in the UI and MUST NOT be returned by `GET /api/widgets`.

#### Scenario: Employee-role user does not see admin-only widgets

- GIVEN a RoleFeaturePermission exists for group `"medewerkers"` with
  `allowedWidgets: ["activity", "recommendations", "notes", "calendar"]`
- AND user "jan.de.vries" belongs to group `"medewerkers"` (and no higher-priority group)
- WHEN jan.de.vries sends `GET /api/widgets`
- THEN the response MUST contain only widgets whose IDs are in `["activity", "recommendations", "notes", "calendar"]`
- AND the widget `"analytics_dashboard"` MUST NOT appear in the response
- AND the response status MUST be HTTP 200

#### Scenario: Admin updates role permissions, affected user sees updated set on reload

- GIVEN user "fatima.el-amrani" has group `"hrm"` and the current RoleFeaturePermission for
  `"hrm"` has `allowedWidgets: ["activity", "calendar"]`
- WHEN an IT admin updates the `"hrm"` RoleFeaturePermission to add `"user_status"` to
  `allowedWidgets`
- AND fatima.el-amrani reloads the dashboard
- THEN `GET /api/widgets` MUST return a list that includes `"user_status"`
- AND the card library MUST display `"user_status"` as available to add

#### Scenario: Direct URL request to restricted feature returns 403

- GIVEN a RoleFeaturePermission for group `"medewerkers"` does NOT include `"analytics_dashboard"`
- AND user "pieter.van.dijk" belongs only to group `"medewerkers"`
- WHEN pieter.van.dijk sends a GET request to the feature URL for `"analytics_dashboard"`
  (e.g. `GET /api/widgets/items?widgetId=analytics_dashboard`)
- THEN the system MUST return HTTP 403
- AND the response body MUST contain `{"message": "Not authorized"}`
- AND an audit entry MUST be written recording the user ID, widget ID, and timestamp
- AND the widget content MUST NOT be returned

---

### REQ-RFP-002: Role-based default dashboard layout

When a new user opens MyDash for the first time and no admin template matches their group,
the system MUST seed their dashboard from the RoleLayoutDefault objects for their primary
group (resolved via `group_order` priority). Existing personal customisations MUST never be
overwritten.

#### Scenario: New user receives role-appropriate default layout

- GIVEN user "noor.yilmaz" is a member of group `"managers"` (highest-priority matching group)
- AND no admin template targets group `"managers"`
- AND RoleLayoutDefault objects exist for group `"managers"`:
  - `analytics_dashboard` at (0,0), 8×6, sortOrder 1, isCompulsory true
  - `activity` at (8,0), 4×6, sortOrder 2, isCompulsory false
- WHEN noor.yilmaz opens MyDash for the first time (no existing dashboard)
- THEN `DashboardResolver::tryCreateFromTemplate()` MUST call `seedLayoutFromRoleDefaults()`
- AND the resulting dashboard MUST contain two WidgetPlacement rows matching the
  RoleLayoutDefault objects (gridX, gridY, gridWidth, gridHeight, isCompulsory preserved)
- AND the `analytics_dashboard` placement MUST have `isCompulsory: 1`

#### Scenario: Admin updates role defaults; new user receives updated layout

- GIVEN an IT admin updates the `"managers"` RoleLayoutDefault to add `"user_status"` at
  (0,7), 4×4, sortOrder 3
- WHEN a new user with group `"managers"` signs in and opens MyDash
- THEN their seeded dashboard MUST include `"user_status"` at the updated position
- AND previously created users are unaffected (their existing placements are not touched)

#### Scenario: Existing personal customisations are preserved when role defaults change

- GIVEN user "sem.de.jong" has an existing personal dashboard with two customised placements
- AND an IT admin updates the RoleLayoutDefault for sem's group
- WHEN sem.de.jong reloads MyDash
- THEN sem's existing placements MUST remain unchanged
- AND the new role defaults MUST NOT overwrite or remove any of sem's personal placements
- NOTE: `seedLayoutFromRoleDefaults()` is only called during first-creation; it does not
  run for existing dashboards.

---

### REQ-RFP-003: Role-based card library

The card library (widget picker) MUST only surface widgets permitted for the authenticated
user's effective role. Widgets outside the allowed set MUST be absent from the picker UI and
from `GET /api/widgets`.

#### Scenario: Manager-only card is hidden for employee-role user

- GIVEN a RoleFeaturePermission for group `"medewerkers"` with
  `allowedWidgets: ["activity", "recommendations", "notes", "calendar"]`
- AND a RoleFeaturePermission for group `"managers"` that additionally includes
  `"analytics_dashboard"`
- AND user "annemarie.de-vries" belongs only to group `"medewerkers"`
- WHEN annemarie opens the widget card library
- THEN `"analytics_dashboard"` MUST NOT be listed in the card library
- AND `"analytics_dashboard"` MUST NOT appear in `GET /api/widgets` response

#### Scenario: User holds a role granting card access — only permitted cards listed

- GIVEN user "mark.visser" belongs to group `"managers"` (in group_order)
- AND the `"managers"` RoleFeaturePermission `allowedWidgets` includes `"analytics_dashboard"`
- WHEN mark.visser opens the card library
- THEN `"analytics_dashboard"` MUST appear in the card library
- AND only widgets in the effective `allowedWidgets` set MUST be listed

#### Scenario: Admin revokes role; previously visible cards no longer accessible

- GIVEN user "jan.de.vries" previously had group `"managers"` which allowed `"analytics_dashboard"`
- WHEN an IT admin removes jan.de.vries from group `"managers"` in Nextcloud
- AND jan.de.vries reloads the dashboard
- THEN `GET /api/widgets` MUST no longer include `"analytics_dashboard"`
- AND if jan.de.vries had a placement of `"analytics_dashboard"` on their dashboard,
  that placement MUST have its `isVisible` set to 0 at render time (widget hidden, not deleted)

---

### REQ-RFP-004: Role-and-priority layout ordering

Widgets on a user's dashboard MUST be ordered according to the `priorityWeights` defined in
the user's effective RoleFeaturePermission. Widgets with higher priority weight MUST appear
earlier in the default sort order.

#### Scenario: New dashboard respects role priority weights

- GIVEN the `"managers"` RoleFeaturePermission has
  `priorityWeights: { "analytics_dashboard": 10, "activity": 8, "user_status": 6 }`
- AND RoleLayoutDefault objects for `"managers"` are seeded with matching sortOrder values
  (analytics_dashboard sortOrder 1, activity sortOrder 2, user_status sortOrder 3)
- WHEN a new `"managers"` user's layout is seeded via `seedLayoutFromRoleDefaults()`
- THEN the resulting WidgetPlacements MUST reflect the priority ordering
  (analytics_dashboard first on the grid, then activity, then user_status)

#### Scenario: Admin updates priority weights; next-loaded dashboard reflects new order

- GIVEN an IT admin updates the `"managers"` RoleFeaturePermission to raise `"activity"`
  weight to 12 (above `"analytics_dashboard"`)
- WHEN the admin also updates the corresponding RoleLayoutDefault sortOrder values
- WHEN a new `"managers"` user opens MyDash after the update
- THEN `"activity"` MUST appear at sortOrder 1 (first position)
- AND `"analytics_dashboard"` MUST appear at sortOrder 2

#### Scenario: Unpermitted high-priority widget is hidden; next eligible widget takes its slot

- GIVEN the `"medewerkers"` RoleFeaturePermission does NOT include `"analytics_dashboard"`
- AND the RoleLayoutDefault for `"managers"` places `"analytics_dashboard"` at sortOrder 1
- AND user "jan.de.vries" belongs only to group `"medewerkers"`
- WHEN jan.de.vries's dashboard is rendered
- THEN `"analytics_dashboard"` placement MUST NOT appear (not in allowed set)
- AND the next eligible widget MUST occupy the first visible slot

---

### REQ-RFP-005: Role precedence for multi-group users

When a user belongs to multiple groups that each have a RoleFeaturePermission, the system
MUST resolve a single deterministic effective widget set using the `group_order` admin setting.
The first matching group's `allowedWidgets` form the base set; additional matched groups widen
the set; `deniedWidgets` from any matched group always win.

#### Scenario: User sees role-default layout matching primary (highest-priority) group

- GIVEN `group_order = ["managers", "medewerkers", "all-staff"]`
- AND user "noor.yilmaz" belongs to groups `["medewerkers", "managers"]`
- WHEN noor.yilmaz's effective widget set is resolved
- THEN the base set MUST come from `"managers"` (appears first in `group_order`)
- AND `"medewerkers"` widgets MUST be unioned in (widening), excluding any `deniedWidgets`
- AND the resulting effective set MUST deterministically include all `"managers"` allowed
  widgets plus any additional `"medewerkers"` allowed widgets not already present

#### Scenario: User in multiple groups; deny-wins rule applies

- GIVEN `group_order = ["managers", "all-staff"]`
- AND the `"all-staff"` RoleFeaturePermission has `deniedWidgets: ["system_monitor"]`
- AND the `"managers"` RoleFeaturePermission has `allowedWidgets` including `"system_monitor"`
- AND user "priya.ganpat" belongs to both `"managers"` and `"all-staff"`
- WHEN priya.ganpat's effective widget set is resolved
- THEN `"system_monitor"` MUST NOT appear in the effective allowed set
  (deny from any matched group overrides any allow)
- AND `GET /api/widgets` MUST NOT return `"system_monitor"` for priya.ganpat

#### Scenario: Active role changes mid-session; dashboard re-renders with new permissions

- GIVEN user "sem.de.jong" is currently resolved to group `"medewerkers"` permissions
- WHEN an IT admin adds sem.de.jong to group `"managers"` (which has higher priority in
  `group_order`)
- AND sem.de.jong triggers a session refresh (page reload)
- THEN `GET /api/widgets` MUST return the `"managers"` effective widget set
- AND the dashboard MUST re-render showing the updated card library for sem's new primary role

---

### REQ-RFP-006: Role, interest, and journey distinction

The system MUST distinguish between role-based access (Nextcloud group membership), expressed
personal interest (user adds a widget they are permitted to add), and journey stage (the user's
active primary group changes during a session). Access MUST be governed by role membership;
interest MAY widen the visible card library within role limits; journey changes MUST take
effect within one session refresh.

#### Scenario: User assigned a role sees only widgets permitted for that role

- GIVEN user "henk.bakker" belongs to group `"medewerkers"` and no other role-priority group
- WHEN henk opens MyDash
- THEN `GET /api/widgets` MUST return only widgets in the `"medewerkers"` effective set
- AND no interest expression (bookmarked widget, previous session) MUST expand this set beyond
  what the role permits

#### Scenario: User expresses interest without a matching role — access denied, audit logged

- GIVEN a widget `"analytics_dashboard"` is in the `"managers"` allowed set
  but NOT in the `"medewerkers"` allowed set
- AND user "jan.de.vries" belongs only to `"medewerkers"` and expresses interest in
  `"analytics_dashboard"` (e.g. via a saved preference or direct URL request)
- WHEN jan.de.vries sends a request for `"analytics_dashboard"` content
- THEN the system MUST return HTTP 403
- AND an audit entry MUST be written with user ID, widget ID, access attempt timestamp,
  and the reason `"interest_without_role"`
- AND the widget MUST NOT be rendered or returned

#### Scenario: Active role changes; dashboard reflects new role's permissions after refresh

- GIVEN user "noor.yilmaz" is mid-session with primary group `"medewerkers"` resolved
- WHEN noor.yilmaz's Nextcloud group membership is updated to add `"managers"`
  (which has higher priority in `group_order`)
- AND noor.yilmaz triggers one session refresh
- THEN `GET /api/widgets` MUST return the `"managers"` effective widget set on the next call
- AND the effective widget set from before the refresh (medewerkers) MUST no longer apply

---

### REQ-RFP-007: Admin CRUD for role-feature-permissions

IT admins MUST be able to create, read, update, and delete RoleFeaturePermission objects via
admin-only API endpoints. Non-admin users MUST receive HTTP 403 on all mutation attempts.

#### Scenario: Admin creates a role-feature-permission

- GIVEN a Nextcloud admin user
- WHEN they send `POST /api/role-feature-permissions` with body:
  ```json
  {
    "groupId": "communicatie",
    "name": "Communicatie widget-rechten",
    "allowedWidgets": ["activity", "notes", "calendar"],
    "deniedWidgets": [],
    "priorityWeights": { "calendar": 10, "activity": 8 }
  }
  ```
- THEN the system MUST create a RoleFeaturePermission object in OpenRegister
- AND the response MUST return HTTP 201 with the created object including its OpenRegister ID
- AND the object MUST be retrievable via `GET /api/role-feature-permissions`

#### Scenario: Non-admin user cannot create or modify role-feature-permissions

- GIVEN user "jan.de.vries" is not a Nextcloud administrator
- WHEN jan sends `POST /api/role-feature-permissions` with any body
- THEN the system MUST return HTTP 403
- AND no object MUST be created

#### Scenario: Admin lists all role-feature-permissions

- GIVEN three RoleFeaturePermission objects exist for groups `"medewerkers"`, `"managers"`,
  `"hrm"`
- WHEN a Nextcloud admin sends `GET /api/role-feature-permissions`
- THEN the response MUST return HTTP 200 with all three objects
- AND each object MUST include `groupId`, `allowedWidgets`, `deniedWidgets`, `priorityWeights`

---

### REQ-RFP-008: Admin CRUD for role-layout-defaults

IT admins MUST be able to create, read, update, and delete RoleLayoutDefault objects. Non-admin
users MUST receive HTTP 403 on all mutation attempts.

#### Scenario: Admin creates a role-layout-default

- GIVEN a Nextcloud admin user
- WHEN they send `POST /api/role-layout-defaults` with body:
  ```json
  {
    "groupId": "managers",
    "widgetId": "user_status",
    "name": "Manager — gebruikersstatus",
    "gridX": 0, "gridY": 7, "gridWidth": 4, "gridHeight": 4,
    "sortOrder": 3, "isCompulsory": false
  }
  ```
- THEN the system MUST create a RoleLayoutDefault object in OpenRegister
- AND the response MUST return HTTP 201
- AND new users joining group `"managers"` after this point MUST receive `"user_status"` in
  their seeded layout

#### Scenario: Non-admin user cannot manage role-layout-defaults

- GIVEN user "sem.de.jong" is not a Nextcloud administrator
- WHEN sem sends `POST /api/role-layout-defaults` with any body
- THEN the system MUST return HTTP 403

---

### REQ-RFP-009: Unconfigured installations retain full backwards-compatibility

When no RoleFeaturePermission objects exist in OpenRegister, `GET /api/widgets` MUST return
the full widget list unchanged. No change in behaviour for existing MyDash installations that
have not configured role-feature-permissions.

#### Scenario: No permissions configured — all widgets visible

- GIVEN no RoleFeaturePermission objects exist for any group
- WHEN any authenticated user sends `GET /api/widgets`
- THEN the system MUST return HTTP 200 with the full list of all registered Nextcloud widgets
  (equivalent to the pre-change behaviour of REQ-WDG-001)
- AND no filtering MUST be applied

#### Scenario: Permissions exist for some groups but not the user's group

- GIVEN a RoleFeaturePermission exists for group `"managers"` but NOT for group `"medewerkers"`
- AND user "jan.de.vries" belongs only to group `"medewerkers"`
- AND no `"default"` RoleFeaturePermission exists
- WHEN jan.de.vries sends `GET /api/widgets`
- THEN the system MUST return the full unfiltered widget list
  (fall-through to unconfigured behaviour when no matching group is found)

---

### REQ-RFP-010: Initial-state widget allowlist

The backend MUST include the resolved `allowedWidgets` list (or `null` for unconfigured) in
the initial-state payload delivered to the frontend, so that the card library can filter
without a separate API call.

#### Scenario: Initial state includes allowedWidgets for role-configured user

- GIVEN user "noor.yilmaz" has group `"managers"` with `allowedWidgets: ["activity", "analytics_dashboard"]`
- WHEN the MyDash page loads and `GET /api/settings` or the initial-state endpoint is called
- THEN the response payload MUST include `"allowedWidgets": ["activity", "analytics_dashboard"]`
- AND the frontend card library MUST use this list to filter without an additional API call

#### Scenario: Initial state includes null for unconfigured installation

- GIVEN no RoleFeaturePermission objects exist
- WHEN any user loads MyDash
- THEN the initial-state payload MUST include `"allowedWidgets": null`
- AND the frontend MUST treat `null` as "no restriction" (show all widgets)

---

## Non-Functional Requirements

- **Security**: All permission checks MUST be enforced server-side in `RoleFeaturePermissionService`.
  Frontend `allowedWidgets` filtering is a UX convenience only; the API layer is the
  authoritative gate (REQ-RFP-001, REQ-RFP-003).
- **Audit**: Every 403 issued for a role-permission denial MUST produce an audit entry via
  `AuditTrailService` (OpenRegister), recording user ID (from `IUserSession::getUser()->getUID()`),
  widget ID, timestamp, and denial reason. NEVER log display names (ADR-005).
- **Performance**: `getAllowedWidgetIds()` MUST complete within 20 ms on a warm server (at most
  two OpenRegister object list queries + one `IGroupManager::getUserGroupIds` call).
- **Backwards-compatibility**: All new endpoints and the `GET /api/widgets` filter MUST be
  additive and non-breaking. Unconfigured installations behave identically to before (REQ-RFP-009).
- **Accessibility (WCAG AA)**: Filtered-out card library items MUST be absent from the DOM
  entirely — not just hidden via CSS — to prevent exposure via DevTools or assistive technology.
- **i18n**: All user-facing strings (error messages, admin UI labels) MUST use `t(appName, 'key')`
  with English keys and Dutch translations in `l10n/nl.json` (ADR-007).
