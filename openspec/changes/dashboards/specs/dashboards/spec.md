---
status: reviewed
---

# Dashboards Specification

## Purpose

Dashboards are the core organizational unit in MyDash. Each user can create and manage multiple personal dashboards, each acting as a container for widget placements, tiles, and layout configuration. Dashboards define the grid structure, permission level, and active state. Only one dashboard can be active per user at a time, serving as their landing page when they open Nextcloud. Dashboards can also be of type `admin_template`, managed by administrators for distribution to users.

## Data Model

Each dashboard record is stored in the `oc_mydash_dashboards` table with the following fields:
- **id**: Auto-increment integer primary key
- **uuid**: Unique identifier (UUID v4)
- **userId**: Nextcloud user ID of the dashboard owner
- **name**: Human-readable dashboard name
- **description**: Optional description of the dashboard purpose
- **type**: Either `user` (personal) or `admin_template` (admin-managed template)
- **basedOnTemplate**: Nullable integer foreign key to the source admin template dashboard ID (set when a user copy is created from a template)
- **gridColumns**: Number of grid columns (default: 12)
- **permissionLevel**: One of `view_only`, `add_only`, `full` (inherited from template or set by admin)
- **targetGroups**: JSON string of group IDs (used for admin templates)
- **isDefault**: SMALLINT (0/1) flag for admin templates indicating default distribution
- **isActive**: SMALLINT (0/1) flag indicating if this is the user's currently active dashboard
- **createdAt**: Timestamp string (Y-m-d H:i:s)
- **updatedAt**: Timestamp string (Y-m-d H:i:s)

## Requirements

### REQ-DASH-001: Create Personal Dashboard

Users MUST be able to create new personal dashboards with a name, optional description, and default grid configuration.

#### Scenario: Create a dashboard with default settings
- GIVEN a logged-in Nextcloud user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "My Work Dashboard"}`
- THEN the system MUST create a new dashboard with:
  - A generated UUID v4 (via custom `DashboardFactory::generateUuid()`)
  - `userId` set to "alice"
  - `type` set to "user"
  - `isActive` set to 1 (true) -- the newly created dashboard becomes active, and all other user dashboards are deactivated via `deactivateAllForUser()`
  - `gridColumns` set to 12 (hardcoded in `DashboardFactory::create()`)
  - `permissionLevel` set to "full" (hardcoded as `Dashboard::PERMISSION_FULL`)
- AND the response MUST return HTTP 201 with the full dashboard object including the generated id and uuid

#### Scenario: Create a dashboard with custom settings
- GIVEN a logged-in Nextcloud user "bob"
- WHEN he sends POST /api/dashboard with body `{"name": "Analytics", "description": "Data overview"}`
- THEN the system MUST create the dashboard with the specified name and description
- AND `gridColumns` MUST be set to 12 (custom gridColumns is not exposed in the create endpoint)

#### Scenario: Create a dashboard with invalid grid columns
- GIVEN a logged-in Nextcloud user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Test", "grid_columns": 0}`
- THEN the system MUST return HTTP 400 with a validation error
- AND `gridColumns` MUST only accept positive integers (minimum 1, maximum 24)
- NOTE: Grid column validation is NOT currently implemented

#### Scenario: Create a dashboard without a name
- GIVEN a logged-in Nextcloud user "alice"
- WHEN she sends POST /api/dashboard with body `{}`
- THEN the system MUST create a dashboard with the default name "My Dashboard"
- NOTE: The controller defaults name to "My Dashboard" if null. No validation error is returned.

#### Scenario: Dashboard creation creates default placements
- GIVEN user "alice" has no dashboards and no templates apply
- WHEN she accesses MyDash for the first time (triggers `tryCreateFromTemplate()`)
- THEN the system MUST create a "My Dashboard" with two default placements:
  - "recommendations" widget at (0, 0) with size 6x5
  - "activity" widget at (6, 0) with size 6x5
- AND both placements MUST have `showTitle: 1`, `isVisible: 1`, and appropriate sortOrder values

### REQ-DASH-002: List User Dashboards

Users MUST be able to retrieve a list of all their dashboards, scoped to their user ID.

#### Scenario: List dashboards for a user with multiple dashboards
- GIVEN user "alice" has 3 dashboards: "Work" (active), "Personal", "Analytics"
- WHEN she sends GET /api/dashboards
- THEN the system MUST return HTTP 200 with an array containing all 3 dashboards
- AND each dashboard object MUST include: id, uuid, name, description, type, basedOnTemplate, gridColumns, permissionLevel, targetGroups, isDefault, isActive, createdAt, updatedAt
- AND the active dashboard MUST have `isActive: 1`

