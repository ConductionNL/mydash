# Design — Nextcloud Unified Search Integration

## Context

Nextcloud provides a global search interface (Ctrl+K / Cmd+K) that aggregates results from all installed apps via the `OCP\Search\IProvider` interface. Currently, MyDash dashboards are not exposed to this search, so users cannot discover dashboards without first opening the MyDash app. This change implements a search provider that makes dashboards, widgets, and metadata searchable from the unified search bar.

## Goals / Non-Goals

**Goals:**

- Make MyDash content discoverable via Nextcloud's unified search without entering the app.
- Support searching by dashboard name, description, widget content, and metadata field values.
- Respect permission boundaries — only return results the user can view.
- Degrade gracefully when optional capabilities (metadata fields) are unavailable.
- Deliver results within Nextcloud's search response budget (bounded per-bucket result count).
- Integrate with Nextcloud's search UI seamlessly (provider name, result icons, deep-links).

**Non-Goals:**

- Full-text search with ranking/relevance scoring (case-insensitive substring matching is sufficient).
- Custom search syntax or operators (e.g., `dashboard:name` filters).
- Real-time search suggestions while typing (Nextcloud's search UI manages debouncing).
- Indexed search performance optimization (search is executed on-demand, not pre-indexed).

## Decisions

### D1: Multi-bucket results (dashboard / widget / metadata)

**Decision**: The provider returns results in three buckets: dashboards (name/description match), widgets (content match), and metadata (field value match), each capped at 10 results.

**Alternatives considered:**

- Single flat list of all matches ranked by relevance. Rejected — users benefit from seeing result types separately (e.g., "I found your dashboard name" vs "I found your widget content").
- Separate search providers per type. Rejected — Nextcloud's search UI already groups by provider, and three providers would clutter the results UI.
- Unlimited results with pagination. Rejected — Nextcloud's search popup must remain responsive; 30 total results (10 per type) is well within the response budget.

**Rationale**: Separate buckets keep the search results organized without adding provider complexity, and the 10-result cap per type balances discoverability with UI responsiveness.

### D2: Permission boundary via `DashboardService::getVisibleToUser()`

**Decision**: The provider calls `DashboardService::getVisibleToUser()` once per search to fetch the canonical permission-filtered set, then searches only within that set.

**Alternatives considered:**

- Check permissions per result after matching. Rejected — O(n) checks are redundant when the boundary already exists and caches the visible set.
- Defer permission checks to the frontend. Rejected — violates OWASP A01:2021 (broken access control); results must never leak to the client.

**Rationale**: `getVisibleToUser()` is the canonical permission boundary (shares, group membership, publication state); reusing it ensures consistency and performance.

### D3: Graceful degradation for optional metadata

**Decision**: If the `dashboard-metadata-fields` capability is unavailable, the provider catches the exception and returns dashboards + widgets only (no error).

**Alternatives considered:**

- Throw an error if metadata cannot be fetched. Rejected — the search is still useful without metadata.
- Pre-check the capability before searching. Rejected — permission failures and other transient exceptions need the same handling.

**Rationale**: Graceful degradation matches the spirit of REQ-SRCH-004: metadata search is a nice-to-have enhancement, not a blocking requirement. The search always works with or without it.

### D4: Case-insensitive substring matching (not regex)

**Decision**: Match dashboards and widgets using `mb_strtolower()` + `str_contains()` for case-insensitive substring matching. No regex or full-text search.

**Alternatives considered:**

- Full-text search with word boundaries. Rejected — adds complexity and database dependencies; substring matching is sufficient for dashboard discovery.
- Whole-word matching only. Rejected — users often search for partial terms (e.g., "pipe" should match "Pipeline").

**Rationale**: Substring matching is simple, predictable, and familiar from other Nextcloud apps (contacts, files). It matches user expectations for a quick discovery search.

### D5: Result ordering: dashboards → widgets → metadata

**Decision**: Results are concatenated in a fixed order: dashboards first, then widgets, then metadata. Within each bucket, the order is deterministic (e.g., by match position or creation order).

**Alternatives considered:**

- Interleave results by relevance across all types. Rejected — makes ordering unpredictable and requires defining "relevance."
- User-configurable result order. Rejected — not needed for a simple search provider.

**Rationale**: Fixed ordering is predictable and makes testing deterministic. Users see the result type that matched most often first (dashboards are the primary object type).

### D6: Widget deep-link hint via `widget=<placementId>` fragment

**Decision**: Widget search results include a `resourceUrl` with a `#dashboard/<dashUuid>;widget=<placementId>` fragment. The frontend MAY scroll to the specific widget (future enhancement).

**Alternatives considered:**

- Separate detail route `/apps/mydash/widget/<placementId>`. Rejected — adds backend routes and complexity; the SPA can deep-link via fragment without a new endpoint.
- Store widget position in the URL query string. Rejected — fragments are cleaner for client-side routing and don't trigger a server round-trip.

**Rationale**: Fragments keep the URL simple and enable future frontend enhancements (scrolling to widget) without backend changes.

### D7: Provider order = 50 (mid-range); boost to 5 in-app

**Decision**: `getOrder()` returns `50` by default (positioned between admin-search at ~10 and contacts/files at 100+). When the current route is a MyDash route, return `5` to boost visibility.

**Alternatives considered:**

- Fixed order 50 always. Rejected — misses the in-app discoverability boost.
- Fixed low order (5) always. Rejected — pushes other providers down unnecessarily when outside MyDash.

**Rationale**: Mid-range order keeps MyDash visible by default without dominating. The in-app boost ensures users see MyDash results first when they're already using the app (low friction).

### D8: HTML stripping for text widget content

**Decision**: Text widget content is HTML-decoded and tag-stripped via `strip_tags()` + `html_entity_decode()` before substring matching.

**Alternatives considered:**

- Match on raw HTML. Rejected — `<h1>Revenue</h1>` would not match "revenue" (tag interference).
- Parse HTML and index text nodes only. Rejected — adds complexity; basic tag stripping works for user-facing content.

**Rationale**: `strip_tags()` is simple and handles the common case (markdown rendered as HTML, or plain text with basic formatting). Matches the user's expectation: "I wrote 'revenue' in my widget, so searching for 'revenue' should find it."

## Design Decisions Matrix

| Decision | Rationale | Risk |
|----------|-----------|------|
| D1: Multi-bucket | Organized results, bounded response | None — tested pattern in Nextcloud |
| D2: Visibility boundary | Canonical permission source | Low — reuses existing service |
| D3: Graceful degradation | Metadata is optional | Low — exception handling is standard |
| D4: Substring matching | Simple, predictable | Medium — full-text would be "better" but adds dependencies |
| D5: Fixed ordering | Deterministic, testable | Low — users expect predictable results |
| D6: Widget deep-link | SPA-native navigation | Low — fragment routing is standard |
| D7: Order priority | In-app boost, out-of-app discretion | Low — order is cosmetic |
| D8: HTML stripping | Works for rendered content | Medium — does not handle complex nested HTML perfectly |

## Risks / Trade-offs

- **Risk:** Widget content search is slow on dashboards with many text widgets. → **Mitigation:** Cap to 10 results per search; optimize by skipping non-text widgets early.
- **Risk:** Permission changes are not real-time. → **Mitigation:** `getVisibleToUser()` is called fresh per search; shared dashboards and group membership are re-evaluated.
- **Risk:** Metadata field key names are not searchable (REQ-SRCH-004). → **Mitigation:** Document in user-facing docs; field keys are system-level metadata, not user-facing content.
- **Risk:** HTML stripping fails on deeply nested or malformed HTML. → **Mitigation:** `strip_tags()` is fail-safe (graceful degradation to text); malformed content is still searchable.
- **Risk:** Single-character searches (e.g., "a") may return false positives. → **Mitigation:** Nextcloud's search bar MAY filter queries client-side; not a backend concern.

## Migration Plan

1. **Implement the search provider class** — `lib/Search/MyDashSearchProvider.php` with all required methods and the multi-bucket matching logic.
2. **Register the provider in Application.php** — ensure `registerSearchProvider()` is called during app bootstrap.
3. **Declare search capability in info.xml** — add `<types><search/></types>`.
4. **Add translation strings** — register `Dashboards`, `Widget content on %s`, `Metadata: %1$s = %2$s` in `l10n/en.json` and `l10n/nl.json`.
5. **Implement matching helpers** — `matchesDashboard()`, `findMatchingTextPlacements()`, `findMatchingMetadata()`.
6. **Add unit tests** — cover all scenarios in REQ-SRCH-001 through REQ-SRCH-012.
7. **Rollback:** Pure backend change, no schema migration. Reverting the PR removes the search provider with no data loss.

## Open Questions

- Should metadata field keys ever be searchable? (Currently excluded by design; can be revisited if user feedback demands it.)
- Is the 10-result cap per bucket sufficient, or should it be configurable? (10 is reasonable for initial release; can be tuned later based on user feedback.)

## Seed Data

For testing the search provider without manual dashboard creation, seed the app with example dashboards and widgets:

### Dashboard 1: Marketing Campaign 2026

```json
{
  "@self": {
    "register": "dashboards",
    "schema": "Dashboard",
    "slug": "marketing-campaign-2026"
  },
  "name": "Marketing Campaign 2026",
  "description": "Company quarterly performance overview for marketing initiatives",
  "createdAt": "2026-01-15T08:00:00Z",
  "owner": "alice",
  "isPublished": true
}
```

### Dashboard 2: Q1 Metrics

```json
{
  "@self": {
    "register": "dashboards",
    "schema": "Dashboard",
    "slug": "q1-metrics"
  },
  "name": "Q1 Metrics",
  "description": "Company quarterly performance overview",
  "createdAt": "2026-01-10T10:30:00Z",
  "owner": "bob",
  "isPublished": true
}
```

### Dashboard 3: Sales Pipeline Analysis

```json
{
  "@self": {
    "register": "dashboards",
    "schema": "Dashboard",
    "slug": "sales-pipeline-analysis"
  },
  "name": "Sales Pipeline Analysis",
  "description": "Current sales opportunities and conversion metrics",
  "createdAt": "2026-01-05T14:20:00Z",
  "owner": "charlie",
  "isPublished": true
}
```

### Dashboard 4: Team Analytics

```json
{
  "@self": {
    "register": "dashboards",
    "schema": "Dashboard",
    "slug": "team-analytics"
  },
  "name": "Team Analytics",
  "description": "Performance metrics for the sales group",
  "createdAt": "2026-01-08T09:15:00Z",
  "owner": "sales-team",
  "isPublished": false,
  "groupId": "sales"
}
```

### Dashboard 5: Analytics (with text widgets)

```json
{
  "@self": {
    "register": "dashboards",
    "schema": "Dashboard",
    "slug": "analytics-with-widgets"
  },
  "name": "Analytics",
  "description": "Key analytics and performance metrics",
  "createdAt": "2026-01-12T11:00:00Z",
  "owner": "alice",
  "isPublished": true
}
```

**Associated Widget Placements for Dashboard 5:**

- Widget 1 (Text): `## Budget Proposal for Q2`
- Widget 2 (Text): `Meeting notes from Monday: Project timeline starts in January`
- Widget 3 (Text): `Sales targets for 2026: Revenue goal $5M`
- Widget 4 (Weather): `[Non-text widget, skipped in search]`

## Reuse Analysis

The search provider reuses:
- `OCP\Search\IProvider` and related Nextcloud search interfaces (no custom implementations needed).
- `DashboardService::getVisibleToUser()` (canonical permission boundary — no custom permission logic).
- `WidgetPlacementMapper::findByDashboardId()` (existing widget data access).
- `MetadataService::getMetadataForDashboard()` (optional metadata retrieval).
- `IL10N` factory for localization (standard Nextcloud translation mechanism).

No duplication of permission checking, data access, or localization logic. The provider is a thin wrapper that orchestrates existing services.
