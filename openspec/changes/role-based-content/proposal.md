# Role-based dashboard content and defaults

## Why

MyDash currently provides no mechanism to restrict which widgets and dashboard features are
visible based on a user's Nextcloud group (role). All authenticated users see the same card
library and can choose from the same widget catalogue regardless of their job function. IT
admins cannot enforce governance at the feature level: an employee-role user can add
manager-only analytics widgets, there is no evidence-seeded default layout for new users, and
no audit entry is created when an unauthorised user attempts to access a restricted feature URL
directly.

The existing `admin-templates` capability (REQ-TMPL-001..011) lets admins distribute a
pre-configured dashboard to a group, but it does not restrict which widgets a user can
subsequently add from the card library, does not define priority ordering of widgets per role,
and does not distinguish between role-based access rights, expressed personal interests, and
journey stage. When a user belongs to multiple groups, there is no deterministic rule that
governs which role's feature set takes precedence.

This change introduces a dedicated **role-feature-permissions** capability that closes all
three gaps: it governs which widgets are shown (or hidden) by role, seeds default layouts from
role evidence, and enforces access at the API layer so that direct URL requests to restricted
features return 403 rather than silently rendering.

## What Changes

- Introduce OpenRegister schema **RoleFeaturePermission** — maps a Nextcloud group ID to an
  allowed-widget list, an explicit deny list, and per-widget priority weights.
- Introduce OpenRegister schema **RoleLayoutDefault** — captures the default grid position,
  size, and display order for each permitted widget within a group, serving as the seed layout
  when no admin template matches a new user.
- Add `RoleFeaturePermissionService` (PHP, stateless) to resolve the effective allowed-widget
  set for a user by walking their group memberships in `group_order` priority and merging
  allow/deny lists.
- Extend `GET /api/widgets` to call `RoleFeaturePermissionService::getAllowedWidgetIds()` and
  filter the returned card library; unauthenticated or unconfigured cases return the full list
  unchanged (backwards-compatible).
- Add admin-only endpoints `GET /api/role-feature-permissions` and
  `POST /api/role-feature-permissions` for CRUD on RoleFeaturePermission objects.
- Add admin-only endpoints `GET /api/role-layout-defaults` and
  `POST /api/role-layout-defaults` for CRUD on RoleLayoutDefault objects.
- Extend `DashboardResolver::tryCreateFromTemplate()` to fall back to seeding a layout from
  `RoleLayoutDefault` objects when no admin template matches a new user, preserving any
  existing personal customisations.
- Add 403 enforcement in `WidgetController::getItems()` and any feature-specific controller
  that serves a widget's URL: if the requesting user's role-feature-permissions do not include
  the widget, return HTTP 403 and log an audit entry.
- Surface `allowedWidgets` (the effective permitted-widget ID list for the authenticated user)
  in the initial-state payload so the frontend card library can filter without a second
  round-trip.
- Add an admin section in `AdminApp.vue` for configuring role→widget mappings and priority
  weights, using `CnDashboardPage` / `CnDataTable` components from `@conduction/nextcloud-vue`.

## Capabilities

### New Capabilities

- **role-feature-permissions**: Governs which dashboard widgets and features are visible,
  accessible, and default-seeded for users based on their Nextcloud group (role). Covers:
  - Admin CRUD for RoleFeaturePermission objects (group → allowed/denied widget IDs +
    priority weights)
  - Admin CRUD for RoleLayoutDefault objects (group → default grid position per widget)
  - Runtime resolution of effective widget permissions for multi-group users via
    `group_order` priority
  - Card library filtering at `GET /api/widgets`
  - Role-based default layout seeding when no admin template matches
  - API-level 403 enforcement with audit logging for direct URL access to restricted features
  - Role vs. interest vs. journey distinction (role = explicit group membership; interest =
    user-expressed preference without a matching role; journey = transient active-role state
    mid-session)

### Modified Capabilities

- **widgets** — `GET /api/widgets` filters the card library by the caller's role-feature-
  permissions when a RoleFeaturePermission object exists for any of the user's groups.
  Existing behaviour (return all widgets) is preserved when no permissions are configured.
- **dashboards** — `tryCreateFromTemplate()` gains a role-layout-default fallback: new users
  whose groups have a RoleLayoutDefault receive a pre-seeded layout instead of the hardcoded
  `recommendations` + `activity` pair. Existing personal customisations are never overwritten.

## Impact

**Code affected:**
- `lib/Service/RoleFeaturePermissionService.php` — new service: resolves allowed widget IDs
  for a user from their groups, merges allow/deny lists, respects `group_order` priority.
- `lib/Controller/RoleFeaturePermissionController.php` — admin-only CRUD controller for
  RoleFeaturePermission and RoleLayoutDefault objects.
- `lib/Controller/WidgetController.php` — extend `list()` to filter by allowed widgets.
- `lib/Service/DashboardResolver.php` — extend `tryCreateFromTemplate()` with role-layout-
  default fallback.
- `src/store/modules/roleFeaturePermission.js` — Pinia store via `createObjectStore`.
- `src/views/AdminApp.vue` — new role-permissions section.
- `lib/Settings/mydash_register.json` — two new schemas + seed data objects.
- `appinfo/routes.php` — four new admin API routes.

**APIs:**
- `GET  /api/widgets` — now filtered by role (non-breaking; unchanged when unconfigured)
- `GET  /api/role-feature-permissions` (admin-only)
- `POST /api/role-feature-permissions` (admin-only)
- `GET  /api/role-layout-defaults` (admin-only)
- `POST /api/role-layout-defaults` (admin-only)

**Data:**
- Two new OpenRegister schemas registered via `mydash_register.json`, imported through
  `ConfigurationService::importFromApp()` in the repair step. Idempotent — re-importing with
  `force: false` skips existing objects matched by slug.
- No migration needed for existing DB tables.

**Dependencies:**
- `AuthorizationService` (OpenRegister) — object-level RBAC checks on admin endpoints.
- `AdminSettingsService` — reads `group_order` (from `group-priority-order` change) for
  multi-group priority resolution.
- `IGroupManager` — reads user group memberships.

**Migration:**
- Zero schema migration for existing DB tables.
- Existing users are unaffected until an admin creates at least one RoleFeaturePermission
  object; the empty-config path returns the full widget list (REQ-WDG-001 unchanged).
- New users seeded via role-layout-defaults only when an admin configures RoleLayoutDefault
  objects for their group.
