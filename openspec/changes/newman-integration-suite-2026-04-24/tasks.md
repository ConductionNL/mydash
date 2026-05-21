# Tasks — newman-integration-suite-2026-04-24

## Task 1: Inventory every route

- [ ] From `appinfo/routes.php`, enumerate every route. Expected count: 17.
- [ ] Group by controller. For each route, note:
  - HTTP verb + path
  - Auth posture (admin-required vs user-required vs public)
  - Request body shape (from the controller's method signature + docblock)
  - Expected response shape
- [ ] Document findings in a scratch file or PR description for Task 2 reference.

## Task 2: Write the collection

- [ ] Create `tests/integration/mydash.postman_collection.json` (Postman 2.1 schema) with collection-level basic auth using `{{admin_user}}` / `{{admin_password}}` variables.
- [ ] Six folders: `Health + Metrics`, `Dashboards`, `Tiles`, `Widgets`, `Rules`, `Admin`.
- [ ] Start with `Dashboards / Fixture setup` — POST `/api/dashboard` with a recognisable name (`newman-fixture-<timestamp>`). Capture the new dashboard id in a collection variable (`{{fixture_dashboard_id}}`).
- [ ] Add one request per route (≥ 17 total). Each request:
  - Sets `OCS-APIRequest: true` + `Accept: application/json` headers
  - Uses `{{fixture_dashboard_id}}` for any dashboard-scoped operations
  - Carries ≥ 2 test-event assertions per the shapes in design.md
  - Happy path asserts status code (200 for success) + `ocs.meta.status === 'ok'`
  - Error paths assert status code (403 for forbidden) + error envelope presence
- [ ] End with `Dashboards / Fixture teardown` — DELETE `/api/dashboard/{{fixture_dashboard_id}}`.

## Task 3: Member-vs-admin branch tests

- [ ] For every admin-gated endpoint, add a companion request under `Admin / Forbidden for members` that overrides auth to `{{member_user}}` + `{{member_password}}` and asserts 403 + error envelope.
- [ ] Member-level happy paths (dashboards they own) go in `Dashboards / Member happy path` (optional for first iteration) — at least list + get + update on a member-owned fixture dashboard.
- [ ] Verify that soft status codes (200/502/503) are used for external HTTP calls (app-store proxy endpoints) so CI without egress passes.

## Task 4: README

- [ ] Create `tests/integration/README.md` with:
  - Local-run command with env-var names:
    ```bash
    npm install -g newman
    newman run tests/integration/mydash.postman_collection.json \
      --env-var base_url=http://nextcloud.local \
      --env-var admin_user=admin \
      --env-var admin_password=admin \
      --env-var member_user=regular \
      --env-var member_password=regular
    ```
  - Fixture-cleanup note: teardown runs at end of Dashboards folder even on test failure (--bail is NOT set).
  - Pointer to `.github/workflows/code-quality.yml` for the CI wiring.
  - **Maintenance contract:** "Every PR touching a controller MUST update the matching request in this collection. Shape drift (e.g., adding/removing response fields) breaks assertions."
  - Guidance on how to add new requests when new routes are added.

## Task 5: CI verification

- [ ] Confirm `.github/workflows/code-quality.yml` already passes `enable-newman: true`. No wiring change needed if so.
- [ ] If the reusable quality workflow doesn't provision a second (member-level) user, add a preamble step that creates `{{member_user}}` / `{{member_password}}` before Newman runs. Alternatively, scope this change to admin-level tests only and defer member-branch coverage to a follow-up (document the decision in the PR description).
- [ ] Verify the `Code Quality → Integration Tests (Newman)` CI job exists in the workflow.
- [ ] Push + wait for the CI run. The Newman job MUST exit 0 (green).

## Task 6: Docs

- [ ] Update `docs/adr-audit.md` — flip ADR-008 `Newman/Postman collection` row from ❌ to ✅.
- [ ] Remove the "Newman / Postman integration collection" item from `docs/adr-audit.md`'s follow-ups list.

## Verification

`openspec validate` exits clean. The collection file is valid Postman 2.1 JSON; local `newman run` with placeholders works; CI integration test job is green. No existing tests are broken.

## Tests (company-wide ADR-008)

Integration tests via Newman. No new unit tests required (collection is pure test artifact, not code).

## Documentation (company-wide ADR-009)

- Inline collection metadata (folder structure, fixture naming).
- `tests/integration/README.md` with local run + maintenance contract.
- Update `docs/adr-audit.md`.

## i18n (company-wide ADR-007)

No new user-facing strings expected. OCS envelope keys are hardcoded API contract.
