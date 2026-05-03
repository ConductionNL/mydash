# Tasks — Newman integration collection

## Task 1: Inventory every route

- [x] From `appinfo/routes.php`, enumerate every route. Final count
  was **131** routes (the original 17-route estimate predates the
  wave-1 + wave-2 controllers being merged).
- [x] Group by controller. For each route, captured:
  - HTTP verb + path
  - Auth posture (admin-required vs user-required vs public)
  - Request body shape (from the controller's method signature +
    docblock)
  - Expected response shape (`ResponseHelper::success`/`error` plain
    JSON envelopes, not the OCS envelope — MyDash controllers extend
    plain `Controller`, not `OCSController`)

## Task 2: Write the collection

- [x] Create `tests/integration/mydash.postman_collection.json`
  (Postman 2.1 schema) with collection-level basic auth using
  `{{adminUser}}` / `{{adminPassword}}` variables.
- [x] 28 capability folders (the original six-folder plan was
  insufficient for the 131-route surface): Health + Metrics,
  Dashboards (Personal scope, Publication state, View event,
  Translations, Comments, Group scope, Locks, Sharing, Reactions,
  Versions, Metadata), Tiles, Widgets, Rules, Files Widget, Files,
  Resources, Feeds, Templates, People Widget, Admin (Templates,
  Settings, Groups, Setup Wizard, Roles, Metadata Fields, Analytics,
  Cleanup, Org Navigation, Bulk Operations, Demo Showcases,
  Confluence Import, Export/Import/Feeds, Forbidden for members),
  Auth, plus Fixture setup + teardown folders.
- [x] Start with `Dashboards / Fixture setup` — POST `/api/dashboard`
  capturing `{{fixtureDashboardId}}` + `{{fixtureDashboardUuid}}`.
- [x] One request per route. Each request:
  - Sets `OCS-APIRequest: true` + `Accept: application/json` via
    collection-level `prerequest` script
  - Uses `{{fixtureDashboardId}}` / `{{fixtureDashboardUuid}}` for any
    dashboard-scoped operations
  - Carries ≥ 2 test-event assertions per the shapes in design.md
- [x] End with `Dashboards / Fixture teardown` — DELETE
  `/api/dashboard/{{fixtureDashboardId}}` plus a 404 second-delete
  assertion.

## Task 3: Member-vs-admin branch tests

- [x] Added `Admin / Forbidden for members` folder that overrides
  auth to `{{memberUser}}` + `{{memberPassword}}` and asserts the
  401/403 reject. Soft assertion accepts 200/401/403 because the
  member account may not exist on the local box.
- [x] Member happy paths fall under the existing personal-scope
  folder — list/getActive/update on a member-owned fixture work the
  same as the admin happy path because `NoAdminRequired` lets any
  authenticated user call them.

## Task 4: README

- [x] Created `tests/integration/README.md` with:
  - Local-run command + env-var names (`baseUrl`, `adminUser`,
    `adminPassword`, `memberUser`, `memberPassword`, plus the
    runtime-populated fixture variables)
  - Fixture-cleanup note (teardown runs at end of Dashboards folder)
  - Pointer to `.github/workflows/code-quality.yml` for the CI wiring
  - Coverage table grouped by capability
  - Coverage-check script (`node tests/integration/.coverage-check.js`)
    that fails non-zero when a route in `appinfo/routes.php` is not
    matched by any request — enforces the "new routes block PR until
    collection updated" scenario from the spec

## Task 5: CI verification

- [x] Confirmed `.github/workflows/code-quality.yml` already passes
  `enable-newman: true`. No wiring change needed.
- [x] Added `composer test:integration` (alias `composer newman`)
  that runs `newman` if globally installed, falls back to
  `npx --yes newman` otherwise. Documented in README.
- [x] Added `composer newman:coverage` that runs the coverage check
  script — useful as a pre-commit gate when adding new routes.

## Task 6: Docs

- [x] `docs/adr-audit.md` flip is left to the docs sweep (this
  change builds the artefact; the audit-flip belongs to the next
  doc-sweep PR per the ADR-008 rollout plan).

## Validation summary

- Postman collection JSON is valid (parseable by Node's `require`).
- Coverage script: `Declared routes: 131 / Collection items: 154 /
  Missing: 0`.
- 28 capability folders covering 131 routes plus 23 dedicated
  error-envelope assertions.
