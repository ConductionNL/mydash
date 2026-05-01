# Tasks — dashboard-view-analytics

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddDashboardViewsTable.php` creating `oc_mydash_dashboard_views`:
  - `id INT AUTO_INCREMENT PRIMARY KEY`
  - `dashboardUuid VARCHAR(36) NOT NULL, FOREIGN KEY REFERENCES oc_mydash_dashboards(uuid) ON DELETE CASCADE`
  - `viewBucket DATE NOT NULL`
  - `viewCount INT DEFAULT 0 NOT NULL`
  - `uniqueViewerCount INT DEFAULT 0 NOT NULL`
  - Composite unique index on `(dashboardUuid, viewBucket)`
  - Index on `(viewBucket)` for date-range queries
- [ ] 1.2 Migration is reversible (DROP TABLE + cascading foreign keys cleaned up)
- [ ] 1.3 Test migration locally on SQLite, MySQL, PostgreSQL; verify schema applied cleanly

## 2. Domain model

- [ ] 2.1 Create `lib/Db/DashboardView.php` entity with fields:
  - `$id` (int)
  - `$dashboardUuid` (string, 36 chars)
  - `$viewBucket` (string, DATE format YYYY-MM-DD)
  - `$viewCount` (int)
  - `$uniqueViewerCount` (int)
  - Getters/setters using Entity `__call` pattern (no named args)
- [ ] 2.2 Add `DashboardView::jsonSerialize()` for API responses

## 3. Mapper layer

- [ ] 3.1 Create `lib/Db/DashboardViewMapper.php` extending `QBMapper` with methods:
  - `findByDashboardAndBucket(string $dashboardUuid, string $viewBucket): ?DashboardView`
  - `findByDashboardInRange(string $dashboardUuid, string $startDate, string $endDate): array` (ordered by viewBucket ASC)
  - `findTopDashboardsInRange(string $startDate, string $endDate, int $limit): array` (sum viewCount per dashboard, ordered DESC)
  - `findInstanceSummaryInRange(string $startDate, string $endDate): array` (totals across all dashboards)
  - `upsertView(string $dashboardUuid, string $viewBucket, int $viewCountDelta, int $uniqueCountDelta): DashboardView` (INSERT or UPDATE)
  - `deleteOlderThan(string $beforeDate): int` (purge; returns count deleted)
- [ ] 3.2 Add PHPUnit test for mapper covering: upsert with increment, find by dashboard, find in range, delete older than, empty results

## 4. Cache layer for unique-viewer dedup

- [ ] 4.1 Create `lib/Service/UniqueViewerDedup.php` service with:
  - `getSaltForDate(string $viewBucketDate): string` — retrieves or generates daily salt (stored in ICache with TTL = 25h, timezone UTC)
  - `hashUserForDate(int $userId, string $viewBucketDate): string` — SHA256(userId + salt), returns hex string
  - `isNewUniqueViewer(int $userId, string $viewBucketDate): bool` — checks cache for hash; if not found, adds to cache (TTL 86400s until next UTC midnight) and returns true; otherwise returns false
  - Cache key format: `mydash_viewer_hash_{viewBucketDate}_{hash}` (prevents collision across dates)
- [ ] 4.2 Test via PHPUnit: dedup identifies first view vs repeat, salt rotates at midnight boundary, cache TTL is correct

## 5. Analytics service

- [ ] 5.1 Create `lib/Service/AnalyticsService.php` with methods:
  - `recordViewEvent(string $dashboardUuid, int $userId, bool $isOptedOut, bool $isGloballyDisabled): bool` — increments counters; returns true if recorded, false if skipped
    - Skips if opted out or disabled
    - Calls upsertView to increment viewCount
    - Calls UniqueViewerDedup::isNewUniqueViewer() and increments uniqueViewerCount if true
  - `getTopDashboards(string $period, int $limit): array` — queries top dashboards, joins with `oc_mydash_dashboards` to include name
  - `getDashboardDetail(string $dashboardUuid, string $period): array` — queries daily breakdown for one dashboard
  - `getInstanceSummary(string $period): array` — returns totals + top 5
  - `generateCsvExport(string $period): string` — returns CSV text (headers + rows)
  - Helper: `periodToDateRange(string $period): array` — parses "7d|30d|90d" to [startDate, endDate] in UTC
- [ ] 5.2 Test via PHPUnit: all query methods with fixtures, CSV generation correctness, period parsing, empty result handling

## 6. Controller + endpoints

- [ ] 6.1 Add `viewEvent()` method to existing `lib/Controller/DashboardController.php`:
  - Route: `POST /api/dashboards/{uuid}/view-event`
  - Attribute: `#[NoAdminRequired]` (logged-in users only)
  - Extract user ID from `$this->userId`
  - Fetch dashboard to verify existence
  - Check user setting `mydash.user_setting.analytics_optout`
  - Check global setting `mydash.analytics_enabled` (default true)
  - Call `AnalyticsService::recordViewEvent($uuid, $userId, $isOptedOut, $isGloballyDisabled)`
  - Return HTTP 204 (empty response)
