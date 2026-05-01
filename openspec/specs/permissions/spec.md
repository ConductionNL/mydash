---
status: implemented
---

# Permission Levels Specification

## Purpose

Permission levels control what users can do with their dashboards. When an admin template is distributed to users, the template's permission level is inherited by the user's personal copy, restricting their editing capabilities. This system allows administrators to create locked-down dashboards (e.g., a company-mandated layout with compulsory widgets) while still giving users varying degrees of customization freedom. The three levels -- `view_only`, `add_only`, and `full` -- form a hierarchy of increasing user control.

## Permission Level Definitions

| Level | Can view | Can add widgets | Can edit widget settings | Can move/resize | Can remove non-compulsory | Can remove compulsory |
|-------|----------|-----------------|--------------------------|-----------------|---------------------------|----------------------|
| `view_only` | Yes | No | No | No | No | No |
| `add_only` | Yes | Yes | Yes | Yes | Yes (non-compulsory) | No |
| `full` | Yes | Yes | Yes | Yes | Yes | Yes |

## Requirements

### REQ-PERM-001: View-Only Permission Level

Dashboards with `permissionLevel: "view_only"` MUST restrict users to viewing only, with no widget or layout editing capabilities.

#### Scenario: View-only user sees the dashboard
- GIVEN user "alice" has a dashboard with effective `permissionLevel: "view_only"` and 5 widget placements
- WHEN she views the dashboard
- THEN all 5 widgets MUST be rendered with their content
- AND the grid MUST be in view mode
- AND there MUST be no "Edit" button visible

#### Scenario: View-only user cannot add widgets
- GIVEN user "alice" has a view-only dashboard id 5
- WHEN she sends POST /api/dashboard/5/widgets with widget data
- THEN the system MUST return HTTP 403 with a message indicating the dashboard is view-only
- AND no widget placement MUST be created
- AND `PermissionService::canAddWidget()` MUST return false for view_only

#### Scenario: View-only user cannot modify widgets
- GIVEN user "alice" has a view-only dashboard with widget placement id 10
- WHEN she sends PUT /api/widgets/10 with body `{"custom_title": "New Title"}`
- THEN the system MUST return HTTP 403
- AND the widget placement MUST NOT be modified
- AND `PermissionService::canStyleWidget()` MUST return false for view_only

#### Scenario: View-only user cannot delete widgets
- GIVEN user "alice" has a view-only dashboard with widget placement id 10
- WHEN she sends DELETE /api/widgets/10
- THEN the system MUST return HTTP 403
- AND the widget placement MUST NOT be deleted
- AND `PermissionService::canRemoveWidget()` MUST return false for view_only

#### Scenario: View-only user cannot add tiles
- GIVEN user "alice" has a view-only dashboard id 5
- WHEN she sends POST /api/dashboard/5/tile with tile data
- THEN the system MUST return HTTP 403
- AND `canAddWidget()` MUST block tile additions the same as widget additions

#### Scenario: View-only dashboard hides all editing UI
- GIVEN user "alice" has a view-only dashboard
- WHEN she views the dashboard
- THEN the following UI elements MUST NOT be displayed:
  - Edit/Done toggle button
  - Add widget button
  - Add tile button
  - Widget context menus (edit, delete, configure)
  - Grid drag handles and resize handles
- NOTE: Frontend permission-based UI hiding is NOT currently implemented.

### REQ-PERM-002: Add-Only Permission Level

Dashboards with `permissionLevel: "add_only"` MUST allow users to add and modify widgets but prevent removal of compulsory widgets.

#### Scenario: Add-only user can add widgets
- GIVEN user "alice" has a dashboard with `permissionLevel: "add_only"`
- WHEN she sends POST /api/dashboard/5/widgets with widget data
- THEN the system MUST create the widget placement
- AND the response MUST return HTTP 201

