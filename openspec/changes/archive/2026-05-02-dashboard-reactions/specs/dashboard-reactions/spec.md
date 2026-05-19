---
status: draft
---

# Dashboard Reactions Specification

## Purpose

Dashboard reactions enable lightweight social feedback via emoji on MyDash dashboards. Users can react with a configurable whitelist of emojis to mark dashboards as useful, appreciated, or funny, without requiring full-featured comments. Reactions are aggregated by emoji and visible to all viewers. An administrator can enable/disable reactions globally and per-dashboard, and can curate the allowed emoji list.

## Data Model

### oc_mydash_dashboard_reactions table

Each reaction is stored with the following fields:
- **id**: Auto-increment integer primary key
- **dashboardUuid**: VARCHAR(36) NOT NULL, foreign key reference to `oc_mydash_dashboards.uuid`
- **userId**: VARCHAR(64) NOT NULL, Nextcloud user ID of the reactor
- **emoji**: VARCHAR(32) NOT NULL, Unicode emoji string (e.g., `👍`, `❤️`, `🎉`)
- **reactedAt**: TIMESTAMP NOT NULL, timestamp when reaction was created

### Composite Constraints

- **Unique index** `(dashboardUuid, userId, emoji)` — one user can react with multiple distinct emojis to the same dashboard, but cannot duplicate the same emoji for the same dashboard
- **Index** `dashboardUuid` — fast lookups by dashboard
- **Index** `emoji` — fast lookups of reactors by emoji type

### oc_mydash_dashboards Extension

- **reactionsEnabled**: SMALLINT(0/1) NULL — per-dashboard toggle. NULL = follow global setting; 1 = reactions enabled; 0 = reactions disabled

### Admin Settings

- **mydash.reactions_enabled_default**: Boolean (default: true) — global toggle for reactions feature
- **mydash.reactions_allowed_emojis**: JSON array of strings (default: `["👍","❤️","🎉","😂","🤔","😢"]`) — whitelist of allowed emoji

## ADDED Requirements

### Requirement: REQ-RXN-001 Add Reaction

Users who can VIEW a dashboard MUST be able to add an emoji reaction to that dashboard.

#### Scenario: User adds a reaction to a dashboard they can view
- GIVEN a logged-in user "alice" who can view dashboard with UUID `dash-123`
- WHEN she sends `POST /api/dashboards/dash-123/reactions` with body `{"emoji": "👍"}`
- THEN the system MUST create a new DashboardReaction record with `dashboardUuid='dash-123'`, `userId='alice'`, `emoji='👍'`, and current timestamp
- AND the response MUST return HTTP 200 with the updated reactions summary `{counts: {"👍": 1, ...}, mine: ["👍"], enabled: true}`

#### Scenario: User cannot react to a dashboard they cannot view
- GIVEN a logged-in user "bob" who cannot VIEW dashboard with UUID `dash-456`
- WHEN he sends `POST /api/dashboards/dash-456/reactions` with body `{"emoji": "❤️"}`
- THEN the system MUST return HTTP 403 (permission denied)
- AND no reaction row MUST be created

#### Scenario: User attempts to react with non-whitelisted emoji
- GIVEN user "alice" can view dashboard `dash-123`
- AND the allowed emoji list is `["👍","❤️","🎉","😂","🤔","😢"]`
- WHEN she sends `POST /api/dashboards/dash-123/reactions` with body `{"emoji": "🚀"}`
- THEN the system MUST return HTTP 400 with error message "Emoji not allowed"
- AND no reaction row MUST be created

#### Scenario: User re-posts the same emoji (idempotent)
- GIVEN user "alice" has already reacted with `👍` to dashboard `dash-123`
- WHEN she sends `POST /api/dashboards/dash-123/reactions` with body `{"emoji": "👍"}` again
- THEN the system MUST return HTTP 200 with the same summary (no duplicate created, unique constraint prevents it)
- AND the count for `👍` MUST remain 1

#### Scenario: User can react with multiple distinct emojis to the same dashboard
- GIVEN user "alice" has already reacted with `👍` to dashboard `dash-123`
- WHEN she sends `POST /api/dashboards/dash-123/reactions` with body `{"emoji": "❤️"}`
- THEN the system MUST create a second reaction row `(dash-123, alice, ❤️)`
- AND the response MUST show `{counts: {"👍": 1, "❤️": 1, ...}, mine: ["👍", "❤️"], enabled: true}`