- [ ] 6.2 Create `lib/Controller/AnalyticsController.php` (new) extending `OCP\AppFramework\Controller` with methods:
  - `topDashboards()` endpoint:
    - Route: `GET /api/admin/analytics/dashboards/top`
    - Attribute: `#[NoAdminRequired]` with in-body admin check via `IGroupManager::isAdmin($this->userId)`
    - Query params: `period` (7d|30d|90d, default 30d), `limit` (int, default 10)
    - Call `AnalyticsService::getTopDashboards($period, $limit)`
    - Return HTTP 200 with array of objects: `{dashboardUuid, name, viewCount, uniqueViewerCount}`
  - `dashboardDetail()` endpoint:
    - Route: `GET /api/admin/analytics/dashboards/{uuid}`
    - Attribute: `#[NoAdminRequired]` with in-body admin check
    - Path param: `uuid`
    - Query param: `period` (default 30d)
    - Call `AnalyticsService::getDashboardDetail($uuid, $period)`
    - Catch `DashboardNotFound` → HTTP 404
    - Return HTTP 200 with array of daily records: `{viewBucket, viewCount, uniqueViewerCount}`
  - `instanceSummary()` endpoint:
    - Route: `GET /api/admin/analytics/summary`
    - Attribute: `#[NoAdminRequired]` with in-body admin check
    - Query param: `period` (default 30d)
    - Call `AnalyticsService::getInstanceSummary($period)`
    - Return HTTP 200 with object: `{totalViewCount, totalUniqueViewers, dashboardCount, period, top5: [...]}`
  - `exportCsv()` endpoint:
    - Route: `GET /api/admin/analytics/export`
    - Attribute: `#[NoAdminRequired]` with in-body admin check
    - Query param: `period` (default 30d)
    - Call `AnalyticsService::generateCsvExport($period)`
    - Set response headers: `Content-Type: text/csv`, `Content-Disposition: attachment; filename=dashboard-analytics-{CURRENT_DATE}.csv`
    - Return HTTP 200 with CSV text body
- [ ] 6.3 Register routes in `appinfo/routes.php`:
  - `POST /api/dashboards/{uuid}/view-event` → DashboardController::viewEvent
  - `GET /api/admin/analytics/dashboards/top` → AnalyticsController::topDashboards
  - `GET /api/admin/analytics/dashboards/{uuid}` → AnalyticsController::dashboardDetail
  - `GET /api/admin/analytics/summary` → AnalyticsController::instanceSummary
  - `GET /api/admin/analytics/export` → AnalyticsController::exportCsv

## 7. Background job

- [ ] 7.1 Create `lib/BackgroundJob/PurgeViewsJob.php` extending `OCP\BackgroundJob\TimedJob`:
  - Constructor sets `$this->setInterval(86400)` (daily)
  - `protected function run($argument)`:
    - Fetch setting `mydash.analytics_retention_days` (default 365, clamped 30–3650)
    - Compute cutoff date: `CURRENT_DATE - retention_days`
    - Call `DashboardViewMapper::deleteOlderThan($cutoffDate)`
    - Log result: "Purged X rows older than YYYY-MM-DD"
    - Do NOT log user identifiable information
