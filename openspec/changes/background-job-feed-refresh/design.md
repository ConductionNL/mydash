# Design — Background Job Feed Refresh

## Context

The MyDash news widget allows users to configure multiple RSS/Atom feed URLs per dashboard. Currently, feeds are fetched on-demand during widget render, which blocks the dashboard load and duplicates work across multiple placements using the same feed. This design formalises the refresh cycle: a background job discovers active feed URLs, fetches them on a schedule, caches the results in the database, and provides an admin API for immediate refresh.

## Goals / Non-Goals

**Goals:**

- Decouple feed refresh from dashboard render — feeds are pre-cached by the background job
- Support multiple placements referencing the same feed without redundant fetches — one cache row per URL
- Scale to hundreds of feeds by batching work across cron ticks when feed count exceeds 500
- Minimize bandwidth via HTTP conditional-get headers (`If-None-Match`, `If-Modified-Since`)
- Prevent single-feed failures (timeout, parse error) from blocking other feeds
- Ensure only one refresh job runs at a time across a clustered instance via distributed locking
- Provide admin endpoint to trigger immediate refresh (all feeds or single URL)
- Prepare for future orphan cleanup via a sibling job that queries the mapper

**Non-Goals:**

- Fetch feeds synchronously during widget render (still out of scope; design assumes caching)
- Per-feed retry policies or exponential backoff (single attempt per tick is sufficient; next tick retries)
- Custom parsing logic per feed format (RSS 2.0 and Atom 1.0 only; extension via interface)
- Real-time feed subscriptions or WebSocket push (polling-based only)
- Feed normalization beyond the `news-widget` schema (summary sanitisation only)
- Analytics on feed freshness or user engagement (metadata only; no metrics)

## Decisions

### D1: One cache row per distinct feed URL, not per placement

**Decision**: `oc_mydash_feed_cache` has a `UNIQUE` constraint on `feedUrl`. All placements sharing a URL write to and read from the same row.

**Alternatives considered:**

- One row per (placement, feedUrl) pair. Rejected — creates duplicate cache entries and complicates discovery (which placement "owns" the cache?).
- Per-placement cache in `oc_mydash_widget_placements.cachedFeedJson`. Rejected — storage is unlimited (MEDIUMTEXT grows unbounded), no deduplication, schema coupling.

**Rationale**: Single row = single refresh per distinct URL = minimal bandwidth. Discovery logic is simpler: map all placements to URLs, deduplicate, refresh unique set, all placements read the same cache.

### D2: Background job + synchronous admin endpoint

**Decision**: Scheduled job runs every 60 minutes by default (configurable). Admin endpoint `POST /api/admin/feeds/refresh-now` calls the same service synchronously, optionally scoped to one URL.

**Alternatives considered:**

- Sync-only (no background job). Rejected — blocks dashboard render if any feed is slow.
- Queue-based (background jobs → queue, admin endpoint → immediate queue submit). Rejected — queued vs. scheduled jobs require parallel infrastructure; simple TimedJob is sufficient.

**Rationale**: TimedJob is simple, stable, and Nextcloud admin can tune the interval. Sync endpoint for admins is convenience; it runs within the request context and may timeout if many feeds are slow, which is acceptable for an admin action.

### D3: HTTP conditional-get with ETag and Last-Modified

**Decision**: On first fetch, store `ETag` and `Last-Modified` headers if provided. On subsequent fetches, send `If-None-Match: <etag>` and `If-Modified-Since: <lastModified>`. If server returns HTTP 304 Not Modified, update only `lastFetchedAt` (skip parse and item storage).

**Alternatives considered:**

- Always fetch full content. Rejected — wastes bandwidth on unchanged feeds.
- Cache `If-None-Match` only, ignore `Last-Modified`. Rejected — servers may not send ETag (e.g., some feeds serve dynamic Last-Modified only).
- Custom cache-busting logic (e.g., hash of last 50 items). Rejected — HTTP 304 is the standard and conserves bandwidth best.

**Rationale**: HTTP conditional-get is a web standard and widely supported by feed servers. ETag + Last-Modified coverage handles both modern and older servers.

### D4: Fail one feed, continue the job

**Decision**: Each feed fetch and parse is wrapped in its own `try/catch`. On error, set `lastFailureReason` and continue to the next feed. No abort, no retry delay.

**Alternatives considered:**

