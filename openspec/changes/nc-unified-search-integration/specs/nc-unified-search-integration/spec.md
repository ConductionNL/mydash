---
status: draft
---

# Nextcloud Unified Search Integration Specification

## Purpose

Nextcloud's unified search (Ctrl+K / Cmd+K) provides a global discovery mechanism for content across all installed apps. MyDash dashboards, widgets, and metadata are currently invisible to this search. This specification formalises the integration of MyDash content into Nextcloud's search provider system, allowing users to discover and navigate to dashboards by name, description, widget content, or metadata field values from the global search bar.

## ADDED Requirements

### Requirement: REQ-SRCH-001 Search Provider Registration

The MyDash app MUST register a search provider implementing Nextcloud's `OCP\Search\IProvider` interface so that pressing Ctrl+K / Cmd+K in Nextcloud automatically includes MyDash dashboards in the unified search results.

#### Scenario: Provider is registered and discoverable
- GIVEN Nextcloud is running with MyDash app installed and enabled
- WHEN a user presses Ctrl+K to open the unified search bar
- THEN the MyDash search provider MUST be loaded via the app's bootstrap process
- AND the provider's `getId()` MUST return `'mydash'`
- AND the provider's `getName()` MUST return the translated string `'Dashboards'` in the user's Nextcloud language
- AND the provider's `getOrder()` MUST return `50` to position it mid-range (admin-search providers typically have lower order values)

#### Scenario: Provider implements the IProvider contract
- GIVEN the MyDash app is installed
- WHEN Nextcloud's search dispatcher calls the provider interface
- THEN the provider class MUST implement all required methods: `getId()`, `getName()`, `getOrder()`, `search(IUser, ISearchQuery): SearchResult`
- AND the methods MUST have correct type hints matching `OCP\Search\IProvider`

#### Scenario: Provider initialization via app bootstrap
- GIVEN MyDash app info.xml declares `<types><type>search</type></types>`
- WHEN the MyDash app boots
- THEN the container MUST register an instance of `MyDashSearchProvider` with the Nextcloud search registry
- AND the provider instance MUST be reachable at `/apps/mydash/lib/Service/SearchProvider/MyDashSearchProvider.php`

#### Scenario: Localized provider name
- GIVEN a Nextcloud instance with language set to Dutch (nl)
- WHEN the search provider's `getName()` is called
- THEN the returned string MUST be translated to Dutch via `IL10N::t('Dashboards')`
- AND the user's search bar MUST display "Dashboards" (Dutch: potentially "Dashboards" or localized equivalent) as the provider label

#### Scenario: Provider name appears in search UI
- GIVEN a user opens the unified search bar (Ctrl+K)
- WHEN MyDash results are shown
- THEN the search UI MUST display the provider name from `getName()` as a section header
- NOTE: Nextcloud's search UI typically groups results by provider name.

### Requirement: REQ-SRCH-002 Dashboard Name and Description Matching

The search provider MUST find dashboards whose name or description matches the search query using case-insensitive substring matching.

#### Scenario: Dashboard name match
- GIVEN user "alice" has a dashboard named "Marketing Campaign 2026"
- WHEN she searches for "market" via the unified search bar
- THEN the "Marketing Campaign 2026" dashboard MUST appear in the search results
- AND the result title MUST be "Marketing Campaign 2026"
- AND the search MUST be case-insensitive (searching "MARKET" or "Market" also returns the result)

#### Scenario: Dashboard description match
- GIVEN user "bob" has a dashboard with name "Q1 Metrics" and description "Company quarterly performance overview"
- WHEN he searches for "quarterly" via the unified search bar
- THEN the "Q1 Metrics" dashboard MUST appear in the search results
- AND the result title MUST be "Q1 Metrics" (name, not description)
- AND the subline MUST include metadata like creation info or widget count

