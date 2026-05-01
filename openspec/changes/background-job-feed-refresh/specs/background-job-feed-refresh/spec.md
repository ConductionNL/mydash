---
status: draft
---

# Background Job Feed Refresh Specification

## ADDED Requirements

### Requirement: REQ-FRJ-001 Feed Cache Table Schema

The system MUST create a database table `oc_mydash_feed_cache` that stores exactly one row per distinct feed URL across all news-widget placements, persisting fetch metadata and cached items.

#### Scenario: Table created on migration

- GIVEN MyDash is freshly installed or upgraded and the migration has not yet run
- WHEN `occ migrations:execute mydash` runs the `AddFeedCacheTable` migration
- THEN the table `oc_mydash_feed_cache` MUST exist with columns: `id` (auto-increment integer PK), `feedUrl VARCHAR(2048) UNIQUE NOT NULL`, `lastFetchedAt TIMESTAMP NULL`, `lastSuccessAt TIMESTAMP NULL`, `lastFailureReason TEXT NULL`, `etag VARCHAR(255) NULL`, `lastModified VARCHAR(255) NULL`, `itemsJson MEDIUMTEXT NULL`
- AND a unique index on `feedUrl` MUST be present

#### Scenario: One row per distinct URL regardless of placement count

- GIVEN two separate news-widget placements both reference `https://example.com/rss`
- WHEN the background job discovers feed URLs and upserts into the cache table
- THEN exactly one row with `feedUrl = 'https://example.com/rss'` MUST exist in `oc_mydash_feed_cache`
- AND updates to that row MUST be shared by both placements

#### Scenario: Schema is compatible across supported databases

- GIVEN the migration runs on SQLite, MySQL 8.x, and PostgreSQL 14+
- WHEN `oc_mydash_feed_cache` is created
- THEN the table MUST be created without error on each engine
- AND `MEDIUMTEXT` MUST be mapped to an equivalent large-text column type on PostgreSQL

#### Scenario: Migration is reversible

- GIVEN the migration has been applied
- WHEN a rollback is triggered (e.g., `occ migrations:execute --revert`)
- THEN the `oc_mydash_feed_cache` table MUST be dropped cleanly
- AND no orphan indexes MUST remain

#### Scenario: itemsJson capped at 50 items on write

- GIVEN a feed returns 120 items in its response
- WHEN the service stores items in `itemsJson`
- THEN only the 50 newest items (sorted by `pubDate` descending) MUST be stored
- AND the stored JSON MUST be valid and parseable

### Requirement: REQ-FRJ-002 TimedJob Registration and Interval

The system MUST register `FeedRefreshJob` as a Nextcloud `\OCP\BackgroundJob\TimedJob` that runs on a configurable interval, defaulting to 60 minutes.

#### Scenario: Job runs every 60 minutes by default

- GIVEN no `mydash.feed_refresh_interval_seconds` app config key has been set
- WHEN Nextcloud's cron daemon evaluates scheduled jobs
- THEN `FeedRefreshJob` MUST run at most once per 3600 seconds (60 minutes)
- AND `$this->setInterval(3600)` MUST be called in the job constructor

#### Scenario: Admin sets custom refresh interval

- GIVEN an admin runs `occ config:app:set mydash feed_refresh_interval_seconds --value=900`
- WHEN `FeedRefreshJob` is next constructed
- THEN it MUST call `$this->setInterval(900)` so the job runs every 15 minutes

#### Scenario: Interval clamped to minimum 300 seconds

- GIVEN an admin sets `mydash.feed_refresh_interval_seconds` to `60`
- WHEN the job constructor reads the config value
- THEN the effective interval MUST be `max(300, configuredValue)` = `300`
- AND the job MUST NOT run more frequently than once every 5 minutes

#### Scenario: Interval clamped to maximum 86400 seconds

- GIVEN an admin sets `mydash.feed_refresh_interval_seconds` to `172800` (48 h)
- WHEN the job constructor reads the config value
- THEN the effective interval MUST be `min(86400, configuredValue)` = `86400`
- AND the job MUST NOT run less frequently than once per 24 hours

