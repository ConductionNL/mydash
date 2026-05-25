# Retrofit — dashboards (Bucket 2a)

Describes observed behavior of 9 methods across 2 controller-helper files under the `dashboards` capability as 4 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Controller/DashboardRequestValidator.php::checkCreatePermissions()`
- `lib/Controller/DashboardRequestValidator.php::checkUpdatePermissions()`
- `lib/Controller/DashboardRequestValidator.php::resolveCreateParams()`
- `lib/Controller/DashboardRequestValidator.php::buildUpdateData()`
- `lib/Controller/ResponseHelper.php::unauthorized()`
- `lib/Controller/ResponseHelper.php::forbidden()`
- `lib/Controller/ResponseHelper.php::error()`
- `lib/Controller/ResponseHelper.php::success()`
- `lib/Controller/ResponseHelper.php::serializeList()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces any observed-but-suspicious behavior

The existing `dashboards` spec covers end-user dashboard behaviour (REQ-DASH-001 .. REQ-DASH-037) but does not describe two helper classes that were extracted from controllers for testability and re-use:

1. **`DashboardRequestValidator`** centralises permission checks and parameter resolution for the create/update endpoints. The existing CRUD requirements (REQ-DASH-001/004) describe the *user-facing* outcome but not the validator's contract (e.g. "metadata-only updates require `canEditDashboardMetadata`, layout updates require `canEditDashboard`").
2. **`ResponseHelper`** is the canonical JSON-envelope shape used across every dashboard endpoint. The existing spec describes payloads but not the envelope/status-code contract or the rule that exception messages are never returned to the client (ADR-005).

This retrofit adds:
- REQ-DASH-038 — Dashboard request validator: permission gating for create + update
- REQ-DASH-039 — Dashboard request parameter resolution (JSON body or scalar params)
- REQ-DASH-040 — Dashboard response envelope shape and status codes
- REQ-DASH-041 — Exception-to-error response (no exception message leak)

## Notes

- `DashboardRequestValidator::checkCreatePermissions()` references `REQ-PERM-007` and `REQ-ASET-002`-style behaviours (multiple-dashboards setting) in code comments but no formal `@spec` tag exists. The new REQ-DASH-038 owns the validator contract; the underlying permission rules stay with `permissions` / `admin-settings`.
- `ResponseHelper::error()` `$logger` parameter is nullable — when omitted, the exception is silently swallowed and the client only sees the generic message. The REQ documents the observed behaviour and surfaces this as a TODO (callers SHOULD always pass a logger).
- `ResponseHelper::serializeList()` assumes every element implements `jsonSerialize()` and will fatal otherwise — the REQ documents the precondition.

Source: `openspec/coverage-report.md` generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