#### Scenario: Add-only user can edit widget settings
- GIVEN user "alice" has an add-only dashboard with widget placement id 10
- WHEN she sends PUT /api/widgets/10 with body `{"customTitle": "My Weather"}`
- THEN the system MUST update the widget placement
- AND the response MUST return HTTP 200

#### Scenario: Add-only user can move and resize widgets
- GIVEN user "alice" has an add-only dashboard in edit mode
- WHEN she drags widget placement id 10 to a new position
- THEN the grid MUST allow the move
- AND the new position MUST be persisted via the API

#### Scenario: Add-only user can remove non-compulsory widgets
- GIVEN user "alice" has an add-only dashboard with widget placement id 10 (`isCompulsory: 0`)
- WHEN she sends DELETE /api/widgets/10
- THEN the system MUST delete the placement
- AND the response MUST return HTTP 200

#### Scenario: Add-only user cannot remove compulsory widgets
- GIVEN user "alice" has an add-only dashboard with widget placement id 11 (`isCompulsory: 1`)
- WHEN she sends DELETE /api/widgets/11
- THEN the system MUST return HTTP 403 with a message indicating compulsory widgets cannot be removed at this permission level
- AND `canRemoveWidget()` checks `placement->getIsCompulsory()` and returns false

#### Scenario: Add-only user cannot remove compulsory widgets via UI
- GIVEN user "alice" has an add-only dashboard in edit mode
- AND widget placement id 11 is compulsory
- WHEN she views the widget's context menu or actions
- THEN the "Remove" or "Delete" option MUST NOT be available for compulsory widgets
- AND a lock icon or "Required" badge SHOULD be displayed on compulsory widgets
- NOTE: Compulsory visual indicator is NOT currently implemented.

#### Scenario: Add-only user can add conditional rules
- GIVEN user "alice" has an add-only dashboard with widget placement id 10 (`isCompulsory: 0`)
- WHEN she sends POST /api/widgets/10/rules with a conditional rule
- THEN the system MUST create the rule
- AND the response MUST return HTTP 201

### REQ-PERM-003: Full Permission Level

Dashboards with `permissionLevel: "full"` MUST allow users complete control over all aspects of the dashboard.

#### Scenario: Full permission user can remove compulsory widgets
- GIVEN user "alice" has a full-permission dashboard with widget placement id 11 (`isCompulsory: 1`)
- WHEN she sends DELETE /api/widgets/11
- THEN the system MUST delete the placement
- AND `canRemoveWidget()` returns true for full regardless of `isCompulsory`

#### Scenario: Full permission is the default for user-created dashboards
- GIVEN user "alice" creates a new dashboard via POST /api/dashboard
- WHEN the dashboard is created
- THEN `permissionLevel` MUST be set to "full" (via `DashboardFactory::create()` which hardcodes `Dashboard::PERMISSION_FULL`)
- AND the user MUST have unrestricted editing capabilities

#### Scenario: Full permission user sees all editing UI
- GIVEN user "alice" has a full-permission dashboard
- WHEN she views the dashboard
- THEN the Edit button MUST be visible
- AND all widget context menus MUST include all options (edit, delete, configure visibility)
- AND all widgets (including compulsory) MUST show delete options in edit mode

### REQ-PERM-004: Compulsory Widget Marking

Admin templates MUST be able to mark specific widget placements as compulsory, and this flag MUST be inherited by user copies.

#### Scenario: Compulsory flag inherited from template
- GIVEN an admin template has widget placement with `isCompulsory: 1` for widget "company_news"
- WHEN user "alice" receives a copy of this template
- THEN alice's copy of the "company_news" placement MUST have `isCompulsory: 1` (copied via `TemplateService::clonePlacement()`)
- AND the compulsory flag MUST persist on the user's copy