#### Scenario: Job registered on application bootstrap

- GIVEN MyDash is installed and enabled
- WHEN `AppInfo/Bootstrap.php::register()` is called
- THEN `$context->registerBackgroundJob(FeedRefreshJob::class)` MUST be called
- AND `occ background-job:list | grep FeedRefreshJob` MUST return a non-empty result

### Requirement: REQ-FRJ-003 Feed URL Discovery

The job MUST discover the complete set of active feed URLs by querying all news-widget placements before each refresh cycle.

#### Scenario: Feed URLs extracted from placements

- GIVEN three news-widget placements with `widgetId = 'mydash_news'`:
  - Placement A: `widgetContent = {"feedUrls": ["https://a.com/rss", "https://b.com/feed"]}`
  - Placement B: `widgetContent = {"feedUrls": ["https://b.com/feed", "https://c.com/atom"]}`
  - Placement C: `widgetContent = {"feedUrls": []}`
- WHEN `FeedRefreshService::discoverFeedUrls()` is called
- THEN the returned array MUST be `["https://a.com/rss", "https://b.com/feed", "https://c.com/atom"]` (deduplicated, sorted)
- AND `https://b.com/feed` MUST appear exactly once

#### Scenario: Placements with no feedUrls are skipped

- GIVEN a placement with `widgetContent = {}` or `widgetContent = {"feedUrls": []}`
- WHEN `discoverFeedUrls()` processes this placement
- THEN no URLs MUST be added from that placement
- AND no error MUST be logged

#### Scenario: New feed URL upserted before first fetch

- GIVEN a placement has just been created with `feedUrl = "https://new.example.org/rss"` not yet in `oc_mydash_feed_cache`
- WHEN `discoverFeedUrls()` finishes and the job iterates feed URLs
- THEN the job MUST call `FeedCacheMapper::upsertUrl("https://new.example.org/rss")` before fetching
- AND a row MUST exist in `oc_mydash_feed_cache` with null metadata fields

#### Scenario: Discovery ignores non-news-widget placements

- GIVEN a placement with `widgetId = 'mydash_links'` and a placement with `widgetId = 'mydash_news'`
- WHEN `discoverFeedUrls()` is called
- THEN ONLY the `mydash_news` placement feeds MUST be included in the result
- AND no URLs from `mydash_links` placements MUST appear

#### Scenario: Empty result when no news widgets are placed

- GIVEN no placement has `widgetId = 'mydash_news'`
- WHEN `discoverFeedUrls()` is called
- THEN the returned array MUST be empty
- AND the job MUST log an INFO-level message "No news widget placements found; nothing to refresh" and exit without error

### Requirement: REQ-FRJ-004 HTTP Conditional GET

The job MUST use HTTP conditional-get headers (`If-None-Match`, `If-Modified-Since`) to avoid re-downloading unchanged feed content and reduce load on feed servers.

#### Scenario: First fetch — no conditional headers sent

- GIVEN `oc_mydash_feed_cache` row for a URL has `etag = NULL` and `lastModified = NULL`
- WHEN the job fetches the feed
- THEN the HTTP request MUST NOT include `If-None-Match` or `If-Modified-Since` headers
- AND the full feed body MUST be received and parsed

#### Scenario: Subsequent fetch — ETag header sent

- GIVEN a previous successful fetch stored `etag = '"abc123"'` for `https://example.com/rss`
- WHEN the job issues the next HTTP request for that URL
- THEN the request MUST include `If-None-Match: "abc123"`
- AND if the server responds HTTP 304, the job MUST update only `lastFetchedAt` and skip parsing

#### Scenario: Subsequent fetch — Last-Modified header sent

- GIVEN a previous fetch stored `lastModified = 'Wed, 21 Oct 2026 07:28:00 GMT'`
- WHEN the job issues the next request
- THEN the request MUST include `If-Modified-Since: Wed, 21 Oct 2026 07:28:00 GMT`

#### Scenario: HTTP 304 — items untouched, only lastFetchedAt updated

