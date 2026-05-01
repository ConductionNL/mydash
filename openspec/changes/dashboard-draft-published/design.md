# Design — Dashboard draft / published / scheduled

## Context

The `dashboard-draft-published` change adds a three-state publication workflow
(`draft` / `published` / `scheduled`) to MyDash dashboards. Before this change,
every dashboard was implicitly visible to its intended audience as soon as it
existed. After the change, dashboards begin life as `draft`, are promoted to
`published` explicitly (or scheduled for automatic promotion), and are hidden from
non-owners until published.

The reference source implements the same concept for pages: a new index table
(`intravox_page_index`) carries a `status` column, pages without a `status` key in
their JSON resolve to `'published'` at index time, and new template copies are
seeded as `'draft'`. This design.md confirms where MyDash follows that pattern
exactly, where it diverges intentionally (column-as-source-of-truth vs.
JSON-plus-index-table), and what implementation traps to avoid.

The MyDash spec (REQ-DASH-019 through REQ-DASH-025) was drafted after reviewing the
source. Most source choices are confirmed below. One meaningful divergence exists in
the backfill strategy (see D1), which the spec must clarify.

## Goals / Non-Goals

**Goals:**
- Confirm the source's lazy-backfill approach (no explicit UPDATE statement) is
  appropriate for MyDash and matches the spec's intent for REQ-DASH-025.
- Pin the authoritative storage location for `publicationStatus` as a typed column
  on `oc_mydash_dashboards`, not embedded in widgetTree JSON.
- Confirm template-spawned copies must default to `'draft'` (REQ-DASH-021 /
  REQ-DASH-022 area).
- Record that scheduled-as-published resolution is a read-time contract, not a
  background-job contract (REQ-DASH-024).
- Prevent a naming collision with a same-named service in the source that does
  something entirely different.

**Non-Goals:**
- Re-specifying REQ-DASH-019 through REQ-DASH-025 (spec.md owns that).
- Designing the optional background materialisation job beyond what the spec
  already captures.
- Any UI work (deferred to `dashboard-publication-ui`).

## Decisions

### D1: Backfill default — `published`, applied lazily via column DEFAULT

**Decision:** The migration adds `publicationStatus` with `DEFAULT 'published'` (not
`DEFAULT 'draft'`). No separate `UPDATE` backfill statement is needed. Existing
dashboard rows acquire `'published'` automatically when the column is materialised
by the database engine. New dashboards created after the migration default to
`'draft'` via application logic in `DashboardService::createDashboard()`, not via
the column default.

**Source evidence:**
- `intravox-source/lib/Migration/Version001300Date20260420000000.php:43-47` — the
  `intravox_page_index.status` column is declared with `default: 'published'`. The
  table itself is new in that migration, so there are no pre-existing rows requiring
  an UPDATE; existing pages are picked up on the next incremental re-index.
- `intravox-source/lib/Service/PageIndexService.php:39, 57` — when indexing a page,
  status is read as `$pageData['status'] ?? 'published'`; pages without a status
  key in their JSON are treated as published at index time.

**Rationale:** MyDash already has rows in `oc_mydash_dashboards` that must become
`'published'` after migration. Using `DEFAULT 'published'` on the migration column
achieves exactly that in one DDL step with no risk of partial-update failures.
Application code then overrides the default to `'draft'` for every new dashboard
created post-migration. This is the same effect as an explicit backfill UPDATE but
with fewer moving parts and no risk of locking a large table during migration.

**Spec note:** REQ-DASH-025's scenario currently reads `DEFAULT 'draft'` plus an
explicit `UPDATE … SET publicationStatus = 'published'`. The scenario is correct in
_outcome_ (pre-existing dashboards end up `'published'`) but wrong in _mechanism_.
The spec scenario should be updated to reflect `DEFAULT 'published'` on the column
with no backfill UPDATE statement. (See "Spec changes implied" below.)

---

### D2: New dashboards default to `draft`; template copies seeded as `draft`

**Decision:** `DashboardService::createDashboard()` MUST explicitly set
`publicationStatus = 'draft'` on every new dashboard object before persisting,
overriding the database column default of `'published'`. Template-spawned copies
created via the `basedOnTemplate` flow (in `TemplateService::createDashboardFromTemplate()`)
MUST also receive `publicationStatus = 'draft'` before first save.

**Source evidence:**
- `intravox-source/lib/Service/PageService.php:6029` — template copies are seeded as
  `'draft'` via `$pageData['status'] = 'draft'` before any persistence call.