### Requirement: REQ-RXN-002 Remove Reaction

Users who have reacted to a dashboard MUST be able to remove their own reactions.

#### Scenario: User removes a reaction they made
- GIVEN user "alice" has reacted with `👍` to dashboard `dash-123`
- WHEN she sends `DELETE /api/dashboards/dash-123/reactions/👍`
- THEN the system MUST delete the reaction row `(dash-123, alice, 👍)`
- AND the response MUST return HTTP 204 (No Content)

#### Scenario: User attempts to remove a reaction they did not make
- GIVEN user "bob" has not reacted to dashboard `dash-123`
- WHEN he sends `DELETE /api/dashboards/dash-123/reactions/👍`
- THEN the system MUST return HTTP 204 (idempotent — delete of non-existent is not an error)
- AND no database error MUST be logged

#### Scenario: User cannot remove another user's reaction
- GIVEN user "alice" has reacted with `❤️` to dashboard `dash-123`
- WHEN user "bob" sends `DELETE /api/dashboards/dash-123/reactions/❤️`
- THEN if "bob" has NOT reacted with `❤️`, the delete is idempotent and returns 204 (per REQ-RXN-002 idempotency)
- NOTE: The DELETE endpoint is not authenticated per-reactor; it removes the calling user's reaction with that emoji. Removing other users' reactions is prevented by the unique constraint: only the calling user's row can be deleted.

### Requirement: REQ-RXN-003 Get Reactions Summary

Users viewing a dashboard MUST be able to see the aggregate reaction counts and their own reactions.

#### Scenario: User retrieves reactions on a dashboard they can view
- GIVEN dashboard `dash-123` has reactions: 3 users with `👍`, 1 user with `❤️`, 2 users with `🎉`
- AND user "alice" has reacted with `👍` and `🎉` to this dashboard
- WHEN she sends `GET /api/dashboards/dash-123/reactions`
- THEN the system MUST return HTTP 200 with body:
  ```json
  {
    "counts": {"👍": 3, "❤️": 1, "🎉": 2},
    "mine": ["👍", "🎉"],
    "enabled": true
  }
  ```

#### Scenario: User cannot view reactions if they cannot view the dashboard
- GIVEN user "bob" cannot VIEW dashboard `dash-456`
- WHEN he sends `GET /api/dashboards/dash-456/reactions`
- THEN the system MUST return HTTP 403 (permission denied)

#### Scenario: No reactions yet on a dashboard
- GIVEN dashboard `dash-789` has zero reactions
- WHEN user "alice" (who can view it) sends `GET /api/dashboards/dash-789/reactions`
- THEN the system MUST return HTTP 200 with body:
  ```json
  {
    "counts": {},
    "mine": [],
    "enabled": true
  }
  ```

#### Scenario: Reactions disabled on dashboard
- GIVEN dashboard `dash-999` has `reactionsEnabled = 0` (force off)
- AND it has 5 existing reactions in the database
- WHEN user "alice" sends `GET /api/dashboards/dash-999/reactions`
- THEN the system MUST return HTTP 200 with:
  ```json
  {
    "counts": {},
    "mine": [],
    "enabled": false
  }
  ```
- NOTE: Existing reactions are hidden (not deleted), but new reactions cannot be added (REQ-RXN-005)

### Requirement: REQ-RXN-004 List Reactors by Emoji

Users MUST be able to see which users have reacted with a specific emoji to a dashboard, with pagination.

#### Scenario: User retrieves reactors for a specific emoji
- GIVEN dashboard `dash-123` has 3 users who reacted with `👍`: alice, bob, carol (in chronological order)
- AND user "dave" can view dashboard `dash-123`
- WHEN dave sends `GET /api/dashboards/dash-123/reactions/👍/users`
- THEN the system MUST return HTTP 200 with body:
  ```json
  [
    {"userId": "alice", "displayName": "Alice Smith", "reactedAt": "2026-03-20 10:00:00"},
    {"userId": "bob", "displayName": "Bob Jones", "reactedAt": "2026-03-20 10:05:00"},
    {"userId": "carol", "displayName": "Carol Brown", "reactedAt": "2026-03-20 10:10:00"}
  ]
  ```