- GIVEN the server returns HTTP 304 Not Modified
- WHEN the job processes the response
- THEN `lastFetchedAt` MUST be updated to the current timestamp
- AND `itemsJson`, `lastSuccessAt`, `etag`, and `lastModified` MUST remain at their previous values

#### Scenario: ETag and Last-Modified updated from 200 response headers

- GIVEN the server returns HTTP 200 with headers `ETag: "xyz789"` and `Last-Modified: Thu, 01 May 2026 10:00:00 GMT`
- WHEN the job processes the response
- THEN `etag` MUST be stored as `"xyz789"` and `lastModified` as `Thu, 01 May 2026 10:00:00 GMT`
- AND both values MUST be used in the next conditional-get request

### Requirement: REQ-FRJ-005 Feed Parse and Item Normalisation

On HTTP 200, the job MUST parse RSS 2.0 and Atom 1.0 feed XML and normalise items to the schema defined by the `news-widget` capability.

#### Scenario: RSS 2.0 item fields mapped to schema

- GIVEN an RSS 2.0 feed with items containing `<title>`, `<description>`, `<link>`, `<pubDate>`, `<guid>`, and `<enclosure type="image/...">`
- WHEN the job parses the feed
- THEN each item MUST be normalised to: `{guid, title, summary, link, pubDate, sourceUrl, sourceTitle, thumbnailUrl}`
- AND `summary` MUST be the sanitised text of `<description>`
- AND `thumbnailUrl` MUST be the `url` attribute of `<enclosure>` if present, else `null`

#### Scenario: Atom 1.0 item fields mapped to schema

- GIVEN an Atom 1.0 feed with `<entry>` elements containing `<title>`, `<summary>` or `<content>`, `<link rel="alternate">`, `<published>` or `<updated>`, and `<id>`
- WHEN the job parses the feed
- THEN `guid` MUST be the value of `<id>`, `pubDate` MUST prefer `<published>` over `<updated>`, and `link` MUST be the `href` of `<link rel="alternate">`

#### Scenario: Items without guid get synthetic guid

- GIVEN a feed item has no `<guid>` (RSS) or `<id>` (Atom)
- WHEN the parser processes the item
- THEN the system MUST generate `guid = sha256(title + pubDate + feedUrl)` (hex string)
- AND the synthetic guid MUST be stable across re-fetches of the same item

#### Scenario: Items sorted newest-first and capped at 50

- GIVEN a feed returns 80 items with varying `pubDate` values
- WHEN the job normalises and stores items
- THEN the stored `itemsJson` MUST contain exactly 50 items
- AND they MUST be sorted descending by `pubDate` (newest first)

#### Scenario: sourceUrl and sourceTitle populated from feed metadata

- GIVEN a feed with channel-level `<title>BBC News</title>` and fetch URL `https://feeds.bbci.co.uk/news/rss.xml`
- WHEN items are normalised
- THEN every item MUST have `sourceUrl = "https://feeds.bbci.co.uk/news/rss.xml"` and `sourceTitle = "BBC News"`
- AND if channel title is absent, `sourceTitle` MUST fall back to the hostname of the feed URL

### Requirement: REQ-FRJ-006 Per-Feed Failure Tolerance

A failure to fetch or parse one feed MUST NOT prevent the job from processing the remaining feeds.

#### Scenario: Single feed timeout does not block others

- GIVEN a job is processing feeds `[A, B, C]` and feed B times out after 10 seconds
- WHEN the job runs
- THEN feeds A and C MUST be processed normally
- AND `lastFailureReason` for B MUST be set to a string beginning with `"timeout:"` (e.g., `"timeout: connect timeout after 10s"`)
- AND `itemsJson` for B MUST remain at its last successful value

#### Scenario: HTTP 4xx error recorded but does not abort job

- GIVEN feed `https://gone.example.com/rss` returns HTTP 410 Gone
- WHEN the job processes this feed
- THEN `lastFailureReason` MUST be set to `"410 Gone"`
- AND `itemsJson` MUST NOT be modified
- AND the job MUST continue to the next feed

#### Scenario: HTTP 5xx error handled gracefully

