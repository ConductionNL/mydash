# Tasks — background-job-feed-refresh

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddFeedCacheTable.php` with `oc_mydash_feed_cache` table: `id` (auto-increment PK), `feedUrl VARCHAR(2048) UNIQUE NOT NULL`, `lastFetchedAt TIMESTAMP NULL`, `lastSuccessAt TIMESTAMP NULL`, `lastFailureReason TEXT NULL`, `etag VARCHAR(255) NULL`, `lastModified VARCHAR(255) NULL`, `itemsJson MEDIUMTEXT NULL`
- [ ] 1.2 Add index `idx_mydash_feed_cache_url` on `(feedUrl)` for fast upsert lookups
- [ ] 1.3 Confirm migration is reversible (drop table in rollback path)
- [ ] 1.4 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Create `lib/Db/FeedCache.php` entity extending `\OCP\AppFramework\Db\Entity` with fields: `feedUrl`, `lastFetchedAt`, `lastSuccessAt`, `lastFailureReason`, `etag`, `lastModified`, `itemsJson`
- [ ] 2.2 Add typed getters/setters following NC Entity `__call` pattern (no named args)
- [ ] 2.3 Add `FeedCache::decodeItems(): array` helper that json_decodes `itemsJson`, returning `[]` on null/invalid JSON
- [ ] 2.4 Add `FeedCache::encodeItems(array $items): void` helper that json_encodes and caps at 50 items

## 3. Mapper layer

- [ ] 3.1 Create `lib/Db/FeedCacheMapper.php` extending `\OCP\AppFramework\Db\QBMapper`
- [ ] 3.2 Add `FeedCacheMapper::findByUrl(string $feedUrl): FeedCache` — raises `\OCP\AppFramework\Db\DoesNotExistException` if absent
- [ ] 3.3 Add `FeedCacheMapper::upsertUrl(string $feedUrl): FeedCache` — inserts row if missing, returns existing row if present (uses INSERT ... ON DUPLICATE KEY or equivalent)
- [ ] 3.4 Add `FeedCacheMapper::findAll(): array` for enumerating all cached feeds
- [ ] 3.5 Add `FeedCacheMapper::findOrphanedBefore(\DateTimeInterface $cutoff): array` — feeds with `lastFetchedAt < $cutoff` (used by sibling orphan-cleanup job)

## 4. Feed refresh service

- [ ] 4.1 Create `lib/Service/FeedRefreshService.php` with injected `IClientService`, `IAppConfig`, `ILockingProvider`, `FeedCacheMapper`, `WidgetPlacementMapper` (or equivalent)
- [ ] 4.2 Implement `FeedRefreshService::discoverFeedUrls(): array` — queries `oc_mydash_widget_placements` for all placements with `widgetId = 'mydash_news'`, extracts `feedUrls` from `widgetContent` JSON, deduplicates, filters against allow-list
- [ ] 4.3 Implement `FeedRefreshService::refreshFeed(string $feedUrl): array` — performs HTTP conditional-get with `If-None-Match` and `If-Modified-Since` headers; returns result summary `{status, itemCount, durationMs}`; wraps entire method in try/catch
- [ ] 4.4 Implement HTTP 304 shortcut: update `lastFetchedAt` only, skip parse step, return early
- [ ] 4.5 Implement HTTP 200 parse path: use `\SimpleXMLElement` (or simplepie if available) to parse RSS/Atom; normalise items to news-widget schema (`guid`, `title`, `summary`, `link`, `pubDate`, `sourceUrl`, `sourceTitle`, `thumbnailUrl`); cap at 50 items; update `itemsJson`, `lastSuccessAt`, `etag`, `lastModified`
- [ ] 4.6 Implement 4xx/5xx/timeout error path: set `lastFailureReason = "<code> <message>"`, do NOT modify `itemsJson`, update `lastFetchedAt`
- [ ] 4.7 Implement allow-list check via `IAppConfig::getValueString('mydash', 'news_widget_allowed_feed_hosts', '')`: if non-empty, parse as comma-separated hostnames; skip disallowed URLs with `lastFailureReason = "host not in allow-list"` and WARN-level log
- [ ] 4.8 Implement proxy support: read NC's `system.proxy` and `system.proxyuserpwd` from `IConfig` and pass to `IClientService` request options
- [ ] 4.9 Build User-Agent string: `Mozilla/5.0 (compatible; MyDash/<appVersion>; +<NC instance URL>/apps/mydash)` — read app version from `IConfig::getSystemValue('version')` or `AppInfo/Application::VERSION`
- [ ] 4.10 Implement `FeedRefreshService::refreshAll(?string $onlyUrl = null): array` — iterates all discovered feeds (or single URL if provided), respects batch cursor for >500 feeds, returns aggregate `{processedCount, successCount, failureCount, durationMs}`
- [ ] 4.11 Implement cursor-based batch processing: persist cursor (last processed `feedUrl` alphabetically) in a temp app config key; on each tick resume from cursor, advance until either all feeds done or 5-minute wall-clock budget consumed, then reset cursor

## 5. Background job

- [ ] 5.1 Create `lib/Job/FeedRefreshJob.php` extending `\OCP\BackgroundJob\TimedJob`
- [ ] 5.2 Constructor reads `mydash.feed_refresh_interval_seconds` via `IAppConfig`; clamps to [300, 86400]; calls `$this->setInterval($interval)` (default 3600)
- [ ] 5.3 Override `run(array $argument): void` — acquires a global job lock (`mydash_feed_refresh_running`) via `ILockingProvider::acquireLock()`; calls `FeedRefreshService::refreshAll()`; releases lock; logs result at INFO level
- [ ] 5.4 Register `FeedRefreshJob` in `AppInfo/Bootstrap.php` via `$context->registerBackgroundJob(FeedRefreshJob::class)`
- [ ] 5.5 If global lock cannot be acquired (another instance still running), log WARN and exit immediately

## 6. Admin endpoint

- [ ] 6.1 Add `AdminController::refreshFeedsNow(?string $feedUrl = null): JSONResponse` mapped to `POST /api/admin/feeds/refresh-now`
- [ ] 6.2 Endpoint delegates to `FeedRefreshService::refreshAll($feedUrl)`; returns `{processedCount, successCount, failureCount, durationMs}` with HTTP 200
- [ ] 6.3 Register route in `appinfo/routes.php`; method requires admin (no `#[NoAdminRequired]`)
- [ ] 6.4 Validate optional `feedUrl` query parameter: reject non-HTTP/HTTPS schemes with HTTP 400

