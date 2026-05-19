# Tasks — news-widget

## 1. Widget registration and service layer

- [ ] 1.1 Create `lib/Service/NewsWidgetService.php` with core methods:
  - `getWidgetInfo(): array` — returns widget metadata (id, title, icon_url, v2 API support)
  - `getItemsForPlacement(int $placementId, int $limit = 10): array` — orchestrates feed fetching and filtering
  - `fetchAndMergeFeeds(array $feedUrls, int $limit = 10): array` — fetch from cache or on-demand, merge, deduplicate
  - `parseRssFeed(string $feedContent, string $sourceUrl, string $sourceTitle): array` — parse RSS/Atom, extract items (guid, title, summary, link, pubDate, thumbnailUrl)
  - `deduplicateItems(array $items): array` — deduplicate by guid, keep first occurrence
  - `sortItemsByDate(array $items): array` — sort by pubDate descending
  - `sanitiseSummaryHtml(string $html): string` — allow safe tags, strip dangerous content, force rel attributes
  - `checkAllowList(string $url): bool` — checks hostname against allow-list setting
  - `checkMetadataFilter(int $dashboardId, array $metadataFilter): bool` — verify dashboard metadata matches filter criteria
- [ ] 1.2 Register the widget in a boot/lifecycle hook or service provider:
  - Hook into Nextcloud's dashboard widget registration (e.g., in `AppInfo/Bootstrap.php` or a listener on `IManager`)
  - Call `IManager::registerWidget()` with a widget provider or inline metadata
  - Widget id: `mydash_news`, title: translatable `app.mydash.news_widget_title`, icon: feed/newspaper icon URL
- [ ] 1.3 Create fixture-based PHPUnit tests for `NewsWidgetService`:
  - `testFetchAndMergeFeedsSuccess` — mock feed URLs, verify merged array returned
  - `testDeduplicateItemsByGuid` — duplicate items with same guid removed, first occurrence kept
  - `testSortItemsByDateDescending` — verify pubDate sorting (newest first)
  - `testSanitiseSummaryHtmlAllowList` — verify safe tags allowed, dangerous tags stripped
  - `testAllowListMatching` — hostname match, mismatch, case-insensitivity
  - `testCheckMetadataFilterMatches` — verify metadata field comparison logic

## 2. Backend controller and routing

- [ ] 2.1 Add endpoint method to `lib/Controller/WidgetController.php`:
  - `public function newsItems(int $placementId, ?int $limit = 10): DataResponse`
  - Validate placement ownership (return 403 if user cannot see dashboard)
  - Validate limit (positive integer, max 50; default 10)
  - Call `NewsWidgetService::getItemsForPlacement()`
  - Return HTTP 200 with item array
  - Decorate with `#[NoCSRFRequired]` and `#[NoAdminRequired]`
- [ ] 2.2 Register route in `appinfo/routes.php`:
  - `GET /api/widgets/news/{placementId}/items` → `WidgetController::newsItems()`
  - Route requirements: placementId (digits), limit (optional query param, digits)
- [ ] 2.3 Create PHPUnit test for controller:
  - `testNewsItemsSuccess` — mock service, verify HTTP 200 + item payload
  - `testNewsItemsMissingPlacement` — return 404 when placement doesn't exist
  - `testNewsItemsAccessDenied` — return 403 when user cannot see dashboard
  - `testNewsItemsInvalidLimit` — return 400 for limit < 1 or > 50

## 3. Placement configuration and schema migration

- [ ] 3.1 Create `lib/Migration/VersionXXXXDate2026...AddNewsWidgetSettings.php`:
  - Add app config table entries for `mydash.news_widget_feed_cache_ttl_seconds` (default 3600)
  - Add app config table entries for `mydash.news_widget_allowed_feed_hosts` (default `null` or `[]`)
  - NOTE: No new `oc_mydash_*` table columns required; all config is stored in placement `widgetContent` JSON
- [ ] 3.2 Add getter/setter methods in `WidgetPlacementService` or factory to safely parse `widgetContent` JSON:
  - `extractNewsConfig(WidgetPlacement $placement): array` — returns parsed config with defaults (feedUrls, layout, itemLimit, showThumbnails, showSummary, summaryMaxChars, dateFormat, metadataFilter)
  - Validate and sanitize config (feedUrls must be HTTP/HTTPS, itemLimit 1-50)
