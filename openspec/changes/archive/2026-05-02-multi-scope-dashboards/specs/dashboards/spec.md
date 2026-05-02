---
capability: dashboards
delta: true
status: draft
---

# Dashboards — Delta from change `multi-scope-dashboards`

## ADDED Requirements

### Requirement: REQ-DASH-011 Group-shared dashboard type

The system MUST support a third dashboard type `group_shared` in addition to `user` and `admin_template`. A group-shared dashboard is owned by an administrator, scoped to one Nextcloud group via a `groupId` field, and rendered live (not copied) to every member of that group. Edits made by an administrator MUST be visible to all group members on their next page load.

#### Scenario: Create a group-shared dashboard

- GIVEN a logged-in administrator "admin" and a Nextcloud group "marketing"
- WHEN admin sends `POST /api/dashboards/group/marketing` with body `{"name": "Marketing Overview"}`
- THEN the system MUST create a dashboard record with `type = 'group_shared'`, `groupId = 'marketing'`, and `userId = null`
- AND the response MUST return HTTP 201 with the new dashboard

#### Scenario: Non-admin cannot create a group-shared dashboard

- GIVEN a logged-in user "alice" who is not an administrator
- WHEN she sends `POST /api/dashboards/group/marketing` with any body
- THEN the system MUST return HTTP 403

#### Scenario: Group-shared dashboard appears for every group member

- GIVEN admin has created a group-shared dashboard `D1` with `groupId = 'marketing'`
- AND user "bob" is a member of group "marketing"
- WHEN bob calls `GET /api/dashboards/visible`
- THEN the response MUST include `D1`

#### Scenario: Group-shared dashboards are read-only for non-admins

- GIVEN bob (non-admin) is viewing group-shared dashboard `D1`
- WHEN he sends `PUT /api/dashboards/group/marketing/{D1.uuid}` with any body
- THEN the system MUST return HTTP 403
- AND the dashboard MUST NOT be modified

#### Scenario: Direct mutation via personal endpoint is rejected

- GIVEN bob (non-admin) is viewing group-shared dashboard `D1` (owner type `group_shared`)
- WHEN he sends `PUT /api/dashboard/{D1.id}` (the personal endpoint)
- THEN the system MUST return HTTP 403 (ownership check fails — `D1.userId` is null, not bob)

#### Scenario: Invariant — `group_shared` requires `groupId`

- GIVEN any caller attempts to insert a dashboard row with `type='group_shared'` and `groupId IS NULL`
- THEN the system MUST throw `\InvalidArgumentException` (enforced by `DashboardFactory::create()`)
- AND no row MUST be persisted

#### Scenario: Invariant — non-`group_shared` types must not have a `groupId`

- GIVEN any caller attempts to insert a dashboard with `type='user'` and `groupId='marketing'`
- THEN the system MUST throw `\InvalidArgumentException`
- AND no row MUST be persisted

### Requirement: REQ-DASH-012 Default-group sentinel

The system MUST recognise the literal `groupId = 'default'` as a synthetic group meaning "visible to all users", regardless of their actual group membership. Group-shared dashboards with `groupId = 'default'` MUST be returned by every user's `/api/dashboards/visible` query in addition to the dashboards from groups they belong to.

#### Scenario: Default-group dashboard visible to user with no matching groups

- GIVEN admin has created group-shared dashboards: `D-default` with `groupId='default'` and `D-eng` with `groupId='engineering'`
- AND user "carol" belongs only to group "support"
- WHEN she calls `GET /api/dashboards/visible`
- THEN the response MUST include `D-default`
- AND MUST NOT include `D-eng`

#### Scenario: 'default' is not a real Nextcloud group

- GIVEN admin sends `POST /api/dashboards/group/default` with body `{"name": "Welcome"}`
- THEN the system MUST accept the request even when no Nextcloud group with id "default" exists
- AND the dashboard MUST be created with `groupId = 'default'`

#### Scenario: Default-group dashboard carries `source: 'default'` not `source: 'group'`

- GIVEN a default-group dashboard `D-default` exists
- AND user "alice" is also a member of a real group (so `D-default` could in theory be tagged either way)
- WHEN she calls `GET /api/dashboards/visible`
- THEN `D-default` MUST appear in the response with `source: 'default'`
- AND MUST NOT appear with `source: 'group'`

### Requirement: REQ-DASH-013 Visible-to-user resolution

The system MUST expose `GET /api/dashboards/visible` that returns the union of three dashboard sets, deduplicated by UUID, in this priority order:

1. Personal `user`-type dashboards owned by the current user
2. `group_shared` dashboards whose `groupId` matches one of the user's Nextcloud groups
3. `group_shared` dashboards whose `groupId = 'default'`

Each returned dashboard MUST carry an additional `source` field with values `'user'`, `'group'`, or `'default'` so the frontend can route subsequent edits to the correct endpoint.

#### Scenario: Source field discriminates origin

