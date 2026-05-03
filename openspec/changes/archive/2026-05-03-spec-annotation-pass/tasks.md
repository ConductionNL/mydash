# Tasks — `@spec` annotation pass

## Task 1: Refresh the coverage scan if stale

- [x] Check `openspec/coverage-report.md` header — scan dated 2026-04-24,
  within the 14-day freshness window relative to this implementation
  (2026-05-03), no rescan required.
- [x] Confirm `coverage-report.json` sidecar exists and parses.

## Task 2: Annotate Bucket 1 (61 methods)

- [x] Annotated all 62 Bucket 1 methods (the JSON listed 68 entries; 4
  of those resolved to private helpers in `RuleEvaluatorService`, which
  fall outside the public-method spec scope, and 2 are the NEEDS-REVIEW
  Settings cases handled in Task 4).
- [x] `@spec <capability>:<REQ-ID>` tags inserted on each method
  docblock as the last `@`-tag.

## Task 3: Annotate Bucket 2b (5 methods in legacy-widget-bridge)

- [x] N/A for `lib/`. The legacy-widget-bridge cluster lives in
  `src/services/widgetBridge.js` (frontend), not in `lib/`. The
  per-language annotation contract for JS bridges is out of scope for
  the PHP `@spec` pass and is tracked separately under the
  `legacy-widget-bridge` capability spec already landed by PR #23.

## Task 4: Resolve NEEDS-REVIEW flags per design.md

- [x] `DashboardResolver::getEffectivePermissionLevel` — annotated with
  `@spec permissions:REQ-PERM-008` plus a docblock note naming
  `PermissionService::getEffectivePermissionLevel()` as the
  authoritative implementation.
- [x] `PermissionService::getEffectivePermissionLevel` — annotated with
  `@spec permissions:REQ-PERM-008`.
- [x] `MyDashAdmin::getForm` + `MyDashAdminSection::getID` — skipped
  per design.md; docblock NOTE added on each pointing back at the
  archive of this change.

## Task 5: Verification

- [x] `grep -rn '@spec ' lib/ | wc -l` reports 62 (target: 60+).
- [x] `composer lint` (PHP syntax) green across `lib/`.
- [x] CI guard added: `composer lint:spec-annotations` runs
  `tools/check-spec-annotations.php` and fails if any public `lib/`
  method lacks `@spec` AND isn't on `tools/spec-annotations-allowlist.txt`.

## Task 6: Docs

- [x] `docs/adr-audit.md` — ADR-003 `@spec` row flipped from ❌ to ✅.
- [x] Removed the "`@spec` annotation pass" item from the follow-ups
  section of `docs/adr-audit.md`.
- [x] Coverage rescan deferred — Bucket 1 now drops to 0 by
  construction (every entry annotated or explicitly skipped).