#### Scenario: List dashboards for a user with no dashboards
- GIVEN user "bob" has never created a dashboard and no template has been distributed to him
- WHEN he sends GET /api/dashboards
- THEN the system MUST return HTTP 200 with an empty array

#### Scenario: Dashboards are user-scoped
- GIVEN user "alice" has 3 dashboards and user "bob" has 1 dashboard
- WHEN "alice" sends GET /api/dashboards
- THEN the response MUST contain only alice's 3 dashboards
- AND bob's dashboard MUST NOT be included
- AND admin templates (type: "admin_template") MUST NOT be included

### REQ-DASH-003: Get Active Dashboard

Users MUST be able to retrieve their currently active dashboard along with its placements and effective permission level in a single request.

#### Scenario: Get the active dashboard
- GIVEN user "alice" has dashboard "Work" marked as active
- WHEN she sends GET /api/dashboard
- THEN the system MUST return HTTP 200 with an object containing:
  - `dashboard`: the "Work" dashboard object (with `isActive: 1`)
  - `placements`: array of all widget placements on this dashboard
  - `permissionLevel`: the effective permission level string (resolved via `PermissionService::getEffectivePermissionLevel()`)

#### Scenario: No active dashboard exists but user has dashboards
- GIVEN user "bob" has 2 dashboards but none is marked as active
- WHEN he sends GET /api/dashboard
- THEN the system MUST activate the first existing dashboard via `DashboardResolver::tryActivateExistingDashboard()`
- AND return that dashboard as the active one

#### Scenario: First-time user triggers template distribution
- GIVEN user "carol" has no dashboards
- AND an admin template exists targeting carol's group
- WHEN she sends GET /api/dashboard
- THEN the system MUST create a personal copy of the matching template via `TemplateService::createDashboardFromTemplate()`
- AND the copy MUST be set as her active dashboard
- AND the response MUST return the newly created dashboard with its placements

#### Scenario: First-time user with no template gets default dashboard
- GIVEN user "dave" has no dashboards
- AND no admin template matches dave's groups
- AND `allowUserDashboards` is true
- WHEN he sends GET /api/dashboard
- THEN the system MUST create a default "My Dashboard" with recommendations and activity widgets
- AND the response MUST return the newly created dashboard

#### Scenario: First-time user with dashboards disabled and no template
- GIVEN user "eve" has no dashboards
- AND no admin template matches eve's groups
- AND `allowUserDashboards` is false
- WHEN she sends GET /api/dashboard
- THEN the system MUST return null (no dashboard available)
- AND the response MUST return HTTP 404 or an empty result

### REQ-DASH-004: Update Dashboard

Users MUST be able to update the name, description, and grid configuration of their dashboards.

#### Scenario: Update dashboard name and description
- GIVEN user "alice" has dashboard with id 5
- WHEN she sends PUT /api/dashboard/5 with body `{"name": "Updated Work", "description": "New desc"}`
- THEN the system MUST update the name and description
- AND set `updatedAt` to the current timestamp
- AND return HTTP 200 with the updated dashboard object

#### Scenario: Update another user's dashboard
- GIVEN user "alice" has dashboard with id 5
- WHEN user "bob" sends PUT /api/dashboard/5 with body `{"name": "Hacked"}`
- THEN the system MUST return HTTP 403 (via ownership check)
- AND the dashboard MUST NOT be modified

#### Scenario: Update grid columns on a dashboard with existing widgets
- GIVEN user "alice" has dashboard id 5 with `gridColumns: 12` and 4 widget placements
- WHEN she sends PUT /api/dashboard/5 with body `{"gridColumns": 6}`
- THEN the system MUST update `gridColumns` to 6
- AND widget placements that exceed the new column count SHOULD be repositioned or flagged for re-layout
- NOTE: Grid reflow is NOT currently implemented. Widgets exceeding the new column count remain at their positions.

#### Scenario: Update permission_level on a user dashboard
- GIVEN user "alice" has a personal dashboard with `permissionLevel: full`
- WHEN she sends PUT /api/dashboard/5 with body `{"permissionLevel": "view_only"}`
- THEN the system MUST ignore the `permissionLevel` field
- AND the permissionLevel MUST remain "full"
- NOTE: `applyDashboardUpdates()` does not handle `permissionLevel` -- it only processes `name`, `description`, `gridColumns`, and `placements`.

#### Scenario: Batch update placement positions via dashboard update
- GIVEN user "alice" has dashboard id 5 with 4 widget placements
- WHEN she sends PUT /api/dashboard/5 with body containing a `placements` array of updated positions
- THEN the system MUST update all placement positions via `placementMapper->updatePositions()`
- AND this enables efficient grid saves after drag-and-drop rearrangement

