# Tasks — dashboard-view-analytics

## Tasks

- [ ] Task 1: Create database migration `AddDashboardViewsTable.php` — creates `oc_mydash_dashboard_views` table with columns (id, dashboardUuid, viewBucket, viewCount, uniqueViewerCount), composite unique index on (dashboardUuid, viewBucket), and index on (viewBucket); verify migration runs on SQLite, MySQL, PostgreSQL
- [ ] Task 2: Register Nextcloud settings keys in app info or config service: `mydash.analytics_enabled` (global, default true), `mydash.analytics_optout` (per-user, default false), `mydash.analytics_retention_days` (global, default 365, clamped to [30, 3650])
- [ ] Task 3: Implement `SaltRotationJob` — background job that runs daily at 02:00 UTC, generates a new 32-byte random salt, overwrites (not appends) `mydash.analytics_dailysalt` in `IConfig`, and logs "Salt rotated for analytics deduplication" with no PII
- [ ] Task 4: Implement `PurgeViewsJob` — background job that runs daily at 02:05 UTC, deletes rows from `oc_mydash_dashboard_views` where `viewBucket < CURRENT_DATE - retention_days`, respects clamped retention bounds [30, 3650], logs count and cutoff date with no PII, idempotent
- [ ] Task 5: Register both `SaltRotationJob` and `PurgeViewsJob` with Nextcloud scheduler in `bootstrap.php` or app info; verify jobs appear in admin Settings > Background jobs
- [ ] Task 6: Implement `AnalyticsService` with method `recordViewEvent(dashboardUuid: string, userId: string): bool` — checks `analytics_enabled` setting, checks user's `analytics_optout` preference, reads salt from `IConfig`, computes hash = SHA256(userId || salt), checks `ICache` for hash with key `mydash_anlt_{dashboardUuid}_{hash}`, if not found: set cache entry with TTL = seconds until next UTC midnight, fetch or create row in `oc_mydash_dashboard_views`, increment viewCount and (if not in cache) uniqueViewerCount; return true on success, false if disabled/opted-out
- [ ] Task 7: Implement `AdminAnalyticsService` with methods: `getTopDashboards(period: string, limit: int)`, `getDashboardBreakdown(dashboardUuid: string, period: string)`, `getInstanceSummary(period: string)` — all query `oc_mydash_dashboard_views` with date filters, join `oc_mydash_dashboards` for dashboard names, sort correctly (by viewCount desc for top, by viewBucket asc for breakdown), return aggregates (no per-user data)
- [ ] Task 8: Implement `AdminAnalyticsController` with endpoints:
  - `GET /api/admin/analytics/dashboards/top?period={7d,30d,90d}&limit={int}` → calls `AdminAnalyticsService::getTopDashboards()`, requires admin, returns JSON array
  - `GET /api/admin/analytics/dashboards/{uuid}?period={7d,30d,90d}` → calls `AdminAnalyticsService::getDashboardBreakdown()`, requires admin, returns 404 if dashboard not found, returns JSON array of daily records
  - `GET /api/admin/analytics/summary?period={7d,30d,90d}` → calls `AdminAnalyticsService::getInstanceSummary()`, requires admin, returns JSON object with totalViewCount, totalUniqueViewers, dashboardCount, period, top5 array
  - `GET /api/admin/analytics/export?period={7d,30d,90d}` → calls `AdminAnalyticsService::getTopDashboards(limit=999)` or similar, builds CSV with columns (dashboardUuid, dashboardName, viewBucket, viewCount, uniqueViewerCount), sets Content-Type: text/csv, sets Content-Disposition: attachment; filename=dashboard-analytics-{YYYY-MM-DD}.csv, requires admin, returns 403 for non-admin
