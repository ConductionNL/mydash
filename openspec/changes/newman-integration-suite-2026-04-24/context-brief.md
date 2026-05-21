# Newman / Postman integration collection

Closes the ADR-008 `Newman/Postman collection` gap flagged in
[`docs/adr-audit.md`](../../../docs/adr-audit.md).

## Why

ADR-008 requires a Newman collection covering every OCS endpoint.
MyDash's 17 routes have no integration coverage today —
`tests/integration/` does not exist. The only end-to-end visibility
today is manual QA + CI PHPUnit.

`.github/workflows/code-quality.yml` already declares
`enable-newman: true` in its reusable-workflow call. The runner picks
up any `*.postman_collection.json` in `tests/integration/` — there's
just no file to run.

## Scope

Add `tests/integration/mydash.postman_collection.json` covering
**all 17 routes** across the 7 controllers:

- **Health + Metrics** (public / admin) — 2 endpoints
- **Dashboard API** — 6 endpoints (list, getActive, create, update,
  delete, activate)
- **Tile API** — 3+ endpoints (full CRUD shape TBD by audit of
  `TileApiController`)
- **Widget API** — 4 endpoints (addWidget, addTile, updateWidget,
  removeWidget)
- **Rule API** — 4 endpoints (list, create, update, delete)
- **Admin** — admin-only endpoints

Assertions follow the app-versions precedent:
- Happy-path status code + shape of the OCS envelope
- Admin-only endpoints: 403 for non-admin callers
- Destructive endpoints (`DELETE` / `POST /activate`) are exercised
  against a fresh fixture dashboard created in the same collection
  run, then cleaned up — no test pollution
- App-store / external-HTTP endpoints accept soft status codes
  (200/502/503) so CI without egress still passes the build

## Not in scope

- UI end-to-end tests (Playwright). That's ADR-008's E2E deliverable,
  a separate effort.
- Load / perf testing.
- Mutation testing.

## Acceptance

1. `tests/integration/mydash.postman_collection.json` exists with ≥ 17
   request definitions.
2. Each request has ≥ 2 assertions (status code + payload shape).
3. `tests/integration/README.md` documents the local-run command and
   env-placeholder credentials (`base_url`, `admin_user`,
   `admin_password`, `member_user`, `member_password`).
4. CI's `Code Quality → Integration Tests (Newman)` job runs green on
   the PR.



## Design

# Design — Newman integration collection

## Shape

One Postman 2.1 collection at
`tests/integration/mydash.postman_collection.json`. Top-level folders:

- `Health + Metrics`
- `Dashboards`
- `Tiles`
- `Widgets`
- `Rules`
- `Admin`

Each folder contains requests hitting one controller. Per-request
assertions live in `event[listen=test].script.exec[]`.

## Auth

HTTP basic over the OCS API. Collection-level `auth` uses
`{{admin_user}}` / `{{admin_password}}` variables. Requests that need
a non-admin caller override auth inline with `{{member_user}}` /
`{{member_password}}`.

Required OCS headers on every request:

```
OCS-APIRequest: true
Accept: application/json
```

## Test pollution strategy

The collection creates + tears down its own fixture dashboard. Order:

1. `Dashboards / Fixture setup` — `POST /api/dashboard` with a
   recognisable name (`newman-fixture-<timestamp>`). Captures the new
   dashboard id in a collection variable (`{{fixture_dashboard_id}}`).
2. All write tests target `{{fixture_dashboard_id}}` — never a pre-
   existing dashboard.
3. `Dashboards / Fixture teardown` — `DELETE /api/dashboard/{{fixture_dashboard_id}}`
   at the end of the folder.