- Abort on first error. Rejected — one slow/broken feed blocks refresh of all others.
- Retry with exponential backoff. Rejected — out of scope; next cron tick retries naturally.
- Queue failed feeds for priority retry. Rejected — unnecessary complexity; failed feeds will be retried on next scheduled run.

**Rationale**: Fault isolation is critical in a batch job. The next cron tick is the retry mechanism.

### D5: Global distributed lock, not per-feed lock

**Decision**: Job acquires `ILockingProvider::acquireLock('mydash_feed_refresh_running', LOCK_EXCLUSIVE)` before starting, released in a `finally` block. If acquired by another instance, second instance logs and exits immediately.

**Alternatives considered:**

- No lock (allow concurrent instances). Rejected — duplicate work, cache write conflicts, race conditions on cursor.
- Per-feed locks. Rejected — overhead and complexity; we want to prevent concurrent job instances, not concurrent feed fetches.
- Advisory lock (non-blocking, best-effort). Rejected — Nextcloud's distributed lock is already exclusive and handles node failure; using it as specified is safer.

**Rationale**: A single job instance at a time prevents duplicate work and cursor conflicts. The lock is released in `finally` so it survives unhandled exceptions.

### D6: Batch with cursor when feed count exceeds 500

**Decision**: Per cron tick, process feeds in sorted order. If >500 feeds, after processing the first 500, store the cursor (`lastProcessedUrl`) in app config and exit. Next tick resumes from cursor. Cursor is cleared when all feeds are processed.

**Alternatives considered:**

- Always process all feeds in one tick (no batching). Rejected — 1000 feeds × 30s timeout = 30000s wall-clock time, exceeds typical cron timeout.
- Process N feeds, then re-queue the job. Rejected — background job is already TimedJob, not queued; re-queueing is unnecessary.
- Compute optimal batch size dynamically. Rejected — overkill; 500 is reasonable (depends on feed timeout + parsing, ~30s per 500 = good wall-clock budget).

**Rationale**: Cursor-based pagination is simple, resumable, and respects cron execution limits (typically 5–30min).

### D7: Batch deadline of 5 minutes wall-clock time

**Decision**: Job checks elapsed wall-clock time and stops processing new feeds if wall-clock elapsed > 300 seconds (5 min). Cursor is persisted, remaining feeds processed in next tick.

**Alternatives considered:**

- Process exactly 500 feeds per tick regardless of time. Rejected — if feeds take longer than expected, timeout occurs and job is killed mid-batch.
- No deadline check (rely on cron timeout). Rejected — no feedback on progress; job may be killed during cursor update.

**Rationale**: 5-minute wall-clock budget is a reasonable hedge against cron executor killing long-running tasks. Explicit check allows graceful cursor persistence.

### D8: Host allow-list enforcement before HTTP fetch

**Decision**: If `mydash.news_widget_allowed_feed_hosts` is set, check feed URL hostname against the list (lowercased, case-insensitive, exact match) before issuing HTTP request. Disallowed hosts are skipped with `lastFailureReason = "host not in allow-list"`.

**Alternatives considered:**

- Allow-list as a blacklist (disallowed hosts). Rejected — whitelist is more secure by default (deny-unless-permitted).
- Wildcard subdomain matching (e.g., `*.example.org` matches `feeds.example.org`). Rejected — adds parsing complexity; exact match is clearer.
- Check after HTTP response. Rejected — wastes network round-trip.

**Rationale**: Allow-list is a security control to prevent users from configuring feeds to internal/restricted hosts. Checking before HTTP avoids unnecessary network calls and signals the failure clearly.

### D9: Feed item capped at 50, sorted newest-first

**Decision**: Parse feeds and extract up to 50 newest items (sorted by `pubDate` descending). Store as JSON in `itemsJson`. If feed returns 150 items, only the 50 newest are stored.

**Alternatives considered:**

- Store all items (no cap). Rejected — MEDIUMTEXT storage is limited, and widget renders only ~5–10 items anyway; cap is reasonable.
- Per-feed configurable cap. Rejected — unnecessary complexity; 50 is a sensible default (generous for widget display).
- Oldest-first order. Rejected — widgets typically display newest items first; reversing order in the widget is wasteful.

**Rationale**: 50-item cap balances storage and freshness. Sorting newest-first matches widget expectations and saves widget-side sorting work.

### D10: Synthetic GUID for items without natural ID

