# MyDash Legacy Quality Cleanup

## Why

The OR-abstraction audit (2026-05-03, stream 3 + the quality-gates
cleanup at session start) flagged that mydash's quality gates have
some legacy debt absorbed via exclude patterns and a small PHPStan
baseline. Burning these down keeps PR diffs honest — gates catch
real regressions rather than silently absorbing already-broken code.

MyDash has 3 phpcs.xml exclude-patterns and an 81-line
phpstan-baseline.neon. PHPMD has no baseline yet. The work is small:
clear PHPCS excludes, run PHPMD unified, and burn down the modest
PHPStan baseline.

This is a tracking change so the burn-down can be picked up later.
It is spec-only; no code changes are proposed in this change.

## What Changes

- Inventory and clear the 3 phpcs.xml exclude-patterns. For each:
  add proper docblocks + named-parameter call audits, then drop
  the exclude.
- Run PHPMD for the first time as a unified gate (phpmd.xml is
  configured but no baseline exists). Capture surfacing violations
  as a baseline OR fix outright depending on volume.
- Burn down the 81-line phpstan-baseline.neon. Small enough to
  clear in 1-2 PRs.
- Wire phpcs/phpmd/phpstan into CI as the unified quality gate.

## Problem

Exclude-patterns and the small PHPStan baseline exist because the
audit captured legacy files / errors that predated current quality
conventions. MyDash is a small app — the entire burn-down should
fit in 1-2 PRs.

PHPMD baseline doesn't exist yet because the gate hasn't been run
as part of unified `check:strict`. Capturing it (or fixing
outright) is a Phase 1 activity.

Note: per project memory, mydash must NOT depend on OR/openconnector
at install-time (BI/dashboard surface; talks to OR via runtime
GraphQL only). Quality cleanup is purely about code hygiene and
does not affect this boundary.

## Proposed Solution

File-by-file cleanup. Phase 2 lists each excluded file; Phase 4 is
a single-cluster burn-down because the baseline is small.

Estimated effort: 1-2 PRs over 1 sprint.

## Out of scope

- Refactoring beyond what the sniff requires
- New features (separate adoption-spec changes own those)
- Adding install-time deps on OR / openconnector — explicitly
  forbidden per the mydash boundary rule
- Test additions (separate test-coverage spec change if needed)

## See also

- The canonical audit lives in openregister at
  `.claude/audit-2026-05-03/03-repo-hygiene.md`. MyDash references
  it from there.
- `phpcs.xml` (the legacy-debt baseline section)
- `phpstan-baseline.neon` (the PHPStan baseline file)
- Hydra ADR-022 (apps consume OR abstractions) — quality conventions
- `composer.json` `check:strict` script (the unified gate target)