#### Scenario: Partial substring match
- GIVEN user "alice" has a dashboard named "Sales Pipeline Analysis"
- WHEN she searches for "pipe"
- THEN the "Sales Pipeline Analysis" dashboard MUST appear
- AND the search MUST use substring matching, not whole-word matching (so "pipe" matches "Pipeline")

#### Scenario: Exact match and partial matches coexist
- GIVEN user "alice" has three dashboards: "Marketing", "Market Research", "Marketing Campaigns"
- WHEN she searches for "market"
- THEN all three dashboards MUST appear in the results
- AND Nextcloud's search UI MAY rank exact matches higher (rank order is not mandated by this spec)

#### Scenario: No match returns empty result
- GIVEN user "alice" has dashboards "Sales", "Marketing", "HR"
- WHEN she searches for "engineering"
- THEN no dashboard results MUST be returned
- AND the search provider MUST return `SearchResult::complete()` with an empty array (no error thrown)

### Requirement: REQ-SRCH-003 Widget Content Matching

The search provider MUST find text-display-widget placements on dashboards whose HTML or markdown content matches the search query, with a deep-link to the specific widget on the dashboard.

#### Scenario: Text widget content match
- GIVEN user "alice" has a dashboard "Analytics" with a text-display widget containing the markdown `## Budget Proposal for Q2`
- WHEN she searches for "budget"
- THEN a search result MUST appear with title "Analytics" and subline indicating a widget match (e.g., "Widget content on Analytics")
- AND the result `resourceUrl` MUST be `/apps/mydash/dashboard/{dashboardUuid}#widget-{placementId}` to deep-link to the specific widget

#### Scenario: Multiple text widgets on same dashboard
- GIVEN user "bob" has a dashboard "Notes" with three text widgets:
  - Widget A: "Meeting notes from Monday"
  - Widget B: "Project timeline: starts in January"
  - Widget C: "Sales targets for 2026"
- WHEN he searches for "january"
- THEN the result for Widget B MUST appear with a deep-link to Widget B's `{placementId}`
- AND searching for "sales" MUST return the result for Widget C
- AND both results share the same dashboard UUID but different widget placement IDs

#### Scenario: Text widget with HTML rendering
- GIVEN user "alice" uploads HTML content to a text-display widget: `<h1>Quarterly Report</h1><p>Revenue up 15%</p>`
- WHEN she searches for "revenue" or "quarterly"
- THEN the widget MUST be found via substring match on the rendered/stripped HTML content
- AND the search MUST handle HTML tags gracefully (not require exact tag matching)

#### Scenario: Case-insensitive widget content search
- GIVEN user "bob" has a text widget with content "IMPORTANT: Project Deadline Tomorrow"
- WHEN he searches for "important" (lowercase)
- THEN the widget MUST be found via case-insensitive match
- AND searching for "DEADLINE" (uppercase) MUST also return the result

#### Scenario: Widget placement without matching content
- GIVEN user "alice" has a dashboard with a text widget containing only "Lorem Ipsum"
- WHEN she searches for "budget"
- THEN this widget MUST NOT appear in the search results
- AND other non-matching widgets on the same dashboard MUST also not appear

#### Scenario: Non-text widgets are skipped
- GIVEN user "bob" has a dashboard with a weather widget, a calendar widget, and a text widget
- WHEN he searches for "forecast"
- THEN only the text widget is searchable (weather and calendar widgets are not indexed for content search)
- AND non-text widget types MUST be skipped silently (no error)

### Requirement: REQ-SRCH-004 Dashboard Metadata Field Matching (Optional Degradation)

When the `dashboard-metadata-fields` capability is enabled, the search provider MUST find dashboards whose metadata field values match the search query. The provider MUST degrade silently if the capability is not available.

#### Scenario: Metadata field value match (when capability enabled)
- GIVEN the `dashboard-metadata-fields` capability is enabled
- AND user "alice" has a dashboard with a custom metadata field "Year: 2026" and another field "Department: Sales"
- WHEN she searches for "2026"
- THEN a result MUST appear with title "Alice's Dashboard" and subline "Metadata: Year = 2026"
- AND the resourceUrl MUST be `/apps/mydash/dashboard/{dashboardUuid}`

