# Tasks — background-job-feed-refresh

## Database & Entity Layer

- [ ] Task 1: Create migration `lib/Migration/Version*.php` (Version2026xxxx) that creates `oc_mydash_feed_cache` table with columns: `id` (auto-increment PK), `feedUrl` (VARCHAR 2048 UNIQUE NOT NULL), `lastFetchedAt` (TIMESTAMP NULL), `lastSuccessAt` (TIMESTAMP NULL), `lastFailureReason` (TEXT NULL), `etag` (VARCHAR 255 NULL), `lastModified` (VARCHAR 255 NULL), `itemsJson` (MEDIUMTEXT NULL); add unique index on `feedUrl`; include reversible drop in rollback (REQ-FRJ-001)

- [ ] Task 2: Create `lib/Db/FeedCache.php` entity class with getters/setters for all columns (constructor with positional args per ADR-003); docblock each field with type + brief description (REQ-FRJ-001)

- [ ] Task 3: Create `lib/Db/FeedCacheMapper.php` extending `QBMapper` with methods: `findByUrl($feedUrl)` (returns FeedCache or throws DoesNotExistException), `upsertUrl($feedUrl)` (insert with nulls if not exists, update lastFetchedAt if exists), `findOrphanedBefore($cutoff)` (returns array of FeedCache rows where lastFetchedAt < cutoff); add `@spec openspec/changes/background-job-feed-refresh/specs/background-job-feed-refresh/spec.md#requirement-req-frj-009` docblock to `findOrphanedBefore` noting the sibling job dependency (REQ-FRJ-001, REQ-FRJ-009)

## Service Layer — Feed Parsing

- [ ] Task 4: Create `lib/Service/FeedParserInterface.php` with methods: `parseRss20Items(\SimpleXMLElement $root, string $feedUrl): array`, `parseAtom10Items(\SimpleXMLElement $root, string $feedUrl): array`; each returns array of normalized items with structure: `[{guid, title, summary, link, pubDate, sourceUrl, sourceTitle, thumbnailUrl}, ...]` (REQ-FRJ-005)

- [ ] Task 5: Create `lib/Service/FeedXmlParser.php` implementing `FeedParserInterface`:
  - `parseRss20Items()`: extract `<title>`, `<description>` (sanitize HTML), `<link>`, `<pubDate>` (ISO 8601), `<guid>` or synthetic (sha256 of title+pubDate+feedUrl), `<enclosure>` url → thumbnailUrl; map `sourceUrl` to feed URL, `sourceTitle` to channel title or hostname; return 50 newest items sorted by pubDate DESC
  - `parseAtom10Items()`: extract `<id>` (guid), `<title>`, `<summary>` or `<content>` → summary, `<link rel="alternate">` (href), prefer `<published>` over `<updated>` for pubDate; same 50-item cap and sourceUrl/sourceTitle rules
  - Add constants `MAX_ITEMS = 50`, `MAX_RESPONSE_SIZE = 10 * 1024 * 1024` (10 MB)
  - Both methods detect feed type (RSS vs Atom), handle missing fields gracefully, reject responses >10 MB before parsing (REQ-FRJ-005)

- [ ] Task 6: Add helper method to `FeedXmlParser`: `sanitizeHtml($html): string` using `strip_tags()` or similar to remove script/dangerous tags from feed descriptions; document HTML sanitization (REQ-FRJ-005)

## Service Layer — Feed Refresh

- [ ] Task 7: Create `lib/Service/FeedRefreshService.php` with constructor DI of `FeedCacheMapper`, `FeedParserInterface`, `IClientService`, `ILockingProvider`, `IAppConfig`, `ILogger`, `IConfig`; add `@spec` docblock to class noting the change (REQ-FRJ-003, REQ-FRJ-004, REQ-FRJ-006, REQ-FRJ-010)

- [ ] Task 8: Implement `FeedRefreshService::discoverFeedUrls(): array` — query `oc_mydash_widget_placements` for all rows with `widgetId = 'mydash_news'`, extract `feedUrls` array from `widgetContent` JSON, deduplicate, sort alphabetically; return array of URL strings; log INFO "No news widget placements found; nothing to refresh" if empty (REQ-FRJ-003)

