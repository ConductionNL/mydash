# Spec — Read-Only Scope Enforcement (REQ-EMB-004)

## REQ-EMB-004 — Read-only enforcement at the API tier

The system SHALL reject any write operation (POST/PUT/PATCH/DELETE) issued under an `embed_token` whose `scope.mode=read`, even when the operation would otherwise be permitted by the subject's ACL. This enforcement happens on the API tier before any state mutation.

### Scenario 4.1 — Read-mode token rejects write operation

GIVEN an `embed_token` with `scope.mode="read"` for dashboard D
  AND an authenticated user holds this token (via JWT in Authorization or query parameter)
  AND the user (or JavaScript running in the iframe) attempts a POST to add a comment on dashboard D
WHEN the SDK or frontend invokes `POST /api/dashboards/D/comments` with a comment payload
THEN the API SHALL respond 403 with body:
  ```json
  {
    "error": "read_only_token",
    "message": "This token does not permit write operations. Token scope: mode=read"
  }
  ```
  AND NO state SHALL be mutated (no comment created, no audit log entry)
  AND a `embed_usage_event` SHALL be written with `eventType: "pageView"`, `responseStatusCode: 403`

### Scenario 4.2 — Read-mode token rejects DELETE

GIVEN the same read-only token for dashboard D
WHEN the frontend attempts `DELETE /api/dashboards/D/widgets/w1` (remove a widget from the dashboard)
THEN the API SHALL respond 403 with body:
  ```json
  {
    "error": "read_only_token",
    "message": "This token does not permit write operations. Token scope: mode=read"
  }
  ```
  AND NO state mutation occurs

### Scenario 4.3 — Read-mode token permits GET and HEAD

GIVEN the same read-only token
WHEN the frontend invokes `GET /api/dashboards/D` (read operation)
THEN the API SHALL respond 200 with the dashboard data
  AND read permission is determined by the subject-match (the token is scoped to dashboard D)
  AND no scope-mode check blocks the read

### Scenario 4.4 — Read-with-interactions token permits scoped actions

GIVEN an `embed_token` with `scope.mode="read-with-interactions", allowedActions=["interact", "drillDown"]` for dashboard D
  AND the dashboard has a filter UI
WHEN the SDK applies an in-bus filter clause (drill-down), invoking `POST /api/dashboards/D/filters` with `{dimension: "gemeente", value: "Amsterdam"}`
THEN the API SHALL respond 200 and process the filter
  AND the operation SHALL be captured in an `embed_usage_event` with `eventType: "filterApplied"`
  AND subsequent `GET` calls reflect the pinned filter

### Scenario 4.5 — Read-with-interactions token rejects disallowed action

GIVEN an `embed_token` with `scope.mode="read-with-interactions", allowedActions=["interact", "drillDown"]` (export NOT allowed)
WHEN the frontend attempts `POST /api/dashboards/D/export` with `format: "csv"`
THEN the API SHALL respond 403 with body:
  ```json
  {
    "error": "action_not_in_scope",
    "message": "The action 'export' is not permitted by this token. Allowed actions: interact, drillDown"
  }
  ```
  AND NO export is generated

### Scenario 4.6 — All write operations checked, regardless of subject type

GIVEN a read-only token for a widget W
WHEN any of the following write operations are attempted on the widget:
  - `POST /api/widgets/W/interactions` (add interaction)
  - `PUT /api/widgets/W/settings` (update widget settings)
  - `PATCH /api/widgets/W` (partial update)
THEN ALL SHALL respond 403 with the read-only error
  AND the API does NOT discriminate by operation type; ALL non-GET/HEAD are blocked

### Scenario 4.7 — Scope enforcement independent of frontend state

GIVEN a read-only token embedded in an iframe
WHEN the JavaScript in the iframe is patched (via browser DevTools) to remove the UI-layer read-only enforcement
  AND the patched JS attempts `POST /api/dashboards/D/widgets/new`
THEN the API-tier check SHALL still respond 403
  AND the tampered frontend cannot bypass the API check

### Scenario 4.8 — Scope metadata visible in token detail view

GIVEN an admin viewing an embed token's details
WHEN they inspect the token's scope
THEN the admin UI SHALL display:
  ```
  Mode: read
  Allowed Filters: status, periode
  Allowed Actions: (none)
  ```
  AND when creating a read-with-interactions token, the admin SHALL see checkboxes for each available action (interact, export, drillDown) to select

### Scenario 4.9 — Filter context pinning persisted with token

GIVEN an `embed_token` for dashboard D with `subject.filterContext={gemeente: "Amsterdam"}`
WHEN the embed is rendered
  AND the user attempts to change the gemeente filter to "Rotterdam" via the UI
THEN the filter interaction SHALL succeed (read-with-interactions mode allows it)
  BUT the filter SHALL NOT persist across page reloads — the next page load resets the gemeente filter to "Amsterdam"
  AND the filter context is enforced by the render route, not by the frontend

### Scenario 4.10 — API middleware integration point

GIVEN the MyDash API middleware/controller base
WHEN a request arrives with an `embed_token` (detected via JWT in Authorization header or token context)
THEN the middleware SHALL:
  1. Verify the token is valid and non-revoked (REQ-EMB-002)
  2. Extract the token's scope
  3. For non-GET/HEAD requests, check if `scope.mode=read` and respond 403 before the controller is invoked
  4. For GET/HEAD requests, proceed to the controller (the subject-match verification happens per-endpoint)
  5. For actions with allowedActions restrictions, check the action name against the allowed set before invoking the handler
