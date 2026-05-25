# Design — retrofit-2026-05-24-dashboards

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Context

The `dashboards` capability has been live in production for many months and is well-specced at the user-facing level (REQ-DASH-001 .. REQ-DASH-037). During the 2026-05-24 coverage scan, two helper classes were flagged as Bucket 2a (capability-owned but no covering REQ):

- `lib/Controller/DashboardRequestValidator.php` — 4 methods extracted from `DashboardApiController` for testability and re-use
- `lib/Controller/ResponseHelper.php` — 5 static helpers that define the JSON envelope shape used across every dashboard endpoint

The methods are not aspirational — they ship today and are exercised by every dashboard create/update/list request. This retrofit captures the existing contract as 4 new REQs so future changes (e.g. tightening the nullable-logger path in `ResponseHelper::error()`) have a written contract to amend.

## Approach

For each of the 9 methods in the cluster:

1. Read inputs (parameters, class state)
2. Read outputs (return type, side effects — log writes, response shape)
3. Read preconditions (caller assumptions, type contracts)
4. Read postconditions (what the method guarantees)
5. Read failure modes (return-nulls, throws, silent-drops)

The 9 methods cluster into 4 distinct observable behaviours:

| REQ | Methods | Behaviour |
|---|---|---|
| REQ-DASH-038 | `checkCreatePermissions`, `checkUpdatePermissions` | Permission-gate that returns `JSONResponse` on deny or `null` on allow |
| REQ-DASH-039 | `resolveCreateParams`, `buildUpdateData` | Parameter resolution from heterogeneous request shapes; partial-update null-filtering |
| REQ-DASH-040 | `unauthorized`, `forbidden`, `success`, `serializeList` | Response envelope shape + HTTP status code contract |
| REQ-DASH-041 | `error` | Exception-to-response translation with no message leak (ADR-005) |

Granularity rationale: `checkCreatePermissions` + `checkUpdatePermissions` share the same observable behaviour (return 403 JSON on deny, null on allow). Collapsing them into REQ-DASH-038 keeps the REQ count honest. Similarly, the four envelope helpers (`unauthorized`/`forbidden`/`success`/`serializeList`) share one envelope contract. The error helper has a distinct security-relevant contract (no message leak) and gets its own REQ.

## Annotation strategy

Each method gets a single-line `@spec openspec/changes/retrofit-2026-05-24-dashboards/tasks.md#task-N` tag in its docblock, alongside any existing tags. The 2 files were not previously annotated.

## Notes — observed-but-suspicious

- `ResponseHelper::error()` accepts a nullable `LoggerInterface`. When omitted, the exception is silently swallowed. REQ-DASH-041 documents the observed behaviour and the spec's Notes section surfaces the future-tightening TODO.
- `serializeList()` has no defensive check — passing plain arrays fatals. Documented as a precondition.
- `DashboardRequestValidator` references `REQ-PERM-007` inline (in code comments) but no formal `@spec` tag — the new REQ owns the validator's contract; the permission rules themselves stay with `permissions` / `admin-settings`.

## Source

- Coverage report: `openspec/coverage-report.json` generated 2026-05-24
- Umbrella issue: ConductionNL/mydash#292
- Bucket: 2a (capability-owned, missing REQ)
