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

Dashboards with `permission_level: "view_only"` MUST restrict users to viewing only, with no editing capabilities.

#### Scenario: View-only user sees the dashboard
- GIVEN user "alice" has a dashboard with `permission_level: "view_only"` and 5 widget placements
- WHEN she views the dashboard
- THEN all 5 widgets MUST be rendered with their content
- AND the grid MUST be in view mode
- AND there MUST be no "Edit" button visible

#### Scenario: View-only user cannot add widgets
- GIVEN user "alice" has a view-only dashboard id 5
- WHEN she sends POST /api/dashboard/5/widgets with widget data
- THEN the system MUST return HTTP 403 with a message indicating the dashboard is view-only
- AND no widget placement MUST be created

#### Scenario: View-only user cannot modify widgets
- GIVEN user "alice" has a view-only dashboard with widget placement id 10
- WHEN she sends PUT /api/widgets/10 with body `{"custom_title": "New Title"}`
- THEN the system MUST return HTTP 403
- AND the widget placement MUST NOT be modified

#### Scenario: View-only user cannot delete widgets
- GIVEN user "alice" has a view-only dashboard with widget placement id 10
- WHEN she sends DELETE /api/widgets/10
- THEN the system MUST return HTTP 403
- AND the widget placement MUST NOT be deleted

#### Scenario: View-only user cannot add tiles
- GIVEN user "alice" has a view-only dashboard id 5
- WHEN she sends POST /api/dashboard/5/tile with tile data
- THEN the system MUST return HTTP 403

#### Scenario: View-only dashboard hides all editing UI
- GIVEN user "alice" has a view-only dashboard
- WHEN she views the dashboard
- THEN the following UI elements MUST NOT be displayed:
  - Edit/Done toggle button
  - Add widget button
  - Add tile button
  - Widget context menus (edit, delete, configure)
  - Grid drag handles and resize handles

### REQ-PERM-002: Add-Only Permission Level

Dashboards with `permission_level: "add_only"` MUST allow users to add and modify widgets but prevent removal of compulsory widgets.

#### Scenario: Add-only user can add widgets
- GIVEN user "alice" has a dashboard with `permission_level: "add_only"`
- WHEN she sends POST /api/dashboard/5/widgets with widget data
- THEN the system MUST create the widget placement
- AND the response MUST return HTTP 201

#### Scenario: Add-only user can edit widget settings
- GIVEN user "alice" has an add-only dashboard with widget placement id 10
- WHEN she sends PUT /api/widgets/10 with body `{"custom_title": "My Weather"}`
- THEN the system MUST update the widget placement
- AND the response MUST return HTTP 200

#### Scenario: Add-only user can move and resize widgets
- GIVEN user "alice" has an add-only dashboard in edit mode
- WHEN she drags widget placement id 10 to a new position
- THEN the grid MUST allow the move
- AND the new position MUST be persisted via the API

#### Scenario: Add-only user can remove non-compulsory widgets
- GIVEN user "alice" has an add-only dashboard with widget placement id 10 (`is_compulsory: false`)
- WHEN she sends DELETE /api/widgets/10
- THEN the system MUST delete the placement
- AND the response MUST return HTTP 200

#### Scenario: Add-only user cannot remove compulsory widgets
- GIVEN user "alice" has an add-only dashboard with widget placement id 11 (`is_compulsory: true`)
- WHEN she sends DELETE /api/widgets/11
- THEN the system MUST return HTTP 403 with a message indicating compulsory widgets cannot be removed at this permission level
- AND the widget placement MUST NOT be deleted

#### Scenario: Add-only user cannot remove compulsory widgets via UI
- GIVEN user "alice" has an add-only dashboard in edit mode
- AND widget placement id 11 is compulsory
- WHEN she views the widget's context menu or actions
- THEN the "Remove" or "Delete" option MUST NOT be available for compulsory widgets
- AND a lock icon or "Required" badge SHOULD be displayed on compulsory widgets

#### Scenario: Add-only user can add conditional rules
- GIVEN user "alice" has an add-only dashboard with widget placement id 10 (`is_compulsory: false`)
- WHEN she sends POST /api/widgets/10/rules with a conditional rule
- THEN the system MUST create the rule
- AND the response MUST return HTTP 201

### REQ-PERM-003: Full Permission Level

Dashboards with `permission_level: "full"` MUST allow users complete control over all aspects of the dashboard.

#### Scenario: Full permission user can remove compulsory widgets
- GIVEN user "alice" has a full-permission dashboard with widget placement id 11 (`is_compulsory: true`)
- WHEN she sends DELETE /api/widgets/11
- THEN the system MUST delete the placement
- AND the response MUST return HTTP 200