- `intravox-source/lib/Service/PageService.php:2056-2057` — the service validates
  that `status` is one of `'draft'` or `'published'` at write time; `'scheduled'` is
  a MyDash addition not present in the source.

**Rationale:** The "create now, share later" contract (proposal.md line 26) requires
that new content is private by default. Seeding template copies as `'draft'` rather
than `'published'` also prevents a template distribution event from immediately
exposing a dashboard the admin has not yet reviewed for the target user's context.

---

### D3: Status canonical home — column on `oc_mydash_dashboards`, not in widgetTree JSON

**Decision:** `publicationStatus` (and `publishAt` / `publishedAt`) live as typed
columns on the dashboards table and are the exclusive source of truth. No duplication
into widgetTree JSON is required or permitted.

**Alternatives considered:**
- _Status in widgetTree JSON (mirrors source's page-JSON + index-table pattern)_:
  rejected. The source duplicates status because its index table is a separate
  read-optimised projection of a JSON document store. MyDash stores dashboards in a
  conventional relational table where a typed column is already the primary read
  path, so duplicating into JSON adds complexity with no benefit and would create
  divergence risk between the two copies.

**Source evidence:**
- `intravox-source/lib/Service/PageIndexService.php:39, 57` — source explicitly
  syncs status from JSON into the index table on each index run, confirming this is
  a workaround for a document-store architecture.

**Rationale:** A typed column enables indexed `WHERE` queries on publication status
(required for the composite index `idx_mydash_dash_user_pubstatus` in REQ-DASH-019
and for any future bulk-status endpoint). It also eliminates desync risk.

---

### D4: Scheduled-as-published lazy resolution

**Decision:** A scheduled dashboard whose `publishAt <= now()` MUST be treated as
published on every read, regardless of whether a background job has run. The optional
background job that eagerly flips the column to `'published'` is for audit-log
cleanliness only, not for correctness. Any code path that checks visibility MUST
implement the `publishAt <= now()` comparison — it cannot rely solely on the stored
`publicationStatus` value.

**Rationale:** Background jobs can be delayed, disabled, or miss a run. Making
read-time materialisation the contractual guarantee means there is no window during
which a scheduled dashboard appears unpublished to viewers after its due time. The
eager flip (REQ-DASH-024 optional scenario) is a cosmetic optimisation: it keeps the
stored column consistent with the logical state so that audit queries on the raw
table are not misleading.

---

### D5: Naming hygiene — do not name the new MyDash service `PublicationSettingsService`

**Decision:** The MyDash service that owns publish / unpublish / schedule transitions
MUST NOT be named `PublicationSettingsService`. Suggested names:
`DashboardPublicationService` or `PublicationStateService`.

**Source evidence:**
- `intravox-source/lib/Service/PublicationSettingsService.php` — this class manages
  MetaVox field names used by the news-widget date-filtering import path. It has no
  connection to draft/published page state.

**Rationale:** When developers cross-reference MyDash code against the source for
implementation patterns, a class named identically to a source class in the same
conceptual area (publication) but with completely different responsibilities would
cause serious confusion. Using a distinct name eliminates that risk upfront.

## Spec changes implied

- **REQ-DASH-025 scenario "Existing dashboards default to published after migration"**:
  Change `DEFAULT 'draft'` to `DEFAULT 'published'`; remove the line requiring an
  explicit `UPDATE … SET publicationStatus = 'published'` backfill statement. The
  outcome sentence ("all existing dashboard rows backfilled to `'published'`") remains
  correct, only the mechanism differs.
- Optionally add a NOTE to REQ-DASH-025 clarifying that new dashboards default to
  `'draft'` via application logic in `DashboardService`, not via the column default.

## Open follow-ups

- Confirm whether `DashboardMapper::findVisibleToUser()` should materialise the
  scheduled→published transition inline in SQL (`WHERE publishAt <= NOW()`) or in
  PHP post-fetch; SQL is preferred for the index to be effective.
- Verify that the `TemplateService::createDashboardFromTemplate()` call site (the
  `basedOnTemplate` flow) is the only place template copies are created — there may
  be an admin bulk-distribute path that also needs the `'draft'` seed.
- Decide whether `scheduledAt` (time the schedule action was called) should be
  persisted separately from `publishAt` (time publication will occur); currently not
  in scope but may be useful for audit logs.
