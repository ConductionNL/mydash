# Nextcloud unified search integration

## Why

Nextcloud's Ctrl+K / Cmd+K unified search is the primary discovery mechanism for users navigating between apps and finding content. MyDash dashboards and widget content currently have no integration with this search, meaning users must navigate explicitly to the MyDash app and browse manually. This change exposes dashboards and widget content to Nextcloud's search provider system, allowing users to find and jump directly to dashboards by name, description, or widget text from the global search bar.

## What Changes

- Register a new `MyDashSearchProvider` class implementing `OCP\Search\IProvider` in the MyDash app.
- The provider exposes search results for:
  - Dashboards the user can VIEW whose name or description matches the query.
  - Text-display-widget placements on viewable dashboards whose HTML/markdown content matches the query (with deep-link to the specific widget).
  - Dashboard metadata field values matching the query (when the `dashboard-metadata-fields` capability is enabled; silent degradation if not).
- Each result entry includes a dashboard or widget icon, title, subline (e.g., creation metadata), and a deep-link resource URL for immediate navigation.
- Search results are paginated with cursor support so "Load more" in Nextcloud's search UI works seamlessly.
- The provider caps result types to maintain search-popup responsiveness and respects permission boundaries via the existing `permissions` capability.

## Capabilities

### New Capabilities

`nc-unified-search-integration` — provider registration + search contract + match logic + permission filtering + pagination + localization.

### Modified Capabilities

`dashboards` — now discoverable via Nextcloud unified search; no REQ changes, but the feature depends on existing dashboards and permissions REQs.

## Impact

**Affected code:**

- `lib/Service/SearchProvider/MyDashSearchProvider.php` — new class implementing `OCP\Search\IProvider` with `search(IUser, ISearchQuery): SearchResult` method.
- `appinfo/info.xml` — register the search provider via `<types>` declaration.
- `lib/Service/SearchProvider/DashboardSearchIndexer.php` — optional helper to index searchable dashboard fields and widget content (deferred to follow-up optimization; basic LIKE queries acceptable at 10K-dashboard scale).
- `src/views/DashboardDetail.vue` — optional: enhance the dashboard detail route to support `#widget-{placementId}` hash fragment for deep-linking from search results (or use GET param `?widgetId=X` as fallback).

**Affected APIs:**

- 1 new search provider registered; no changes to existing dashboard/widget API routes.
- Nextcloud's built-in `GET /ocs/v2.php/search/providers/{providerId}/search?term={query}` route automatically calls the provider's `search()` method.

**Dependencies:**

- `OCP\Search\IProvider`, `OCP\Search\SearchResult`, `OCP\Search\SearchResultEntry` (Nextcloud 21+, available in all supported Nextcloud versions).
- `OCP\IL10N` for localized provider name and result sublines.
- No new composer or npm dependencies.

**Migration:**

- Zero-impact: the provider is registered at app bootstrap and queries are read-only.
- No schema migration required.

## Risks

- **Performance**: Naive LIKE queries on large dashboard datasets (10K+) may exceed the 500ms timeout. Indexed columns and query limits mitigate this; a full-text index is a future concern (ADR-level if adopted).
- **Permission boundary**: must never leak dashboards the user cannot VIEW — the implementation delegates to the existing `permissions` capability for the boundary check.
- **Localization coverage**: provider name and result sublines must be translated; missing translations fall back to English strings.
