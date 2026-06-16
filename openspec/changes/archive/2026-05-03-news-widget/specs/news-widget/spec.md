---
status: draft
---

# News Widget Specification

## ADDED Requirements

### Requirement: REQ-NEWS-001 Widget Registration

The system MUST register a new dashboard widget with id `mydash_news` via `OCP\Dashboard\IManager` that appears in the widget picker alongside other discovered Nextcloud dashboard widgets.

#### Scenario: Widget registration on bootstrap
- GIVEN MyDash is installed and enabled
- WHEN the application bootstraps (AppInfo/Bootstrap.php or equivalent lifecycle hook)
- THEN the system MUST call `IManager::registerWidget()` with widget metadata
- AND the widget MUST have id `mydash_news`, translatable title (e.g., `app.mydash.news_widget_title`), and a feed/newspaper icon URL
- AND the widget MUST support Nextcloud Dashboard Widget API v2 (`IAPIWidgetV2`) for item loading

#### Scenario: Widget picker includes news widget
- GIVEN a user opens the widget picker dialog
- WHEN the picker loads available Nextcloud widgets
- THEN the `mydash_news` widget MUST appear in the list of discovered widgets
- AND it MUST be selectable for placement on any dashboard

#### Scenario: Multiple news widget instances
- GIVEN a user has added the news widget to their dashboard once
- WHEN they open the widget picker again
- THEN they MUST be able to add the news widget a second time (duplicates allowed)
- AND each placement MUST have independent configuration and cache

#### Scenario: Widget metadata is discoverable
- GIVEN the widget is registered
- WHEN the frontend fetches `GET /api/widgets`
- THEN the response MUST include an object with id `mydash_news` and basic metadata (title, icon_url)

### Requirement: REQ-NEWS-002 Per-Placement Configuration

Widget placements MUST store configuration in the `widgetContent` JSON field to specify feed sources, layout, and filtering preferences.

