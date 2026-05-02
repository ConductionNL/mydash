---
capability: admin-settings
delta: true
status: draft
---

# Admin Settings — Delta from change `allow-personal-dashboards-flag`

## MODIFIED Requirements

### Requirement: REQ-ASET-003 Allow User Dashboards Setting (extended runtime gating)

The setting `allow_user_dashboards` (boolean stored as `'0'` / `'1'`, default `'0'`) MUST gate every endpoint that **creates** a personal (`type='user'`) dashboard. Read endpoints, update endpoints, and existing personal dashboards MUST remain accessible regardless of the flag's value. Toggling the flag MUST NOT mutate any dashboard records.

The endpoints listed below MUST evaluate the flag at request time and, when it equals `'0'`, MUST return HTTP 403 with response body `{status: 'error', error: 'personal_dashboards_disabled', message: <translated string>}`:

- `POST /api/dashboards` (when payload omits `type` or sets `type='user'`)
- `POST /api/dashboards/{uuid}/fork` (always — fork target is always `type='user'`)

Endpoints that MUST NOT check the flag (so existing personal dashboards remain functional):

- `GET /api/dashboards/visible`
- `GET /api/dashboards/{uuid}`
- `PUT /api/dashboards/{uuid}` (existing personal dashboard updates)
- `DELETE /api/dashboards/{uuid}` (users can still clean up their old personal dashboards)
- `POST /api/dashboards/active`
- All `group_shared` and `admin_template` endpoints

#### Scenario: Flag off blocks personal dashboard creation

- **GIVEN** admin setting `allow_user_dashboards = '0'`
- **WHEN** user "alice" sends `POST /api/dashboards` with body `{"name": "My Test"}`
- **THEN** the system MUST return HTTP 403 with `{status: 'error', error: 'personal_dashboards_disabled', message: 'Personal dashboards are not enabled by your administrator'}`

#### Scenario: Flag off blocks fork

- **GIVEN** admin setting `allow_user_dashboards = '0'`
- **AND** alice can read group-shared dashboard `S`
- **WHEN** she sends `POST /api/dashboards/{S.uuid}/fork`
- **THEN** the system MUST return HTTP 403 with the same `personal_dashboards_disabled` error envelope

#### Scenario: Flag off does not break existing personal dashboards

- **GIVEN** alice has 2 existing personal dashboards `P1`, `P2`
- **AND** admin toggles `allow_user_dashboards` from `'1'` to `'0'`
- **WHEN** alice opens the workspace page
- **THEN** `P1` and `P2` MUST still appear in `GET /api/dashboards/visible`
- **AND** alice MUST be able to `PUT` and `DELETE` them
- **AND** alice MUST be able to set either as her active dashboard
- **AND** only `POST /api/dashboards` and fork endpoints MUST return 403

#### Scenario: Toggling does not destructively mutate data

- **GIVEN** alice has 1 personal dashboard `P1` (active)
- **AND** admin toggles `allow_user_dashboards` to `'0'` and back to `'1'`
- **THEN** `P1` MUST still exist with all original fields and placements
- **AND** `P1.isActive` MUST still be `1` (unchanged)
- **AND** no rows in `oc_mydash_dashboards` or `oc_mydash_widget_placements` MUST have been touched

#### Scenario: Default value when setting is missing

- **GIVEN** a fresh MyDash install with no row for `allow_user_dashboards` in `oc_mydash_admin_settings`
- **WHEN** any code reads the setting
- **THEN** it MUST evaluate to `false` (creation blocked)

## ADDED Requirements

### Requirement: REQ-ASET-015 Initial-state mirror of the flag

The setting's current value MUST be pushed as initial state `allowUserDashboards: bool` on every workspace and admin page render so the frontend can hide the "+ New Dashboard" affordance and the fork button without an extra round-trip.

#### Scenario: Initial state matches setting

- **GIVEN** admin setting `allow_user_dashboards = '1'`
- **WHEN** any user loads the workspace page
- **THEN** the page initial state MUST include `allowUserDashboards: true`

#### Scenario: Frontend honours the flag

- **GIVEN** initial state has `allowUserDashboards: false`
- **WHEN** the workspace renders
- **THEN** the "+ New Dashboard" button in the sidebar MUST NOT be visible
- **AND** any "Fork as personal" affordance MUST NOT be visible
- **AND** attempting to invoke the underlying actions via direct API call MUST still hit the 403 (defense in depth)