#### Scenario: Users cannot change the compulsory flag
- GIVEN widget placement id 10 with `isCompulsory: 1` on alice's dashboard
- WHEN she sends PUT /api/widgets/10 with body `{"isCompulsory": 0}`
- THEN the system MUST ignore the `isCompulsory` field in the update
- OR return HTTP 403 for that specific field
- AND `isCompulsory` MUST remain 1
- NOTE: `PlacementUpdater` does not explicitly block `isCompulsory` changes.

#### Scenario: Compulsory widget visual indicator
- GIVEN a dashboard with both compulsory and non-compulsory widgets
- WHEN the dashboard is rendered in edit mode
- THEN compulsory widgets MUST display a visual indicator (e.g., lock icon, "Required" badge)
- AND the indicator MUST be visible only in edit mode (not in view mode)
- NOTE: Not currently implemented. `WidgetWrapper.vue` has `canRemove` computed property but no visual badge.

#### Scenario: Non-compulsory widgets on template-derived dashboards
- GIVEN an admin template has 5 widgets: 3 compulsory and 2 non-compulsory
- AND user "alice" receives a copy with `permissionLevel: "add_only"`
- WHEN alice enters edit mode
- THEN she MUST be able to remove the 2 non-compulsory widgets
- AND she MUST NOT be able to remove the 3 compulsory widgets

#### Scenario: User-added widgets are never compulsory
- GIVEN user "alice" has a dashboard with `permissionLevel: "add_only"`
- WHEN she adds a new widget via POST /api/dashboard/5/widgets
- THEN the new placement MUST have `isCompulsory: 0` (default via `PlacementService::addWidget()`)
- AND alice MUST be able to remove this widget even on an add-only dashboard

### REQ-PERM-005: Permission Level Immutability for Users

Users MUST NOT be able to change the permission level on their own dashboards.

#### Scenario: User tries to escalate permission level
- GIVEN user "alice" has a dashboard with `permissionLevel: "add_only"` (inherited from template)
- WHEN she sends PUT /api/dashboard/5 with body `{"permissionLevel": "full"}`
- THEN the system MUST ignore the `permissionLevel` field
- AND the permissionLevel MUST remain "add_only"
- NOTE: `DashboardService::applyDashboardUpdates()` only processes `name`, `description`, `gridColumns`, and `placements`. `permissionLevel` is not handled.

#### Scenario: User tries to downgrade permission level
- GIVEN user "alice" has a dashboard with `permissionLevel: "full"`
- WHEN she sends PUT /api/dashboard/5 with body `{"permissionLevel": "view_only"}`
- THEN the system MUST ignore the `permissionLevel` field
- AND the permissionLevel MUST remain "full"

### REQ-PERM-006: Permission Enforcement on API Level

Permission checks MUST be enforced at the API/service level, not just in the frontend UI.

#### Scenario: API rejects widget addition on view-only dashboard
- GIVEN dashboard id 5 has `permissionLevel: "view_only"`
- WHEN any HTTP client sends POST /api/dashboard/5/widgets
- THEN the system MUST return HTTP 403 regardless of how the request was made (UI, curl, API client)

#### Scenario: API rejects compulsory widget deletion on add-only dashboard
- GIVEN dashboard id 5 has `permissionLevel: "add_only"`
- AND widget placement id 11 has `isCompulsory: 1`
- WHEN any HTTP client sends DELETE /api/widgets/11
- THEN the system MUST return HTTP 403

#### Scenario: API allows all operations on full-permission dashboard
- GIVEN dashboard id 5 has `permissionLevel: "full"`
- WHEN any valid widget/tile operation is sent
- THEN the system MUST allow the operation (assuming proper ownership)

#### Scenario: Permission checks happen before service calls
- GIVEN a widget operation request
- WHEN the controller processes it
- THEN permission checks (`canAddWidget`, `canStyleWidget`, `canRemoveWidget`) MUST be called before any service method that modifies data
- AND rejected requests MUST NOT cause any state changes

### REQ-PERM-007: Dashboard Metadata Editing

Permission levels MUST NOT restrict editing of dashboard metadata (name, description) for users who own the dashboard.

