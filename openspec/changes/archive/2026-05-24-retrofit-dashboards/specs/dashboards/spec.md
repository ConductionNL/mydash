---
retrofit_extensions:
  - REQ-DASH-038
  - REQ-DASH-039
  - REQ-DASH-040
  - REQ-DASH-041
---

## ADDED Requirements

### REQ-DASH-038: Dashboard request validator permission gating

The system MUST centralise dashboard create + update permission checks in `DashboardRequestValidator`, returning a `JSONResponse` (with status 403 and a localised error message) when a request is denied and `null` when it is allowed. Update requests with `placements === null` are treated as metadata-only and require only `canEditDashboardMetadata`; requests carrying placements require the stricter `canEditDashboard`. Create requests additionally MUST fail with 403 when the user already owns at least one dashboard and `canHaveMultipleDashboards()` is false.

#### Scenario: Metadata-only update allowed for users with metadata-edit permission

- GIVEN a user with `canEditDashboardMetadata = true` but `canEditDashboard = false` on a dashboard
- WHEN `checkUpdatePermissions(userId, dashboardId, placements: null)` is called
- THEN it returns `null` (allowed) and the caller proceeds with the update

#### Scenario: Layout update blocked for users without full edit permission

- GIVEN a user with `canEditDashboardMetadata = true` but `canEditDashboard = false`
- WHEN `checkUpdatePermissions(userId, dashboardId, placements: [...])` is called with a non-null placements array
- THEN it returns a `JSONResponse` with status 403 and body `{ "error": "Access denied" }`

#### Scenario: Multiple-dashboard creation blocked when admin setting is off

- GIVEN a user who already owns at least one dashboard
- AND the admin setting `canHaveMultipleDashboards(userId)` is false
- WHEN `checkCreatePermissions(userId)` is called
- THEN it returns a `JSONResponse` with status 403 and body `{ "error": "Multiple dashboards not allowed" }` (localised)

### REQ-DASH-039: Dashboard create-request parameter resolution

The system MUST accept dashboard creation parameters from EITHER a structured JSON body (a single `$name` parameter that is an array containing `name` and `description` keys) OR individual scalar parameters (`$name: string|null`, `$description: string|null`). When the structured-body form is used, missing `name` defaults to `'My Dashboard'` (untranslated) and missing `description` defaults to `null`. When the scalar form is used, a null name defaults to `$l10n->t('My Dashboard')` (translated). The update form MUST filter out null fields before persistence so that PATCH-style partial updates do not overwrite existing values.

#### Scenario: Resolve create params from JSON body

- GIVEN the request body parses to `['name' => 'Sales', 'description' => 'Q1 dashboard']`
- WHEN `resolveCreateParams($body, null)` is called
- THEN it returns `['name' => 'Sales', 'description' => 'Q1 dashboard']`

#### Scenario: Resolve create params from scalar inputs

- GIVEN `$name = null` and `$description = null`
- WHEN `resolveCreateParams(null, null)` is called
- THEN it returns `['name' => $l10n->t('My Dashboard'), 'description' => null]`

#### Scenario: Partial update preserves untouched fields

- GIVEN an existing dashboard with name `'A'`, description `'B'`, placements `[…]`
- WHEN `buildUpdateData(name: 'Renamed', description: null, placements: null)` is called
- THEN it returns `['name' => 'Renamed']` (description and placements are filtered out as nulls)

### REQ-DASH-040: Dashboard response envelope shape and status codes

The system MUST return every dashboard API response as a `JSONResponse` built through `ResponseHelper`. The envelope contract is:

- Success responses use `{...payload...}` with status 200 (default) or a caller-specified 2xx code
- Unauthorized responses use `{ "error": "Not logged in" }` with status 401
- Forbidden responses use `{ "error": <message> }` with status 403 (default message: `"Access denied"`)
- Error responses use `{ "error": <generic-message> }` with status 400 (or caller-specified)
- List responses MUST be serialized via `ResponseHelper::serializeList()` which calls `jsonSerialize()` on each element

#### Scenario: Unauthorized helper returns canonical 401

- GIVEN no authenticated user
- WHEN a controller calls `ResponseHelper::unauthorized()`
- THEN the response body is `{ "error": "Not logged in" }` with HTTP status 401

#### Scenario: Forbidden helper accepts custom message

- WHEN a controller calls `ResponseHelper::forbidden('Dashboard creation not allowed')`
- THEN the response body is `{ "error": "Dashboard creation not allowed" }` with HTTP status 403

#### Scenario: Success helper accepts custom status

- WHEN a controller calls `ResponseHelper::success(['id' => 1], 201)`
- THEN the response body is `{ "id": 1 }` with HTTP status 201

#### Scenario: Serialize list of dashboards

- GIVEN an array of three `Dashboard` entities (each implementing `JsonSerializable`)
- WHEN a controller calls `ResponseHelper::serializeList($dashboards)`
- THEN it returns an array of three associative arrays, each the result of calling `jsonSerialize()` on the corresponding entity

### REQ-DASH-041: Exception-to-error response without message leak

The system MUST translate caught exceptions into JSON error responses via `ResponseHelper::error()` without ever exposing the raw exception message to the client (ADR-005). Callers SHOULD pass a `LoggerInterface` so the real exception (message + stack) is recorded at ERROR level on the server; the client always receives a generic `$message` (default `"Operation failed"`) and the caller-specified HTTP status (default 400).

#### Scenario: Caught exception is logged and a generic error is returned

- GIVEN a controller catches `\RuntimeException('Database constraint X violated')`
- AND the controller has an injected `LoggerInterface`
- WHEN the controller calls `ResponseHelper::error($e, 500, $logger, 'Could not save dashboard')`
- THEN the logger records the raw `'Database constraint X violated'` message at ERROR level with the exception in the context
- AND the response body is `{ "error": "Could not save dashboard" }` with HTTP status 500

#### Scenario: Logger omitted — exception is silently swallowed

- GIVEN a controller catches `\RuntimeException('secret')`
- WHEN the controller calls `ResponseHelper::error($e, 400, null, 'Operation failed')`
- THEN the response body is `{ "error": "Operation failed" }` with HTTP status 400
- AND no log entry is written (observed behaviour — see Notes)

#### Notes

- The "logger optional" path in `ResponseHelper::error()` is a latent risk: a caller that forgets to wire the logger gets silently dropped exceptions with no audit trail. Future-tightening TODO: make `LoggerInterface` non-nullable in a follow-up change once every call site is converted.
- `serializeList()` assumes every element implements `jsonSerialize()` — passing plain arrays results in a fatal `Error: Call to a member function jsonSerialize() on array`. This is a precondition, not a defensive check.
