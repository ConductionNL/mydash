# Design — @spec annotation pass

## Context

MyDash has zero `@spec` PHPDoc tags across 64 PHP files and ~215 public methods today. The coverage scan (`openspec/coverage-report.md`, 2026-04-24) has classified all methods into buckets; this change applies annotations to the high-confidence classifications.

The task is mechanical and non-invasive: add PHPDoc tags that bind implementation methods to their owning specifications. No code logic changes. No method signatures change. No behaviour changes.

## Goals / Non-Goals

**Goals:**

- Close the ADR-003 `@spec` tag gap by annotating 66 methods with high confidence matches.
- Establish the file-level and method-level `@spec` tag format for future use.
- Resolve the 3 ambiguous NEEDS-REVIEW flags with documented decisions.
- Flip ADR-003 `@spec` compliance from ❌ to ✅.

**Non-Goals:**

- New capability specs. Bucket 2a (`dashboards` extension) is a separate change.
- Behaviour changes. This is annotation-only — no code logic touched.
- Annotating the 9 "plumbing" methods (constructors, getters, framework hooks).

## Decisions

### D1: Bucket 1 + Bucket 2b annotated; Bucket 2a + Plumbing skipped

**Decision**: Annotate only the 61 Bucket 1 methods (confidence ≥ 0.75) and 5 Bucket 2b methods (retrofit from PR #23). Skip Bucket 2a (4 methods in `dashboards` cluster needing spec extension) and the 9 plumbing methods.

**Rationale**: Bucket 1 has high-confidence matches ready for immediate annotation. Bucket 2b specs already landed. Bucket 2a requires spec work first (separate change). Plumbing methods have no domain behaviour to tag.

### D2: File-level tag points to primary capability; method-level tag points to specific Requirement

**Decision**: Add a file-level `@spec` tag in each file's main docblock pointing to the owning capability. Add a method-level `@spec` tag in each method's docblock pointing to the specific Requirement (format: `openspec/specs/<capability>/spec.md#requirement-<slug>`).

**Alternatives considered:**

- Only method-level tags (file-level omitted). Rejected — file-level tags improve IDE navigation and searchability.
- Only capability reference without requirement anchor. Rejected — specific requirements enable traceability; the anchor allows Docusaurus to hyperlink the spec.

**Rationale**: Two-level tagging (capability → requirement) mirrors the existing app-versions precedent and enables both broad (file) and precise (method) traceability.

### D3: `DashboardResolver::getEffectivePermissionLevel` and `PermissionService::getEffectivePermissionLevel` both tag the same Requirement

**Decision**: Both methods own the same Requirement — `permissions/spec.md#requirement-effective-permission-level`. The `DashboardResolver` variant is a delegating thin wrapper; tag both with `@spec` pointing at the same Requirement. Mark `DashboardResolver`'s copy as a "delegating thin wrapper" in its docblock so future readers know the authoritative implementation lives in `PermissionService`.

**Rationale**: Duplication is documented + transparent. Both methods implement the same spec requirement. The wrapper's docblock clarifies the delegation.

### D4: `MyDashAdmin::getForm` and `MyDashAdminSection::getID` are plumbing; skip annotation

**Decision**: Treat `MyDashAdmin::getForm` + `MyDashAdminSection::getID` as plumbing (Nextcloud framework hooks). Skip `@spec` annotation. Document in the file-level docblock: "Nextcloud admin-UI registration boilerplate; behaviour defined by OCP\Settings contracts, not a MyDash spec."

**Rationale**: These are pure OCP interface implementations required by Nextcloud's admin UI registration system. They have no domain behaviour to spec. Matches how `app-versions` handled its `Application::__construct`.

### D5: Format follows app-versions + retrofit precedent

**Decision**: Use the format:

```php
 * @spec openspec/specs/dashboards/spec.md
```

for file-level tags, and

```php
 * @spec openspec/specs/dashboards/spec.md#requirement-list-user-dashboards
```

for method-level tags.

**Rationale**: Mirrors the app-versions precedent and the 2 retrofit commits that already landed.

## Risks / Trade-offs

- **Risk:** Anchor drift — markdown-anchor format for Requirement headings depends on heading capitalisation + bracketed tier. → **Mitigation:** Verify the anchors resolve in rendered Docusaurus output before merging.
- **Risk:** Coverage-scan staleness — the scan was 2026-04-24. If significant code landed between then and merge, the coverage may shift. → **Mitigation:** Re-run `/opsx-coverage-scan mydash` if more than 14 days have passed before this PR is built.
- **Risk:** NEEDS-REVIEW decision bleed — the three decisions above MUST land in design.md before tasks.md executes, so the builder doesn't improvise. → **Mitigation:** Design decisions documented here; tasks reference this file.

## Migration Plan

1. **Refresh coverage scan if stale** — check the scan date in `openspec/coverage-report.md`; re-run `/opsx-coverage-scan mydash` if > 14 days old.
2. **Annotate Bucket 1** (61 methods) — run `/opsx-annotate mydash --bucket 1 --write`.
3. **Annotate Bucket 2b** (5 methods) — run `/opsx-annotate mydash --cluster legacy-widget-bridge --write`.
4. **Resolve NEEDS-REVIEW flags** — manually annotate the 3 flagged methods per decisions D3 and D4 above.
5. **Verify** — `grep -rc '@spec' lib/` ≥ 75; `composer test:unit` green; `composer check:strict` green; spot-check 5 Docusaurus links resolve.
6. **Update docs** — flip ADR-003 row in `docs/adr-audit.md` to ✅; remove the annotation-pass follow-up item.
7. **Final scan** — re-run `/opsx-coverage-scan mydash` to confirm Bucket 1 count drops to 0.

## Open Questions

None at this time. The coverage scan has already resolved method classification. The three NEEDS-REVIEW flags are decided above.
