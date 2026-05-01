# Design — NC Unified Search Integration

## Context

Nextcloud ships a platform-wide search surface (Ctrl+K) backed by the `OCP\Search\IProvider`
contract. Any app registering a provider gains automatic presence in the search dropdown — no
custom UI or route is needed. MyDash currently has no registered provider, so dashboards and
widget content are invisible to operators and end-users searching from the global bar.

The integration must stay simple: the unified search fires on keypress, so the response budget
is tight. A pre-built search index would require a separate indexing job, schema migrations, and
cache invalidation logic that is disproportionate for this scope. Live querying at search-time
with a narrow `LIKE %term%` scan is sufficient because the total number of dashboards per user
is small.

Permission correctness is non-negotiable. The NC search API passes results straight to the
frontend, so any dashboard the requester cannot view must be excluded server-side before the
response is assembled. The existing `canViewDashboard($userId, $dashboard)` helper on the
`DashboardService` is the single gate for this check.

## Goals / Non-Goals

**Goals:**
- Register a `mydash` search provider visible in the NC Ctrl+K dropdown.
- Surface dashboard titles, descriptions, and widget body text as search results.
- Enforce per-result permission filtering before returning results.
- Return a direct link that opens the matching dashboard.

**Non-Goals:**
- Pre-built or persisted search index.
- Searching widget configuration / settings fields.
- Full-text-search infrastructure (e.g. Elasticsearch integration).
- Cross-user or admin-wide search (results are always scoped to the requesting user).

## Decisions

### D1: Indexed surface
**Decision:** Index dashboard title + description + widget body text only. Widget config keys,
setting values, and internal metadata are excluded.
**Alternatives considered:** Index everything stored in the widget JSON blob.
**Rationale:** Config fields are operator-facing strings (column names, filter expressions) that
produce meaningless results for end-users. Keeping the surface narrow also bounds query cost.

### D2: Search backend
**Decision:** Execute a live `LIKE %term%` query at search-time via the existing
`DashboardMapper` and `WidgetMapper`. No index table is created.
**Alternatives considered:** Nightly job building a `mydash_search_index` table with pre-tokenised
snippets.
**Rationale:** The `IProvider` contract does not require an index. User dashboard counts are low
(typically < 100), so a live query is fast enough. An index adds migration surface and cache
invalidation complexity with no measurable benefit at this scale.

### D3: Permission filtering
**Decision:** Call `DashboardService::canViewDashboard($userId, $dashboard)` for every candidate
result before adding it to the response. Results that fail the check are silently dropped.
**Alternatives considered:** Pre-filter by a join on the shares table inside the SQL query.
**Rationale:** Centralising the permission check in the service layer ensures the search path
uses the same logic as the page controller — no risk of the two diverging.

### D4: Result ranking
**Decision:** Title matches are returned before description matches, which are returned before
widget body matches. Within each tier, results are ordered by `updatedAt DESC`.
**Alternatives considered:** Relevance scoring with term frequency; single flat list ordered by
`updatedAt`.
**Rationale:** A simple tier sort is deterministic and requires no scoring infrastructure. It
matches user intuition (a title match is more relevant than a body match).

### D5: Provider ID and group label
**Decision:** Register the provider with id `mydash`. The NC search UI derives the dropdown
group label from the provider id — this will render as "MyDash".
**Alternatives considered:** `my_dash`, `intravox`, or a localised string id.
**Rationale:** `mydash` matches the app id used everywhere else in info.xml and routes, keeping
the namespace consistent and avoiding a separate label mapping.

### D6: Result link target
**Decision:** Each result's URL is built via
`linkToRoute('mydash.PageController.view', ['dashboardUuid' => $dashboard->getUuid()])`.
**Alternatives considered:** A dedicated `/search-result/{uuid}` redirect route.
**Rationale:** The page controller route already handles deep-linking to a specific dashboard.
Adding a redirect layer would be dead weight.

## Risks / Trade-offs

- `LIKE %term%` queries on large body-text columns can be slow; a database index on the widget
  body column should be added in the same migration.
- The provider runs in the NC search request context (user-authenticated); CLI or cron contexts
  must not trigger it.
- Widget body text may contain raw HTML from rich-text widgets; strip tags before indexing to
  avoid leaking markup fragments into search snippets.

## Open follow-ups

- Evaluate whether a dedicated `mydash_search_index` table is warranted once widget counts
  grow beyond ~1 000 per installation.
- Add snippet highlighting (bold the matched term) if NC's `SearchResult` API exposes that
  field in a future platform version.
- Consider exposing a `mydash:search:reindex` CLI command if an index is adopted later.
