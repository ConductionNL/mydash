# Tasks — `@spec` annotation pass

## Task 1: Refresh the coverage scan if stale

- [ ] Check `openspec/coverage-report.md` header — if the scan date
  is more than 14 days before the PR is built, re-run
  `/opsx-coverage-scan mydash` to regenerate.
- [ ] Confirm `coverage-report.json` sidecar exists and parses.

## Task 2: Annotate Bucket 1 (61 methods)

- [ ] Run `/opsx-annotate mydash --bucket 1 --write`.
- [ ] The skill adds `@spec` tags on method docblocks + file-level
  tags where missing. Commit with message
  `spec(annotate): bucket 1 — 61 methods via opsx-annotate`.

## Task 3: Annotate Bucket 2b (5 methods in legacy-widget-bridge)

- [ ] Run `/opsx-annotate mydash --cluster legacy-widget-bridge
  --write`.
- [ ] These methods were specced by PR #23 (retrofit, archived at
  `openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/`).
  Tags point at the archive's `tasks.md#task-1`, consistent with
  app-versions precedent.

## Task 4: Resolve NEEDS-REVIEW flags per design.md

- [ ] `DashboardResolver::getEffectivePermissionLevel` — annotate with
  `@spec openspec/specs/permissions/spec.md#requirement-effective-permission-level`
  + add a docblock note naming `PermissionService` as the authoritative
  implementation.
- [ ] `PermissionService::getEffectivePermissionLevel` — same
  `@spec` target.
- [ ] `MyDashAdmin::getForm` + `MyDashAdminSection::getID` — skip,
  add docblock comment "Nextcloud admin-UI registration boilerplate"
  instead of `@spec`.

## Task 5: Verification

- [ ] `grep -rc '@spec' lib/` ≥ 75.
- [ ] `composer test:unit` green.
- [ ] `composer check:strict` green (including phpcs — tags must match
  PHPDoc style).
- [ ] Rendered Docusaurus links in the 66 method tags resolve (spot-
  check 5 across different capabilities).

## Task 6: Docs

- [ ] Update `docs/adr-audit.md` — flip ADR-003 `@spec` row from ❌ to ✅.
- [ ] Remove the "`@spec` annotation pass" item from the follow-ups
  section of `docs/adr-audit.md`.
- [ ] Re-run `opsx-coverage-scan mydash` at the end; confirm Bucket 1
  count drops to 0 or matches only plumbing methods.
