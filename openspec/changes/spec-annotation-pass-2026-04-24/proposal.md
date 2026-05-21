# @spec annotation pass across lib/

This change closes the ADR-003 `@spec` tag gap by annotating 66 public methods across 64 PHP files in `lib/` with their corresponding capability specs. The coverage scan (`openspec/coverage-report.md`, 2026-04-24) has already classified all ~215 public methods into buckets; this change applies `@spec` tags to the 61 Bucket 1 methods (high confidence matches) and 5 Bucket 2b methods (from the retrofit that landed in PR #23).

## Affected code units

- All 64 PHP files in `lib/` — file-level `@spec` tag pointing to the owning capability
- 66 methods across those files — method-level `@spec` tags pointing to specific Requirements
- `docs/adr-audit.md` — flip ADR-003 `@spec` row from ❌ to ✅

## Why now

The `openspec/coverage-report.md` (2026-04-24) has already done the hard classification work:

- **Bucket 1** — 61 methods matched cleanly to existing Requirements across 9 capability specs. Confidence ≥ 0.75 for all; ready to annotate mechanically.
- **Bucket 2b** — 5 methods in `legacy-widget-bridge` cluster; already specced by PR #23 (retrofit), so the 5 methods here get `@spec` tags in this pass too.
- **Plumbing** — 9 methods that don't need specs (constructors, getters, framework hooks).

Three NEEDS-REVIEW flags require human judgement before annotation:

- `DashboardResolver::getEffectivePermissionLevel` (0.80) — duplicates same method in `PermissionService`
- `MyDashAdmin::getForm` (0.80) + `MyDashAdminSection::getID` (0.75) — admin-UI-registration boilerplate

## Approach

1. Run `/opsx-annotate mydash` against the 61 Bucket 1 methods + 5 Bucket 2b methods (66 total).
2. Resolve the 3 NEEDS-REVIEW flags per the decisions in design.md.
3. Add file-level `@spec` tags pointing at each file's owning capability.
4. Add method-level `@spec` tags pointing at specific Requirements.
5. Update `docs/adr-audit.md` to reflect the new compliance status.

## Capabilities

**Modified Capabilities:**

- Nine capability specs receive method-level `@spec` annotations across their implementations.

## Acceptance

1. `grep -rc '@spec' lib/` reports ≥ 75 hits (66 methods + ~10 file-level tags).
2. The 3 NEEDS-REVIEW flags are resolved with decisions recorded in design.md.
3. `docs/adr-audit.md` reflects the new compliance status.
4. No diff in behaviour — `composer test:unit` is green.
