# Design — Dashboard Metadata Fields

## Context

This capability is a MyDash-native invention — not present in the source app. Admins define typed
metadata fields; users and widgets store values against them. The spec (`dashboard-metadata-fields`)
pins the two-table model, CRUD endpoints, type enum, and filter-by-metadata query contract.

The primary driver is news-widget filtering: a dashboard carries a `topic` select-field and the
news widget scopes its feed to it. This design documents the type system, value encoding, orphan
tolerance, and cascade semantics that the spec implies but leaves to the implementer.

## Goals / Non-Goals

**Goals:**
- Document the type enum and value encoding per type
- Specify orphan-value handling (field deleted while values exist)
- Clarify cascade-on-field-delete safety mechanism
- Define filter-by-metadata query approach
- Specify validation failure response shape

**Non-Goals:**
- Widget-side consumption of metadata (each widget spec owns that)
- Admin UI layout decisions (frontend concern)
- Per-user metadata field visibility rules (not in spec)

## Decisions

### D1: Type enum
**Decision**: Six types — `text`, `number`, `date`, `select`, `multi-select`, `boolean`. Stored as VARCHAR(20).
**Alternatives considered**:
- `url`/`email` as first-class types — deferred; handle as text+regex validation for now
- `richtext` — rejected; XSS surface not justified at MVP
**Rationale**: Covers all widget-filtering use cases from spec triage.

### D2: Value encoding strategy
**Decision**: TEXT column in `oc_mydash_metadata_values.value`. Plain string for scalar types;
JSON array string for `select`/`multi-select`. `boolean` stored as `"1"`/`"0"`.
**Alternatives considered**:
- Typed columns per type — rejected; painful migrations when adding types
- JSON column for all — rejected; MariaDB JSONB support inconsistent across supported NC versions
**Rationale**: TEXT is universally supported; `MetadataValueService` parses/validates on read/write.

### D3: Validation failure response
**Decision**: Type-mismatched value write returns HTTP 422 `{"error":"validation_failed","field":"value","type":"<expected>"}`.
**Alternatives considered**: HTTP 400 — 422 is more semantically precise per RFC 9110.
**Rationale**: Consistent with MyDash API error contract.

### D4: Orphan-value tolerance
**Decision**: Deleting a field does NOT auto-delete its values. `MetadataValueService` hides orphaned
values via `JOIN` on existing field IDs. `GET /api/admin/metadata-fields/orphans` surfaces them.
**Alternatives considered**:
- Hard cascade — rejected; silent irreversible data loss
- Soft-delete field — adds complexity without MVP benefit
**Rationale**: Preserving orphan rows is safer; admin cleanup endpoint provides governance.

### D5: Cascade-on-field-delete safety gate
**Decision**: `DELETE /api/admin/metadata-fields/{id}` without query param returns HTTP 409 if
values exist. `?cascade=true` deletes values and the field definition in a transaction.
**Alternatives considered**:
- Always cascade — rejected; matches the philosophy in `dashboard-tree` D5 for consistency
**Rationale**: Explicit opt-in for destructive operations. Two-step pattern used consistently
across MyDash delete flows.

### D6: Filter-by-metadata query approach
**Decision**: Dashboard list endpoint accepts `?meta[fieldSlug]=value` params; translated to
`INNER JOIN oc_mydash_metadata_values` with `WHERE field_id = ? AND value = ?` per param.
**Alternatives considered**:
- Full-text search across value column — rejected; too broad, breaks type semantics
- POST body filter — rejected; GET semantics for list queries, bookmarkable URLs
**Rationale**: SQL join approach is simple, indexed on `(dashboard_uuid, field_id)`, and keeps
query logic server-side rather than loading all metadata to filter in PHP.

### D7: Field-slug uniqueness scope
**Decision**: Field slugs are globally unique (admin-defined, reused across dashboards).
**Alternatives considered**: Per-dashboard scope — rejected; widget configs reference fields by slug (`meta.topic`), so global uniqueness makes configs portable.
**Rationale**: Simpler widget configuration; fields are admin-governed, not per-dashboard inventions.

## Risks / Trade-offs

- **Multi-select filter** → `?meta[tags]=news` matches exact JSON string, not containment; document this; array containment is v2
- **Filter fan-out** → each `?meta[]` param adds a JOIN; cap at 5 simultaneous filters to avoid runaway queries

## Open follow-ups

- Add `contains` operator for multi-select filter (JSON_CONTAINS or PHP post-filter)
- Consider `fieldRequired` flag for form-validation use cases
- Evaluate `fieldOrder` integer for admin UI ordering
