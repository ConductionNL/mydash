# Newman / Postman integration collection

Closes the ADR-008 `Newman/Postman collection` gap flagged in [`docs/adr-audit.md`](../../../docs/adr-audit.md). MyDash has 17 OCS API routes across 7 controllers with no integration test coverage — this change adds a Postman collection covering all endpoints with assertions for happy path, error handling, and fixture isolation.

## Affected code units

- **New:** `tests/integration/mydash.postman_collection.json` — Postman 2.1 collection with 17+ request definitions across 6 folders
- **New:** `tests/integration/README.md` — documentation for local run and environment setup
- **Modified:** `docs/adr-audit.md` — flip ADR-008 `Newman/Postman collection` from ❌ to ✅

## Why a delta

ADR-008 requires a Newman collection covering every OCS endpoint. Today, `tests/integration/` does not exist and the only end-to-end visibility is manual QA + CI PHPUnit. `.github/workflows/code-quality.yml` already declares `enable-newman: true` in its reusable-workflow call, but there's no collection file to run. This change fulfi lls the ADR-008 requirement by providing comprehensive integration test coverage with fixture-based isolation, multi-user auth scenarios, and assertions on both happy path and error cases.

## Approach

- **One collection** at `tests/integration/mydash.postman_collection.json` (Postman 2.1 schema)
- **Six folders** by controller: Health + Metrics, Dashboards, Tiles, Widgets, Rules, Admin
- **Fixture pattern** — `Dashboards / Fixture setup` creates a timestamped dashboard; all destructive operations target it; `Fixture teardown` cleans up at the end
- **Multi-user auth** — collection-level admin auth; non-admin requests override with member credentials
- **Assertions** — every request has ≥ 2 tests (status code + payload shape); error paths tested (403 for non-admin; shape validation on 5xx soft codes)
- **Environment variables** — `base_url`, `admin_user`, `admin_password`, `member_user`, `member_password` for local/CI flexibility
- **No code changes required** — pure test artifact; CI wiring already in place via `enable-newman: true`

## Capabilities

**Modified Capabilities:**

- `testing` (implements ADR-008 Newman collection requirement)

## Notes

- The collection enforces the "every PR touching a controller updates the matching request" contract via documentation + code review — no automated enforcement.
- Tile/Widget/Rule shape drift is a known risk; each controller change MUST update assertions.
- Admin user provisioning in CI is assumed; if the reusable workflow doesn't provide a member-level user, Task 5 defers member-branch coverage to a follow-up.
