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