#### Scenario: Metadata search falls back gracefully
- GIVEN the `dashboard-metadata-fields` capability is NOT available in the MyDash app
- WHEN user "bob" performs a search
- THEN the search provider MUST still return dashboard-name, description, and widget-content results
- AND no error MUST be thrown about missing metadata fields
- AND the provider MUST not attempt to fetch metadata (graceful degradation)

#### Scenario: Metadata field names are not searchable, only values
- GIVEN user "alice" has a metadata field named "ProjectStatus" with value "In Progress"
- WHEN she searches for "ProjectStatus"
- THEN the result MUST NOT appear (field name is not searchable)
- WHEN she searches for "In Progress"
- THEN the result MUST appear (field value is searchable)

#### Scenario: Case-insensitive metadata value search
- GIVEN user "bob" has a metadata field with value "URGENT"
- WHEN he searches for "urgent" (lowercase)
- THEN the result MUST appear via case-insensitive match

### Requirement: REQ-SRCH-005 Permission Filtering

The search provider MUST never return search results for dashboards that the user does NOT have VIEW permission on. Permission boundaries are enforced via the existing `permissions` capability.

#### Scenario: User sees only dashboards with VIEW permission
- GIVEN user "alice" owns dashboard "My Dashboard" and user "bob" owns dashboard "Bob's Private Dashboard" (not shared with alice)
- WHEN alice searches
- THEN "My Dashboard" MUST appear in results
- AND "Bob's Private Dashboard" MUST NOT appear
- AND the provider MUST call `DashboardPermissionService::canView()` for each candidate dashboard

#### Scenario: Permission check happens before result inclusion
- GIVEN user "alice" searches for a term that matches a dashboard she cannot view
- WHEN the search provider evaluates the match
- THEN the permission check MUST happen before the result is added to the SearchResult
- AND the match score or relevance is irrelevant if permission is denied (no leakage)

#### Scenario: Group-shared dashboard visible only to group members
- GIVEN the `multi-scope-dashboards` capability is enabled
- AND a group-shared dashboard "Team Analytics" exists with `groupId = 'sales'`
- AND user "alice" is in the 'sales' group, user "bob" is not
- WHEN alice searches for "analytics"
- THEN "Team Analytics" MUST appear
- WHEN bob searches for "analytics"
- THEN "Team Analytics" MUST NOT appear
- NOTE: Permission checking delegates to the existing `permissions` capability's group-membership logic

#### Scenario: Permission service unavailable (fall back to safe default)
- GIVEN the `permissions` capability or permission service fails to load
- WHEN a search is performed
- THEN the search provider MUST fail safely (return empty results or re-throw error in debug mode)
- AND the provider MUST NOT return any results if permission cannot be verified

### Requirement: REQ-SRCH-006 Result Entry Structure and Icons

Each SearchResultEntry returned by the provider MUST include a title, subline, thumbnail icon, and resource URL that identifies the dashboard or widget uniquely.

