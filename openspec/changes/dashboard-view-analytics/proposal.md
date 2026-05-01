# Dashboard View Analytics

## Why

MyDash admins today have no way to understand which dashboards are actually being used. Without visibility into dashboard engagement, organizations cannot optimize their intranet content, identify stale dashboards, or validate that their information architecture is effective. Admins need aggregate, privacy-preserving view counts per dashboard (daily buckets) to understand usage patterns, surface top dashboards, and identify low-engagement content for improvement or retirement.

## What Changes

- Extend `oc_mydash_dashboards` with a reference to daily view-count rows (one-to-many via composite key).
- Create a new table `oc_mydash_dashboard_views` with columns: `id` (PK), `dashboardUuid VARCHAR(36)`, `viewBucket DATE`, `viewCount INT DEFAULT 0`, `uniqueViewerCount INT DEFAULT 0`, with composite unique index `(dashboardUuid, viewBucket)` — ONE row per (dashboard, day).
- Expose `POST /api/dashboards/{uuid}/view-event` — called on dashboard load; increments daily counters (authed users only). Body: `{}`. Returns 204.
- Unique-viewer counting is privacy-preserving: hash userId with a daily-rotating salt, store in cache, do NOT store raw user IDs. Deduplication via cache lookup.
- Per-user opt-out: setting `mydash.user_setting.analytics_optout` (default `false`). When set, that user's view events are no-ops.
- Global admin disable: setting `mydash.analytics_enabled` (default `true`). When `false`, all view events are no-ops.
- Admin query endpoints:
  - `GET /api/admin/analytics/dashboards/top?period=7d|30d|90d&limit=10` — top dashboards by view count
  - `GET /api/admin/analytics/dashboards/{uuid}?period=7d|30d|90d` — daily breakdown for one dashboard
  - `GET /api/admin/analytics/summary?period=7d|30d|90d` — instance-wide totals + top-5 list
  - `GET /api/admin/analytics/export?period=30d` — CSV download (admin-only)
- Daily background job purges rows older than 365 days (configurable via `mydash.analytics_retention_days`, min 30, max 3650).
- Frontend instrumentation: Vue dashboard render calls `POST /api/dashboards/{uuid}/view-event` once on mount, debounced to prevent multi-tab inflation.
- Admin UI: new "Analytics" tab in MyDash admin section showing top dashboards, 30-day trends chart.

## Capabilities

### New Capability

- `dashboard-view-analytics`: adds REQ-ANLT-001..REQ-ANLT-011 for schema, view-event tracking, privacy, per-user opt-out, global disable, admin query endpoints, retention purge, CSV export, frontend instrumentation, and admin UI.

## Impact

**Affected code:**

- `lib/Db/DashboardView.php` (new) — entity for `oc_mydash_dashboard_views` rows
- `lib/Db/DashboardViewMapper.php` (new) — mapper for view-count rows, find-by-bucket, upsert, purge
- `lib/Migration/VersionXXXXDate2026...AddDashboardViewsTable.php` (new) — schema migration creating table + composite index
- `lib/Service/AnalyticsService.php` (new) — aggregation queries (top, detail, summary), CSV generation
- `lib/Controller/AnalyticsController.php` (new) — admin query endpoints
- `lib/Controller/DashboardController.php` — add `viewEvent()` POST endpoint
- `lib/Service/DashboardService.php` — add call to AnalyticsService from view-event handler
- `appinfo/routes.php` — register 5 new routes (1 public authed, 4 admin)
- `lib/BackgroundJob/PurgeViewsJob.php` (new) — daily retention purge
- `appinfo/app.php` — register background job
- `src/stores/dashboards.js` — add view-event fetch call on mount
- `src/views/DashboardView.vue` — call store action on component mount (debounced)
- `src/views/AdminAnalytics.vue` (new) — admin analytics UI with charts + tables
- `src/components/AnalyticsChart.vue` (new) — 30-day sparkline chart

**Affected APIs:**

- 5 new routes (1 authed public, 4 admin-only)
- No existing routes changed

**Dependencies:**

- No new composer or npm dependencies

**Migration:**

- Zero-impact: new table. No changes to existing dashboard rows.
- Background job runs daily (after app upgrade, Nextcloud scheduler picks it up).
- Default settings enable analytics globally; admins can disable per-user or instance-wide.