**Decision**: If an RSS item lacks `<guid>` or Atom entry lacks `<id>`, generate synthetic GUID as `sha256(title + pubDate + feedUrl)` (hex string). Synthetic GUID is stable across re-fetches of the same item.

**Alternatives considered:**

- Use item position in feed. Rejected — unstable when older items are pruned or new items appear.
- Use item hash (content only). Rejected — hash unstable if description is updated.
- Leave GUID blank / null. Rejected — widget or downstream dedup logic may assume GUID is always present.

**Rationale**: SHA256 of (title + pubDate + feedUrl) is stable and unlikely to collide, allowing the widget to detect duplicate items across refreshes.

### D11: No third-party feed parsing library

**Decision**: Use PHP's built-in `simplexml_load_string()` with `LIBXML_NOCDATA | LIBXML_NONET` flags. Parsing logic is encapsulated in a `FeedParserInterface` so implementation can be swapped later.

**Alternatives considered:**

- Add a third-party library (e.g., `simplepie`, `feed-io`). Rejected — adds dependency, increases attack surface. Built-in XML support is sufficient for RSS 2.0 and Atom 1.0.
- Store raw feed content and defer parsing. Rejected — complicates widget logic.

**Rationale**: `simplexml_load_string` is familiar, built-in, and secure when used with `LIBXML_NONET` (prevents XXE). Interface design allows a future spec change to swap in a more sophisticated parser.

### D12: User-Agent header with app version and instance URL

**Decision**: Every HTTP request includes User-Agent header: `Mozilla/5.0 (compatible; MyDash/<version>; +<instanceUrl>/apps/mydash)`. Version is read dynamically from app metadata at job instantiation.

**Alternatives considered:**

- Static User-Agent (e.g., `MyDash/1.0`). Rejected — doesn't signal app version to feed servers, complicates support.
- No User-Agent. Rejected — some feed servers reject requests without a User-Agent.

**Rationale**: Descriptive User-Agent helps feed server admins identify and support MyDash clients. Dynamic version reading ensures accuracy after upgrades.

## Architecture Overview

### Entity & Storage

- **FeedCache** entity: `id`, `feedUrl` (UNIQUE), `lastFetchedAt`, `lastSuccessAt`, `lastFailureReason`, `etag`, `lastModified`, `itemsJson`
- **FeedCacheMapper**: CRUD interface with `findByUrl()`, `upsertUrl()`, `findOrphanedBefore()` (for sibling cleanup job)
- **Database migration**: creates table on upgrade, drops on rollback

### Service Layer

- **FeedRefreshService**:
  - `refreshAll(?$feedUrl)` — main entry point for job and admin endpoint
  - `discoverFeedUrls()` — query all `mydash_news` placements, extract and deduplicate feed URLs
  - `fetchAndCacheFeed($feedUrl, $cursor)` — fetch a single feed (with conditional headers), parse, update cache
  - `parseItemsFromResponse($body, $feedUrl)` — delegate to `FeedParserInterface`

- **FeedParserInterface & FeedXmlParser**:
  - `parseRss20Items(\SimpleXMLElement, $feedUrl)`
  - `parseAtom10Items(\SimpleXMLElement, $feedUrl)`
  - Item normalisation to `{guid, title, summary, link, pubDate, sourceUrl, sourceTitle, thumbnailUrl}`

### Background Job

- **FeedRefreshJob (TimedJob)**:
  - Constructor reads `mydash.feed_refresh_interval_seconds` (clamped 300–86400, default 3600)
  - `run()` acquires lock, calls `FeedRefreshService::refreshAll()`, handles batch cursor, releases lock
  - `finally` block ensures lock release on exception

### Admin Controller

- **AdminController::refreshNow()**:
  - `#[RequireAdmin]` enforcement (AdminController is not `#[NoAdminRequired]`)
  - `POST /api/admin/feeds/refresh-now?feedUrl=<optional>`
  - Calls `FeedRefreshService::refreshAll($feedUrl)` synchronously
  - Returns `{processedCount, successCount, failureCount, durationMs}`

### Data Flow

