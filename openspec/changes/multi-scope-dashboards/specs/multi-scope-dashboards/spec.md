# Specification — Multi-scope dashboards

## Requirements

### REQ-MSC-001: Dashboard scope property

A dashboard entity MUST carry a `scope` property with enum values: `personal`, `shared`, `organisation`.

**GIVEN** a dashboard is created  
**WHEN** no scope is specified  
**THEN** default scope is `personal`

**GIVEN** a dashboard's scope is `shared`  
**WHEN** querying visible dashboards for any organisation member  
**THEN** the shared dashboard appears in the list (even if the user doesn't own it)

**GIVEN** a dashboard's scope is `organisation`  
**WHEN** querying visible dashboards for any organisation member  
**THEN** the organisation dashboard appears in the list

### REQ-MSC-002: Dashboard persona targeting

A dashboard entity MAY carry a `targetPersonas` property with value type `string[]` (array of role/persona slugs).

**GIVEN** a dashboard has `targetPersonas: ['chair', 'secretary']`  
**WHEN** a user with role 'member' (but not 'chair' or 'secretary') queries visible dashboards  
**THEN** the dashboard is filtered out

**GIVEN** a dashboard has `targetPersonas: []` (empty array)  
**WHEN** any user queries visible dashboards  
**THEN** the dashboard is visible to all (no persona restriction)

### REQ-MSC-003: Scope resolver service

A service `DashboardScopeResolver` MUST expose:
- `findDashboardsForUser(user: IUser): Dashboard[]` — returns all dashboards visible to the user based on scope + targetPersonas rules

**GIVEN** a user with roles ['member', 'chair']  
**WHEN** `findDashboardsForUser` is called  
**THEN** returns dashboards with scope `personal` (owned by the user), scope `shared`, scope `organisation`, and any dashboards with `targetPersonas` matching 'member' or 'chair'

### REQ-MSC-004: Layout selector service

A service `PersonaLayoutSelector` MUST expose:
- `selectActiveLayout(user: IUser, dashboards: Dashboard[]): Dashboard` — returns the dashboard to activate based on highest-priority persona match

**GIVEN** a user with roles ['member', 'chair'] (in that priority order)  
**WHEN** available dashboards include one with `targetPersonas: ['member']` and one with `targetPersonas: ['chair']`  
**THEN** the 'chair' dashboard is selected (higher priority match)

**GIVEN** a user has no dashboards matching their personas  
**WHEN** `selectActiveLayout` is called with available dashboards  
**THEN** returns the first available dashboard (fail-safe fallback)

### REQ-MSC-005: Dashboard switcher UI

The dashboard page MUST display a switcher control when the user has multiple visible dashboards.

**GIVEN** a user has 3 visible dashboards (one personal, one shared, one organisation)  
**WHEN** the dashboard page loads  
**THEN** a dropdown/switcher control is rendered showing all 3 dashboards with the active one highlighted

**GIVEN** the user clicks on a different dashboard in the switcher  
**WHEN** the selection is submitted  
**THEN** the page loads the selected dashboard and remember the selection for future sessions (via localStorage or user settings)

### REQ-MSC-006: Shared page adoption

When a user adopts a shared start page, MyDash MUST create an independent copy.

**GIVEN** a shared dashboard named "Board Member Overview"  
**WHEN** a user clicks "Adopt this template"  
**THEN** a new personal dashboard is created with the same widgets + settings, and the user is redirected to their copy

**GIVEN** a user has adopted a shared dashboard  
**WHEN** the template is updated (by an admin)  
**THEN** the user's adopted copy is NOT automatically updated

### REQ-MSC-007: Admin configuration

The dashboard admin panel MUST allow configuring scope + targetPersonas for each dashboard.

**GIVEN** an admin is editing a dashboard  
**WHEN** the admin sets scope to 'shared' and targetPersonas to ['chair', 'secretary']  
**THEN** the changes are persisted and the dashboard becomes visible to users with those roles

**GIVEN** the admin tries to set targetPersonas to a role that does not exist in the system  
**WHEN** the admin submits the form  
**THEN** an error is displayed (list of known roles is provided as a dropdown, not free-text)

### REQ-MSC-008: Seed data

On first install, MyDash MUST include 3-5 example shared dashboards demonstrating scope + persona use.

**GIVEN** a fresh MyDash installation  
**WHEN** the app is enabled  
**THEN** seed data includes at least 3 example shared dashboards:
- "Board Member Overview" (scope: shared, targetPersonas: ['member'])
- "Chair Dashboard" (scope: shared, targetPersonas: ['chair'])
- "Organisation Summary" (scope: organisation, targetPersonas: [])

## Verification

- Scope resolver returns all and only dashboards matching the user's scope + persona rules
- Layout selector picks the highest-priority matching persona when multiple dashboards are visible
- Shared dashboard adoption creates an independent copy
- Switcher UI renders when multiple dashboards are visible and persists user selection
- Seed data loads on install with no errors
