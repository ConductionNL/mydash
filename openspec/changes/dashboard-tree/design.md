# Design — Dashboard Tree

## Context

This capability adds parent/child hierarchy to dashboards via a `parentUuid` foreign key on
`oc_mydash_dashboards`. The spec (`dashboard-tree`) pins the data model, API contract, max depth 5,
per-parent slug uniqueness, and cascade-delete guard. Sibling spec `dashboard-cascade-events` owns
delete propagation; this design covers write-path constraints and read-time breadcrumb derivation.

Implementation lives in the existing `DashboardMapper` and a new `DashboardTreeService`. Adjacency-
list is sufficient at depth 5 — no separate tree table needed. This design adds what the spec implies
but doesn't spell out: cycle detection, enforcement points, breadcrumb caching, and slug uniqueness.

## Goals / Non-Goals

**Goals:**
- Document the cycle-detection algorithm used at write time
- Specify slug uniqueness scope and query approach
- Define breadcrumb computation strategy (on read vs. stored)
- Specify max-depth enforcement point
- Describe cascade-delete guard behaviour

**Non-Goals:**
- Moving subtrees (out of scope per spec)
- Full-text search across tree hierarchy
- UI tree-rendering decisions (frontend concern)

## Decisions

### D1: Cycle detection algorithm
**Decision**: Visited-set DFS walking ancestor chain at write time before persisting `parentUuid`.
**Source evidence**: No direct source — pattern is well-established for adjacency-list trees.
**Alternatives considered**:
- Nested set model — rejected; read-optimised but expensive writes and complex rebalancing at depth 5
- Deferred async check — rejected; would allow transient invalid state
**Rationale**: DFS over a max-5-deep chain costs at most 5 DB reads. Acceptable at write frequency.
Setting a new `parentUuid` triggers DFS starting from the proposed parent walking upward; if the
current dashboardUuid appears in the chain, reject with HTTP 422 `cycle_detected`.

### D2: Slug uniqueness scope
**Decision**: Unique per `(parentUuid, slug)` pair — siblings must have distinct slugs, not global.
**Source evidence**: `intravox-source/lib/Service/PageService.php:~140` — slug uniqueness enforced
within parent context only.
**Alternatives considered**:
- Global unique slug — rejected; makes deeply nested dashboards impossible to name naturally
**Rationale**: Per-parent matches the spec contract and mirrors how filesystem directories work.
Enforced via DB unique index on `(parent_uuid, slug)` (NULL-safe: root dashboards use `IS NULL` scope).

### D3: Max-depth enforcement point
**Decision**: Enforce at write time (create/move), not at read time.
**Alternatives considered**:
- Read-time pruning — rejected; silently hides data, confusing UX
**Rationale**: Prevents invalid tree states reaching persistence. Service method
`DashboardTreeService::getDepth(string $uuid): int` walks ancestors (max 5 hops) and throws if
depth would exceed 5 after the proposed attach.

### D4: Breadcrumb computation strategy
**Decision**: Compute breadcrumbs on read by walking ancestor chain; no stored `breadcrumb` column.
**Alternatives considered**:
- Materialized path column — rejected; adds write complexity for a small read gain at depth 5
- Separate index table (like `PageIndexService` in source) — rejected; over-engineering for ≤5 levels
**Rationale**: At max depth 5 the ancestor walk is at most 4 additional queries (or 1 recursive CTE).
`DashboardTreeService::getBreadcrumbs(string $uuid): array` returns ordered ancestor stubs. Results
MAY be cached in APCu with a 60 s TTL keyed on `mydash_bc_{uuid}`.

### D5: Cascade-delete guard
**Decision**: Deleting a dashboard with children returns HTTP 409 unless `?force=true` is passed;
`?force=true` delegates cascade to the `DashboardDeletedEvent` listener in `dashboard-cascade-events`.
**Alternatives considered**:
- Silent cascade always — rejected; destructive without user awareness
**Rationale**: Two-step explicit confirmation pattern is safer; `dashboard-cascade-events` already
owns the event-driven cascade logic so this spec does not duplicate it.

### D6: Root-level parent representation
**Decision**: Root dashboards store `parent_uuid = NULL`; API serialises as `"parentUuid": null`.
**Alternatives considered**:
- Sentinel UUID (`00000000-...`) — rejected; complicates queries unnecessarily
**Rationale**: NULL is idiomatic for adjacency-list roots and maps cleanly to JSON null.

## Risks / Trade-offs

- **N+1 breadcrumb queries** → mitigate with APCu cache (60 s TTL) or single recursive CTE if DB supports it
- **Slug index on NULL** → MySQL/MariaDB allows multiple NULLs in unique index; verify composite index covers `(parent_uuid, slug)` correctly in migration
- **Concurrent reparent + cycle check race** → mitigate with `SELECT ... FOR UPDATE` on ancestor rows during DFS

## Open follow-ups

- Evaluate recursive CTE for breadcrumb fetch once MySQL 8+ is confirmed as minimum baseline
- Consider subtree-move endpoint post-MVP (requires depth re-validation for entire subtree)
- APCu breadcrumb TTL may need reducing if dashboards are renamed frequently — gather usage data first