#### Scenario: View-only user can edit dashboard name
- GIVEN user "alice" has a view-only dashboard id 5
- WHEN she sends PUT /api/dashboard/5 with body `{"name": "Renamed Dashboard"}`
- THEN the system MUST allow the update via `PermissionService::canEditDashboardMetadata()` which only checks ownership, not permission level
- AND the dashboard name MUST be updated

#### Scenario: View-only user can delete the dashboard
- GIVEN user "alice" has a view-only dashboard id 5
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST allow the deletion
- AND users always have the right to remove dashboards from their account regardless of permission level

#### Scenario: Metadata editing separate from widget editing
- GIVEN user "alice" has a view-only dashboard
- WHEN she tries to update the dashboard
- THEN `canEditDashboardMetadata()` (ownership only) MUST be used for name/description changes
- AND `canEditDashboard()` (ownership + permission level) MUST be used for widget/layout changes

### REQ-PERM-008: Effective Permission Level Resolution

The system MUST resolve effective permission levels through a defined chain: source template, dashboard's own level, admin default.

#### Scenario: Template-based permission resolution
- GIVEN user "alice" has a dashboard with `basedOnTemplate: 42`
- AND template 42 has `permissionLevel: "add_only"`
- WHEN `getEffectivePermissionLevel()` is called
- THEN the system MUST return "add_only" from the source template

#### Scenario: Template deleted, fallback to dashboard level
- GIVEN user "alice" has a dashboard with `basedOnTemplate: 42` and `permissionLevel: "add_only"`
- AND template 42 has been deleted
- WHEN `getEffectivePermissionLevel()` is called
- THEN the template lookup MUST throw `DoesNotExistException`
- AND the system MUST fall back to the dashboard's own `permissionLevel: "add_only"`

#### Scenario: No template, no dashboard level, fallback to admin default
- GIVEN a dashboard with `basedOnTemplate: null` and `permissionLevel: null`
- AND the admin default is "add_only"
- WHEN `getEffectivePermissionLevel()` is called
- THEN the system MUST return the admin default "add_only"

#### Scenario: Admin template changes propagate dynamically
- GIVEN template 42 has `permissionLevel: "add_only"`
- AND 10 users have copies with `basedOnTemplate: 42`
- WHEN the admin changes template 42's `permissionLevel` to "full"
- THEN all 10 users' effective permission level MUST immediately resolve to "full"
- AND no migration or re-copying is needed (resolution is dynamic at runtime)

### REQ-PERM-009: Permission-Based Widget Styling

Widget styling (custom title, style config, visibility) MUST respect permission levels via `canStyleWidget()`.

#### Scenario: View-only user cannot style widgets
- GIVEN user "alice" has a view-only dashboard with widget placement id 10
- WHEN she sends PUT /api/widgets/10 with any style changes
- THEN the system MUST return HTTP 403
- AND `canStyleWidget()` MUST return false for view_only

#### Scenario: Add-only user can style widgets
- GIVEN user "alice" has an add-only dashboard with widget placement id 10
- WHEN she sends PUT /api/widgets/10 with body `{"customTitle": "My Widget", "showTitle": 0}`
- THEN the system MUST allow the update
- AND `canStyleWidget()` MUST return true for add_only

#### Scenario: Full user can style widgets
- GIVEN user "alice" has a full-permission dashboard with widget placement id 10
- WHEN she sends PUT /api/widgets/10 with style changes
- THEN the system MUST allow the update

### REQ-PERM-010: Ownership Verification

All permission checks MUST verify that the requesting user owns the dashboard or placement before checking permission levels.

#### Scenario: Non-owner cannot modify widgets regardless of permission
- GIVEN user "alice" has a full-permission dashboard with placement id 10
- WHEN user "bob" sends any operation on placement 10
- THEN the system MUST return HTTP 403
- AND the ownership check MUST happen before the permission level check

