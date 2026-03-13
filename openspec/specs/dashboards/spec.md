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
  - A generated UUID
  - `userId` set to "alice"
  - `type` set to "user"
  - `isActive` set to 1 (true) -- the newly created dashboard becomes active, and all other user dashboards are deactivated
  - `gridColumns` set to 12
  - `permissionLevel` set to "full"
- AND the response MUST return HTTP 201 with the full dashboard object including the generated id and uuid

#### Scenario: Create a dashboard with custom settings
- GIVEN a logged-in Nextcloud user "bob"
- WHEN he sends POST /api/dashboard with body `{"name": "Analytics", "description": "Data overview", "grid_columns": 6}`
- THEN the system MUST create the dashboard with the specified name, description, and grid_columns
- AND `gridColumns` MUST be set to 6

#### Scenario: Create a dashboard with invalid grid columns
- GIVEN a logged-in Nextcloud user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Test", "grid_columns": 0}`
- THEN the system MUST return HTTP 400 with a validation error
- AND `gridColumns` MUST only accept positive integers (minimum 1, maximum 24)

#### Scenario: Create a dashboard without a name
- GIVEN a logged-in Nextcloud user "alice"
- WHEN she sends POST /api/dashboard with body `{}`
- THEN the system MUST return HTTP 400 with a validation error indicating that "name" is required

### REQ-DASH-002: List User Dashboards

Users MUST be able to retrieve a list of all their dashboards.

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

### REQ-DASH-003: Get Active Dashboard

Users MUST be able to retrieve their currently active dashboard in a single request.

#### Scenario: Get the active dashboard
- GIVEN user "alice" has dashboard "Work" marked as active
- WHEN she sends GET /api/dashboard
- THEN the system MUST return HTTP 200 with an object containing:
  - `dashboard`: the "Work" dashboard object (with `isActive: 1`)
  - `placements`: array of all widget placements on this dashboard
  - `permissionLevel`: the effective permission level string

#### Scenario: No active dashboard exists
- GIVEN user "bob" has 2 dashboards but none is marked as active
- WHEN he sends GET /api/dashboard
- THEN the system MUST return HTTP 404
- OR the system MUST return the most recently created dashboard as a fallback

#### Scenario: First-time user triggers template distribution
- GIVEN user "carol" has no dashboards
- AND an admin template exists targeting carol's group with `is_default: true`
- WHEN she sends GET /api/dashboard
- THEN the system MUST create a personal copy of the matching template
- AND the copy MUST be set as her active dashboard
- AND the response MUST return the newly created dashboard

### REQ-DASH-004: Update Dashboard

Users MUST be able to update the name, description, and grid configuration of their dashboards.

#### Scenario: Update dashboard name and description
- GIVEN user "alice" has dashboard with id 5
- WHEN she sends PUT /api/dashboard/5 with body `{"name": "Updated Work", "description": "New desc"}`
- THEN the system MUST update the name and description
- AND return HTTP 200 with the updated dashboard object

#### Scenario: Update another user's dashboard
- GIVEN user "alice" has dashboard with id 5
- WHEN user "bob" sends PUT /api/dashboard/5 with body `{"name": "Hacked"}`
- THEN the system MUST return HTTP 403
- AND the dashboard MUST NOT be modified

#### Scenario: Update grid columns on a dashboard with existing widgets
- GIVEN user "alice" has dashboard id 5 with `gridColumns: 12` and 4 widget placements
- WHEN she sends PUT /api/dashboard/5 with body `{"gridColumns": 6}`
- THEN the system MUST update `gridColumns` to 6
- AND widget placements that exceed the new column count SHOULD be repositioned or flagged for re-layout

#### Scenario: Update permission_level on a user dashboard
- GIVEN user "alice" has a personal dashboard with `permissionLevel: full`
- WHEN she sends PUT /api/dashboard/5 with body `{"permissionLevel": "view_only"}`
- THEN the system MUST return HTTP 400 or ignore the field
- AND users MUST NOT be able to change permissionLevel on their own dashboards (only inherited from templates or set by admin)
- NOTE: The current `applyDashboardUpdates()` implementation does NOT block `gridColumns` changes but also does NOT apply `permissionLevel` changes from user requests, since `permissionLevel` is not handled in the update method

### REQ-DASH-005: Delete Dashboard

Users MUST be able to delete their own dashboards.

#### Scenario: Delete a dashboard
- GIVEN user "alice" has dashboard id 5 with 3 widget placements
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST delete the dashboard
- AND all associated widget placements MUST be cascade-deleted
- AND all associated conditional rules MUST be cascade-deleted
- AND the response MUST return HTTP 200

#### Scenario: Delete the active dashboard
- GIVEN user "alice" has dashboard id 5 marked as active and dashboard id 6 as inactive
- WHEN she sends DELETE /api/dashboard/5
- THEN the system MUST delete dashboard 5
- AND the system SHOULD automatically activate dashboard 6 (the remaining dashboard)
- OR the user MUST be left with no active dashboard

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

### REQ-DASH-006: Activate Dashboard

Users MUST be able to set one of their dashboards as the active dashboard, ensuring only one is active at a time.

#### Scenario: Activate a dashboard
- GIVEN user "alice" has dashboard "Work" (id 5, active) and "Personal" (id 6, inactive)
- WHEN she sends POST /api/dashboard/6/activate
- THEN dashboard 6 MUST have `isActive: 1`
- AND dashboard 5 MUST have `isActive: 0`
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
- AND this invariant MUST be enforced at the database level or in the service layer before returning

### REQ-DASH-007: Dashboard Name Validation

Dashboard names MUST be validated for length and content.

#### Scenario: Name length validation
- GIVEN a logged-in user
- WHEN they create a dashboard with a name exceeding 255 characters
- THEN the system MUST return HTTP 400 with a validation error
- AND dashboard names MUST be between 1 and 255 characters

#### Scenario: Duplicate dashboard names allowed
- GIVEN user "alice" already has a dashboard named "Work"
- WHEN she creates another dashboard named "Work"
- THEN the system MUST allow this (dashboard names are not unique per user)
- AND the two dashboards MUST be distinguishable by their id and uuid

### REQ-DASH-008: Dashboard Type Enforcement

The `type` field MUST distinguish between user-created dashboards and admin templates, with appropriate access controls.

#### Scenario: Users cannot create admin_template type dashboards
- GIVEN a non-admin user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Fake Template", "type": "admin_template"}`
- THEN the system MUST either ignore the `type` field (defaulting to "user") or return HTTP 403
- AND the created dashboard MUST have `type: user`

#### Scenario: Admin creates a template dashboard
- GIVEN a Nextcloud admin user
- WHEN they send POST /api/admin/templates with template data
- THEN the system MUST create a dashboard with `type: admin_template`
- AND the template dashboard MUST NOT appear in regular users' GET /api/dashboards responses

## Non-Functional Requirements

- **Performance**: GET /api/dashboards MUST return within 500ms for users with up to 50 dashboards.
- **Data integrity**: The single-active-dashboard invariant MUST be enforced consistently, even under concurrent requests from the same user.
- **Accessibility**: Dashboard management UI elements (create, edit, delete, activate) MUST be operable via keyboard and screen readers.
- **Localization**: All error messages and validation messages MUST support English and Dutch.