#### Scenario: Pagination with cursor
- GIVEN dashboard `dash-123` has 150 users who reacted with `🎉`
- WHEN user "alice" sends `GET /api/dashboards/dash-123/reactions/🎉/users` (first request, no cursor)
- THEN the system MUST return HTTP 200 with the first 100 results plus a `nextCursor` field (if more exist):
  ```json
  [
    {"userId": "user1", "displayName": "User 1", "reactedAt": "..."},
    ...
    {"userId": "user100", "displayName": "User 100", "reactedAt": "..."}
  ]
  ```
- AND the response header MUST include `Link: <...?cursor=xyz>; rel="next"` if more results exist

#### Scenario: Emoji with no reactors
- GIVEN dashboard `dash-123` has no reactions with emoji `😢`
- WHEN user "alice" (who can view `dash-123`) sends `GET /api/dashboards/dash-123/reactions/😢/users`
- THEN the system MUST return HTTP 200 with an empty array `[]`

#### Scenario: User cannot view reactors if they cannot view the dashboard
- GIVEN user "bob" cannot VIEW dashboard `dash-456`
- WHEN he sends `GET /api/dashboards/dash-456/reactions/👍/users`
- THEN the system MUST return HTTP 403

### Requirement: REQ-RXN-005 Global Reactions Toggle

Administrators MUST be able to enable or disable reactions for all dashboards at once via an admin setting.

#### Scenario: Admin disables reactions globally
- GIVEN the admin setting `mydash.reactions_enabled_default` is false
- WHEN user "alice" sends `GET /api/dashboards/dash-123/reactions`
- THEN the response MUST include `"enabled": false`
- AND any `POST /api/dashboards/dash-123/reactions` MUST return HTTP 403 with message "Reactions are disabled"

#### Scenario: Admin re-enables reactions globally
- GIVEN the admin setting `mydash.reactions_enabled_default` is true
- WHEN user "alice" sends `POST /api/dashboards/dash-123/reactions` with body `{"emoji": "👍"}`
- THEN the system MUST create the reaction and return HTTP 200
- NOTE: Existing reactions remain in the database; they are just hidden/disabled when the toggle is off

#### Scenario: Dashboards created before global toggle change inherit the toggle state
- GIVEN dashboard `dash-old` was created when `mydash.reactions_enabled_default` was true
- AND admin then sets `mydash.reactions_enabled_default` to false
- WHEN user "alice" sends `GET /api/dashboards/dash-old/reactions`
- THEN the response MUST include `"enabled": false` (the dashboard follows the new global setting, since its `reactionsEnabled` is NULL)

### Requirement: REQ-RXN-006 Per-Dashboard Reactions Toggle

Administrators MUST be able to enable or disable reactions on individual dashboards, overriding the global setting.

#### Scenario: Admin enables reactions on a dashboard that has global reactions disabled
- GIVEN global setting `mydash.reactions_enabled_default` is false
- AND dashboard `dash-123` has `reactionsEnabled = 1` (force on)
- WHEN user "alice" sends `GET /api/dashboards/dash-123/reactions`
- THEN the response MUST include `"enabled": true`
- AND user "alice" can POST reactions to this dashboard

#### Scenario: Admin disables reactions on a dashboard that has global reactions enabled
- GIVEN global setting `mydash.reactions_enabled_default` is true
- AND dashboard `dash-456` has `reactionsEnabled = 0` (force off)
- WHEN user "bob" sends `GET /api/dashboards/dash-456/reactions`
- THEN the response MUST include `"enabled": false`
- AND user "bob" cannot POST reactions (returns 403)

#### Scenario: Null reactionsEnabled field follows global setting
- GIVEN global setting `mydash.reactions_enabled_default` is true
- AND dashboard `dash-789` has `reactionsEnabled = NULL`
- WHEN user "carol" sends `GET /api/dashboards/dash-789/reactions`
- THEN the response MUST include `"enabled": true` (inherits global)

#### Scenario: Admin updates per-dashboard toggle
- GIVEN dashboard `dash-123` with `reactionsEnabled = 1`
- WHEN an admin updates it to `reactionsEnabled = 0`
- THEN user "alice" immediately sees `"enabled": false` on next GET request
- NOTE: Existing reactions remain in the database, hidden but not deleted