- [ ] Task 9: Implement `FeedRefreshService::refreshAll(?string $feedUrl = null): array` — main entry point for job and admin endpoint:
  - Call `discoverFeedUrls()` to get active feed URLs
  - If `$feedUrl` provided, filter to single URL (verify it exists; if not, log WARN and return empty stats)
  - If no URLs, log INFO and return `{processedCount: 0, successCount: 0, failureCount: 0, durationMs: 0}`
  - Call `FeedCacheMapper::upsertUrl()` for each URL to ensure row exists in cache
  - Call `processFeedBatch()` (see below) to fetch/parse/cache feeds
  - Return stats object `{processedCount, successCount, failureCount, durationMs}` (REQ-FRJ-010)

- [ ] Task 10: Implement `FeedRefreshService::processFeedBatch(array $urls, ?string $cursor = null): array` — internal method that:
  - Start wall-clock timer
  - Sort URLs alphabetically
  - Find start index: if cursor provided and exists in URLs, start after cursor; else start at 0; if cursor not found, log WARN "Cursor URL not found, restarting from beginning" and reset to 0
  - Loop through URLs starting from index:
    - For each URL, call `fetchAndCacheFeed($url)` (see Task 11)
    - Increment counters (processedCount, successCount on parse OK, failureCount on exception)
    - After each fetch, check wall-clock elapsed; if > 300s, break loop and store cursor in app config `mydash.feed_refresh_cursor`
  - If all URLs processed, clear cursor from app config
  - Return stats
  - (REQ-FRJ-008)

- [ ] Task 11: Implement `FeedRefreshService::fetchAndCacheFeed(string $feedUrl): bool` — wraps fetch+parse+cache in try/catch:
  - Check allow-list via `isHostAllowed($feedUrl)` (see Task 12); if disallowed, set `lastFailureReason = "host not in allow-list"`, log WARN, return false
  - Load cache row via `FeedCacheMapper::findByUrl($feedUrl)` (or upserted empty row)
  - Build headers: include `If-None-Match: {etag}` (if cached), `If-Modified-Since: {lastModified}` (if cached)
  - Build User-Agent via `buildUserAgent()` (see Task 13)
  - Call `IClientService::get($feedUrl)` with User-Agent, timeout 10/30s, honor proxy settings
  - If HTTP 304: update `lastFetchedAt`, call `FeedCacheMapper::save()`; return true
  - If HTTP 200: check response size < 10 MB; if too large, set `lastFailureReason = "response too large"`, return false
  - Parse via `FeedParserInterface` (detect RSS vs Atom); on parse exception, set `lastFailureReason = "parse error: <msg>"`; return false
  - On success: store items as JSON in `itemsJson`, update `etag` and `lastModified` from response headers, set `lastSuccessAt = now()`, `lastFetchedAt = now()`, clear `lastFailureReason`, call `FeedCacheMapper::save()`; return true
  - Catch all exceptions: set `lastFailureReason` appropriately (e.g., "timeout: <msg>", "410 Gone", "503 Service Unavailable"), call `FeedCacheMapper::save()`, return false (REQ-FRJ-004, REQ-FRJ-005, REQ-FRJ-006, REQ-FRJ-011, REQ-FRJ-012)

- [ ] Task 12: Implement `FeedRefreshService::isHostAllowed(string $feedUrl): bool` — helper:
  - Read app config `mydash.news_widget_allowed_feed_hosts` (default empty string)
  - If empty, return true (no allow-list = all hosts allowed)
  - Parse URL hostname via `parse_url($feedUrl, PHP_URL_HOST)`
  - Split allow-list by comma, trim, lowercase all entries
  - Check if hostname (lowercased) exact-matches any entry; return true/false
  - (REQ-FRJ-011)

