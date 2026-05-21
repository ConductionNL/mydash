# Design — Newman integration collection

## Context

MyDash exposes 17 OCS API routes across 7 controllers (Health + Metrics, Dashboards, Tiles, Widgets, Rules, Admin). Until now, these endpoints have only been tested via manual QA and unit-level PHPUnit tests — there is no end-to-end integration test suite. `.github/workflows/code-quality.yml` already wires up Newman via `enable-newman: true`, which means the CI infrastructure exists; we just need the collection file.

ADR-008 (company-wide testing standard) mandates that every new API endpoint gets a Newman/Postman collection entry with ≥ 2 assertions per request (status code + payload shape) and coverage of error paths (403, 401, 400). This change fulfi lls that requirement for MyDash's full endpoint surface.

## Goals / Non-Goals

**Goals:**

- Cover all 17 documented routes in `appinfo/routes.php` with ≥ 1 request per route.
- Each request has ≥ 2 assertions (status code + OCS envelope shape).
- Admin-only endpoints are tested for both happy path (admin caller) and forbidden path (member caller).
- Destructive endpoints (DELETE, POST /activate) exercise against a fresh fixture dashboard created and cleaned up within the collection run.
- Test execution passes in CI without network egress (soft status codes 200/502/503 for external HTTP calls).
- Local developer run is simple: install newman + run the documented command with 5 env-var placeholders.

**Non-Goals:**

- UI end-to-end testing (Playwright) — that's a separate ADR-008 deliverable.
- Load / perf testing — not in scope.
- Mutation testing — out of scope.
- Custom auth schemes (OAuth, SAML, etc.) — HTTP basic auth over OCS API.
- Inline fixture creation per-request — use single setup/teardown in the Dashboards folder for simplicity and to batch cleanup.

## Decisions

### D1: Fixture pattern — create once, use many, tear down once

**Decision:** Use a single fixture dashboard (created in `Dashboards / Fixture setup`) that is reused by all write operations (update, delete, activate) and torn down in `Dashboards / Fixture teardown` at the end of the Dashboards folder.

**Alternatives considered:**

- Create + tear down a fixture per request. Rejected — O(n) API calls for n requests, slows CI unnecessarily.
- Pre-create fixtures in the test environment setup step. Rejected — less obvious, harder to debug if cleanup fails, bleeds test state across runs.
- Run against prod dashboards (never delete). Rejected — pollutes the database with orphaned test data.

**Rationale:** Single setup/teardown is clear, efficient, and leaves the database clean. Teardown runs even if a test fails (no `--bail`), and the timestamped name (`newman-fixture-<timestamp>`) makes orphaned fixtures easy to grep if cleanup somehow doesn't run.

### D2: Collection-level admin auth + inline overrides for member tests

**Decision:** Set HTTP basic auth at the collection level using `{{admin_user}}` / `{{admin_password}}`. Requests testing non-admin access (e.g., member-forbidden paths) override the collection auth inline with `{{member_user}}` / `{{member_password}}`.

**Alternatives considered:**

- Separate collections for admin and member tests. Rejected — fragmented, harder to maintain, CI wiring becomes ambiguous.
- Use a pre-auth token endpoint. Rejected — OCS API uses HTTP basic auth as the standard; no need to complicate.

**Rationale:** Collection-level auth is the Postman idiom. Overriding per-request is clean and visible in the request UI.

### D3: Every request gets ≥ 2 assertions; error paths use soft status codes

**Decision:** Every request has ≥ 2 test assertions:
- Happy path: `pm.test('200 OK', ...)` + `pm.test('OCS envelope', ...)` checking `ocs.meta.status === 'ok'`
- Forbidden path: `pm.test('403 Forbidden', ...)` + `pm.test('Error envelope', ...)` checking for `error` key
- External HTTP calls: `pm.expect([200, 502, 503]).to.include(pm.response.code)` (soft codes)

**Alternatives considered:**

- Single assertion per request (status code only). Rejected — violates ADR-008 requirement.
- Nested payload validation (deep property checks). Rejected — overkill for integration tests; schema drifts incrementally and adding/removing optional fields is non-breaking.