#### Scenario: Full permission is the default for user-created dashboards
- GIVEN user "alice" creates a new dashboard via POST /api/dashboard
- WHEN the dashboard is created
- THEN `permission_level` MUST be set to "full"
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
- GIVEN an admin template has widget placement with `is_compulsory: true` for widget "company_news"
- WHEN user "alice" receives a copy of this template
- THEN alice's copy of the "company_news" placement MUST have `is_compulsory: true`
- AND the compulsory flag MUST persist on the user's copy

#### Scenario: Users cannot change the compulsory flag
- GIVEN widget placement id 10 with `is_compulsory: true` on alice's dashboard
- WHEN she sends PUT /api/widgets/10 with body `{"is_compulsory": false}`
- THEN the system MUST ignore the `is_compulsory` field in the update
- OR return HTTP 403 for that specific field
- AND `is_compulsory` MUST remain true

#### Scenario: Compulsory widget visual indicator
- GIVEN a dashboard with both compulsory and non-compulsory widgets
- WHEN the dashboard is rendered in edit mode
- THEN compulsory widgets MUST display a visual indicator (e.g., lock icon, "Required" badge)
- AND the indicator MUST be visible only in edit mode (not in view mode)

#### Scenario: Non-compulsory widgets on template-derived dashboards
- GIVEN an admin template has 5 widgets: 3 compulsory and 2 non-compulsory
- AND user "alice" receives a copy with `permission_level: "add_only"`
- WHEN alice enters edit mode
- THEN she MUST be able to remove the 2 non-compulsory widgets
- AND she MUST NOT be able to remove the 3 compulsory widgets

### REQ-PERM-005: Permission Level Immutability for Users

Users MUST NOT be able to change the permission level on their own dashboards.

#### Scenario: User tries to escalate permission level
- GIVEN user "alice" has a dashboard with `permission_level: "add_only"` (inherited from template)
- WHEN she sends PUT /api/dashboard/5 with body `{"permission_level": "full"}`
- THEN the system MUST ignore the `permission_level` field
- OR return HTTP 403 for that specific field
- AND the permission_level MUST remain "add_only"

#### Scenario: User tries to downgrade permission level
- GIVEN user "alice" has a dashboard with `permission_level: "full"`
- WHEN she sends PUT /api/dashboard/5 with body `{"permission_level": "view_only"}`
- THEN the system MUST ignore the `permission_level` field
- AND the permission_level MUST remain "full"

### REQ-PERM-006: Permission Enforcement on API Level

Permission checks MUST be enforced at the API/service level, not just in the frontend UI.

#### Scenario: API rejects widget addition on view-only dashboard
- GIVEN dashboard id 5 has `permission_level: "view_only"`
- WHEN any HTTP client sends POST /api/dashboard/5/widgets
- THEN the system MUST return HTTP 403 regardless of how the request was made (UI, curl, API client)

#### Scenario: API rejects compulsory widget deletion on add-only dashboard
- GIVEN dashboard id 5 has `permission_level: "add_only"`
- AND widget placement id 11 has `is_compulsory: true`
- WHEN any HTTP client sends DELETE /api/widgets/11
- THEN the system MUST return HTTP 403

#### Scenario: API allows all operations on full-permission dashboard
- GIVEN dashboard id 5 has `permission_level: "full"`
- WHEN any valid widget/tile operation is sent
- THEN the system MUST allow the operation (assuming proper ownership)

### REQ-PERM-007: Dashboard Metadata Editing

Permission levels MUST NOT restrict editing of dashboard metadata (name, description).

#### Scenario: View-only user can edit dashboard name
- GIVEN user "alice" has a view-only dashboard id 5
- WHEN she sends PUT /api/dashboard/5 with body `{"name": "Renamed Dashboard"}`
- THEN the system MUST allow the update
- AND the dashboard name MUST be updated
- AND only widget/tile/layout operations are restricted by permission level

#### Scenario: View-only user can delete the dashboard
- GIVEN user "alice" has a view-only dashboard id 5
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST allow the deletion
- AND the user always has the right to remove dashboards from their account

## Non-Functional Requirements

- **Security**: Permission checks MUST be enforced server-side in the service layer, not only in the frontend. All permission-related API responses MUST use HTTP 403 with descriptive error messages.
- **Performance**: Permission level checks MUST add no more than 5ms overhead to any API request.
- **Accessibility**: Permission-related UI states (disabled buttons, lock icons, required badges) MUST be communicated to screen readers via appropriate ARIA attributes.
- **Localization**: Permission-related error messages and UI labels MUST support English and Dutch.
