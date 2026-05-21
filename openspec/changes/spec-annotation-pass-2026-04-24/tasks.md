# Tasks — @spec annotation pass

## Tasks

- [ ] Task 1: Refresh coverage scan if stale — check `openspec/coverage-report.md` header for scan date; if more than 14 days before PR build, re-run `/opsx-coverage-scan mydash` and confirm `coverage-report.json` sidecar exists and parses.

- [ ] Task 2: Annotate Bucket 1 (61 methods) — run `/opsx-annotate mydash --bucket 1 --write`. The skill adds `@spec` tags on method docblocks + file-level tags where missing. Commit with message `spec(annotate): bucket 1 — 61 methods via opsx-annotate`.

- [ ] Task 3: Annotate Bucket 2b (5 methods in legacy-widget-bridge) — run `/opsx-annotate mydash --cluster legacy-widget-bridge --write`. These methods were specced by PR #23 (retrofit, archived at `openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/`). Tags point at the archive's `tasks.md#task-1`, consistent with app-versions precedent.

- [ ] Task 4: Resolve NEEDS-REVIEW flag — `DashboardResolver::getEffectivePermissionLevel` — manually add `@spec openspec/specs/permissions/spec.md#requirement-effective-permission-level` + a docblock note naming `PermissionService` as the authoritative implementation.

- [ ] Task 5: Resolve NEEDS-REVIEW flag — `PermissionService::getEffectivePermissionLevel` — add same `@spec openspec/specs/permissions/spec.md#requirement-effective-permission-level` tag.

- [ ] Task 6: Resolve NEEDS-REVIEW flag — `MyDashAdmin::getForm` + `MyDashAdminSection::getID` — skip `@spec` annotation; add docblock comment "Nextcloud admin-UI registration boilerplate; behaviour defined by OCP\Settings contracts, not a MyDash spec." instead.

- [ ] Task 7: Verify grep count — `grep -rc '@spec' lib/` should report ≥ 75 hits (66 methods + ~10 file-level tags).

- [ ] Task 8: Verify tests pass — `composer test:unit` green.

- [ ] Task 9: Verify code style — `composer check:strict` green (including phpcs — tags must match PHPDoc style).

- [ ] Task 10: Verify Docusaurus links — rendered Docusaurus links in the 66 method tags resolve. Spot-check 5 links across different capabilities; confirm each `#requirement-<slug>` anchor appears in the corresponding `spec.md` file.

- [ ] Task 11: Update audit docs — flip ADR-003 `@spec` row in `docs/adr-audit.md` from ❌ to ✅.

- [ ] Task 12: Clean up audit docs — remove the "`@spec` annotation pass" item from the follow-ups section of `docs/adr-audit.md`.

- [ ] Task 13: Final coverage scan — re-run `/opsx-coverage-scan mydash` at the end; confirm Bucket 1 count drops to 0 or matches only plumbing methods.

## Verification

`grep -rc '@spec' lib/` ≥ 75; `composer test:unit` green; `composer check:strict` green; 5 Docusaurus links spot-checked; ADR-003 status flipped; Bucket 1 coverage count drops to 0.

## Tests (company-wide ADR-008)

No new unit tests required — this is annotation-only. Existing test suite must remain green.

## Documentation (company-wide ADR-009)

File-level and method-level `@spec` tags establish the format for future use. Docblock notes on wrapper methods document the delegating pattern.

## i18n (company-wide ADR-007)

No user-facing strings. No i18n changes required.