### REQ-DASH-005: Delete Dashboard

Users MUST be able to delete their own dashboards with proper cascade deletion of associated data.

#### Scenario: Delete a dashboard
- GIVEN user "alice" has dashboard id 5 with 3 widget placements
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST delete the dashboard
- AND all associated widget placements MUST be cascade-deleted via `placementMapper->deleteByDashboardId()`
- AND the response MUST return HTTP 200

#### Scenario: Delete the active dashboard
- GIVEN user "alice" has dashboard id 5 marked as active and dashboard id 6 as inactive
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST delete dashboard 5
- AND the system does NOT automatically activate dashboard 6
- NOTE: Auto-activation after delete is NOT currently implemented. The user will have no active dashboard until the next GET /api/dashboard triggers `tryActivateExistingDashboard()`.

#### Scenario: Delete another user's dashboard
- GIVEN user "alice" has dashboard id 5
- WHEN user "bob" sends DELETE /api/dashboard/5
- THEN the system MUST return HTTP 403
- AND the dashboard MUST NOT be deleted

#### Scenario: Delete the last remaining dashboard
- GIVEN user "alice" has only 1 dashboard (id 5)
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST delete the dashboard
- AND subsequent GET /api/dashboards MUST return an empty array

#### Scenario: Delete does not check permission level
- GIVEN user "alice" has a view-only dashboard id 5 (based on a template with `permissionLevel: "view_only"`)
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST allow the deletion
- AND users MUST always have the right to remove dashboards from their account regardless of permission level

### REQ-DASH-006: Activate Dashboard

Users MUST be able to set one of their dashboards as the active dashboard, ensuring only one is active at a time.

#### Scenario: Activate a dashboard
- GIVEN user "alice" has dashboard "Work" (id 5, active) and "Personal" (id 6, inactive)
- WHEN she sends POST /api/dashboard/6/activate
- THEN dashboard 6 MUST have `isActive: 1`
- AND dashboard 5 MUST have `isActive: 0` (via `DashboardMapper::setActive()` which deactivates all others first)
- AND the response MUST return HTTP 200 with the newly activated dashboard

#### Scenario: Activate an already active dashboard
- GIVEN user "alice" has dashboard "Work" (id 5, active)
- WHEN she sends POST /api/dashboard/5/activate
- THEN the system MUST return HTTP 200 (idempotent operation)
- AND dashboard 5 MUST remain active

#### Scenario: Activate another user's dashboard
- GIVEN user "alice" has dashboard id 5
- WHEN user "bob" sends POST /api/dashboard/5/activate
- THEN the system MUST return HTTP 403

#### Scenario: Only one active dashboard per user
- GIVEN user "alice" has 5 dashboards
- WHEN she activates dashboard id 8
- THEN exactly one dashboard (id 8) MUST have `isActive: 1`
- AND all other 4 dashboards MUST have `isActive: 0`

### REQ-DASH-007: Dashboard Name Validation

Dashboard names MUST be validated for length and content.

#### Scenario: Name length validation
- GIVEN a logged-in user
- WHEN they create a dashboard with a name exceeding 255 characters
- THEN the system MUST return HTTP 400 with a validation error
- AND dashboard names MUST be between 1 and 255 characters
- NOTE: Name length validation is NOT currently implemented

#### Scenario: Duplicate dashboard names allowed
- GIVEN user "alice" already has a dashboard named "Work"
- WHEN she creates another dashboard named "Work"
- THEN the system MUST allow this (dashboard names are not unique per user)
- AND the two dashboards MUST be distinguishable by their id and uuid

#### Scenario: Empty name defaults to "My Dashboard"
- GIVEN a logged-in user
- WHEN they create a dashboard without providing a name
- THEN the system MUST use the default name "My Dashboard"
- AND the dashboard MUST be created successfully

### REQ-DASH-008: Dashboard Type Enforcement

The `type` field MUST distinguish between user-created dashboards and admin templates, with appropriate access controls.

#### Scenario: Users cannot create admin_template type dashboards
- GIVEN a non-admin user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Fake Template", "type": "admin_template"}`
- THEN the system MUST ignore the `type` field (defaulting to "user" via `DashboardFactory::create()`)
- AND the created dashboard MUST have `type: user`

#### Scenario: Admin creates a template dashboard
- GIVEN a Nextcloud admin user
- WHEN they send POST /api/admin/templates with template data
- THEN the system MUST create a dashboard with `type: admin_template`
- AND the template dashboard MUST NOT appear in regular users' GET /api/dashboards responses