- GIVEN a feed server returns HTTP 503 Service Unavailable
- WHEN the job processes that feed
- THEN `lastFailureReason` MUST be set to `"503 Service Unavailable"`
- AND the job MUST not retry during this tick (no retry logic in scope)
- AND subsequent ticks MUST reattempt the fetch normally

#### Scenario: Malformed XML does not crash the job

- GIVEN a feed server returns HTTP 200 with body `<rss><channel>TRUNCATED`
- WHEN `\SimpleXMLElement` (or simplepie) attempts to parse the body
- THEN the parse exception MUST be caught within a try/catch block
- AND `lastFailureReason` MUST be set to `"parse error: <exception message>"`
- AND `itemsJson` MUST remain at its previous value

#### Scenario: Connect timeout is 10 s, total timeout is 30 s

- GIVEN a feed server accepts the TCP connection but responds after 40 seconds
- WHEN the job fetches that feed via `IClientService`
- THEN the request MUST be aborted after 30 seconds total
- AND the feed MUST be recorded as failed with `lastFailureReason = "timeout: total timeout after 30s"`

### Requirement: REQ-FRJ-007 Concurrency Locking

Only one instance of `FeedRefreshJob` MUST run at a time across the Nextcloud cluster. The job MUST acquire a named lock before processing and release it on completion or exception.

#### Scenario: Job acquires global lock on start

- GIVEN no other instance of `FeedRefreshJob` is running
- WHEN the job's `run()` method is invoked by the Nextcloud cron daemon
- THEN the job MUST call `ILockingProvider::acquireLock('mydash_feed_refresh_running', ILockingProvider::LOCK_EXCLUSIVE)`
- AND the lock MUST be released (via `releaseLock`) in a `finally` block after all feeds are processed

#### Scenario: Second concurrent invocation exits without processing

- GIVEN one `FeedRefreshJob` instance holds the global lock
- WHEN a second instance attempts to run concurrently
- THEN `acquireLock` MUST throw `\OCP\Lock\LockedException`
- AND the second instance MUST catch that exception, log a WARN-level message "FeedRefreshJob already running; skipping this tick", and return immediately
- AND no feed fetches MUST occur in the second instance

#### Scenario: Lock released on unhandled exception

- GIVEN the job holds the global lock and an unhandled exception occurs mid-run
- WHEN the exception propagates out of the core processing loop
- THEN the `finally` block MUST release the lock
- AND the lock MUST NOT be left in a stuck state that would permanently block future ticks

#### Scenario: Lock scope is per-Nextcloud-instance (not per-feed)

- GIVEN a Nextcloud cluster with two nodes running the same cron simultaneously
- WHEN both nodes invoke `FeedRefreshJob::run()` at the same time
- THEN only one node MUST proceed past the `acquireLock` call
- AND the other node MUST log and exit (per the second-concurrent scenario above)

### Requirement: REQ-FRJ-008 Batch Processing for Large Feed Sets

When the number of active feed URLs exceeds 500, the job MUST split work across consecutive ticks using a cursor so that each tick completes in under 5 minutes.

#### Scenario: ≤500 feeds — all processed in one tick

- GIVEN there are 450 active feed URLs
- WHEN `FeedRefreshJob::run()` is invoked
- THEN all 450 feeds MUST be processed within a single tick
- AND the cursor state MUST be cleared after completion

#### Scenario: >500 feeds — cursor advances across ticks

- GIVEN there are 750 active feed URLs, sorted alphabetically, and the job processes 500 per tick
- WHEN the first tick runs
- THEN feeds 1–500 MUST be processed
- AND the cursor value (last processed `feedUrl`) MUST be stored in app config `mydash.feed_refresh_cursor`

- WHEN the second tick runs
- THEN feeds 501–750 MUST be processed (resuming from cursor)
- AND the cursor MUST be cleared on completion of the last batch

#### Scenario: Cursor is invalidated when feed list changes significantly

- GIVEN a cursor is stored after a partial batch run
- AND between ticks, all placements referencing the cursor's URL are deleted
- WHEN the next tick resumes from the stale cursor
- THEN the job MUST detect that the cursor URL no longer exists in the discovered set
- AND MUST restart from the beginning of the sorted feed list