1. Cron scheduler invokes `FeedRefreshJob::run()` every 60 minutes (configurable)
2. Job acquires global lock; if locked, logs and exits
3. `discoverFeedUrls()` queries `oc_mydash_widget_placements` for all `mydash_news` widgetId entries, extracts and deduplicates URLs
4. For each URL (or resumed from cursor):
   - Upsert row in `oc_mydash_feed_cache` if not exists
   - Send HTTP GET with `If-None-Match` / `If-Modified-Since` (if cached)
   - If HTTP 304: update `lastFetchedAt`, skip parse
   - If HTTP 200: parse via `FeedParserInterface`, store up to 50 items as JSON, update headers and timestamps
   - On error: set `lastFailureReason`, continue
5. If >500 feeds and wall-clock > 5 min: persist cursor, exit
6. On completion: clear cursor, release lock
7. Admin calls `POST /api/admin/feeds/refresh-now` → synchronously calls same service

## Seed Data

### `oc_mydash_feed_cache` Example Rows

```json
{
  "id": 1,
  "feedUrl": "https://www.bbc.com/news/rss.xml",
  "lastFetchedAt": "2026-05-21T10:30:00Z",
  "lastSuccessAt": "2026-05-21T10:30:00Z",
  "lastFailureReason": null,
  "etag": "\"abc123def\"",
  "lastModified": "Mon, 19 May 2026 15:22:00 GMT",
  "itemsJson": "[{\"guid\": \"bbc-article-001\", \"title\": \"Tech news headline\", \"summary\": \"A brief overview...\", \"link\": \"https://bbc.com/article\", \"pubDate\": \"2026-05-21T09:00:00Z\", \"sourceUrl\": \"https://www.bbc.com/news/rss.xml\", \"sourceTitle\": \"BBC News\", \"thumbnailUrl\": \"https://bbc.com/image.jpg\"}, ...]"
}
```

```json
{
  "id": 2,
  "feedUrl": "https://feeds.arstechnica.com/arstechnica/index",
  "lastFetchedAt": "2026-05-21T10:28:00Z",
  "lastSuccessAt": "2026-05-21T10:28:00Z",
  "lastFailureReason": null,
  "etag": "\"xyz789abc\"",
  "lastModified": "Tue, 20 May 2026 08:45:00 GMT",
  "itemsJson": "[...]"
}
```

```json
{
  "id": 3,
  "feedUrl": "https://feeds.theguardian.com/world/rss",
  "lastFetchedAt": "2026-05-21T10:25:00Z",
  "lastSuccessAt": "2026-05-20T10:25:00Z",
  "lastFailureReason": "timeout: total timeout after 30s",
  "etag": null,
  "lastModified": null,
  "itemsJson": "[...]"
}
```

## Risks / Trade-offs

- **Risk:** Cursor restart on feed list change. If a feed is deleted while cursor points to it, the job detects the stale cursor and restarts from the beginning. This might re-fetch recently-processed feeds. → **Mitigation:** Cursor invalidation is logged at WARN level; re-fetches are idempotent (304 Not Modified skips parse), so impact is minimal.
- **Risk:** 5-minute wall-clock deadline may not be generous enough on slow server. → **Mitigation:** Admin can increase `feed_refresh_interval_seconds` to a multiple of 5 min (e.g., 600s = 10 min job, 300s pause between ticks) or increase server capacity.
- **Risk:** MEDIUMTEXT storage is limited (~16 MB per row). A feed with 50 items × 5 KB per item JSON = 250 KB is safe, but very large items could approach the limit. → **Mitigation:** Item cap of 50 and test with realistic item sizes; document the limit in PHP docblock.
- **Trade-off:** No automatic retry (exponential backoff). A permanently broken feed remains broken until admin manually refreshes or feed is removed. → **Accepted:** Simplicity wins; next scheduled tick is a retry, and admin endpoint is available for immediate action.
- **Trade-off:** Batch processing is coarse (process 500, stop). Feeds are processed in alphabetical order by URL, so URL distribution affects batch fairness. → **Accepted:** Deterministic and testable; finer granularity adds little value.

## Open Questions

1. Should failed feeds be deprioritized in future ticks (e.g., moved to the end of the queue)? Current decision: No — maintain alphabetical order for predictability. A future `failed-feed-prioritization` spec can address this.
2. Should the job emit activity/notification events (e.g., "Feed XYZ failed")? Current decision: No — logging only. Notifications can be a follow-up spec.
3. Should widget-render code cache items from `oc_mydash_feed_cache` in-memory or fetch fresh from DB each render? Current decision: Out of scope (widget spec responsibility). Widget can use a short-lived in-memory cache or always fetch fresh.
