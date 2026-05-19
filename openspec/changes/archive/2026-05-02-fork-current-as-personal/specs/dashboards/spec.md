---
capability: dashboards
delta: true
status: draft
---

# Dashboards — Delta from change `fork-current-as-personal`

## ADDED Requirements

### Requirement: REQ-DASH-020 Fork any visible dashboard as a personal copy

The system MUST expose `POST /api/dashboards/{uuid}/fork` that creates a new `user`-type dashboard owned by the calling user, deep-copying all widget placements from the source. The new dashboard MUST become the user's active dashboard. Forking MUST be gated on the admin setting `allow_user_dashboards = '1'`; otherwise the endpoint MUST return HTTP 403.

#### Scenario: Fork a group-shared dashboard

- GIVEN admin setting `allow_user_dashboards = '1'`
- AND user "alice" can read group-shared dashboard `S` (groupId='marketing') containing 4 widget placements
- WHEN she sends `POST /api/dashboards/{S.uuid}/fork` with body `{"name": "My Marketing"}`
- THEN the system MUST create a new dashboard `F` with `userId = 'alice'`, `type = 'user'`, `groupId = null`, `isDefault = 0`, `isActive = 1` (and all other alice-owned dashboards deactivated), `gridColumns = S.gridColumns`, and `name = "My Marketing"`
- AND `F` MUST contain 4 widget placements that are byte-for-byte clones of `S`'s placements (same gridX/Y/W/H, customTitle, styleConfig, tile fields) with new placement IDs and `dashboardId = F.id`
- AND `S` MUST remain unchanged
- AND the response MUST be HTTP 201 with the full `F` payload

#### Scenario: Fork uses a default name when none provided

- GIVEN dashboard `S` has `name = "Marketing Overview"`
- WHEN alice sends `POST /api/dashboards/{S.uuid}/fork` with empty body
- THEN the new dashboard's `name` MUST be `"My copy of Marketing Overview"` (translated string `t('My copy of {name}', {name: S.name})`)

#### Scenario: Fork is gated on admin setting

- GIVEN admin setting `allow_user_dashboards = '0'`
- WHEN alice sends `POST /api/dashboards/{S.uuid}/fork` with any body
- THEN the system MUST return HTTP 403 with `{error: 'Personal dashboards are not enabled'}`

#### Scenario: Cannot fork a dashboard you cannot read

- GIVEN group-shared dashboard `T` exists in group "executives" and alice does NOT belong to that group
- WHEN alice sends `POST /api/dashboards/{T.uuid}/fork`
- THEN the system MUST return HTTP 404 (do not leak existence)

#### Scenario: Forking a personal dashboard creates an independent duplicate

- GIVEN alice already has personal dashboard `P` with 2 placements
- WHEN she sends `POST /api/dashboards/{P.uuid}/fork`
- THEN the system MUST create a new dashboard `P2` that is a deep clone of `P`
- AND mutating `P2` MUST NOT affect `P` (and vice versa)

### Requirement: REQ-DASH-021 Fork is transactional

The fork operation MUST execute inside a single database transaction. If any part fails (placement insert, deactivation of other dashboards, etc.) the entire fork MUST be rolled back and HTTP 500 returned.

#### Scenario: Partial-failure rollback

- GIVEN any database error occurs while inserting cloned placements
- WHEN the fork endpoint catches the error
- THEN the new dashboard row MUST also be removed (transaction rolled back)
- AND `S`'s placements MUST remain visible to alice
- AND alice's previously active dashboard (if any) MUST remain active

### Requirement: REQ-DASH-022 Fork does not duplicate uploaded resources

When cloned placements reference uploaded resources (e.g. `tileIcon` URLs starting with `/apps/mydash/resource/...`, or widget content fields with similar URLs), the fork MUST keep the same URL — it MUST NOT duplicate the underlying resource bytes. Both dashboards then reference the shared resource record.

#### Scenario: Shared resource reference

- GIVEN dashboard `S` has a tile placement with `tileIcon = '/apps/mydash/resource/abc123.png'`
- WHEN alice forks `S`
- THEN `F`'s corresponding placement MUST have `tileIcon = '/apps/mydash/resource/abc123.png'` (same URL)
- AND no new file MUST be created in app data
