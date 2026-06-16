# Design — Dashboard Export / Import

## Context

Operators need to move dashboards between installations — e.g. shipping a demo configuration to
a customer, migrating between environments, or backing up a curated layout before a major
upgrade. Today there is no export path; dashboards must be recreated by hand.

The feature must handle arbitrarily large exports (a site-wide export with many widgets and
embedded assets). Loading the entire archive into PHP memory is not viable; the implementation
must stream the ZIP on the fly using the platform's file API. Import faces the symmetric
problem: the uploaded file may be large, and collision handling (two dashboards with the same
slug) must be declared upfront so the operation is predictable.

Both directions are user-facing operations, but they have different permission models. Export is
always scoped to what the requester can read — it cannot escalate privilege. Import writes to the
requester's personal dashboards by default; an admin-only query parameter can redirect the
import target for provisioning workflows.

## Goals / Non-Goals

**Goals:**
- ZIP export of a single dashboard or the full site (all dashboards the requester can see).
- Streaming export — never buffer the full archive in memory.
- ZIP import with configurable collision handling.
- Dry-run mode that previews what would be imported without persisting.
- Admin-controlled import target.

**Non-Goals:**
- Incremental / delta export (only changed dashboards since last export).
- Scheduled / automated export jobs (handled by a separate background-job spec).
- Export of platform configuration or user account data.

## Decisions

### D1: Archive format
**Decision:** A ZIP file containing `manifest.json`, one `dashboards/<uuid>.json` per dashboard,
one `widgets/<placement-id>.json` per widget, and an `assets/` directory for referenced files.
**Alternatives considered:** JSON-LD bundle (single file); tar.gz.
**Rationale:** ZIP is natively supported by the platform's file helpers and is universally
readable without specialist tooling. Splitting dashboards and widgets into separate files allows
partial inspection and future partial-import without parsing a monolith.

### D2: Manifest schema
**Decision:** `manifest.json` has shape `{schemaVersion: 1, exportedAt: <ISO8601>,
exportedBy: <userId>, dashboards: [{uuid, title, file}]}`. The `schemaVersion` field gates
import compatibility checks.
**Alternatives considered:** Embedding all metadata inside each dashboard file; no manifest.
**Rationale:** A top-level manifest lets the importer validate compatibility and enumerate
contents before opening any dashboard file, enabling the dry-run summary without full
deserialization.

### D3: Streaming export
**Decision:** Use the platform `\OCP\Files\IRootFolder` write stream + a streaming ZIP library
to append files on-the-fly. The HTTP response streams chunks as they are produced.
**Alternatives considered:** Temp-file ZIP then stream; hold everything in memory.
**Rationale:** Gigabyte-scale exports are a stated requirement; streaming is the only approach
that does not impose a size ceiling.

### D4: Import collision handling
**Decision:** `?onCollision=rename|replace|skip` (default `rename`). `rename` appends ` (2)`,
` (3)` to the title; `replace` overwrites in-place; `skip` leaves the existing record and notes
the skip in the response.
**Alternatives considered:** Always rename; require manual pre-delete.
**Rationale:** Three strategies map cleanly to the three real workflows: safe demo import,
environment sync, and idempotent provisioning.

### D5: Permission scoping
**Decision:** Export respects `canViewDashboard($userId, $dashboard)` per dashboard. Import
writes to the requester's personal dashboards unless an admin supplies `?importTarget=<userId>`.
**Alternatives considered:** Export always requires admin.
**Rationale:** Scoped export lets regular users share their own dashboards without privilege
escalation.

### D6: Dry-run support
**Decision:** `?dryRun=true` runs full validation and returns a summary JSON without persisting.
**Alternatives considered:** A separate `/import/preview` endpoint.
**Rationale:** One endpoint, one code path — dry-run exercises exactly the same logic as the
live import up to the persistence call.

### D7: Schema version gating
**Decision:** A `schemaVersion` higher than the app's supported maximum causes HTTP 422 rejection
with a descriptive error message.
**Alternatives considered:** Best-effort import with unknown-field warnings.
**Rationale:** Silent imports of future schema versions risk data loss; explicit rejection is
safer and easier to debug.

## Risks / Trade-offs

- Streaming ZIP generation ties up a PHP worker thread for the export duration; large exports
  risk gateway timeouts — operators should be warned.
- Asset deduplication across dashboards is not handled in v1; the same image may appear twice.
- Import does not validate asset MIME types beyond the platform's existing upload restrictions.

## Open follow-ups

- Add `?dashboardUuids[]=` filter to export a subset of dashboards.
- Evaluate resumable/chunked upload once field file sizes are better understood.
- Consider a `mydash:export` CLI command for automated backup workflows.