#### Scenario: Configure feed URLs
- GIVEN a user is editing a news widget placement
- WHEN they specify feed URLs `["https://example.com/feed", "https://other.org/rss"]`
- AND save via PUT /api/widgets/{placementId} with `{"widgetContent": {"feedUrls": [...]}}`
- THEN the placement MUST store the URLs in its widgetContent JSON
- AND the stored URLs MUST be validated to be HTTP or HTTPS (reject ftp://, file://, etc.)

#### Scenario: Configure layout mode
- GIVEN a placement is created
- WHEN the config specifies `layout: "grid"`
- THEN the widget MUST render in grid (card) layout instead of the default list layout
- AND valid layout values MUST be: `list`, `grid`, `carousel`

#### Scenario: Configure item limit
- GIVEN a placement specifies `itemLimit: 15`
- WHEN items are fetched
- THEN the backend MUST limit the result to 15 items (default 10, max 50)
- AND the API parameter ?limit=N MUST override the placement config (request-time override)

#### Scenario: Configure thumbnail and summary display
- GIVEN a placement specifies `{"showThumbnails": false, "showSummary": true, "summaryMaxChars": 150}`
- WHEN the widget renders
- THEN thumbnails MUST NOT be displayed
- AND summaries MUST be displayed, truncated to 150 characters
- AND defaults MUST be: showThumbnails=true, showSummary=true, summaryMaxChars=200

#### Scenario: Configure date format
- GIVEN a placement specifies `dateFormat: "relative"`
- WHEN the widget renders
- THEN item publication dates MUST display as "2 hours ago", "1 day ago", etc.
- AND if `dateFormat: "absolute"`, dates MUST display as ISO 8601 or human-readable format (e.g., "May 1, 2026 14:30")

#### Scenario: Optional metadata filtering configuration
- GIVEN a placement specifies `metadataFilter: {"fieldKey": "department", "value": "marketing"}`
- WHEN the widget processes items
- THEN the widget MUST verify that the parent dashboard's metadata field `department` equals `marketing`
- AND if the filter does not match, the widget MUST not fetch items (see REQ-NEWS-008)

#### Scenario: Config defaults and fallback
- GIVEN a placement has empty or minimal widgetContent
- WHEN the service calls `extractNewsConfig(placement)`
- THEN defaults MUST apply: feedUrls=[], layout=list, itemLimit=10, showThumbnails=true, showSummary=true, summaryMaxChars=200, dateFormat=relative, metadataFilter=null

### Requirement: REQ-NEWS-003 Fetch and Merge Feed Items

The backend MUST provide an endpoint to fetch, parse, and return merged feed items from all configured sources.

#### Scenario: Fetch items for a news widget placement
- GIVEN a placement (id=42) has config `{"feedUrls": ["https://example.com/rss", "https://other.org/feed"]}`
- WHEN the frontend sends GET /api/widgets/news/42/items?limit=10
- THEN the system MUST call `NewsWidgetService::getItemsForPlacement(42, 10)`
- AND return HTTP 200 with a JSON array of items: `[{guid, title, summary, link, pubDate, sourceUrl, sourceTitle, thumbnailUrl}]`

#### Scenario: Items sorted by publication date (newest first)
- GIVEN feed sources contain items with pubDates [2026-05-01T14:00, 2026-04-30T10:00, 2026-05-01T16:00]
- WHEN items are merged and returned
- THEN the response order MUST be: [2026-05-01T16:00, 2026-05-01T14:00, 2026-04-30T10:00]
- AND sorting MUST be descending (newest first)

#### Scenario: Deduplicate items across feeds
- GIVEN feed A has item guid="article-123" and feed B also has guid="article-123"
- WHEN items are merged
- THEN only one copy of the item MUST be included
- AND the first occurrence MUST be retained
- AND if the sources differ, the sourceTitle MUST indicate both (or the first found)

#### Scenario: Item deduplication by guid
- GIVEN multiple feeds include the same article (identified by matching guid)
- WHEN items are deduplicated
- THEN the guid field MUST be the primary deduplication key
- AND items without a guid MUST generate a synthetic guid from hash(title + pubDate + sourceUrl)

#### Scenario: Response includes source attribution
- GIVEN an item from feed "https://example.com/rss"
- WHEN returned in the API response
- THEN the item object MUST include:
  - `sourceUrl`: the feed URL
  - `sourceTitle`: the feed's title (extracted from feed metadata or URL)

#### Scenario: Limit parameter validation
- GIVEN the frontend sends GET /api/widgets/news/42/items?limit=100
- WHEN the backend processes the request
- THEN the system MUST reject limit > 50 and return HTTP 400
- AND limit < 1 MUST also return HTTP 400
- AND limit parameter MUST be optional (default 10)

#### Scenario: Missing or empty feed list
- GIVEN a placement has no feedUrls configured
- WHEN the frontend requests items
- THEN the system MUST return HTTP 200 with an empty array `[]`
- AND the widget MUST display the empty state

### Requirement: REQ-NEWS-004 Feed Cache Integration

The widget MUST read from a feed-cache table (populated by the `background-job-feed-refresh` change) and only perform on-demand fetches on cold-start.

#### Scenario: Cache hit — serve from background job refresh
- GIVEN a feed "https://example.com/rss" was fetched 5 minutes ago
- AND the cache entry is fresh (TTL 60 minutes not expired)
- WHEN the widget requests items
- THEN the system MUST retrieve items from the feed-cache table (populated by the background job)
- AND NO HTTP request to https://example.com/rss MUST occur
- AND response time MUST be sub-100ms (in-database lookup)

#### Scenario: Cache miss — on-demand fetch (cold-start)
- GIVEN a feed cache has no entry for "https://example.com/rss"
- WHEN the widget requests items for the first time
- THEN the system MUST perform a synchronous HTTP fetch to https://example.com/rss
- AND the raw feed content MUST be stored in Nextcloud's ICache with TTL 60 minutes
- AND subsequent requests within TTL MUST use the cache (delegating refresh to background job)

#### Scenario: Cache TTL configuration
- GIVEN an admin sets `mydash.news_widget_feed_cache_ttl_seconds` to 1800 (30 minutes)
- WHEN a feed is cached
- THEN the cache entry MUST expire after 1800 seconds
- AND cache TTL MUST be readable from `IAppConfig::getValueInt('mydash', 'news_widget_feed_cache_ttl_seconds', 3600)`
- AND default TTL MUST be 3600 seconds (60 minutes)

#### Scenario: Dependency on background-job-feed-refresh
- GIVEN the `background-job-feed-refresh` change has NOT been implemented
- WHEN the widget attempts to fetch items
- THEN the widget MUST fall back to synchronous on-demand fetch (graceful degradation)
- AND a WARN-level log MUST note that background job is unavailable
- AND the widget MUST still function (not crash)

### Requirement: REQ-NEWS-005 HTML Sanitisation of Summaries

Feed item summaries MUST be sanitised to remove dangerous HTML while preserving safe formatting.

#### Scenario: Allow whitelisted tags
- GIVEN a feed item summary contains HTML: `<p>Read our <strong>latest</strong> post <a href="https://example.com">here</a></p>`
- WHEN the summary is sanitised
- THEN the output MUST retain: `<p>`, `<strong>`, and `<a>` tags
- AND the rendered summary MUST preserve the formatting

#### Scenario: Strip dangerous tags
- GIVEN a feed item summary contains `<script>alert('xss')</script>` or `<iframe>`, `<svg>`, or `<img onerror="...">`
- WHEN sanitised
- THEN these tags MUST be completely removed
- AND only their safe content (if any) MUST remain (no tag wrapper)

#### Scenario: Enforce rel attributes on links
- GIVEN a sanitised summary contains an `<a>` tag: `<a href="https://external.com">Link</a>`
- WHEN rendered in the widget
- THEN the system MUST ensure the tag becomes `<a href="https://external.com" rel="noopener noreferrer">Link</a>`
- AND this MUST apply to all `<a>` tags in summaries (enforce during sanitisation or template rendering)

#### Scenario: Allowed tag whitelist
- GIVEN a feed summary with mixed formatting
- WHEN sanitised
- THEN the following tags MUST be allowed: `<p>`, `<a>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`
- AND all other tags (including custom data attributes) MUST be stripped
- AND tag attributes MUST be restricted (e.g., `href` allowed on `<a>`, but `onclick` stripped)

#### Scenario: XSS prevention in summary content
- GIVEN a feed item summary contains encoded or obfuscated malicious content: `<p>Click here: <a href="javascript:void(0)">x</a></p>`
- WHEN sanitised
- THEN the `href="javascript:..."` MUST be stripped/replaced with a safe default (e.g., `href="#"`)
- AND the link text MUST be preserved

### Requirement: REQ-NEWS-006 Feed Host Allow-List

An admin setting MUST restrict feed sources to an explicit allow-list of hostnames.

#### Scenario: Allow-list is empty or null (all hosts allowed)
- GIVEN the admin setting `mydash.news_widget_allowed_feed_hosts` is `null` or `[]`
- WHEN a widget specifies feedUrl "https://any-domain.com/feed"
- THEN the system MUST accept and fetch from the URL
- AND no whitelist check failure MUST occur

#### Scenario: Allow-list restricts to specified hosts
- GIVEN the admin sets `mydash.news_widget_allowed_feed_hosts` to `["bbc.com", "example.org"]`
- WHEN a widget specifies feedUrl "https://bbc.com/rss"
- THEN the system MUST allow the fetch
- AND when feedUrl "https://other-domain.com/feed" is specified
- THEN the fetch MUST be silently skipped (not allowed)

#### Scenario: Disallowed feed is skipped without error
- GIVEN feedUrl "https://blocked-domain.com/feed" fails the allow-list check
- WHEN the widget attempts to fetch items
- THEN the URL MUST be silently skipped (not causing the entire widget to fail)
- AND a WARN-level log MUST note that the URL was disallowed
- AND the widget MUST continue fetching other allowed feeds
- AND a failure badge MUST note "1 feed skipped (disallowed)" or similar

#### Scenario: Hostname comparison is case-insensitive
- GIVEN the allow-list contains "Example.Org"
- WHEN feedUrl "https://example.org/feed" is checked
- THEN the match MUST succeed (case-insensitive comparison)

#### Scenario: Subdomain matching
- GIVEN the allow-list contains "example.org"
- WHEN feedUrl "https://news.example.org/feed" is checked
- THEN the match MUST fail (exact hostname required, no wildcard subdomain expansion)
- AND the feed MUST be skipped

### Requirement: REQ-NEWS-007 Metadata-Based Filtering

The system MUST support optional metadata-based filtering: if a widget specifies a metadata filter, the widget SHALL only process items if the parent dashboard's metadata field matches the filter value.

#### Scenario: Widget with no metadata filter (bypass check)
- GIVEN a placement specifies no `metadataFilter` (null or absent from config)
- WHEN the widget requests items
- THEN the check MUST pass and the widget MUST fetch items normally
- AND no metadata lookup MUST occur

#### Scenario: Widget with metadata filter — match succeeds
- GIVEN a dashboard has metadata field `department: "marketing"`
- AND a placement specifies `metadataFilter: {"fieldKey": "department", "value": "marketing"}`
- WHEN the widget requests items
- THEN the check MUST pass
- AND items MUST be fetched and returned normally

#### Scenario: Widget with metadata filter — no match
- GIVEN a dashboard has metadata field `department: "engineering"`
- AND a placement specifies `metadataFilter: {"fieldKey": "department", "value": "marketing"}`
- WHEN the widget requests items
- THEN the check MUST fail
- AND the API MUST return HTTP 200 with an empty items array `[]`
- AND no feed fetches MUST occur (optimization)

#### Scenario: Metadata field missing or null
- GIVEN a dashboard has no metadata field "department" (field not set on dashboard)
- AND a placement specifies `metadataFilter: {"fieldKey": "department", "value": "marketing"}`
- WHEN the widget requests items
- THEN the check MUST treat missing field as null
- AND the comparison null !== "marketing" MUST fail
- AND return empty items array

#### Scenario: Metadata filter dependency on dashboard-metadata-fields spec
- GIVEN the sibling spec `dashboard-metadata-fields` is NOT yet implemented
- WHEN a widget specifies a metadata filter
- THEN the system MUST gracefully handle missing metadata (treat as no fields defined)
- AND return empty items array if a required metadata field is absent

### Requirement: REQ-NEWS-008 Failure Tolerance

A single feed source failure MUST NOT break the widget. Successful feeds MUST render while failed ones are noted.

#### Scenario: Single feed fails, others succeed
- GIVEN a placement has feedUrls: ["https://good.com/rss", "https://bad.com/rss", "https://good2.com/rss"]
- WHEN https://bad.com/rss returns HTTP 500 or times out
- THEN the widget MUST fetch from good.com and good2.com successfully
- AND items from both successful feeds MUST render
- AND a failure badge (top-right corner) MUST show "1 feed failed" with hover tooltip listing "bad.com"

#### Scenario: HTTP error handling
- GIVEN a feed URL returns HTTP 404, 403, or 5xx
- WHEN the widget fetches
- THEN the system MUST log a WARN-level message and skip the URL
- AND the response MUST include metadata: `{"feedsFailed": 1, "failedUrls": ["https://..."]}` in the header or footer (or returned separately)
- AND the failure badge MUST be displayed

#### Scenario: Timeout handling
- GIVEN an HTTP fetch to a feed URL takes longer than 10 seconds
- WHEN the system is fetching
- THEN the fetch MUST timeout and be skipped
- AND a WARN-level log MUST note the timeout
- AND the failure badge MUST count this as a failed feed

#### Scenario: Malformed feed XML
- GIVEN a feed returns valid HTTP 200 but invalid/malformed RSS/Atom XML
- WHEN the system attempts to parse
- THEN parsing MUST fail gracefully
- AND a WARN-level log MUST note the parse error
- AND this feed MUST be skipped
- AND the failure badge MUST count it as failed

#### Scenario: All feeds fail
- GIVEN all feedUrls fail (404, timeout, or parse error)
- WHEN the widget requests items
- THEN the response MUST be HTTP 200 with empty array `[]`
- AND the widget MUST display empty state plus failure badge showing "All N feeds failed"

### Requirement: REQ-NEWS-009 Three Layout Modes

The widget MUST support three distinct render layouts: list, grid, and carousel.

#### Scenario: List layout (default)
- GIVEN a placement specifies `layout: "list"` or uses the default
- WHEN the widget renders
- THEN items MUST be displayed in a single-column vertical list
- AND each item MUST show (if enabled in config):
  - Thumbnail (left or top, if showThumbnails=true)
  - Title (bold or heading)
  - Summary (preview text, if showSummary=true)
  - Link (as clickable text or button)
  - Date (formatted per config)
  - Source attribution (small text, e.g., "from BBC News")

#### Scenario: Grid layout
- GIVEN a placement specifies `layout: "grid"`
- WHEN the widget renders
- THEN items MUST be displayed as cards in a multi-column grid (2-4 columns depending on widget size)
- AND each card MUST show:
  - Thumbnail at the top (if enabled)
  - Title
  - Summary (truncated, if enabled)
  - Link
  - Date
  - Source
- AND card styling MUST use border and shadow for visual separation

#### Scenario: Carousel layout
- GIVEN a placement specifies `layout: "carousel"`
- WHEN the widget renders
- THEN items MUST be displayed in a horizontal carousel (single item visible)
- AND navigation arrows (left/right) MUST allow browsing through items
- AND each item card MUST show all information (thumbnail, title, summary, link, date, source)
- AND carousel auto-advance is OPTIONAL (implementation choice; manual nav is sufficient)

#### Scenario: Layout switching
- GIVEN a user edits the widget placement and changes layout from "list" to "grid"
- WHEN they save
- THEN the widget MUST immediately re-render in grid layout
- AND no data re-fetch MUST be needed (layout is UI-only)

#### Scenario: Responsive layout
- GIVEN a widget is rendered on mobile (narrow viewport)
- WHEN the layout is grid or carousel
- THEN the grid MUST collapse to 1 column on small screens (no explicit requirement; best-practice behavior)

### Requirement: REQ-NEWS-010 Empty State and User Messaging

The widget MUST display a helpful empty state and clear error/failure information.

#### Scenario: Empty state when no feeds configured
- GIVEN a placement has feedUrls: []
- WHEN the widget renders
- THEN the widget content area MUST display:
  - A message: "No news yet — try adding feeds in the widget settings"
  - An icon (e.g., RSS or newspaper)
  - A call-to-action button or link to open the placement config (implementation choice)

#### Scenario: Empty state when no items found
- GIVEN feedUrls are configured and feeds were fetched, but no items exist (or all are filtered)
- WHEN the widget renders
- THEN the widget MUST display the same empty state message
- AND the failure badge MUST NOT appear (0 feeds failed)

#### Scenario: Failure badge — single failure
- GIVEN a placement has 3 feeds, and 1 failed
- WHEN the widget renders
- THEN a small badge (top-right) MUST show "1 feed failed"
- AND hovering over the badge MUST show a tooltip with "Failed: bad.com" (URL of the failed feed)

#### Scenario: Failure badge — multiple failures
- GIVEN 3 feeds fail out of 5
- WHEN the widget renders
- THEN the badge MUST show "3 feeds failed"
- AND the tooltip MUST list all failed URLs (or "3 feeds failed; see details in widget settings")

#### Scenario: Loading state
- GIVEN the widget is fetching items from feeds
- WHEN the fetch is in progress
- THEN a loading spinner or skeleton placeholders MUST be visible
- AND the widget MUST not block user interaction (async fetch)

#### Scenario: Fetch error message
- GIVEN an unexpected error occurs during fetch (e.g., database connection lost)
- WHEN the widget attempts to render
- THEN a user-friendly error message MUST be displayed: "Unable to load news. Please try again later."
- AND technical details MUST NOT be exposed to the user
- AND a WARN or ERROR-level log MUST contain full error context

### Requirement: REQ-NEWS-011 Link Handling and Click-Through

Clicking on a feed item MUST open the link in a new tab with security protections.

#### Scenario: Click on item title or link
- GIVEN a feed item has `link: "https://example.com/article"`
- WHEN the user clicks on the item title, summary text, or a dedicated "Read more" link
- THEN the system MUST open the link in a new tab: `window.open(url, '_blank')`
- AND the link MUST have `target="_blank" rel="noopener noreferrer"` in the HTML

#### Scenario: Link security — noopener
- GIVEN an item link is rendered
- WHEN the link is opened in a new tab
- THEN the new page MUST NOT have access to the `window.opener` object (XSS protection)
- AND this MUST be enforced by the `rel="noopener"` attribute

#### Scenario: Link security — noreferrer
- GIVEN an item link is opened in a new tab
- WHEN the user navigates to the target URL
- THEN the referrer header MUST NOT be sent to the destination site (privacy protection)
- AND this MUST be enforced by the `rel="noreferrer"` attribute

#### Scenario: Missing link in feed item
- GIVEN a feed item has no link (null or empty string)
- WHEN the widget renders
- THEN the item MUST still display (title, summary, thumbnail)
- AND the click action MUST be disabled or the link area MUST be a non-interactive element
- AND no error MUST be logged

#### Scenario: Malformed or unsafe link
- GIVEN a feed item link is "javascript:alert('xss')" or "data:text/html,..."
- WHEN sanitised
- THEN the link MUST be replaced with "#" or removed entirely
- AND the click MUST not execute arbitrary JavaScript
