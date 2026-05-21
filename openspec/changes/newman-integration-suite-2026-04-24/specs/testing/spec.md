---
capability: testing
delta: true
status: draft
---

# Testing — Delta from change `newman-integration-suite-2026-04-24`

## MODIFIED Requirements

### Requirement: REQ-TST-008 Integration Test Coverage via Newman/Postman

The application MUST have a Newman/Postman collection file covering every OCS API endpoint with integration-level assertions.

**Required elements:**

1. **Collection file** — `tests/integration/mydash.postman_collection.json` (Postman 2.1 schema) containing ≥ 17 request definitions (one per documented route in `appinfo/routes.php`).
2. **Folders by controller** — requests MUST be organized into folders per controller: `Health + Metrics`, `Dashboards`, `Tiles`, `Widgets`, `Rules`, `Admin`.
3. **Fixture isolation** — destructive operations (DELETE, POST /activate) MUST use a fresh fixture dashboard created in the collection setup and cleaned up in the teardown. The fixture dashboard MUST have a timestamped name (`newman-fixture-<ISO8601>`).
4. **Auth patterns** — collection MUST use environment variable placeholders for credentials (`{{base_url}}`, `{{admin_user}}`, `{{admin_password}}`, `{{member_user}}`, `{{member_password}}`). No hardcoded defaults.
5. **Assertions** — every request MUST have ≥ 2 test assertions:
   - Happy path: status code + OCS envelope shape validation
   - Forbidden path (admin-only endpoints): 403 status + error envelope validation
   - External HTTP calls: accept soft status codes (200/502/503)
6. **OCS headers** — every request MUST include `OCS-APIRequest: true` and `Accept: application/json` headers.
7. **Documentation** — `tests/integration/README.md` MUST document:
   - Local run command with env-var names
   - Fixture cleanup guarantees (teardown runs even on test failure)
   - Maintenance contract (every controller change MUST update the matching request)
   - Pointer to CI wiring in `.github/workflows/code-quality.yml`

#### Scenario: Collection covers all 17 routes

- **GIVEN** the file `tests/integration/mydash.postman_collection.json` exists
- **WHEN** the collection is introspected for request definitions
- **THEN** it MUST contain ≥ 17 requests (one per route from `appinfo/routes.php`)
- **AND** each request MUST map to exactly one controller method

#### Scenario: Fixture setup creates + captures dashboard ID

- **GIVEN** a Newman run with env vars `base_url`, `admin_user`, `admin_password` set
- **WHEN** the `Dashboards / Fixture setup` request executes
- **THEN** it MUST POST to `/api/dashboard` with a recognisable name (`newman-fixture-<timestamp>`)
- **AND** the response MUST be captured in a collection variable `{{fixture_dashboard_id}}`
- **AND** the response MUST have OCS status `ok`

#### Scenario: All write operations target the fixture dashboard

- **GIVEN** the fixture setup has captured `{{fixture_dashboard_id}}`
- **WHEN** any destructive request executes (DELETE, PUT, POST /activate)
- **THEN** the request URL MUST include `{{fixture_dashboard_id}}` as the target
- **AND** the request MUST NOT modify any pre-existing (non-fixture) dashboard

#### Scenario: Fixture teardown cleans up even on test failure

- **GIVEN** a Newman run with `--bail` NOT set (bail is disabled)
- **WHEN** any test in the Dashboards folder fails
- **THEN** the `Dashboards / Fixture teardown` request MUST still execute
- **AND** it MUST DELETE `/api/dashboard/{{fixture_dashboard_id}}`
- **AND** the fixture dashboard MUST be removed from the app

#### Scenario: Every request has ≥ 2 assertions

- **GIVEN** any request in the collection
- **WHEN** the collection is executed
- **THEN** the request's test event (`listen=test`) MUST have ≥ 2 assertions in `event.script.exec[]`
- **AND** assertions MUST verify both HTTP status AND response structure (not just status code)

#### Scenario: Admin-only endpoints tested for forbidden access

- **GIVEN** an endpoint documented as admin-only in `appinfo/routes.php`
- **WHEN** the collection includes a companion request that overrides auth to `{{member_user}}` / `{{member_password}}`
- **THEN** the request MUST assert a 403 Forbidden response
- **AND** the response MUST include an error envelope (not OCS `ok` status)

#### Scenario: Environment variables are used, no hardcoded credentials

- **GIVEN** the collection file `tests/integration/mydash.postman_collection.json`
- **WHEN** the JSON is inspected for auth blocks and request bodies
- **THEN** credentials MUST be referenced as `{{admin_user}}`, `{{admin_password}}`, `{{member_user}}`, `{{member_password}}`
- **AND** no plaintext strings like `admin`, `regular`, `password` MUST appear in the collection JSON
- **AND** the base URL MUST be `{{base_url}}` (not hardcoded http://localhost, http://nextcloud.local, etc.)

#### Scenario: CI integration test job runs green

- **GIVEN** `.github/workflows/code-quality.yml` declares `enable-newman: true`
- **WHEN** a PR is opened with the `tests/integration/mydash.postman_collection.json` file
- **THEN** the reusable quality workflow MUST auto-detect the collection file
- **AND** the `Code Quality → Integration Tests (Newman)` CI job MUST run and exit 0 (green)
- **AND** all test assertions in the collection MUST pass in CI

## See Also

- [ADR-008: Testing](../../.claude/openspec/architecture/adr-008-testing.md) — company-wide integration test standard
- `docs/adr-audit.md` — tracks ADR-008 completion status