- [ ] 7.2 Register job in `appinfo/app.php` via `\OCP\BackgroundJob\IJobList::add(PurgeViewsJob::class)`

## 8. Configuration defaults

- [ ] 8.1 Define default settings in app config (e.g., via migration or in-code):
  - `mydash.analytics_enabled` → `true`
  - `mydash.analytics_retention_days` → `365`
- [ ] 8.2 Per-user setting `mydash.user_setting.analytics_optout` → default `false` (user opts in by default)
  - Stored in `oc_preferences` with key `mydash.analytics_optout` per user

## 9. Frontend store

- [ ] 9.1 Add action to `src/stores/dashboards.js`:
  - `recordViewEvent(dashboardUuid)` — calls `POST /api/dashboards/{dashboardUuid}/view-event`, handles 204 response silently, logs errors to console (not user-visible)
- [ ] 9.2 Add action to `src/stores/admin.js` (or create if missing):
  - `fetchTopDashboards(period, limit)` — calls `GET /api/admin/analytics/dashboards/top?period=...&limit=...`
  - `fetchDashboardDetail(uuid, period)` — calls `GET /api/admin/analytics/dashboards/{uuid}?period=...`
  - `fetchInstanceSummary(period)` — calls `GET /api/admin/analytics/summary?period=...`
  - `exportAnalytics(period)` — calls `GET /api/admin/analytics/export?period=...`, triggers browser download
- [ ] 9.3 Store state for analytics UI: `topDashboards`, `dashboardDetail`, `instanceSummary`, `loading`, `error`

## 10. Frontend instrumentation

- [ ] 10.1 Update `src/views/DashboardView.vue`:
  - On component mount, call store action `recordViewEvent(dashboardUuid)`
  - Wrap call in debounce with 1-second window per dashboard UUID
  - Debounce instance stored in component data (per component, not global)
  - Check app config for `analytics_enabled` — if false, skip the call entirely
  - Handle network errors silently (log to console, no user-facing error)
- [ ] 10.2 Example code pattern:
  ```javascript
  import { debounce } from 'lodash-es'
  
  mounted() {
    if (!this.analyticsEnabled) return
    this.debouncedRecordView = debounce(() => {
      this.dashboardsStore.recordViewEvent(this.dashboardUuid)
    }, 1000)
    this.debouncedRecordView()
  }
  ```

## 11. Frontend admin UI

- [ ] 11.1 Create `src/views/AdminAnalytics.vue` (new) displaying:
  - "Analytics" header
  - Period selector (7d, 30d, 90d) with default 30d
  - Instance summary card showing: total views, total unique viewers, dashboard count
  - "Top Dashboards" table showing top 10 with columns: name, view count, unique viewers, trend sparkline
  - "Export" button calls `exportAnalytics(period)` to download CSV
  - Loading spinner while data is fetched
  - Error message if query fails
- [ ] 11.2 Create `src/components/AnalyticsChart.vue` (new) displaying 30-day sparkline:
  - Accepts props: `dashboardUuid`, `period`
  - Fetches dashboard detail via store
  - Renders small 30-day trend line chart (using existing charting library, e.g., Chart.js if available)
  - Shows min/max/avg view count in tooltip
- [ ] 11.3 Integrate AdminAnalytics into main admin navigation:
  - Add "Analytics" link to admin sidebar/menu (location TBD by design)
  - Route: `/admin/analytics` or similar

## 12. Configuration UI (optional)

- [ ] 12.1 In existing MyDash admin settings view, add "Analytics Settings" section:
  - Toggle: "Enable view tracking" (sets `mydash.analytics_enabled`)
  - Input: "Retention period (days)" with spinbox, min 30, max 3650 (sets `mydash.analytics_retention_days`)
  - Display current retention value and delete cutoff date
  - "Save" button persists changes
- [ ] 12.2 Per-user setting in user preferences view:
  - Checkbox: "Opt out of dashboard analytics" (toggles `mydash.user_setting.analytics_optout`)
  - Help text: "Your dashboard views will not be counted"

## 13. PHPUnit tests

