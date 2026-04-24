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