#### Scenario: Dashboard search result entry
- GIVEN user "alice" has a dashboard "Sales Dashboard" created by her with 5 widgets
- WHEN a search result is returned for this dashboard
- THEN the SearchResultEntry MUST include:
  - `title`: "Sales Dashboard" (dashboard name)
  - `subline`: "Created by Alice • 5 widgets" (formatted via IL10N)
  - `thumbnailUrl`: a URL to the MyDash dashboard icon (e.g., `/apps/mydash/img/dashboard-icon.svg` or Nextcloud's built-in icon)
  - `resourceUrl`: `/apps/mydash/dashboard/{uuid}` (absolute or relative, deep-link to the dashboard)

#### Scenario: Widget content search result entry
- GIVEN user "bob" has a dashboard "Analytics" with a text widget on widget placement 42
- WHEN a search result is returned for the widget
- THEN the SearchResultEntry MUST include:
  - `title`: "Analytics" (parent dashboard name)
  - `subline`: "Widget content on Analytics" (formatted via IL10N)
  - `thumbnailUrl`: a URL to a widget-type icon (e.g., `/apps/mydash/img/widget-icon.svg` or the text-widget icon)
  - `resourceUrl`: `/apps/mydash/dashboard/{dashboardUuid}#widget-{placementId}` (hash fragment targets the specific widget)

#### Scenario: Metadata field search result entry
- GIVEN the `dashboard-metadata-fields` capability is enabled
- AND a search result for a metadata field match is returned
- THEN the SearchResultEntry MUST include:
  - `title`: dashboard name
  - `subline`: "Metadata: {fieldName} = {fieldValue}" (formatted via IL10N)
  - `thumbnailUrl`: a metadata icon or fallback to dashboard icon
  - `resourceUrl`: `/apps/mydash/dashboard/{uuid}` (no hash fragment, user lands on main dashboard view)

#### Scenario: Icon URL is valid and reachable
- GIVEN a search result is returned with a `thumbnailUrl`
- WHEN the Nextcloud frontend fetches the icon
- THEN the URL MUST be accessible (HTTP 200) and return a valid SVG or image file
- AND the icon MUST be sized appropriately for display in the search UI (typically 32×32 or 64×64px)

### Requirement: REQ-SRCH-007 Result Capping and Pagination

The search provider MUST cap result counts per result type to keep the search-popup responsive. Pagination MUST support cursor-based navigation so "Load more" in Nextcloud's search UI works seamlessly.

#### Scenario: Dashboard results capped to 10
- GIVEN user "alice" has 25 dashboards matching the search term "dashboard"
- WHEN she searches
- THEN the initial results MUST include at most 10 dashboard entries
- AND the SearchResult MUST include a cursor or pagination indicator so the frontend can load the next 10

#### Scenario: Widget results capped to 10
- GIVEN user "bob" has 50 text widgets with matching content across multiple dashboards
- WHEN he searches
- THEN the initial results MUST include at most 10 widget entries
- AND each result MUST deep-link to the correct widget placement ID

#### Scenario: Metadata results capped to 10
- GIVEN the `dashboard-metadata-fields` capability is enabled
- AND 15 metadata field values match the search term
- WHEN the search is performed
- THEN at most 10 metadata results MUST be returned
- AND a cursor MUST be provided for pagination

#### Scenario: Query limit parameter is respected
- GIVEN a search query includes a limit parameter `?limit=20`
- WHEN the search is performed
- THEN the provider MUST respect the limit up to its internal maximum (or override with 10 if 20 > max)
- AND Nextcloud's `ISearchQuery::getLimit()` method MUST be called to determine the requested limit

#### Scenario: Cursor-based pagination
- GIVEN user "alice" has 25 matching dashboards and requests the first page
- WHEN the search completes with 10 results
- THEN the SearchResult MUST include a cursor (typically the ID of the last-seen result)
- WHEN alice clicks "Load more" and the same search is re-run with the cursor parameter
- THEN the next 10 results MUST be returned, skipping the first 10
- AND the cursor parameter is obtained from `ISearchQuery::getCursor()`

#### Scenario: Combined result types and capping
- GIVEN a search matches 5 dashboards, 8 widgets, and 6 metadata fields
- WHEN results are returned
- THEN the total MUST not exceed approximately 30 items (10 per type, rough guideline; exact cap is left to implementation)
- AND each result type SHOULD be represented fairly to give users visibility into all result categories

### Requirement: REQ-SRCH-008 Localization of Provider and Results

All user-facing strings in the search provider MUST be translated via Nextcloud's `IL10N` translation factory so they appear in the user's language.

#### Scenario: Provider name is translated
- GIVEN Nextcloud language is set to Dutch (nl)
- WHEN a user opens the unified search
- THEN the provider label MUST be displayed in Dutch (e.g., as translated by `IL10N::t('Dashboards')`)
- AND the translation string MUST be registered in the app's translation files

#### Scenario: Subline strings are translated
- GIVEN user "bob" (NC language: Dutch) has a dashboard "Analytics" with 5 widgets
- WHEN he views the search result
- THEN the subline MUST read "Created by Bob • 5 widgets" translated to Dutch
- AND the translation string `'Created by %s • %d widgets'` MUST exist in Dutch translation files

#### Scenario: Widget subline is translated
- GIVEN a search result for a widget on dashboard "Marketing"
- WHEN displayed to a user with NC language set to French
- THEN the subline MUST read "Widget content on Marketing" translated to French
- AND the translation string `'Widget content on %s'` MUST exist in French translation files (or fallback to English)

#### Scenario: Metadata subline is translated (when capability enabled)
- GIVEN the `dashboard-metadata-fields` capability is enabled
- AND a search result for a metadata field match is shown
- THEN the subline MUST read "Metadata: Year = 2026" translated to the user's language
- AND the translation string `'Metadata: %s = %s'` MUST exist in the app's translation files

#### Scenario: Missing translation falls back to English
- GIVEN Nextcloud language is set to a language not yet translated (e.g., "xx-test")
- WHEN a search is performed
- THEN the provider MUST fall back to English strings via `IL10N` (Nextcloud's standard behavior)
- AND no error MUST be thrown

### Requirement: REQ-SRCH-009 Empty Result Handling

When a search query matches no dashboards, widgets, or metadata fields, the provider MUST return an empty SearchResult instead of an error or 404 response.

#### Scenario: No matches returns empty array
- GIVEN user "alice" has dashboards "Sales", "Marketing", "HR"
- WHEN she searches for "nonexistent-term-xyz"
- THEN the search provider MUST return `SearchResult::complete($this->getName(), [])`
- AND the response HTTP status MUST be 200 (not 404 or 500)
- AND the user's search bar MUST show "No results for 'nonexistent-term-xyz'" or equivalent

#### Scenario: Empty search term
- GIVEN a user submits a search with an empty or whitespace-only query string
- WHEN the provider processes the query
- THEN the provider MAY return an empty array (implementation-specific)
- OR the provider MAY return a helpful message like "Enter a search term"
- AND no error MUST occur

#### Scenario: Very short query term (single character)
- GIVEN user "bob" searches for "a" (single character)
- WHEN the search is performed
- THEN the provider MUST attempt to match (e.g., dashboard "Admin Dashboard" matches)
- AND if no matches exist, return an empty result gracefully
- NOTE: Single-character searches are allowed by Nextcloud; performance impacts are implementation-specific

### Requirement: REQ-SRCH-010 Search Performance

The search provider MUST complete all queries in under 500ms for dashboard libraries up to 10,000 dashboards. Indexed database columns and result capping are the primary mechanisms to achieve this.

#### Scenario: Query completes in <500ms for 1000 dashboards
- GIVEN a MyDash instance with 1,000 dashboards
- WHEN user "alice" performs a search query
- THEN the provider's `search()` method MUST return within 500ms
- AND the response MUST be complete (all matching results returned or capped per REQ-SRCH-007)

#### Scenario: Query completes in <500ms for 10000 dashboards
- GIVEN a MyDash instance with 10,000 dashboards
- WHEN user "bob" performs a search query matching 100+ dashboards
- THEN the provider's `search()` method MUST return within 500ms
- AND results MUST be capped to 10 per type to keep response time acceptable
- AND the database query MUST use indexed columns (`name`, `description`) with LIKE operators

#### Scenario: Indexed columns are present
- GIVEN the MyDash app is installed and a schema migration has run
- WHEN the database is queried
- THEN the `oc_mydash_dashboards.name` column MUST have an index
- AND the `oc_mydash_dashboards.description` column MUST have an index
- AND LIKE queries on these columns MUST benefit from the index for fast substring search
- NOTE: Full-text indices are a future optimization (out of scope for this change)

#### Scenario: Widget content search is efficient
- GIVEN user "alice" has 10,000 dashboards with a total of 50,000 text-widget placements
- WHEN she searches for a term
- THEN the search MUST NOT scan all 50,000 widgets individually
- AND the provider MUST return within 500ms by using a JOIN with the indexed dashboards table or similar optimization

#### Scenario: Permission checks do not degrade performance
- GIVEN user "bob" performs a search
- WHEN the provider calls `DashboardPermissionService::canView()` for each result candidate
- THEN the permission checks MUST be efficient (e.g., use a single batch query or in-memory set of readable dashboard IDs)
- AND the total search time (including permission filtering) MUST remain <500ms

### Requirement: REQ-SRCH-011 Nextcloud Search UI Integration

The search provider MUST integrate seamlessly with Nextcloud's built-in search UI so that users can navigate directly to dashboards by clicking search results or pressing Enter.

#### Scenario: Search result is clickable
- GIVEN user "alice" opens the unified search (Ctrl+K) and types "marketing"
- WHEN the "Marketing Dashboard" result appears
- WHEN she clicks on the result title or thumbnail
- THEN the browser MUST navigate to `/apps/mydash/dashboard/{uuid}`
- AND the MyDash app MUST render the dashboard view

#### Scenario: Search result resourceUrl is deep-linked
- GIVEN a search result for a widget on dashboard "Analytics"
- WHEN user "bob" clicks the result
- THEN the browser MUST navigate to `/apps/mydash/dashboard/{dashboardUuid}#widget-{placementId}`
- AND the MyDash frontend MUST scroll or highlight the widget with placement ID `{placementId}`

#### Scenario: Search result thumbnail is displayed
- GIVEN a search result is rendered in Nextcloud's search popup
- WHEN the result entry includes a `thumbnailUrl`
- THEN Nextcloud's UI MUST fetch and display the thumbnail image
- AND the image MUST be sized appropriately (typically 32×32px or 64×64px)

#### Scenario: Search result appears in the correct section
- GIVEN the search results include dashboards, files, and contacts
- WHEN the results are displayed
- THEN the MyDash results MUST appear under a "Dashboards" section header (from `getName()`)
- AND they MUST NOT be mixed with other app results

### Requirement: REQ-SRCH-012 Provider Order in Nextcloud Search

The MyDash search provider MUST be positioned at order `50` so that it appears after high-priority admin-search providers but before lower-priority result types in Nextcloud's search UI.

#### Scenario: Provider order is respected
- GIVEN Nextcloud has multiple search providers installed (e.g., admin-search at order 10, MyDash at order 50, contacts at order 100)
- WHEN the unified search is opened
- THEN the results MUST be grouped and displayed in order: admin-search results first, then MyDash results, then contacts
- AND the order is determined by `getOrder()` returning `50`

#### Scenario: Order does not affect search completeness
- GIVEN user "alice" searches for a term
- WHEN the search is performed
- THEN all matching MyDash results MUST be found regardless of order
- AND the order only affects the visual grouping and ranking in the search UI

---

## ADDED Dependencies

- `OCP\Search\IProvider` (Nextcloud 21+)
- `OCP\Search\SearchResult` (Nextcloud 21+)
- `OCP\Search\SearchResultEntry` (Nextcloud 21+)
- `OCP\Search\ISearchQuery` (Nextcloud 21+)
- `OCP\IUser` (Nextcloud Core)
- `OCP\IL10N` (Nextcloud Core)
- Existing: `DashboardMapper`, `DashboardPermissionService`

## ADDED Indexes

- `oc_mydash_dashboards.name` (existing; confirm via schema)
- `oc_mydash_dashboards.description` (may need to be added if missing)

## ADDED Configuration / Feature Flags

- None; the search provider is always available when MyDash is enabled.
- If `dashboard-metadata-fields` capability is available, metadata search is enabled; otherwise, graceful degradation.