### Requirement: REQ-RXN-007 Allowed Emoji Whitelist

Administrators MUST be able to configure which emoji are allowed via an admin setting.

#### Scenario: Admin updates the allowed emoji list
- GIVEN the admin setting `mydash.reactions_allowed_emojis` is `["👍","❤️","🎉"]`
- WHEN user "alice" sends `POST /api/dashboards/dash-123/reactions` with body `{"emoji": "😂"}`
- THEN the system MUST return HTTP 400 with message "Emoji not allowed"

#### Scenario: Empty emoji in whitelist
- GIVEN the admin setting `mydash.reactions_allowed_emojis` is `[]`
- WHEN user "alice" sends `POST /api/dashboards/dash-123/reactions` with body `{"emoji": "👍"}`
- THEN the system MUST return HTTP 400 ("Emoji not allowed")
- AND GET reactions still works, returning counts and `enabled: true` (disabling via empty list is allowed, but typically the global toggle would be used)

#### Scenario: Default allowed emoji list
- GIVEN a fresh MyDash installation with no custom admin setting
- AND user "alice" sends `POST /api/dashboards/dash-123/reactions` with body `{"emoji": "👍"}`
- THEN the system MUST accept it and create the reaction
- AND the default list MUST be `["👍","❤️","🎉","😂","🤔","😢"]`

### Requirement: REQ-RXN-008 Permission Enforcement

Only users who can VIEW a dashboard MUST be able to react, retrieve reactions, or list reactors.

#### Scenario: User cannot react to a dashboard they cannot view
- GIVEN user "alice" does NOT have VIEW permission on dashboard `dash-secret`
- WHEN she sends `POST /api/dashboards/dash-secret/reactions` with any body
- THEN the system MUST return HTTP 403

#### Scenario: User in a group cannot react if group permissions revoked
- GIVEN a group-shared dashboard `dash-group` that user "bob" could view
- AND an admin revokes bob's group membership
- WHEN bob sends `POST /api/dashboards/dash-group/reactions`
- THEN the system MUST return HTTP 403 (permission check re-evaluated at request time)

#### Scenario: User with full permission can react
- GIVEN user "alice" is the owner of a personal dashboard `dash-123` (permissionLevel: full)
- WHEN she sends `POST /api/dashboards/dash-123/reactions`
- THEN the reaction MUST be created and return HTTP 200

### Requirement: REQ-RXN-009 Cascade Delete on Dashboard Deletion

When a dashboard is deleted, ALL reactions on that dashboard MUST be removed.

#### Scenario: Dashboard deletion cascades to reactions
- GIVEN dashboard `dash-123` has 10 reactions from 5 users
- WHEN an admin deletes the dashboard (via `DELETE /api/dashboard/dash-123`)
- THEN the system MUST delete all 10 reaction rows in `oc_mydash_dashboard_reactions` where `dashboardUuid = 'dash-123'`
- AND subsequent GET requests for that dashboard MUST return 404

#### Scenario: Cascade delete does not affect other dashboards' reactions
- GIVEN dashboard `dash-A` has 3 reactions and dashboard `dash-B` has 5 reactions
- WHEN the user deletes dashboard `dash-A`
- THEN the 3 reactions on `dash-A` MUST be deleted
- AND the 5 reactions on `dash-B` MUST remain unchanged

## Non-Functional Requirements

- **Performance**: `GET /api/dashboards/{uuid}/reactions` MUST return within 200ms. `GET /api/dashboards/{uuid}/reactions/{emoji}/users` with 100-item result MUST return within 300ms (pagination used if > 100).
- **Data integrity**: The unique constraint `(dashboardUuid, userId, emoji)` MUST be enforced at the database level to prevent duplicate reactions.
- **Idempotency**: POST add and DELETE remove MUST be fully idempotent (same request sent twice produces the same result without error).
- **Localization**: All error messages (`"Emoji not allowed"`, `"Reactions disabled"`, etc.) MUST support English and Dutch per i18n requirements.
- **Cascade safety**: Deleting a dashboard MUST reliably cascade-delete all reactions in the same transaction.

### Current Implementation Status

**Not yet implemented** — this is a new capability.