- [ ] Task 13: Implement `FeedRefreshService::buildUserAgent(): string` — helper:
  - Read app version from `IAppConfig` or manifest (dynamically, not hard-coded)
  - Read instance URL from `IConfig::getSystemValue('overwrite.cli.url')` (fallback to app root)
  - Return `sprintf('Mozilla/5.0 (compatible; MyDash/%s; +%s/apps/mydash)', $appVersion, $instanceUrl)`
  - (REQ-FRJ-012)

## Background Job

- [ ] Task 14: Create `lib/BackgroundJob/FeedRefreshJob.php` extending `TimedJob`:
  - Constructor: read `mydash.feed_refresh_interval_seconds` from app config, clamp to [300, 86400], default 3600; call `$this->setInterval($interval)` (REQ-FRJ-002)
  - Constructor: inject `FeedRefreshService`, `ILockingProvider`, `ILogger`, `IAppConfig` via DI
  - Implement `run(array $argument)`:
    - Try to acquire lock via `ILockingProvider::acquireLock('mydash_feed_refresh_running', LOCK_EXCLUSIVE)`
    - On `LockedException`, log WARN "FeedRefreshJob already running; skipping this tick", return
    - Call `FeedRefreshService::refreshAll()`
    - Process response stats (log result)
    - Finally: release lock
  - Add `@spec openspec/changes/background-job-feed-refresh/specs/background-job-feed-refresh/spec.md#requirement-req-frj-007` docblock to lock section (REQ-FRJ-002, REQ-FRJ-007)

## Controller & Routes

- [ ] Task 15: Update or create `lib/Controller/AdminController.php`:
  - Add route `POST /api/admin/feeds/refresh-now` with optional query param `feedUrl`
  - Implement `refreshNow(Request $request): JSONResponse`:
    - Verify admin via `IGroupManager::isAdmin()`; throw 403 if not
    - Extract `feedUrl` from query param; if provided, validate is HTTP/HTTPS scheme via `parse_url(..., PHP_URL_SCHEME)`; return 400 if ftp or other non-http
    - Call `FeedRefreshService::refreshAll($feedUrl)`
    - Return HTTP 200 JSON with `{processedCount, successCount, failureCount, durationMs}` (REQ-FRJ-010)

- [ ] Task 16: Register route in `appinfo/routes.php`: `['name' => 'admin#refreshNow', 'url' => '/api/admin/feeds/refresh-now', 'verb' => 'POST']` (REQ-FRJ-010)

## Bootstrap & Registration

- [ ] Task 17: Update `appinfo/Bootstrap.php` — in `register()` method, add `$context->registerBackgroundJob(FeedRefreshJob::class)` so the job is auto-discovered (REQ-FRJ-002)

## Linting & Quality

- [ ] Task 18: Run `phpcs lib/` and `php -l lib/` to verify syntax and coding standards; add SPDX headers `// SPDX-License-Identifier: EUPL-1.2` to every PHP file created (per ADR-015)

- [ ] Task 19: Verify all `@spec` PHPDoc tags are correct and link to the change artifact (per ADR-003 backend pattern); grep for dangling references to classes/methods

- [ ] Task 20: Run `./occ migrations:list` and verify the new migration appears; manually test migration UP and rollback DOWN to confirm table is created and dropped cleanly

## Tests (per ADR-008)

- [ ] Task 21: Create `tests/Unit/Db/FeedCacheMapperTest.php` with Vitest coverage of:
  - `findByUrl()` returns correct row, throws DoesNotExistException on missing URL
  - `upsertUrl()` inserts new row, re-upsert updates existing
  - `findOrphanedBefore()` returns rows older than cutoff, excludes newer rows

- [ ] Task 22: Create `tests/Unit/Service/FeedXmlParserTest.php` with coverage of:
  - `parseRss20Items()` extracts title, description, link, pubDate, guid, enclosure; handles missing fields; caps at 50 items; sorts by pubDate DESC
  - `parseAtom10Items()` extracts id, title, summary/content, link rel=alternate, published/updated; handles missing fields
  - Synthetic GUID generation (sha256 of title+pubDate+feedUrl) is stable
  - Response >10 MB rejected before parsing
  - Malformed XML caught (parse exception → `lastFailureReason`)