- GIVEN user "alice" has 1 personal dashboard, 2 group-shared dashboards in groups she belongs to, and 1 default-group dashboard exists
- WHEN she calls `GET /api/dashboards/visible`
- THEN the response MUST contain 4 dashboards
- AND each MUST carry exactly one of `source: 'user' | 'group' | 'default'`
- AND the personal dashboard MUST have `source: 'user'`

#### Scenario: Deduplication by UUID

- GIVEN a group-shared dashboard exists where the user is a member of the targeted group AND that same dashboard's UUID also appears in another result set (rare edge case from a future multi-group support or a misconfigured fixture)
- WHEN she calls `GET /api/dashboards/visible`
- THEN it MUST appear only once in the response

#### Scenario: User with no personal dashboards still gets visible result

- GIVEN user "dave" has zero personal dashboards
- AND he is a member of group "engineering" which has 1 group-shared dashboard
- AND 1 default-group dashboard exists
- WHEN he calls `GET /api/dashboards/visible`
- THEN the response MUST contain 2 dashboards (the engineering one with `source='group'`, the default one with `source='default'`)

#### Scenario: User with no groups and no defaults gets only personal

- GIVEN user "eve" has 1 personal dashboard
- AND she belongs to no groups
- AND no default-group dashboards exist
- WHEN she calls `GET /api/dashboards/visible`
- THEN the response MUST contain exactly 1 dashboard with `source='user'`

#### Scenario: Admin gets group-shared dashboards as `source='group'` even though they own them

- GIVEN admin "root" created group-shared dashboard `D1` in group "marketing"
- AND admin "root" is a member of group "marketing"
- WHEN admin "root" calls `GET /api/dashboards/visible`
- THEN `D1` MUST appear with `source='group'` (not `source='user'`)
- NOTE: ownership of `group_shared` dashboards is admin-collective, not per-user — the `userId` column is null on these rows

### Requirement: REQ-DASH-014 Group-shared dashboard mutation endpoints

The system MUST expose CRUD endpoints scoped to a group:

- `GET /api/dashboards/group/{groupId}` — list group-shared dashboards in that group (any logged-in user can list)
- `POST /api/dashboards/group/{groupId}` — create a new one (admin only)
- `GET /api/dashboards/group/{groupId}/{uuid}` — get one (any logged-in user)
- `PUT /api/dashboards/group/{groupId}/{uuid}` — update name/layout/icon (admin only)
- `DELETE /api/dashboards/group/{groupId}/{uuid}` — remove (admin only)

#### Scenario: Update propagates immediately

- GIVEN admin updates the layout of group-shared dashboard `D1`
- WHEN any group member next loads the workspace page
- THEN the new layout MUST be served (no per-user copy interferes)

#### Scenario: Group-shared dashboard cannot be deleted while it is the last one in the group

- GIVEN group "marketing" has exactly one group-shared dashboard `D1`
- WHEN admin sends `DELETE /api/dashboards/group/marketing/D1.uuid`
- THEN the system MUST return HTTP 400 with `{error: 'Cannot delete the only dashboard in the group'}`
- NOTE: Personal dashboards do NOT have this guard — REQ-DASH-005 deletion remains unrestricted for `user`-type

#### Scenario: Default group is exempt from the last-in-group delete guard

- GIVEN the `default` group has exactly one group-shared dashboard `D-default`
- WHEN admin sends `DELETE /api/dashboards/group/default/D-default.uuid`
- THEN the system MUST delete the dashboard
- AND return HTTP 200
- NOTE: the default group is curated, not user-bound — admins can intentionally clear it

#### Scenario: Update on a group-shared dashboard rejects userId field changes

- GIVEN admin sends `PUT /api/dashboards/group/marketing/D1.uuid` with body `{"userId": "alice"}`
- THEN the system MUST ignore the `userId` field
- AND `D1.userId` MUST remain null
- AND `D1.type` MUST remain `'group_shared'`

#### Scenario: GroupId mismatch between path and record returns 404

- GIVEN dashboard `D1` has `groupId='marketing'`
- WHEN admin sends `GET /api/dashboards/group/engineering/D1.uuid`
- THEN the system MUST return HTTP 404 (the dashboard does not belong to the group named in the path)

#### Scenario: GET /api/dashboards remains backward-compatible

- GIVEN user "alice" has 2 personal dashboards
- AND admin has created 3 group-shared dashboards visible to her
- WHEN she sends `GET /api/dashboards` (the legacy listing endpoint)
- THEN the response MUST contain only her 2 personal dashboards
- AND MUST NOT contain any of the group-shared ones
- NOTE: clients wanting the union must call `GET /api/dashboards/visible`; this preserves REQ-DASH-002 semantics for older API consumers

#### Scenario: Group-shared dashboard serialisation includes `groupId`

- GIVEN a group-shared dashboard `D1` is returned via any endpoint
- WHEN the JSON payload is inspected
- THEN it MUST contain `groupId` equal to the dashboard's group ID (a non-null string)
- AND personal / admin_template dashboards in any payload MUST contain `groupId: null`