- [ ] Task 9: Implement `DashboardViewController` with endpoint `POST /api/dashboards/{uuid}/view-event` — validates dashboard exists (return 404 if not), calls `AnalyticsService::recordViewEvent(uuid, userId)`, returns HTTP 204 No Content regardless of tracking enabled/opted-out (no error leak), requires authentication (return 401 if not authed), accepts empty JSON body {}
- [ ] Task 10: Modify `DashboardView.vue` to call view-event endpoint in `mounted()` hook — add method that sends `POST /api/dashboards/{this.uuid}/view-event` asynchronously (fire-and-forget), debounce per-uuid with 1-second window (e.g., use lodash debounce or native throttle), skip call if config setting `analytics_enabled` is false (check via component props or API), handle response silently (no UI changes on 204 or error)
- [ ] Task 11: PHPUnit tests for `AnalyticsService`:
  - Test `recordViewEvent()` with analytics_enabled true/false
  - Test per-user analytics_optout true/false
  - Test unique-viewer dedup: same user same day = uniqueViewerCount += 1 only once, different users = increments independently
  - Test cache key format and TTL calculation (seconds until next UTC midnight)
  - Test salt reading from IConfig
  - Test concurrent view events on same (dashboardUuid, viewBucket)
- [ ] Task 12: PHPUnit tests for `AdminAnalyticsService` and `AdminAnalyticsController`:
  - Test `getTopDashboards()` returns correct sorting (by viewCount desc), respects period and limit parameters
  - Test `getDashboardBreakdown()` returns sorted daily records (by viewBucket asc), omits days with no views, respects period
  - Test `getInstanceSummary()` returns correct totals and top5 array
  - Test CSV export header and row format, filename includes today's date
  - Test 403 response for non-admin on all admin endpoints
  - Test 404 for non-existent dashboard on per-dashboard endpoints
- [ ] Task 13: PHPUnit tests for background jobs:
  - Test `SaltRotationJob` overwrites (not appends) salt, generates 32-byte value
  - Test `PurgeViewsJob` deletes rows older than retention_days, respects clamped bounds, idempotent on re-run
  - Test job logging contains no PII
- [ ] Task 14: Vitest tests for `DashboardView.vue`:
  - Test view-event endpoint is called in mounted()
  - Test debouncing: multiple simultaneous mounts of same uuid = 1 API call, different uuids = separate calls
  - Test page reload triggers new event (new mount = new debounce window)
  - Test view-event is not called when analytics_enabled is false
- [ ] Task 15: Playwright end-to-end test:
  - Create a dashboard and navigate to it; verify single view-event POST request is made (via network inspector or mock API)
  - Open same dashboard in two tabs simultaneously; verify only one view-event request is sent (debounce check)
  - Check admin analytics endpoint: load dashboard multiple times per day, verify viewCount and uniqueViewerCount increment correctly
  - Load dashboard on 2 consecutive days; verify rows in database show 2 separate (dashboardUuid, viewBucket) entries
  - Export CSV; verify content, filename format, sorting
  - Set analytics_enabled to false; refresh page; verify no view-event request
- [ ] Task 16: Code quality:
  - Run `composer check:strict` (PHPStan/Psalm) on new backend code; fix any issues
  - Run ESLint on `DashboardView.vue`; fix any issues
  - Run i18n audit — no new user-facing strings expected; if any are introduced, add to both `nl` and `en` translation files
  - Verify no hardcoded UTC timezone assumptions; use Nextcloud DateTimeImmutable or similar for all date operations
- [ ] Task 17: Documentation:
  - Add admin guide section: "View Analytics" explaining settings (`analytics_enabled`, `analytics_optout`, `analytics_retention_days`), CSV interpretation, expected job log entries
  - Update changelog with entry: "Added privacy-preserving dashboard view analytics with daily aggregation, user opt-out, and admin query endpoints"
  - Document in code: comment in `AnalyticsService` explaining salt rotation and cache TTL alignment rationale (reference design.md D1 and D2)

## Verification

`npm run test:unit` and `npm run test:e2e` pass. Admin can enable/disable analytics via settings, users can opt out, view events increment counters correctly per day, unique-viewer dedup works, CSV export is valid, background jobs run without errors in logs, no database schema errors on MySQL/SQLite/PostgreSQL.

## Tests (company-wide ADR-009)

PHPUnit for backend services and controllers (Tasks 11–13); Vitest for Vue component (Task 14); Playwright for e2e flow (Task 15).

## Documentation (company-wide ADR-010)

Admin guide (Task 17), inline code comments linking design decisions (Task 17), changelog (Task 17).

## i18n (company-wide ADR-007)

No user-facing strings expected in core feature. If settings labels or CSV headers require translation, add to both `nl` and `en` (Task 16).