**Rationale:** Status code + envelope shape catches both "endpoint works" and "response is valid OCS format". Soft codes allow CI without egress to pass (external services are not guaranteed available in the build environment).

### D4: Six folders grouped by controller, not by operation type

**Decision:** Structure the collection with folders per controller: `Health + Metrics`, `Dashboards`, `Tiles`, `Widgets`, `Rules`, `Admin`. Within each folder, requests are ordered by HTTP verb / operation (list, get, create, update, delete, activate).

**Alternatives considered:**

- Group by operation type (All GETs, All POSTs, All DELETEs). Rejected — mirrors the API structure poorly; makes it harder to find "all Dashboard endpoints" for audit.
- One flat folder with all 17 requests. Rejected — harder to navigate as the collection grows; fixture setup/teardown can't scope cleanly.

**Rationale:** Controller-based grouping mirrors the code structure (`DashboardApiController`, `TileApiController`, etc.) and makes it obvious when a controller changes that the corresponding folder's requests need audit.

### D5: Environment variables over hardcoded defaults

**Decision:** All credentials and the base URL are environment variables: `{{base_url}}`, `{{admin_user}}`, `{{admin_password}}`, `{{member_user}}`, `{{member_password}}`. No defaults are baked into the collection JSON.

**Alternatives considered:**

- Hardcoded defaults (admin/admin, base_url=http://localhost:8080). Rejected — CI needs the same collection file as local developers; no template needed.

**Rationale:** Developers run locally with their own credentials; CI provides different values via `--env-var`. Single collection file, multiple contexts.

## Risks / Trade-offs

- **Risk:** Tile / Widget / Rule shape drift — collection assertions match the payload shape as of 2026-04-24. If a request/response model changes, assertions break. → **Mitigation:** Documentation + code review enforcement: every PR touching a controller MUST update the matching request in the collection.
- **Risk:** Admin role provisioning in CI — the reusable workflow may not provision a second (member-level) user. → **Mitigation:** Task 5 checks this; if needed, preamble step creates member credentials before Newman runs. If not possible, scope this change to admin-level tests only and defer member-branch coverage to a follow-up.
- **Risk:** Fixture cleanup fails (e.g., network error mid-run). → **Mitigation:** Timestamped names make orphaned fixtures detectable; periodic cleanup job can grep for `newman-fixture-*` and delete stale ones (not in scope of this change).
- **Trade-off:** Soft status codes (200/502/503) mask real failures. → **Mitigation:** External HTTP calls are expected to be unreliable in CI; if an endpoint calls app-store, the test uses soft codes. Critical paths (Dashboard CRUD) use strict 200 assertion.

## Migration Plan

1. **Inventory routes** — Task 1 exhaustively lists all 17 endpoints from `appinfo/routes.php` with auth posture, request shape, response shape.
2. **Create collection** — Task 2 writes the skeleton: 6 folders, fixture setup, one request per route.
3. **Add assertions** — Task 3 polishes assertions and adds admin-gated + member tests.
4. **Create README** — Task 4 documents local run, env-var names, fixture cleanup, maintenance contract.
5. **Verify CI wiring** — Task 5 confirms `.github/workflows/code-quality.yml` is ready; adjusts if member-user provisioning is missing.
6. **Update audit** — Task 6 flips ADR-008 in `docs/adr-audit.md` from ❌ to ✅.
7. **Rollback:** Pure test artifact, no code changes. Removing the PR deletes the collection file; no schema migration, no data loss.

## Open Questions

- Should the collection include a "health check" request at the start that pings `/status` to fail fast if the app is down? Current decision: not needed; PHPUnit CI job already checks this. Can be added later if developers request it.
- Should fixture teardown use soft status codes (don't fail if already deleted)? Current decision: strict 200 + delete success envelope, so test failures are obvious. Cleanup is guaranteed by the fixture setup request capturing the new ID.
- Should member-level tests cover create/update/delete on dashboards they own? Current decision: defer to follow-up (Task 3 allows for this via a `Member happy path` subfolder, but first iteration focuses on admin-level coverage).