- [ ] 3.3 Create PHPUnit test for placement config parsing:
  - `testExtractNewsConfigWithDefaults` — verify default values are applied
  - `testExtractNewsConfigValidation` — invalid URLs rejected, valid ones accepted

## 4. Feed fetching, caching, and allow-list

- [ ] 4.1 Implement in `NewsWidgetService::fetchAndMergeFeeds()`:
  - For each URL in `feedUrls`:
    - Check allow-list via `checkAllowList($url)`
    - If disallowed, skip and log warning
    - If allowed, try to fetch from cache key `mydash_news_feed_{placementId}_{urlHash}` (via FeedCacheService if available, else ICache directly)
    - If cache hit, use cached raw feed content
    - If cache miss, HTTP fetch with 10-second timeout (use `IClientService`)
    - On HTTP 4xx/5xx, log warning, skip URL, record failure for UI badge
    - On timeout, log warning, skip URL, record failure
    - On success, cache raw feed for TTL (read from `IAppConfig::getValueInt()`)
    - Parse cached/fetched feed via `NewsWidgetService::parseRssFeed()` 
    - Merge and deduplicate across all sources
    - Sort by pubDate descending
    - Collect failure info (number of failed feeds) for response metadata
- [ ] 4.2 Implement `NewsWidgetService::parseRssFeed()`:
  - Accept raw XML/feed content and source metadata (URL, title)
  - Use SimpleXML or lightweight feed parser to extract VEVENT/VENTRY data
  - For each item, extract: guid (unique identifier), title, summary, link, pubDate (ISO 8601), thumbnailUrl (if present)
  - Handle both RSS 2.0 and Atom 1.0 formats
  - Return flat array of item objects
  - Log malformed feeds at INFO level (don't crash widget)
- [ ] 4.3 Implement `NewsWidgetService::checkAllowList()`:
  - Read `mydash.news_widget_allowed_feed_hosts` from `IAppConfig::getValueString()`
  - If empty or null, return true (all allowed)
  - Otherwise, parse URL, extract hostname (case-insensitive)
  - Check for exact match in allow-list (no wildcard subdomain expansion)
  - Return boolean

## 5. HTML sanitisation and metadata filtering

- [ ] 5.1 Implement `NewsWidgetService::sanitiseSummaryHtml()`:
  - Accept raw HTML string from feed item summary
  - Use Nextcloud's `HtmlSanitizer` or equivalently safe library (e.g., `HTML Purifier` if available)
  - Allow tags: `<p>`, `<a>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`
  - Strip all other HTML tags
  - Force `rel="noopener noreferrer"` on all `<a>` elements
  - Return sanitised HTML
  - Log dropped content at DEBUG level if substantial content is removed
- [ ] 5.2 Implement `NewsWidgetService::checkMetadataFilter()`:
  - Accept dashboard ID and metadata filter object `{fieldKey: string, value: string}`
  - If metadataFilter is null, return true (no filter applied)
  - Look up dashboard and retrieve its metadata fields (from sibling spec `dashboard-metadata-fields`)
  - Check if the field identified by `fieldKey` has value matching `value` (case-sensitive string comparison)
  - Return boolean: true if filter passes, false if filter fails
  - Log filter rejection at DEBUG level for troubleshooting
- [ ] 5.3 Create PHPUnit tests:
  - `testSanitiseSummaryAllowsWhitelistedTags` — `<p>`, `<a>`, `<strong>`, etc. preserved
  - `testSanitiseSummaryStripsScriptTags` — `<script>`, `<iframe>`, `<svg>` removed
  - `testSanitiseSummaryForcesRelAttributes` — all `<a>` tags get `rel="noopener noreferrer"`
  - `testMetadataFilterMatches` — dashboard metadata matches filter
  - `testMetadataFilterRejects` — dashboard metadata does not match filter
  - `testMetadataFilterNullBypass` — null filter returns true

## 6. Vue component and UI rendering

- [ ] 6.1 Create `src/components/widgets/NewsWidget.vue`:
  - Three layout modes: `list`, `grid`, `carousel` (switchable via placement config)
  - List: single-column item feed, title + thumbnail (if enabled) + summary preview (if enabled) + link + date
  - Grid: multi-column card layout, thumbnail, title, summary truncated, link, date
  - Carousel: horizontal scroll, card layout, single item visible with arrow navigation
  - Empty state: "No news yet — try adding feeds in the widget settings"
  - Failure badge (top-right corner): shows "N feeds failed" with hover tooltip listing failed URLs
  - Click on item → `window.open(item.link, '_blank', 'rel=noopener')`
  - Date formatting: `dateFormat: 'relative'` shows "2 hours ago", `'absolute'` shows "2026-05-01 14:30"
  - Load items via `GET /api/widgets/news/{placementId}/items?limit={itemLimit}`
  - Show loading spinner while fetching
  - Handle fetch error with user-friendly message
- [ ] 6.2 Create `src/components/widgets/newspicker/NewsWidgetConfig.vue`:
  - Placement config form with fields:
    - Feed URLs (array input with add/remove)
    - Layout mode (radio or select: list, grid, carousel)
    - Item limit (number input, 1-50)
    - Metadata filter (optional):
      - Checkbox "Filter by dashboard metadata"
      - If enabled: fieldKey select (populated from dashboard's metadata field keys), value text input
    - Show thumbnails (checkbox, default true)
    - Show summary (checkbox, default true)
    - Summary max chars (number input, default 200)
    - Date format (radio: relative, absolute)
  - Validation: feedUrls must be HTTP/HTTPS
  - Save via `PUT /api/widgets/{placementId}` with updated `widgetContent`
- [ ] 6.3 Create Storybook stories (optional but recommended):
  - NewsWidget with list layout
  - NewsWidget with grid layout
  - NewsWidget with carousel layout
  - NewsWidget empty state
  - NewsWidget with failure badge
  - NewsWidgetConfig form

## 7. Integration tests and edge cases

- [ ] 7.1 Create integration test `tests/Integration/WidgetNewsIntegrationTest.php`:
  - `testNewsWidgetE2E` — create dashboard, add news widget placement, fetch items via API, verify merge and dedup
  - `testMetadataFilterEnforcement` — widget with metadata filter only returns items when dashboard metadata matches
  - `testFeedCacheTTL` — verify cache expiry and refetch
  - `testAllowListEnforcement` — disallowed feed hosts are skipped
  - `testFailureTolerance` — single feed failure does not break widget; other feeds still render
- [ ] 7.2 Handle edge cases:
  - Empty feed list → return empty items array + empty state
  - All feeds fail → return empty items array + failure badge showing "All feeds failed"
  - Feed with no items → skip, no error, continue with other feeds
  - Feed item missing guid → generate synthetic guid from title + pubDate + sourceUrl
  - Feed item missing pubDate → use current timestamp as fallback
  - Circular or self-referential metadataFilter → log and skip (safety)
- [ ] 7.3 Performance considerations:
  - Fetch timeout: 10 seconds per URL (prevent hang)
  - Cache hits should be sub-100ms
  - Deduplication uses O(n) hash-based lookup, not O(n²)
  - Sorting uses native array sort (stable)

## 8. Admin settings UI

- [ ] 8.1 Add admin settings form (in MyDash admin panel or central Nextcloud settings):
  - `mydash.news_widget_feed_cache_ttl_seconds` — text input (number), default 3600, help text "Cache TTL in seconds (60-86400)"
  - `mydash.news_widget_allowed_feed_hosts` — text area with JSON array format, help text "JSON array of allowed feed hostnames, e.g., `["bbc.com", "example.org"]`. Leave empty to allow all."
  - Validate: cache TTL must be 60-86400; JSON must parse
  - Save via existing Nextcloud admin settings API
- [ ] 8.2 Add documentation in README or inline comments:
  - Explain feed allow-list purpose (security, bandwidth control)
  - Explain cache TTL and its relation to background job refresh frequency
  - Note: widget relies on `background-job-feed-refresh` for periodic updates

## 9. Documentation and examples

- [ ] 9.1 Add example to `openspec/examples/news-widget-placement-config.json`:
  ```json
  {
    "feedUrls": [
      "https://news.bbc.co.uk/rss/feeds/world",
      "https://feeds.example.org/marketing"
    ],
    "layout": "grid",
    "itemLimit": 15,
    "metadataFilter": {
      "fieldKey": "department",
      "value": "marketing"
    },
    "showThumbnails": true,
    "showSummary": true,
    "summaryMaxChars": 250,
    "dateFormat": "relative"
  }
  ```
- [ ] 9.2 Add developer notes to `openspec/changes/news-widget/IMPLEMENTATION_NOTES.md`:
  - Feed format support (RSS 2.0, Atom 1.0, description of any known limitations)
  - Caching strategy (per-feed, per-placement, key structure)
  - Metadata filter dependency on dashboard-metadata-fields spec
  - Background job integration points