- [ ] 13.1 `DashboardViewMapperTest`:
  - `upsertView` with increment (INSERT, then UPDATE)
  - `findByDashboardAndBucket` with and without data
  - `findByDashboardInRange` multiple days, empty range
  - `findTopDashboardsInRange` ordering, limit, joins name
  - `findInstanceSummaryInRange` totals
  - `deleteOlderThan` with cutoff date, verify count returned
- [ ] 13.2 `UniqueViewerDedupTest`:
  - `isNewUniqueViewer` detects first view, rejects duplicate same day
  - Salt rotates at date boundary (mock current date)
  - Cache TTL is correct (verify cache has 86400s TTL)
  - Different users not confused
- [ ] 13.3 `AnalyticsServiceTest`:
  - `recordViewEvent` increments view count
  - Skips if opted out (no DB change)
  - Skips if globally disabled
  - Calls UniqueViewerDedup for unique count
  - `getTopDashboards` returns sorted by viewCount desc
  - `getDashboardDetail` returns daily breakdown
  - `getInstanceSummary` returns totals + top 5
  - `generateCsvExport` produces valid CSV with correct headers and data
  - Period parsing ("7d", "30d", "90d")
- [ ] 13.4 `DashboardControllerTest::viewEvent`:
  - Authed user records event → 204
  - Unauthenticated user → 401
  - Nonexistent dashboard → 404
  - Opted-out user → 204 but no DB change
  - Global disable → 204 but no DB change
- [ ] 13.5 `AnalyticsControllerTest`:
  - Non-admin receives 403 on all endpoints
  - `topDashboards` returns sorted array, respects limit and period
  - `dashboardDetail` nonexistent uuid → 404
  - `instanceSummary` returns object with totals and top 5
  - `exportCsv` returns CSV with correct headers and filename
- [ ] 13.6 `PurgeViewsJobTest`:
  - Job runs and deletes rows older than retention cutoff
  - Rows within retention are preserved
  - Retention bounds are enforced (min 30, max 3650)
  - Job is idempotent

## 14. Playwright E2E tests

- [ ] 14.1 User calls `POST /api/dashboards/{uuid}/view-event`, receives 204
- [ ] 14.2 Admin calls `GET /api/admin/analytics/dashboards/top?period=7d`, receives array
- [ ] 14.3 Admin calls `GET /api/admin/analytics/dashboards/{uuid}?period=30d`, receives daily breakdown
- [ ] 14.4 Admin calls `GET /api/admin/analytics/summary?period=30d`, receives totals + top 5
- [ ] 14.5 Admin calls `GET /api/admin/analytics/export?period=30d`, downloads CSV
- [ ] 14.6 Non-admin receives 403 on admin endpoints
- [ ] 14.7 Opted-out user's POST view-event returns 204 with no counter change
- [ ] 14.8 Vue component calls `POST /api/dashboards/{uuid}/view-event` on mount (verify via network log)
- [ ] 14.9 Multi-tab debouncing: open same dashboard in two tabs, verify only ONE request sent

## 15. Quality gates

- [ ] 15.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes
- [ ] 15.2 ESLint + Stylelint clean on all Vue/JS files
- [ ] 15.3 SPDX headers on all new PHP files (inside docblock per SPDX-in-docblock convention)
- [ ] 15.4 `i18n` translation keys for all new UI strings (nl + en):
  - "View Analytics"
  - "Top Dashboards"
  - "Total Views"
  - "Unique Viewers"
  - "Analytics Settings"
  - "Enable view tracking"
  - "Retention period (days)"
  - "Opt out of analytics"
  - "Your dashboard views will not be counted"
  - "Export Analytics"
  - Period labels: "Last 7 days", "Last 30 days", "Last 90 days"
- [ ] 15.5 Update generated OpenAPI spec / Postman collection with 5 new endpoints
- [ ] 15.6 Run all `hydra-gates` locally before opening PR

## 16. Documentation

- [ ] 16.1 Update `README.md` or `docs/ANALYTICS.md` with:
  - Overview of view-tracking feature
  - Privacy model (no user IDs stored, daily salt rotation)
  - Admin configuration (retention, global disable)
  - User opt-out instructions
  - API endpoints reference