#### Scenario: 5-minute wall-clock budget enforced per tick

- GIVEN 800 feeds, each taking an average of 400ms to fetch
- WHEN the job runs
- THEN after 300 seconds (5 minutes) of elapsed time, the job MUST stop processing further feeds in this tick
- AND persist the cursor at the last completed feed URL
- AND the remaining feeds MUST be processed in subsequent ticks

### Requirement: REQ-FRJ-009 Orphaned Feed Cleanup Hand-off

Feeds no longer referenced by any active placement for ≥30 days MUST be pruned. This job does not perform cleanup itself — it signals the dependency clearly and provides the mapper interface needed by the sibling `orphaned-data-cleanup` job.

#### Scenario: FeedCacheMapper exposes orphan query for sibling job

- GIVEN the `orphaned-data-cleanup` sibling job is implemented
- WHEN it calls `FeedCacheMapper::findOrphanedBefore($cutoff)` where `$cutoff = now() - 30 days`
- THEN the mapper MUST return all rows where `lastFetchedAt < $cutoff`
- NOTE: This spec does not implement the deletion — that is the responsibility of the `orphaned-data-cleanup` spec

#### Scenario: lastFetchedAt updated on every tick for active feeds

- GIVEN a feed URL is actively referenced by a placement
- WHEN the background job runs its tick (even if the feed returns 304)
- THEN `lastFetchedAt` MUST be updated to the current timestamp
- AND the row MUST NOT be returned by `findOrphanedBefore` during the 30-day window

#### Scenario: Feed removed from all placements starts ageing

- GIVEN a feed URL was removed from all placements at `T`
- WHEN the background job runs ticks after `T`
- THEN the URL MUST no longer appear in `discoverFeedUrls()`
- AND `lastFetchedAt` MUST NOT be updated after `T`
- AND after `T + 30 days`, `findOrphanedBefore` MUST include this row

#### Scenario: Sibling job dependency noted in failure state

- GIVEN the `orphaned-data-cleanup` sibling job has NOT been implemented
- WHEN the feed cache grows over time with unreferenced entries
- THEN `oc_mydash_feed_cache` rows for orphaned feeds MUST remain in the table (no auto-delete)
- AND no error MUST be raised — the table grows until the sibling job is active

### Requirement: REQ-FRJ-010 Admin Refresh-Now Endpoint

Administrators MUST be able to trigger an immediate feed refresh via `POST /api/admin/feeds/refresh-now`, optionally scoped to a single URL.

#### Scenario: Full refresh triggered by admin

- GIVEN an admin sends `POST /api/admin/feeds/refresh-now` with no body
- WHEN the controller handles the request
- THEN `FeedRefreshService::refreshAll(null)` MUST be called synchronously
- AND the response MUST be HTTP 200 with body `{processedCount, successCount, failureCount, durationMs}`

#### Scenario: Single-URL refresh triggered by admin

- GIVEN an admin sends `POST /api/admin/feeds/refresh-now?feedUrl=https%3A%2F%2Fexample.com%2Frss`
- WHEN the controller handles the request
- THEN `FeedRefreshService::refreshAll("https://example.com/rss")` MUST be called
- AND only that one feed MUST be fetched and updated
- AND the response MUST include `processedCount: 1`

#### Scenario: Non-admin receives HTTP 403

- GIVEN a regular user "alice" sends `POST /api/admin/feeds/refresh-now`
- WHEN the controller evaluates the request
- THEN the system MUST return HTTP 403
- AND no feed refresh MUST occur
- NOTE: Access is restricted because the `AdminController` does not carry `#[NoAdminRequired]`

#### Scenario: Invalid feedUrl scheme rejected

- GIVEN an admin sends `POST /api/admin/feeds/refresh-now?feedUrl=ftp%3A%2F%2Fbad.com%2Frss`
- WHEN the controller validates the parameter
- THEN the system MUST return HTTP 400 with a message indicating only HTTP/HTTPS URLs are accepted
- AND no fetch MUST occur

#### Scenario: Response body includes timing metadata

