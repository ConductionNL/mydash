# Background Job Feed Refresh — Specification

Implement a background job that periodically refreshes RSS and Atom feeds for the MyDash news widget, with caching, conditional HTTP requests, and fault tolerance across distributed instances.

## Affected code units

- `lib/Db/FeedCache.php` — entity object for cached feed data
- `lib/Db/FeedCacheMapper.php` — database CRUD for feed cache table
- `lib/Service/FeedRefreshService.php` — business logic for feed discovery, fetching, parsing, and caching
- `lib/Service/FeedParserInterface.php` — interface for pluggable feed parsing
- `lib/Service/FeedXmlParser.php` — default RSS 2.0 / Atom 1.0 parser using `simplexml_load_string`
- `lib/BackgroundJob/FeedRefreshJob.php` — `TimedJob` that orchestrates the refresh cycle
- `lib/Controller/AdminController.php` — new endpoint `POST /api/admin/feeds/refresh-now` for on-demand refresh
- `appinfo/Bootstrap.php` — register `FeedRefreshJob` on app startup
- `appinfo/Database.php` — migration to create `oc_mydash_feed_cache` table
- No schema changes to existing entities (news widget placements already store feed URLs)

## Why a background job

The news widget displays feeds from multiple configured URLs per placement. Fetching and parsing each feed synchronously during widget render would block the dashboard load. A background job decouples feed refresh from UI rendering, allows retries and failure tolerance, and scales to hundreds of feeds via batch processing and cursor-based pagination.

## Approach

- **Single source of truth for feed URLs**: extracted from active `oc_mydash_widget_placements` rows where `widgetId = 'mydash_news'`
- **Deduplication at storage**: one row per distinct feed URL in `oc_mydash_feed_cache`, regardless of how many placements reference it
- **Conditional GET**: store `ETag` and `Last-Modified` headers to send `If-None-Match` / `If-Modified-Since` on subsequent fetches, reducing bandwidth
- **Fault isolation**: a timeout or parse error on one feed does NOT abort the job; other feeds continue processing
- **Concurrency locking**: only one job instance runs at a time via `ILockingProvider::acquireLock('mydash_feed_refresh_running')`
- **Batch processing**: when feed count exceeds 500, process in chunks across multiple cron ticks using a cursor
- **Admin refresh**: synchronous endpoint for admins to trigger an immediate refresh (all feeds or single URL)
- **Orphan cleanup hand-off**: the job signals the interface for a sibling `orphaned-data-cleanup` job to prune unused feeds after 30 days

## Notes

- Uses Nextcloud's `IClientService` for all HTTP requests with 10s connect / 30s total timeout
- User-Agent identifies the app and version
- Respects Nextcloud's configured proxy and `noproxy` settings
- Feed host allow-list enforced if configured, preventing fetch attempts to disallowed domains
- No third-party RSS library dependency — parsing uses PHP's built-in `simplexml_load_string` with `LIBXML_NOCDATA | LIBXML_NONET` flags
- Parser interface allows swapping the implementation without touching the job or service