- [ ] Task 23: Create `tests/Unit/Service/FeedRefreshServiceTest.php` with coverage of:
  - `discoverFeedUrls()` returns deduplicated, sorted URLs from news-widget placements; ignores non-news widgets; returns empty array with log when no news widgets exist
  - `isHostAllowed()` returns true for empty allow-list; checks exact hostname match (case-insensitive); rejects subdomains
  - `buildUserAgent()` returns correctly formatted User-Agent with app version and instance URL
  - `fetchAndCacheFeed()` handles HTTP 304 (update lastFetchedAt only), HTTP 200 with new content (parse, store, update etag/lastModified), timeouts, 4xx/5xx errors, parse errors, host disallowed
  - Batch processing: ≤500 feeds processed in one tick, >500 feeds split across ticks using cursor, cursor invalidation on deleted feed

- [ ] Task 24: Create `tests/Unit/BackgroundJob/FeedRefreshJobTest.php` with coverage of:
  - Constructor sets interval clamped to [300, 86400]; default 3600
  - `run()` acquires lock, calls service, releases lock even on exception
  - Concurrent invocation exits with log on LockedException

- [ ] Task 25: Create `tests/Unit/Controller/AdminControllerTest.php` with coverage of:
  - Non-admin user receives HTTP 403
  - Admin can trigger full refresh; response includes stats
  - Admin can trigger single-URL refresh; stats show processedCount=1
  - Invalid feedUrl scheme (ftp) rejected with HTTP 400

## Documentation

- [ ] Task 26: Add changelog entry (`.changelog/<version>/<number>.{feature,bugfix}.md`) describing the new feed cache, background job, and admin endpoint; mention ETag/Last-Modified optimization and failure tolerance

- [ ] Task 27: Add PHP docblock to `lib/Db/FeedCache.php` and `lib/Db/FeedCacheMapper.php` describing the feed cache schema, unique URL constraint, and role in feed refresh cycle

- [ ] Task 28: Update app README or inline docs to describe the feed refresh configuration (interval seconds, allow-list hosts) and admin endpoint URL

## i18n (per ADR-007)

- [ ] Task 29: Identify all user-facing strings in logs, error messages, and admin endpoint responses; ensure all are translatable via `$this->l->t()` (PHP) or `this.t()` (Vue); avoid hardcoded Dutch or English

## Verification

- [ ] Task 30: Run `occ background-job:list | grep FeedRefreshJob` and confirm job is registered and shows correct interval

- [ ] Task 31: Manually trigger the job via `occ background-job:execute --class 'OCA\MyDash\BackgroundJob\FeedRefreshJob'` (or let it run on next cron) and verify:
  - `oc_mydash_feed_cache` table is populated with fetched feeds
  - `lastFetchedAt` and `itemsJson` are updated
  - On second run, HTTP 304 is sent if ETag/Last-Modified match; `lastFetchedAt` is updated but `itemsJson` unchanged
  - Failed feeds have `lastFailureReason` set and `itemsJson` unchanged

- [ ] Task 32: Call admin endpoint `POST /api/admin/feeds/refresh-now` as admin and verify response includes stats and refreshed feeds

- [ ] Task 33: Call admin endpoint as non-admin and verify HTTP 403 is returned

- [ ] Task 34: Test batch processing: create >500 news-widget feeds, trigger job, verify cursor is set after first tick, second tick resumes and clears cursor on completion

- [ ] Task 35: Test allow-list: set `mydash.news_widget_allowed_feed_hosts = "bbc.com"`, create placements with bbc.com and blocked.com URLs, trigger job, verify only bbc.com is fetched

- [ ] Task 36: Run `openspec validate` and verify all requirements in spec.md are satisfied

## Notes

- Every task MUST include a `@spec openspec/changes/background-job-feed-refresh/...` reference in the code's docblock
- No `composer.json` dependency changes (no third-party feed parser added)
- All HTTP requests via `IClientService` (no raw cURL)
- All exceptions caught per-feed (no job abort on single-feed error)
- Distributed lock prevents concurrent instances across cluster
- Cursor-based batching supports unlimited feeds without O(n²) processing
