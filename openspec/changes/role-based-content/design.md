# Design — Role-based dashboard content and defaults

## Architecture

### Backend

```
RoleFeaturePermissionController   (admin-only, thin: routing + validation + response)
        │
        ▼
RoleFeaturePermissionService      (all business logic, stateless)
        │
        ├── ObjectService          (OpenRegister CRUD — findObjects, saveObject, deleteObject)
        ├── AdminSettingsService   (reads group_order for priority resolution)
        └── IGroupManager          (reads user group memberships from Nextcloud)
```

**Service responsibilities:**
- `getAllowedWidgetIds(string $userId): array` — resolve effective widget permission set by
  iterating `group_order`, fetching the first RoleFeaturePermission whose `groupId` matches a
  user's group, then merging any additional group allow-lists for widening. Returns `null`
  (= no restriction) when no RoleFeaturePermission objects exist for any of the user's groups.
- `isWidgetAllowed(string $userId, string $widgetId): bool` — convenience wrapper used by
  `WidgetController` and feature-specific controllers for single-widget checks.
- `seedLayoutFromRoleDefaults(string $userId, Dashboard $dashboard): void` — called by
  `DashboardResolver::tryCreateFromTemplate()` when no admin template matches; reads
  RoleLayoutDefault objects for the user's primary group (via `group_order`) and creates
  WidgetPlacement rows accordingly.
- `authorizeAdminObject(array $object, IUser $user): void` — throws
  `OCSForbiddenException` if the authenticated user is not an admin; used from every
  mutation endpoint (ADR-005).

**Controller pattern (ADR-003):**
- `RoleFeaturePermissionController` handles all four new admin endpoints.
- Methods: `listPermissions()`, `savePermission()`, `listLayoutDefaults()`,
  `saveLayoutDefault()`.
- All methods annotated `#[AuthorizedAdminSetting(Application::APP_ID)]`.
- No direct mapper calls; all data access through `RoleFeaturePermissionService`.

### Frontend

```
AdminApp.vue
  └── RolePermissionsSection.vue    (new component — role→widget assignment UI)
        ├── CnDataTable              (list existing RoleFeaturePermission objects)
        ├── CnFormDialog             (create / edit a RoleFeaturePermission)
        └── CnDeleteDialog           (delete confirmation)

src/store/modules/roleFeaturePermission.js   (createObjectStore('role-feature-permission'))
src/store/modules/roleLayoutDefault.js       (createObjectStore('role-layout-default'))
```

`App.vue` initial-state payload extended with `allowedWidgets: string[]|null`; null means
unconfigured (all widgets visible). The card library component (`WidgetPicker.vue`) reads
`allowedWidgets` from the settings store and filters the list before rendering.

---

## Data Model

> All domain data stored in OpenRegister objects. Schemas defined in
> `lib/Settings/mydash_register.json`, imported via `ConfigurationService::importFromApp()`.
> Schema vocabulary follows schema.org + ADR-011.

### Schema: RoleFeaturePermission

**Register key:** `mydash`
**Schema key:** `role-feature-permission`
**schema.org type:** `schema:DefinedTerm` (a term within a classification scheme, here:
the set of permitted features for a role)

| Property | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Human-readable name, e.g. "Medewerker widget-rechten" (`schema:name`) |
| `description` | string | no | Purpose of this permission set (`schema:description`) |
| `groupId` | string | yes | Nextcloud group ID this permission applies to (`schema:identifier`) |
| `allowedWidgets` | array\<string\> | yes | Widget IDs the group may add to their dashboard. Empty array = no widgets allowed. (`schema:itemListElement`) |
| `deniedWidgets` | array\<string\> | no | Explicit deny list; overrides `allowedWidgets` when widening from multiple groups. Default `[]`. |
| `priorityWeights` | object | no | Map of `{ widgetId: integer }` — higher value = higher priority position in layout seeding and dashboard ordering. (`schema:position`) |

**Uniqueness constraint:** one RoleFeaturePermission per `groupId`. The import slug convention
enforces this: slug = `rfp-{groupId}`.

---

### Schema: RoleLayoutDefault