#### Scenario: Dashboard ownership check
- GIVEN user "alice" owns dashboard id 5
- WHEN `PermissionService::verifyDashboardOwnership("bob", 5)` is called
- THEN the method MUST throw an exception with "Access denied"
- AND no permission level evaluation MUST occur

#### Scenario: Placement ownership check via dashboard
- GIVEN placement id 10 belongs to dashboard id 5 owned by "alice"
- WHEN `PermissionService::verifyPlacementOwnership("bob", 10)` is called
- THEN the method MUST look up the placement, find its dashboardId, verify dashboard ownership
- AND throw "Access denied" since "bob" does not own dashboard 5

### REQ-PERM-011: Admin Template Permission Restrictions

Admin templates MUST only be editable by Nextcloud admin users, not by regular users.

#### Scenario: Regular user cannot edit admin template dashboard
- GIVEN template id 1 has `type: "admin_template"`
- WHEN `canEditDashboard("alice", 1)` is called for a regular user
- THEN the method MUST return false (admin templates are blocked regardless of ownership)

#### Scenario: Admin templates have no userId
- GIVEN template id 1 has `userId: null`
- WHEN a regular user tries any operation on template 1
- THEN the ownership check MUST fail since `null !== "alice"`
- AND HTTP 403 MUST be returned

## Non-Functional Requirements

- **Security**: Permission checks MUST be enforced server-side in the service layer, not only in the frontend. All permission-related API responses MUST use HTTP 403 with descriptive error messages.
- **Performance**: Permission level checks MUST add no more than 5ms overhead to any API request. The `getEffectivePermissionLevel()` resolution chain involves at most 2 database queries (template lookup + admin default lookup).
- **Accessibility**: Permission-related UI states (disabled buttons, lock icons, required badges) MUST be communicated to screen readers via appropriate ARIA attributes.
- **Localization**: Permission-related error messages and UI labels MUST support English and Dutch.

### Current Implementation Status

**Fully implemented:**
- REQ-PERM-001 (View-Only): `canAddWidget()`, `canEditDashboard()`, `canRemoveWidget()`, `canStyleWidget()` all return false for view_only.
- REQ-PERM-002 (Add-Only): `canRemoveWidget()` checks `isCompulsory` for add_only. All other operations permitted.
- REQ-PERM-003 (Full): `canRemoveWidget()` returns true for full regardless of `isCompulsory`.
- REQ-PERM-005 (Immutability): `applyDashboardUpdates()` does not process `permissionLevel`.
- REQ-PERM-006 (API-Level Enforcement): All checks in controller layer before service calls.
- REQ-PERM-007 (Metadata Editing): `canEditDashboardMetadata()` checks ownership only. `deleteDashboard()` checks ownership only.
- REQ-PERM-008 (Effective Resolution): `getEffectivePermissionLevel()` chains template -> dashboard -> admin default.
- REQ-PERM-010 (Ownership Verification): `verifyDashboardOwnership()` and `verifyPlacementOwnership()` implemented.
- REQ-PERM-011 (Admin Template Restrictions): `canEditDashboard()` blocks admin templates for non-admin users.

**Not yet implemented:**
- REQ-PERM-004 `isCompulsory` immutability: `PlacementUpdater` does not block `isCompulsory` changes.
- REQ-PERM-004 visual indicator: No compulsory widget visual indicator in frontend.
- REQ-PERM-001 frontend UI hiding: No frontend logic hides UI elements based on permission level.
- REQ-PERM-009 frontend styling restrictions: No frontend enforcement of style editing restrictions.

### Standards & References
- Nextcloud AppFramework: `OCP\AppFramework\Http\Attribute\NoAdminRequired` for non-admin access
- HTTP 403 Forbidden: Used consistently for permission denials
- WCAG 2.1 AA: Disabled states, lock icons, required badges must be communicated to screen readers
- WAI-ARIA: `aria-disabled`, `aria-label` for permission-restricted UI elements
