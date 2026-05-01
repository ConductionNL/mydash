# Design — Orphaned Data Cleanup

## Context

Over time, MyDash accumulates stale rows that are no longer referenced by any live entity:
widget assets uploaded for a dashboard that was subsequently deleted, locks held by sessions
that crashed before releasing them, and share tokens whose parent dashboards no longer exist.
These rows waste storage, pollute admin diagnostics, and — in the case of dangling locks — can
block legitimate write operations.

The cleanup surface is not static. New capabilities (calendar widgets, embedded media, advanced
sharing) will introduce their own orphan categories. A hardcoded list of cleanup steps would
require a core change every time a new capability ships. The design therefore uses a registry
pattern: each capability registers its own detector, and the cleanup command and admin UI
iterate the registry without knowing the detector details.

Safety is the overriding constraint. A cleanup that deletes data the admin did not intend to
remove is worse than leaving orphans in place. The three-tier flow (scan → preview → confirm)
and the checksum guard on the confirm step ensure that the admin reviews exactly the data that
will be removed, and that a concurrent modification cannot cause a stale-state deletion.

## Goals / Non-Goals

**Goals:**
- A `mydash:cleanup` CLI command that scans for and optionally removes orphaned data.
- An admin UI panel that surfaces the scan results and allows one-click cleanup.
- A DI-tag registry so capabilities can register detectors without modifying core.
- Audit emission for every cleanup run.
- Per-detector enable/disable toggles in admin settings.

**Non-Goals:**
- Automatic scheduled cleanup (operators must trigger it explicitly in v1).
- Cleanup of data owned by other apps.
- Recovery / undo of cleaned rows.

## Decisions

### D1: Registry pattern
**Decision:** Detectors implement a `IOrphanDetector` interface and are registered via DI tag
`mydash.orphan_detector`. The `OrphanCleanupService` collects all tagged implementations
via constructor injection and runs them in sequence.
**Alternatives considered:** A static list of detector class names in a config file; a database
registry table.
**Rationale:** DI tags are the idiomatic extension point in this framework. No database table
or config file needs updating when a new capability ships its detector — only the DI wiring
in the capability's own service definition.

### D2: Three-tier safety flow
**Decision:** The flow is scan → preview (show counts and a checksum) → cleanup (requires
`?confirm=true` plus the checksum from the preview response).
**Alternatives considered:** Single-step cleanup with a `?dryRun` flag; two-step (scan +
immediate cleanup).
**Rationale:** The checksum binds the confirm request to a specific scan result. If another
process modifies the data between scan and confirm, the checksums diverge and the cleanup
is rejected, preventing stale-state deletions.

### D3: Per-detector enable/disable
**Decision:** Each detector is toggled by `mydash.orphan_cleanup_<detector_id>_enabled`
(default `true`). Disabled detectors are skipped and listed under `skipped` in the response.
**Alternatives considered:** Single global toggle; no per-detector control.
**Rationale:** Some installations legitimately retain certain orphan categories (e.g. assets
kept for audit). Granular control avoids a global toggle blocking targeted cleanup.

### D4: Audit emission
**Decision:** Every run emits Activity event `mydash_orphan_cleanup_run` with
`{detector, scannedCount, removedCount, dryRun}` — including scan-only runs.
**Alternatives considered:** Log-only; emit only on destructive runs.
**Rationale:** Admin actions that can remove data must produce a user-attributed, queryable
audit trail.

### D5: CLI default to scan-only
**Decision:** `mydash:cleanup` scans by default; `--apply` triggers deletions;
`--detector=<id>` isolates a single detector.
**Alternatives considered:** Default to destructive, require explicit `--dry-run`.
**Rationale:** An operator who forgets the flag gets a harmless report — not an unexpected
deletion.

### D6: Source reference
**Decision:** The `OrphanedDataController` from `intravox-source/lib/Controller/` is the
reference; the refactor swaps its hardcoded list for the DI-tag registry.
**Alternatives considered:** Full rewrite ignoring source.
**Rationale:** Reusing the HTTP scaffolding reduces risk; the registry swap is the only
structural change.

## Risks / Trade-offs

- High-churn installations may see frequent checksum mismatches between scan and confirm,
  requiring repeated scan cycles.
- Slow detectors (large asset tables) should report per-detector duration to the operator.
- A buggy detector that misclassifies live data as orphaned has only the preview step as a
  safety net — the checksum does not validate detector logic.

## Open follow-ups

- Add `--since=<date>` to limit scans to recently orphaned rows.
- Implement opt-in scheduled background job mode once behaviour is validated in the field.
- Define a per-detector retention window (e.g. assets eligible only after 30 days orphaned).
