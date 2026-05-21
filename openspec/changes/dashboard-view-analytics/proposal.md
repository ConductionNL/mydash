# Dashboard View Analytics

MyDash administrators need to understand which dashboards are actually being used by their users. This change introduces privacy-preserving view-count analytics: when a user loads a dashboard, the system increments a daily aggregate counter. View deduplication uses a per-dashboard-per-user hash stored temporarily in Nextcloud's cache layer (no per-view rows persisted to the database). Admins query top dashboards, per-dashboard daily breakdowns, instance-wide summaries, and CSV exports through admin-only endpoints. A daily background job purges analytics rows older than the configured retention window (default 365 days, clamped to [30, 3650]).

## Affected code units

- **Database schema** — new migration creating `oc_mydash_dashboard_views` table with (dashboardUuid, viewBucket) composite unique index
- **Services** — `AnalyticsService` to record view events, compute unique-viewer hashes, increment counters
- **Jobs** — `SaltRotationJob` runs daily at UTC midnight, regenerates salt and overwrites config; `PurgeViewsJob` runs daily and deletes old rows
- **Controllers** — new `AdminAnalyticsController` with endpoints for top dashboards, per-dashboard breakdown, instance summary, CSV export
- **Vue components** — `DashboardView.vue` instrumented to call view-event endpoint on mount with debouncing per dashboard
- **Configuration** — settings gates: `mydash.analytics_enabled` (global), `mydash.analytics_optout` (per-user), `mydash.analytics_retention_days` (admin-configurable)
- **Cache** — leverages Nextcloud `ICache` for same-day deduplication keys

## Why a delta

Analytics are foundational for adoption monitoring but today are absent from MyDash. Without view counts, administrators have no way to understand which dashboards are in active use vs. abandoned. Adding analytics requires privacy-preserving deduplication to prevent re-identification across days, explicit user opt-out so privacy-sensitive users can disable tracking, and a retention policy to prevent unbounded database growth.

## Approach

- **Privacy by design** — unique-viewer dedup uses a daily-rotating salt (32 bytes, regenerated at UTC midnight) so viewer hashes cannot be correlated across days. Cache entries expire when the salt rotates, and no salt history is retained.
- **Admin-only query endpoints** — endpoints return aggregated view counts (not per-user event logs) so privacy is enforced at the API layer.
- **Settings gates** — two independent settings control tracking: global `analytics_enabled` and per-user `analytics_optout`. When either is off, no data is recorded.
- **Background jobs** — `SaltRotationJob` and `PurgeViewsJob` run daily to rotate the salt and clean up old data, with logging but no PII in logs.
- **Frontend instrumentation** — `DashboardView.vue` calls the view-event endpoint on mount, debounced per-dashboard to prevent multi-tab inflation.

## Capabilities

**New Capabilities:**

- `dashboard-analytics` (new) — tracks view events, deduplicates viewers, and exposes admin queries

## Notes

- Salt rotation is deliberate: a static instance secret would allow cross-day re-identification if the analytics table and config leaked together. Daily rotation prevents this at the cost of disabling cross-day per-user analysis (intentional trade-off for privacy).
- Unique-viewer counts use cache-stored hashes, not database-persisted values; only aggregate counters are persisted.
- CSV export is admin-only to prevent summary statistics from being guessed via timing attacks on aggregates.
