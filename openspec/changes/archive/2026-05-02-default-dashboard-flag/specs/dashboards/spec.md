---
capability: dashboards
delta: true
status: draft
---

# Dashboards — Delta from change `default-dashboard-flag`

## ADDED Requirements

### Requirement: REQ-DASH-015 Single default group-shared dashboard per group

Within each group (including the synthetic `'default'` group), at most one `group_shared` dashboard MAY have `isDefault = 1`. Switching a dashboard to default MUST atomically clear the flag on any other dashboard in the same group. The transition MUST run inside a single database transaction so concurrent calls cannot leave two dashboards with `isDefault = 1` in the same group.

#### Scenario: Setting default flips others off

- GIVEN group "marketing" has 3 group-shared dashboards: `A` (`isDefault=1`), `B`, `C`
- WHEN admin sends `POST /api/dashboards/group/marketing/default` with body `{"uuid": "<C.uuid>"}`
- THEN `C.isDefault` MUST become `1`
- AND `A.isDefault` MUST become `0`
- AND `B.isDefault` MUST remain `0`

#### Scenario: Default cannot be set across groups

- GIVEN dashboard `D1` has `groupId = 'marketing'`
- WHEN admin sends `POST /api/dashboards/group/sales/default` with body `{"uuid": "<D1.uuid>"}`
- THEN the system MUST return HTTP 404
- AND no `isDefault` flag MUST be modified on any dashboard

#### Scenario: Setting non-existent dashboard as default

- GIVEN group "marketing" exists with no dashboards (or no dashboard with the given uuid)
- WHEN admin sends `POST /api/dashboards/group/marketing/default` with a uuid that does not match any dashboard in the group
- THEN the system MUST return HTTP 404

#### Scenario: Non-admin cannot set default

- GIVEN user "alice" who is not an administrator
- WHEN she sends `POST /api/dashboards/group/marketing/default` with any body
- THEN the system MUST return HTTP 403
- AND no `isDefault` flag MUST be modified

#### Scenario: Transaction safety under concurrent calls

- GIVEN group "marketing" has 3 group-shared dashboards `A`, `B`, `C` with `A.isDefault=1`
- WHEN two admins concurrently send `POST /api/dashboards/group/marketing/default` with body `{"uuid": "<B.uuid>"}` and `{"uuid": "<C.uuid>"}` respectively
- THEN exactly one of `B` or `C` MUST end up with `isDefault=1`
- AND the other two dashboards in the group MUST have `isDefault=0`
- AND no row MUST be left with `isDefault=1` for two different uuids in the same group

### Requirement: REQ-DASH-016 New group-shared dashboards default to non-default

When a `group_shared` dashboard is created via `POST /api/dashboards/group/{groupId}`, the system MUST set `isDefault = 0` regardless of any `isDefault` field present in the request body. Promoting a dashboard to default requires an explicit `POST /api/dashboards/group/{groupId}/default` call.

#### Scenario: Create-then-no-default

- GIVEN group "marketing" has no dashboards
- WHEN admin sends `POST /api/dashboards/group/marketing` with body `{"name": "First"}`
- THEN the resulting dashboard MUST have `isDefault = 0`
- AND no other dashboard MUST be created with `isDefault = 1`

#### Scenario: Create payload cannot smuggle isDefault

- GIVEN group "marketing" has no dashboards
- WHEN admin sends `POST /api/dashboards/group/marketing` with body `{"name": "Sneaky", "isDefault": 1}`
- THEN the resulting dashboard MUST have `isDefault = 0`
- AND the `isDefault` field in the request body MUST be ignored by `DashboardService::saveGroupShared`

#### Scenario: First dashboard in a group is not auto-promoted

- GIVEN group "engineering" has zero group-shared dashboards
- WHEN admin creates the first group-shared dashboard `D1` via `POST /api/dashboards/group/engineering`
- THEN `D1.isDefault` MUST be `0`
- AND the active-dashboard resolution chain MUST fall through to "first by sortOrder" semantics rather than implicitly promoting `D1`

### Requirement: REQ-DASH-017 Default flag survives admin edits

Updates to a group-shared dashboard via `PUT /api/dashboards/group/{groupId}/{uuid}` MUST NOT change the `isDefault` flag, regardless of payload contents. The flag is only mutated by the dedicated `POST /api/dashboards/group/{groupId}/default` endpoint.

#### Scenario: PUT cannot flip the default off

- GIVEN dashboard `A` has `isDefault = 1`
- WHEN admin sends `PUT /api/dashboards/group/marketing/<A.uuid>` with body `{"name": "Renamed", "isDefault": 0}`
- THEN `A.name` MUST become "Renamed"
- AND `A.isDefault` MUST remain `1`

#### Scenario: PUT cannot flip the default on

- GIVEN dashboard `B` has `isDefault = 0`
- AND dashboard `A` in the same group has `isDefault = 1`
- WHEN admin sends `PUT /api/dashboards/group/marketing/<B.uuid>` with body `{"name": "Renamed", "isDefault": 1}`
- THEN `B.name` MUST become "Renamed"
- AND `B.isDefault` MUST remain `0`
- AND `A.isDefault` MUST remain `1`
