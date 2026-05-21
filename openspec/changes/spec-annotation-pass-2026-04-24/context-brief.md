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



## Design

# Design — `@spec` annotation pass

## Approach

This is a mechanical follow-up to an already-completed coverage scan.
The scan (`openspec/coverage-report.md`, 2026-04-24) emitted
`coverage-report.json` — a machine-readable classification that the
`/opsx-annotate mydash` skill consumes.

Procedure:

1. Load `coverage-report.json`.
2. For each Bucket 1 entry (61 methods):
   - Open the file at `entry.file`.
   - Add a file-level `@spec` tag in the main docblock if not present,
     pointing at the primary capability (first Requirement reference).
   - Add a method-level `@spec` tag above the declaration of the
     method at `entry.method`. Format:
     `@spec openspec/specs/<capability>/spec.md#requirement-<slug>`.
3. For each Bucket 2b entry (5 methods in `legacy-widget-bridge` —
   specs landed by PR #23):
   - Same as Bucket 1, referencing the `legacy-widget-bridge` spec.
4. Resolve the 3 NEEDS-REVIEW flags (see below).
5. Skip plumbing (9 methods).

## NEEDS-REVIEW decisions

### `DashboardResolver::getEffectivePermissionLevel` vs
### `PermissionService::getEffectivePermissionLevel`

Both methods compute the same thing (effective permission for a
user+dashboard pair). The coverage scan's confidence dropped because
the duplication makes ownership ambiguous.

**Proposed call**: both methods own the same Requirement —
`permissions/spec.md#requirement-effective-permission-level`. The
`DashboardResolver` variant is a facade that delegates to
`PermissionService` (grep confirms); tag both with `@spec` pointing at
the same Requirement. Mark `DashboardResolver`'s copy as a
"delegating thin wrapper" in its docblock so future readers know
the authoritative implementation lives in `PermissionService`.

### `MyDashAdmin::getForm` + `MyDashAdminSection::getID`

These are `OCP\Settings\ISettings` / `OCP\Settings\IIconSection`
interface implementations — required by Nextcloud for admin UI
registration. They're pure boilerplate and don't implement domain
behaviour.

**Proposed call**: treat as **plumbing** (skip annotation). Document
in the file-level docblock: "Nextcloud admin-UI registration
boilerplate; behaviour defined by OCP\Settings contracts, not a
MyDash spec." This matches how `app-versions` handled its
`Application::__construct` (Nextcloud framework hook, no `@spec`).

## Format convention

File-level tag (in the main PHPDoc docblock, after `@link`):

```php
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/dashboards/spec.md
 */
```

Method-level tag (in the method's PHPDoc, last before attributes):

```php
    /**
     * List all dashboards for the current user.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/dashboards/spec.md#requirement-list-user-dashboards
     */
    #[NoAdminRequired]
    public function list(): JSONResponse
```

Format matches the app-versions precedent
(`openspec/specs/version-management/spec.md#requirement-list-installed-apps`)
and the 2 retrofit commits that already landed on this repo
(`@spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-1`).

## Risk

- **Anchor drift**: markdown-anchor format for Requirement headings
  depends on heading capitalisation + bracketed tier. Verify the
  anchors resolve in rendered Docusaurus output before merging.
- **Coverage-scan staleness**: the scan was 2026-04-24. If significant
  code landed between then and this change merging, re-run
  `/opsx-coverage-scan` first.
- **NEEDS-REVIEW decision bleed**: the two decisions above MUST land
  in design.md before tasks.md executes, so the builder doesn't
  improvise.



## Tasks

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