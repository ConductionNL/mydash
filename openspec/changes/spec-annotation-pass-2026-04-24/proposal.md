# `@spec` annotation pass across `lib/`

Closes the ADR-003 `@spec` tag gap flagged in
[`docs/adr-audit.md`](../../../docs/adr-audit.md). MyDash has **zero**
`@spec` PHPDoc tags across 64 PHP files and ~215 public methods today.

## Why now

The coverage scan at `openspec/coverage-report.md` (2026-04-24) has
already done the hard classification work:

- **Bucket 1** — 61 methods matched cleanly to existing Requirements
  across 9 capability specs. Confidence ≥ 0.75 for all; ready to
  annotate mechanically.
- **Bucket 2a** — 4 methods in a `dashboards` cluster need the
  existing `dashboards` spec extended first (separate change — not
  this one).
- **Bucket 2b** — 5 methods in a `legacy-widget-bridge` cluster;
  **already specced** and landed by PR #23 (retrofit commit), so the
  5 methods here get `@spec` tags in this pass too.
- **Plumbing** — 9 methods that don't need specs (constructors,
  getters, framework hooks).

Three NEEDS-REVIEW flags from the coverage report (listed below)
require human judgement before annotation — this change proposes the
call for each.

## Scope

- Run `/opsx-annotate mydash` against the 61 Bucket 1 methods +
  5 Bucket 2b methods (from the retrofit that just landed) — 66 methods
  total.
- Resolve the 3 `NEEDS-REVIEW` flags:
  - `DashboardResolver::getEffectivePermissionLevel` (0.80) — duplicates
    same method in `PermissionService`. Decide: does it belong to
    `permissions` spec (most likely) or `dashboards` (delegator
    pattern)?
  - `MyDashAdmin::getForm` (0.80) + `MyDashAdminSection::getID` (0.75) —
    admin-UI-registration boilerplate. Decide: extend `admin-settings`
    spec with a new Requirement, or treat as plumbing (skip).
- Add file-level `@spec` tags pointing at each file's owning
  capability; method-level `@spec` tags pointing at the specific
  Requirement (format:
  `openspec/specs/<capability>/spec.md#requirement-<slug>`).
- Update `docs/adr-audit.md` — flip ADR-003 `@spec` row from ❌ to ✅.

## Not in scope

- New capability specs. Bucket 2a (`dashboards` extension) is a
  separate change.
- Behaviour changes. This is annotation-only — no code logic touched.
- The 9 "plumbing" methods are skipped by design.

## Acceptance

1. `grep -rc '@spec' lib/` reports ≥ 75 hits (66 methods + ~10
   file-level tags).
2. The 3 NEEDS-REVIEW flags are resolved with a decision recorded in
   design.md.
3. `docs/adr-audit.md` reflects the new compliance status.
4. No diff in behaviour — `composer test:unit` is green.
