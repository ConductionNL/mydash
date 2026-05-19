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