**Register key:** `mydash`
**Schema key:** `role-layout-default`
**schema.org type:** `schema:ListItem` (an item in an ordered list, here: a widget slot in a
role's default layout)

| Property | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Display name for this layout slot, e.g. "Manager — activiteiten" (`schema:name`) |
| `groupId` | string | yes | Nextcloud group ID this default applies to (`schema:identifier`) |
| `widgetId` | string | yes | Nextcloud Dashboard widget ID to place (`schema:identifier`) |
| `gridX` | integer | yes | Column position (0-based) (`schema:position`) |
| `gridY` | integer | yes | Row position (0-based) (`schema:position`) |
| `gridWidth` | integer | yes | Widget width in grid columns (min 1, default 4) |
| `gridHeight` | integer | yes | Widget height in grid rows (min 1, default 4) |
| `sortOrder` | integer | yes | Ordering within the layout (lower = rendered first, default 0) |
| `isCompulsory` | boolean | no | If true, user cannot remove this widget (inherited from template logic). Default `false`. |
| `description` | string | no | Optional notes about why this widget appears at this position (`schema:description`) |

**Slug convention:** `rld-{groupId}-{widgetId}` (one row per group+widget combination).

---

## Seed Data

> 3–5 realistic objects per schema with Dutch field values. Loaded via `@self` envelope
> through `ConfigurationService::importFromApp()`. Idempotent — re-import matches by slug.

### RoleFeaturePermission seed objects

```json
[
  {
    "@self": {
      "register": "mydash",
      "schema": "role-feature-permission",
      "slug": "rfp-medewerkers"
    },
    "name": "Medewerker widget-rechten",
    "description": "Standaard widget-toegang voor alle medewerkers van de gemeente",
    "groupId": "medewerkers",
    "allowedWidgets": ["activity", "recommendations", "notes", "calendar"],
    "deniedWidgets": [],
    "priorityWeights": { "activity": 10, "recommendations": 8, "calendar": 6, "notes": 4 }
  },
  {
    "@self": {
      "register": "mydash",
      "schema": "role-feature-permission",
      "slug": "rfp-managers"
    },
    "name": "Manager widget-rechten",
    "description": "Uitgebreide widget-toegang voor teamleiders en afdelingshoofden",
    "groupId": "managers",
    "allowedWidgets": ["activity", "recommendations", "notes", "calendar", "analytics_dashboard", "user_status"],
    "deniedWidgets": [],
    "priorityWeights": { "analytics_dashboard": 10, "activity": 8, "user_status": 6, "calendar": 5 }
  },
  {
    "@self": {
      "register": "mydash",
      "schema": "role-feature-permission",
      "slug": "rfp-ict-beheer"
    },
    "name": "ICT-beheer widget-rechten",
    "description": "Widget-toegang voor de ICT-beheerdersgroep met systeemoverzicht",
    "groupId": "ict-beheer",
    "allowedWidgets": ["activity", "recommendations", "notes", "calendar", "system_monitor", "user_status"],
    "deniedWidgets": [],
    "priorityWeights": { "system_monitor": 10, "activity": 8, "user_status": 7 }
  },
  {
    "@self": {
      "register": "mydash",
      "schema": "role-feature-permission",
      "slug": "rfp-hrm"
    },
    "name": "HRM widget-rechten",
    "description": "Widget-toegang voor de afdeling Human Resource Management",
    "groupId": "hrm",
    "allowedWidgets": ["activity", "recommendations", "notes", "calendar", "user_status"],
    "deniedWidgets": ["system_monitor"],
    "priorityWeights": { "user_status": 10, "calendar": 9, "activity": 7 }
  },
  {
    "@self": {
      "register": "mydash",
      "schema": "role-feature-permission",
      "slug": "rfp-default"
    },
    "name": "Standaard widget-rechten",
    "description": "Basistoegang voor gebruikers zonder specifieke groepsindeling",
    "groupId": "default",
    "allowedWidgets": ["activity", "recommendations"],
    "deniedWidgets": [],
    "priorityWeights": { "recommendations": 10, "activity": 8 }
  }
]
```

### RoleLayoutDefault seed objects

```json
[
  {
    "@self": {
      "register": "mydash",
      "schema": "role-layout-default",
      "slug": "rld-medewerkers-activity"
    },
    "name": "Medewerker — activiteiten",
    "groupId": "medewerkers",
    "widgetId": "activity",
    "gridX": 0, "gridY": 0, "gridWidth": 6, "gridHeight": 5,
    "sortOrder": 1, "isCompulsory": false,
    "description": "Activiteitenoverzicht linksboven voor medewerkers"
  },
  {
    "@self": {
      "register": "mydash",
      "schema": "role-layout-default",
      "slug": "rld-medewerkers-recommendations"
    },
    "name": "Medewerker — aanbevelingen",
    "groupId": "medewerkers",
    "widgetId": "recommendations",
    "gridX": 6, "gridY": 0, "gridWidth": 6, "gridHeight": 5,
    "sortOrder": 2, "isCompulsory": false,
    "description": "Persoonlijke aanbevelingen rechtsboven voor medewerkers"
  },
  {
    "@self": {
      "register": "mydash",
      "schema": "role-layout-default",
      "slug": "rld-managers-analytics"
    },
    "name": "Manager — analysedashboard",
    "groupId": "managers",
    "widgetId": "analytics_dashboard",
    "gridX": 0, "gridY": 0, "gridWidth": 8, "gridHeight": 6,
    "sortOrder": 1, "isCompulsory": true,
    "description": "Verplicht analysedashboard als eerste widget voor managers"
  },
  {
    "@self": {
      "register": "mydash",
      "schema": "role-layout-default",
      "slug": "rld-managers-activity"
    },
    "name": "Manager — activiteiten",
    "groupId": "managers",
    "widgetId": "activity",
    "gridX": 8, "gridY": 0, "gridWidth": 4, "gridHeight": 6,
    "sortOrder": 2, "isCompulsory": false,
    "description": "Teamactiviteiten rechtsbovenhoek voor managers"
  },
  {
    "@self": {
      "register": "mydash",
      "schema": "role-layout-default",
      "slug": "rld-ict-beheer-system-monitor"
    },
    "name": "ICT-beheer — systeemmonitor",
    "groupId": "ict-beheer",
    "widgetId": "system_monitor",
    "gridX": 0, "gridY": 0, "gridWidth": 12, "gridHeight": 4,
    "sortOrder": 1, "isCompulsory": true,
    "description": "Verplicht systeemoverzicht als prominente balk voor ICT-beheer"
  }
]
```

---

## Reuse Analysis

This change delegates all commodity functionality to existing platform services and avoids
rebuilding anything the platform provides for free.

| Concern | Platform service / component used | Custom code needed? |
|---|---|---|
| OpenRegister CRUD for schemas | `ObjectService.saveObject()`, `findObjects()`, `deleteObject()` | No — only the service wrapper calling these |
| Admin list UI for permissions | `CnDataTable` + `CnFormDialog` from `@conduction/nextcloud-vue` | Only the Vue section component |
| Object-level auth checks | `AuthorizationService` (OpenRegister) | `authorizeAdminObject()` thin wrapper |
| Group membership lookup | `IGroupManager::getUserGroupIds()` | No — read-only call |
| Group priority order | `AdminSettingsService::getGroupOrder()` (from `group-priority-order` change) | No — read-only call |
| Audit trail on permission changes | `AuditTrailService` (OpenRegister, automatic) | None |
| Store state management | `createObjectStore('role-feature-permission')` (Pinia plugin) | Only the store registration |
| Schema-driven form generation | `CnFormDialog` reads OpenRegister schema → auto-generates fields | None |
| Import / seed data | `ConfigurationService::importFromApp()` via repair step | Schema JSON only |

**Deduplication check — existing OpenRegister capabilities NOT duplicated:**
- `AuthorizationService` and `PropertyRbacHandler` (OpenRegister) already provide general
  RBAC; this change adds *widget-scoped* role filtering on top, which is domain-specific
  logic not present in the platform.
- `PermissionService` (existing mydash change `permissions`) controls per-dashboard edit
  permissions (view_only / add_only / full). This change controls *which widgets can be added*,
  which is orthogonal — both checks run independently.
- `AdminTemplateService` (existing `admin-templates`) distributes full dashboard copies.
  This change governs which widgets appear in the card library, not which templates are
  distributed. Both can apply simultaneously.
- No overlap with `ObjectService`, `RegisterService`, `SchemaService`, or any
  `@conduction/nextcloud-vue` component was found.

---

## Multi-group Resolution Algorithm

When a user belongs to multiple Nextcloud groups and more than one RoleFeaturePermission
exists:

1. Read `group_order` from `AdminSettingsService::getGroupOrder()` (admin-configured priority
   list).
2. Walk `group_order` left-to-right; for the **first** group the user belongs to, take that
   group's `allowedWidgets` as the base set.
3. For every additional group the user belongs to (in `group_order` order), **union** the
   `allowedWidgets` into the base set (widens access), then **subtract** any `deniedWidgets`
   from all matched groups (deny always wins).
4. If no `group_order` entry matches any of the user's groups, fall back to the `'default'`
   RoleFeaturePermission (sentinel group ID = `'default'`, per `Dashboard::DEFAULT_GROUP_ID`
   from `group-routing` change).
5. If no `'default'` RoleFeaturePermission exists either, return `null` (= no restriction,
   full widget list visible). This preserves backwards-compatibility for unconfigured
   installations.

**Deny-wins rule:** if `widgetId` appears in any matched group's `deniedWidgets`, it is
removed from the effective set regardless of how many groups allow it. This prevents
privilege widening through multi-group membership from bypassing an explicit deny.
