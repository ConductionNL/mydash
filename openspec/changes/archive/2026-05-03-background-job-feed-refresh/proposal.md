# Background Job Feed Refresh

## Why

MyDash news widget users rely on fresh feed content to stay informed. Currently, when a user visits the dashboard, the news widget must fetch external RSS/Atom feeds on-demand, which adds latency and introduces external feed failures directly into the page-load experience. A periodic background job that refreshes feeds asynchronously ensures:

- Users always see recently-cached feed items on dashboard load (sub-second widget render)
- External feed timeouts or server errors do not break the dashboard
- Feed hosts receive predictable, efficient update requests (conditional GET with ETag/Last-Modified)
- Heavy feeds are pruned to a manageable size (50 items per feed)
- Administrators can tune the refresh interval and define allowed feed hosts

## What Changes

- Add a new table `oc_mydash_feed_cache` to persist feed fetch metadata (ETag, Last-Modified, last fetch time, error state) and cached items per feed URL.
- Register a Nextcloud `\OCP\BackgroundJob\TimedJob` named `FeedRefreshJob` that runs every 60 minutes (admin-tunable via `mydash.feed_refresh_interval_seconds`, min 5 min, max 24h).
- The job discovers all active feed URLs from news-widget placements, fetches each with HTTP conditional-get headers (If-None-Match, If-Modified-Since), parses responses, normalises items to the news-widget schema, and stores items as JSON.
- Per-feed concurrency locking ensures only one job instance processes each feed at a time.
- Single-feed failures (timeout, 4xx/5xx, parse error) do not block other feeds.
- Batch processing allows jobs with >500 feeds to spread work across consecutive ticks using a cursor.
- Daily orphan-cleanup job (sibling capability) prunes feeds not referenced for ≥30 days.
- Expose `POST /api/admin/feeds/refresh-now` endpoint (admin-only) to trigger immediate full refresh or per-URL refresh.
- Honour global proxy settings and allow-list of feed hostnames via app config.
- Use appropriate User-Agent header to identify MyDash as the requestor.

## Capabilities

### New Capabilities

- `background-job-feed-refresh` — A periodic background job that asynchronously fetches and caches external RSS/Atom feeds for the news widget, with HTTP conditional-get optimisation, per-feed locking, failure tolerance, and admin-tunable controls.

## Impact

**Affected code:**

- `lib/Db/FeedCacheMapper.php` + `lib/Db/FeedCache.php` — database entity and mapper for feed cache table.
- `lib/Service/FeedRefreshService.php` — core service logic: discover feed URLs, fetch with conditional GET, parse, normalise, cache items, handle failures, batch processing.
- `lib/Job/FeedRefreshJob.php` — Nextcloud TimedJob registration, orchestration of `FeedRefreshService`.
- `lib/Controller/AdminController.php` — new endpoint `POST /api/admin/feeds/refresh-now`.
- `appinfo/routes.php` — register admin endpoint.
- `appinfo/app.php` or `AppInfo/Bootstrap.php` — register the TimedJob in Nextcloud's job queue.
- `lib/Migration/VersionXXXXDate2026...AddFeedCacheTable.php` — schema migration creating `oc_mydash_feed_cache` table.
- `lib/Service/NewsWidgetService.php` (if exists) or `WidgetService.php` — integrate feed cache lookup on news-widget render.

**Affected APIs:**

- 1 new route: `POST /api/admin/feeds/refresh-now` (admin-only).
- 0 changes to existing routes; news-widget remains `GET /api/widgets/news/{placementId}`.

**Dependencies:**

- `OCP\BackgroundJob\TimedJob` — Nextcloud background job framework.
- `OCP\IConfig` — read proxy settings and app config (`mydash.feed_refresh_interval_seconds`, `mydash.news_widget_allowed_feed_hosts`).
- `OCP\Http\Client\IClientService` — HTTP fetch with timeout and proxy support.
- `simplepie` or `SimpleXMLElement` + manual parsing — RSS/Atom feed parsing (simplepie recommended for robustness).
- `OCP\Lock\ILockingProvider` — per-feed concurrency locking.
- Nextcloud's built-in `\DateTime` and `\DateInterval` for timestamp handling.
- No new composer or npm dependencies if using built-in parsing; optional `simplepie/simplepie` for robustness.

**Migration:**

- One schema migration to create `oc_mydash_feed_cache` table.
- Zero data backfill required; job begins operating immediately after migration.
- Admin can immediately configure `mydash.feed_refresh_interval_seconds` and `mydash.news_widget_allowed_feed_hosts` via `IAppConfig`.

**Concurrency & Performance:**

- Per-feed locking using Nextcloud's `ILockingProvider` prevents concurrent fetches of the same URL.
- Batch processing: jobs with >500 feeds spread work across consecutive ticks using `cursor` state persisted in `oc_mydash_feed_cache.processingCursor`.
- Target throughput: <5 minutes for ≤500 feeds.
- HTTP 304 responses (not modified) skip parse step; only metadata updated.
- Individual feed fetch failures do not interrupt overall job; each feed wrapped in try/catch.