- GIVEN an admin triggers a full refresh with 30 active feeds
- WHEN the refresh completes
- THEN the response body MUST include `durationMs` (integer ≥ 0)
- AND `processedCount` MUST equal the number of feeds attempted
- AND `successCount + failureCount` MUST equal `processedCount`

### Requirement: REQ-FRJ-011 Feed Host Allow-List Enforcement

The job MUST check each feed URL against the configured allow-list before fetching. URLs with disallowed hosts MUST be silently skipped and recorded.

#### Scenario: Allow-list empty — all hosts permitted

- GIVEN `mydash.news_widget_allowed_feed_hosts` is not set or is an empty string
- WHEN the job processes any feed URL
- THEN no allow-list check MUST be performed
- AND all HTTP/HTTPS feed URLs MUST proceed to fetch

#### Scenario: Disallowed host skipped before HTTP request

- GIVEN `mydash.news_widget_allowed_feed_hosts = "bbc.com,example.org"`
- AND the job discovers `https://blocked-site.com/feed`
- WHEN the job processes this URL
- THEN NO HTTP request MUST be made to `blocked-site.com`
- AND `lastFailureReason` MUST be set to `"host not in allow-list"`
- AND a WARN-level log MUST record the skipped URL

#### Scenario: Allowed host passes check and is fetched

- GIVEN `mydash.news_widget_allowed_feed_hosts = "bbc.com,example.org"`
- AND the job discovers `https://bbc.com/news/rss.xml`
- WHEN the job processes this URL
- THEN the allow-list check MUST succeed
- AND the HTTP fetch MUST proceed normally

#### Scenario: Allow-list comparison is case-insensitive

- GIVEN `mydash.news_widget_allowed_feed_hosts = "BBC.com"`
- AND the feed URL is `https://bbc.com/rss`
- WHEN the hostname is compared against the allow-list
- THEN the match MUST succeed (lowercased comparison on both sides)

#### Scenario: Subdomains not covered by base hostname

- GIVEN `mydash.news_widget_allowed_feed_hosts = "example.org"`
- AND the feed URL is `https://feeds.example.org/rss`
- WHEN the hostname is compared
- THEN the match MUST fail (exact hostname required; no wildcard subdomain expansion)
- AND `lastFailureReason` MUST be `"host not in allow-list"`

### Requirement: REQ-FRJ-012 User-Agent and Proxy Configuration

The job MUST identify itself with a descriptive User-Agent header and MUST honour global Nextcloud proxy settings when making outbound HTTP requests.

#### Scenario: User-Agent header sent on every request

- GIVEN the job fetches any feed URL
- WHEN the HTTP request is constructed via `IClientService`
- THEN the `User-Agent` header MUST be set to `Mozilla/5.0 (compatible; MyDash/<appVersion>; +<instanceUrl>/apps/mydash)`
- AND `<appVersion>` MUST be the app's current version string (e.g., `1.0.0`)
- AND `<instanceUrl>` MUST be the NC instance URL from `IConfig::getSystemValue('overwrite.cli.url')`

#### Scenario: Proxy settings honoured from NC config

- GIVEN the Nextcloud instance has `system.proxy` set to `http://proxy.corp.example:3128` in `config.php`
- WHEN the job makes an outbound HTTP request via `IClientService`
- THEN the request MUST be routed through the configured proxy
- AND proxy authentication (`system.proxyuserpwd`) MUST be passed if set

#### Scenario: Proxy bypass for local hosts

- GIVEN `system.noproxy` contains `"localhost,127.0.0.1,internal.corp"`
- WHEN the job fetches a feed at `https://internal.corp/feed`
- THEN the proxy MUST NOT be used for this request
- AND NC's `IClientService` handles this transparently (no custom logic required)

#### Scenario: User-Agent identifies version correctly after app upgrade

- GIVEN the app is upgraded from version `1.0.0` to `1.1.0`
- WHEN the job runs after upgrade
- THEN the User-Agent MUST reflect `MyDash/1.1.0`
- AND the version MUST be read dynamically (not hard-coded) from app metadata at job instantiation