## 7. News widget integration

- [ ] 7.1 Update `lib/Service/NewsWidgetService.php` `getItemsForPlacement()` to read `itemsJson` from `oc_mydash_feed_cache` per feed URL before falling back to on-demand fetch (per REQ-NEWS-004 in sibling news-widget spec)
- [ ] 7.2 On cache miss, perform on-demand fetch and upsert into `oc_mydash_feed_cache` (populates cache for next background job tick)

## 8. PHPUnit tests

- [ ] 8.1 `FeedCacheMapperTest::upsertUrl` — creates new row; returns existing row on duplicate; verify UNIQUE constraint respected
- [ ] 8.2 `FeedRefreshServiceTest::discoverFeedUrls` — returns deduplicated list; allow-list filtering; placements with no feedUrls ignored
- [ ] 8.3 `FeedRefreshServiceTest::refreshFeed304` — 304 response updates `lastFetchedAt` only, `itemsJson` unchanged
- [ ] 8.4 `FeedRefreshServiceTest::refreshFeedSuccess` — 200 response parses items, caps at 50, updates `lastSuccessAt`, `etag`, `lastModified`
- [ ] 8.5 `FeedRefreshServiceTest::refreshFeedFailure` — 500 response sets `lastFailureReason`, does NOT overwrite `itemsJson`
- [ ] 8.6 `FeedRefreshServiceTest::refreshFeedTimeout` — timeout exception caught, sets `lastFailureReason`, other feeds continue
- [ ] 8.7 `FeedRefreshServiceTest::allowListSkip` — disallowed host skips fetch, sets `lastFailureReason = "host not in allow-list"`
- [ ] 8.8 `FeedRefreshJobTest::runAcquiresLock` — lock acquired at start, released after run; second concurrent call exits early (lock not acquired)
- [ ] 8.9 `AdminControllerTest::refreshNowReturns200` — returns aggregate summary JSON; non-admin gets 403
- [ ] 8.10 `AdminControllerTest::refreshNowInvalidScheme` — `feedUrl=ftp://foo` returns 400

## 9. End-to-end Playwright tests

- [ ] 9.1 Admin triggers `POST /api/admin/feeds/refresh-now` and receives `{processedCount, successCount, failureCount, durationMs}`
- [ ] 9.2 After background job runs, news widget displays items without on-demand fetch (mock feed server)
- [ ] 9.3 Non-admin sending `POST /api/admin/feeds/refresh-now` receives HTTP 403
- [ ] 9.4 Feed behind disallowed host shows `lastFailureReason = "host not in allow-list"` in DB after job tick
- [ ] 9.5 Simulate 504-feed scenario: verify cursor state in app config after first tick, items processed in second tick

## 10. Quality gates

- [ ] 10.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 10.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 10.3 Update generated OpenAPI spec / Postman collection so external API consumers see the new endpoint
- [ ] 10.4 i18n keys for all new admin labels and error messages in both `nl` and `en`
- [ ] 10.5 SPDX headers inside the file docblock on every new PHP file (per SPDX-in-docblock convention)
- [ ] 10.6 Run all hydra-gates locally before opening PR
