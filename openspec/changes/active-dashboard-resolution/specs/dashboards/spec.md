---
capability: dashboards
delta: true
status: draft
---

# Dashboards — Delta from change `active-dashboard-resolution`

## ADDED Requirements

### Requirement: REQ-DASH-018 Active-dashboard resolution chain (multi-scope)

When the workspace page renders for a user, the system MUST resolve which dashboard is "active" by walking the following precedence and stopping at the first match:

1. The dashboard whose UUID equals the user's `active_dashboard_uuid` preference, IF that dashboard is currently visible to the user (per REQ-DASH-013).
2. The `group_shared` dashboard with `isDefault = 1` in the user's primary group (per `group-routing` change, REQ-TMPL-012).
3. The `group_shared` dashboard with `isDefault = 1` in the synthetic `'default'` group.
4. The first `group_shared` dashboard (by `sortOrder` ascending, then `createdAt`) in the user's primary group.
5. The first `group_shared` dashboard in the `'default'` group.
6. The user's first personal `user`-type dashboard (by `sortOrder`, then `createdAt`).
7. `null` — the workspace page MUST then render an empty-state with a "Create your first dashboard" affordance.

The resolver MUST attach a `source` field to the returned dashboard descriptor with one of `'user'`, `'group'`, `'default'`.

#### Scenario: Honoured user preference

- GIVEN user "alice" has `active_dashboard_uuid` set to `<X.uuid>`
- AND `X` is a personal dashboard owned by alice
- WHEN she opens the workspace page
- THEN the resolved active dashboard MUST be `X` with `source = 'user'`

#### Scenario: Stale preference is silently cleared

- GIVEN user "alice" has `active_dashboard_uuid` set to `<Y.uuid>`
- AND `Y` has been deleted (or is no longer visible to alice)
- WHEN she opens the workspace page
- THEN the resolver MUST clear her `active_dashboard_uuid` preference (set to empty string or unset)
- AND MUST proceed down the precedence chain
- AND the response MUST NOT raise an error to the user

#### Scenario: Group default wins over default-group default

- GIVEN user "bob" belongs to group "engineering"
- AND group "engineering" has a default dashboard `E`
- AND the `'default'` group also has a default dashboard `D`
- AND bob has no `active_dashboard_uuid` preference
- WHEN he opens the workspace page
- THEN the resolved dashboard MUST be `E` with `source = 'group'`

#### Scenario: Falls through to default group when primary group has no dashboards

- GIVEN user "carol" belongs to group "support" which has zero group-shared dashboards
- AND the `'default'` group has one default dashboard `D`
- WHEN she opens the workspace page
- THEN the resolved dashboard MUST be `D` with `source = 'default'`

#### Scenario: Empty state when no dashboards exist anywhere

- GIVEN a brand-new MyDash install with no dashboards of any type
- WHEN any user opens the workspace page
- THEN the resolver MUST return `null`
- AND the response MUST include `activeDashboardId: ''` in initial state
- AND the page MUST render the empty-state UI

### Requirement: REQ-DASH-019 Persist active-dashboard preference

The system MUST expose `POST /api/dashboards/active` accepting `{uuid: string}`. On success it MUST persist the value to the user's `active_dashboard_uuid` preference.

#### Scenario: Save preference

- GIVEN user "alice" is logged in
- WHEN she sends `POST /api/dashboards/active` with body `{"uuid": "abc-123"}`
- THEN her `active_dashboard_uuid` preference MUST become `"abc-123"`
- AND the response MUST be HTTP 200 `{status: 'success'}`

#### Scenario: Empty uuid clears the preference

- GIVEN alice has a saved preference
- WHEN she sends `POST /api/dashboards/active` with body `{"uuid": ""}`
- THEN her `active_dashboard_uuid` preference MUST be cleared (next page load falls through the chain from step 2)

#### Scenario: No existence check on write

- GIVEN alice sends `POST /api/dashboards/active` with body `{"uuid": "does-not-exist"}`
- THEN the system MUST accept the write (HTTP 200)
- NOTE: The resolver's stale-preference path (REQ-DASH-018 scenario "stale preference") will silently clear it on next render. We deliberately do not validate on write to keep the endpoint cheap.