#### Scenario: Template-derived dashboards have type "user"
- GIVEN an admin template "Company Dashboard" is distributed to user "alice"
- WHEN the system creates a copy for alice via `TemplateService::createDashboardFromTemplate()`
- THEN the copy MUST have `type: "user"` (NOT "admin_template")
- AND `basedOnTemplate` MUST reference the source template's ID

### REQ-DASH-009: Dashboard Resolution Chain

The system MUST resolve the effective dashboard through a defined chain when GET /api/dashboard is called.

#### Scenario: Active dashboard found immediately
- GIVEN user "alice" has an active dashboard
- WHEN GET /api/dashboard is called
- THEN `DashboardResolver::tryGetActiveDashboard()` MUST find and return it immediately
- AND no template distribution or default creation logic MUST be triggered

#### Scenario: No active dashboard but existing dashboards
- GIVEN user "alice" has dashboards but none is active
- WHEN GET /api/dashboard is called
- THEN `DashboardResolver::tryActivateExistingDashboard()` MUST activate the first found dashboard
- AND return it as the active dashboard

#### Scenario: No dashboards at all with template available
- GIVEN user "alice" has no dashboards
- AND a matching admin template exists
- WHEN GET /api/dashboard is called
- THEN `DashboardService::tryCreateFromTemplate()` MUST be called
- AND a template copy MUST be created and set as active

### REQ-DASH-010: Dashboard Serialization

Dashboard objects MUST be consistently serialized across all API responses.

#### Scenario: Dashboard object includes all fields
- GIVEN a dashboard exists
- WHEN it is returned via any API endpoint
- THEN the serialized object MUST include all fields: id, uuid, userId, name, description, type, basedOnTemplate, gridColumns, permissionLevel, targetGroups, isDefault, isActive, createdAt, updatedAt

#### Scenario: Null fields are included in serialization
- GIVEN a dashboard with `description: null` and `basedOnTemplate: null`
- WHEN the dashboard is serialized
- THEN both `description` and `basedOnTemplate` MUST be present in the JSON with null values

#### Scenario: Timestamp format consistency
- GIVEN a dashboard with `createdAt` and `updatedAt` set
- WHEN the dashboard is serialized
- THEN timestamps MUST be in "Y-m-d H:i:s" format (e.g., "2026-03-20 14:30:00")

## Non-Functional Requirements

- **Performance**: GET /api/dashboards MUST return within 500ms for users with up to 50 dashboards. GET /api/dashboard MUST return within 1 second including template distribution if needed.
- **Data integrity**: The single-active-dashboard invariant MUST be enforced consistently, even under concurrent requests from the same user.
- **Accessibility**: Dashboard management UI elements (create, edit, delete, activate) MUST be operable via keyboard and screen readers.
- **Localization**: All error messages and validation messages MUST support English and Dutch.

### Current Implementation Status

**Fully implemented:**
- REQ-DASH-001 (Create Personal Dashboard): `DashboardService::createDashboard()` delegates to `DashboardFactory::create()`. Default placements created via `createDefaultPlacements()` during first-time access.
- REQ-DASH-002 (List User Dashboards): `DashboardService::getUserDashboards()` calls `DashboardMapper::findByUserId()`. User-scoped, templates filtered out.
- REQ-DASH-003 (Get Active Dashboard): `DashboardService::getEffectiveDashboard()` chains `tryGetActiveDashboard` -> `tryActivateExistingDashboard` -> `tryCreateFromTemplate`.
- REQ-DASH-004 (Update Dashboard): `DashboardService::updateDashboard()` with `applyDashboardUpdates()` handles name, description, gridColumns, placements.
- REQ-DASH-005 (Delete Dashboard): `DashboardService::deleteDashboard()` deletes placements then dashboard.
- REQ-DASH-006 (Activate Dashboard): `DashboardService::activateDashboard()` via `DashboardMapper::setActive()`.
- REQ-DASH-008 (Dashboard Type Enforcement): Admin templates via `AdminController`, user dashboards via `DashboardFactory`.
- REQ-DASH-009 (Dashboard Resolution Chain): Full chain implemented in `DashboardService::getEffectiveDashboard()`.

**Not yet implemented:**
- REQ-DASH-001/007 validation: No name or gridColumns validation.
- REQ-DASH-004 grid reflow: Updating gridColumns does not reposition widgets.
- REQ-DASH-005 auto-activate after delete: Not implemented.
- REQ-DASH-005 cascade-delete conditional rules: Not explicitly handled.

### Standards & References
- Nextcloud Controller patterns: `OCP\AppFramework\Controller`, `#[NoAdminRequired]` attribute
- UUID generation: Custom UUID v4 implementation in `DashboardFactory::generateUuid()`
- WCAG 2.1 AA: Dashboard management UI elements should be keyboard-operable