If a test fails mid-run, the teardown still runs (Newman's `—
--bail` is NOT set). The fixture's timestamped name makes orphaned
fixtures easy to find with `newman-fixture-*` grep.

## Environment

`tests/integration/README.md` documents local run:

```bash
npm install -g newman
newman run tests/integration/mydash.postman_collection.json \
  --env-var base_url=http://nextcloud.local \
  --env-var admin_user=admin \
  --env-var admin_password=admin \
  --env-var member_user=regular \
  --env-var member_password=regular
```

CI passes `base_url=http://localhost:8080`.

## Assertion shape

Each request gets ≥ 2 assertions. Minimal pattern:

```javascript
pm.test('200 OK', () => pm.response.to.have.status(200));
pm.test('OCS envelope', () => {
    const json = pm.response.json();
    pm.expect(json).to.have.nested.property('ocs.meta.status');
    pm.expect(json.ocs.meta.status).to.equal('ok');
});
```

Forbidden / admin-gated endpoints get the mirror:

```javascript
pm.test('403 Forbidden for non-admin', () => pm.response.to.have.status(403));
pm.test('Error envelope', () => {
    const json = pm.response.json();
    pm.expect(json).to.have.property('error');
});
```

## CI wiring

`.github/workflows/code-quality.yml` already sets `enable-newman: true`.
The reusable quality workflow picks up any
`tests/integration/*.postman_collection.json` automatically. Soft
failures on network-dependent calls (app-store proxy) use
`pm.expect([200, 502, 503]).to.include(pm.response.code)` so CI
without egress passes the build.

## Risk

- **Tile / Widget / Rule shape drift**: the collection assertions
  match the payload shape as of 2026-04-24. If a request/response
  model changes (e.g., additional fields), the shape tests need to
  be updated alongside the code change. Each change proposal that
  modifies a controller should update this collection.
- **Admin role in CI**: the reusable workflow provisions an admin
  user by default. The member-level tests need a second user
  provisioned — design.md for the workflow may need an extension
  if this capability isn't already there.



## Tasks

# Tasks — Newman integration collection

## Task 1: Inventory every route

- [ ] From `appinfo/routes.php`, enumerate every route. Expected
  count: 17.
- [ ] Group by controller. For each route, note:
  - HTTP verb + path
  - Auth posture (admin-required vs user-required vs public)
  - Request body shape (from the controller's method signature +
    docblock)
  - Expected response shape

## Task 2: Write the collection

- [ ] Create `tests/integration/mydash.postman_collection.json`
  (Postman 2.1 schema) with collection-level basic auth using
  `{{admin_user}}` / `{{admin_password}}` variables.
- [ ] Six folders: `Health + Metrics`, `Dashboards`, `Tiles`,
  `Widgets`, `Rules`, `Admin`.
- [ ] Start with `Dashboards / Fixture setup` — POST /api/dashboard
  capturing `{{fixture_dashboard_id}}`.
- [ ] Add one request per route. Each request:
  - Sets `OCS-APIRequest: true` + `Accept: application/json`
  - Uses `{{fixture_dashboard_id}}` for any dashboard-scoped operations
  - Carries ≥ 2 test-event assertions per the shapes in design.md
- [ ] End with `Dashboards / Fixture teardown` — DELETE
  /api/dashboard/{{fixture_dashboard_id}}.

## Task 3: Member-vs-admin branch tests

- [ ] For every admin-gated endpoint, add a companion request under
  `Admin / Forbidden for members` that overrides auth to
  `{{member_user}}` + `{{member_password}}` and asserts 403.
- [ ] Member-level happy paths (dashboards they own) go in
  `Dashboards / Member happy path` — at least list + get + update
  on a member-owned fixture dashboard.

## Task 4: README

- [ ] Create `tests/integration/README.md` with:
  - Local-run command + env-var names
  - Fixture-cleanup note (teardown runs at end of Dashboards folder)
  - Pointer to `.github/workflows/code-quality.yml` for the CI wiring
  - Guidance: every PR touching a controller MUST update the matching
    request in this collection

## Task 5: CI verification

- [ ] Confirm `.github/workflows/code-quality.yml` already passes
  `enable-newman: true`. No wiring change needed if so.
- [ ] If the reusable quality workflow doesn't provision a second
  (member-level) user, add a preamble step that creates `regular` /
  `regular` before Newman runs. Alternatively, scope this change
  to admin-level tests only and defer member-branch coverage to a
  follow-up.
- [ ] Push + wait for the CI run. `Code Quality → Integration Tests
  (Newman)` must be green.

## Task 6: Docs

- [ ] Update `docs/adr-audit.md` — flip ADR-008 `Newman/Postman
  collection` row from ❌ to ✅.
- [ ] Remove the "Newman / Postman integration collection" item
  from `docs/adr-audit.md`'s follow-ups list.