# Design — Dashboard View Analytics

## Context

MyDash provides dashboards as a way for users to organize and visualize data, but administrators have no visibility into which dashboards are being actively used. Today, usage metrics are absent, making it impossible to identify abandoned dashboards, plan feature improvements, or justify dashboard maintenance efforts. Analytics infrastructure must be added, but with strict privacy controls: no per-user event logs, no session tracking, no cross-day re-identification.

## Goals / Non-Goals

**Goals:**

- Enable administrators to see which dashboards are most popular and track usage over time.
- Provide daily-level granularity (e.g., 10 views today, 8 views yesterday) for trend analysis.
- Prevent per-user re-identification: same user on different days produces uncorrelated hashes.
- Allow users to opt out of tracking entirely (per-user preference) and admins to disable tracking globally.
- Prevent unbounded database growth via automated purge jobs with configurable retention.
- Expose admin-only query endpoints for top dashboards, per-dashboard breakdowns, instance summary, and CSV export.
- Instrument the frontend to call view-event endpoint on dashboard load with multi-tab debouncing.

**Non-Goals:**

- Real-time analytics dashboards (day-level buckets sufficient for now).
- Cross-day per-user analysis (privacy trade-off — daily salt rotation intentionally prevents this).
- Session tracking or page-view funnel analysis.
- Tracking which dashboard elements (widgets) are interacted with — only view events on dashboard load.
- Role-based analytics filtering (all dashboards visible to any admin).
- A/B testing or cohort analysis (aggregates only, no user identity retained).

## Decisions

### D1: Daily-rotating salt for deduplication, not static instance secret

**Decision**: Generate a new 32-byte random salt at UTC midnight via `SaltRotationJob`, store it in `IConfig` under `mydash.analytics_dailysalt`, and **overwrite** (not append) on each rotation. Unique-viewer hash = SHA256(userId || dailySalt).

**Alternatives considered:**

- Static instance secret: SHA256(userId || instanceSecret). Simple, but allows cross-day re-identification if analytics table + config leak together — the same user produces an identical hash forever.
- Salted hash in database: store hash + salt in `oc_mydash_dashboard_views`. Requires database space and reveals salt to anyone with DB access; cross-day correlation still possible.
- Client-side token per session: user's browser generates a random token on load. Rejected — doesn't survive page reload, complicates dedup, and multi-tab still double-counts unless tokens are synchronized (same complexity as server-side salt).

**Rationale**: Daily rotation prevents cross-day user correlation from the analytics table alone. Overwriting (not appending) history means an attacker who later obtains the current salt cannot retroactively reconstruct past user hashes. Privacy gain justifies the cost of losing cross-day per-user analysis (which was never a goal).

### D2: Cache-only deduplication, not database-persisted viewer identifiers

**Decision**: Unique-viewer hash is stored in Nextcloud `ICache` with TTL = seconds until next UTC midnight. Database stores only aggregate counters (viewCount, uniqueViewerCount). No per-user or per-hash rows are persisted.

**Alternatives considered:**

- Hash + count in database: add `(dashboardUuid, viewerHash, viewBucket)` rows. Rejected — even hashed PII in the database is a larger privacy surface than temporary cache entries, and the hash is already salted so multi-day re-id is prevented; no benefit to storing it.
- In-memory map per process: store hashes in PHP process memory. Rejected — multi-process PHP (FPM, etc.) means each worker maintains its own map, causing double-counting and missed dedup across processes.

**Rationale**: Cache entries with midnight-aligned TTL automatically expire when the salt rotates, keeping cache and salt lifecycles in sync. No persistent hash storage minimizes the privacy surface and simplifies compliance (no "delete this user's analytics identifiers" logic).

### D3: Admin-only endpoints return aggregates, not event logs

**Decision**: All query endpoints (`/api/admin/analytics/*`) return summed/averaged view counts per dashboard and date bucket. No per-user event logs, no viewer identifiers, no drill-down to individual sessions.

**Alternatives considered:**

- Expose raw event logs to admins: `/api/admin/analytics/events?userId=...`. Rejected — enables per-user tracking queries and contradicts the privacy design (admins learn which users viewed which dashboards).
- Let admins configure who can see analytics. Rejected — any admin can view all dashboards' data today (no field-level RBAC). Keep analytics scope aligned to existing admin capabilities.

**Rationale**: Admins care about usage trends (top dashboards, daily breakdowns, instance totals), not per-user behavior. Aggregate-only endpoints align incentives: admins get what they need, users retain privacy.

### D4: Per-user and per-admin settings are independent gates

**Decision**: Two independent config settings control tracking:
- `mydash.analytics_enabled` (global, default true): when false, all view events are no-ops
- `mydash.analytics_optout` (per-user, default false): when true, this user's view events are no-ops

**Alternatives considered:**

- Single global setting only: rejected — users have no opt-out and cannot disable tracking for privacy-sensitive use cases (e.g., mental health dashboards).
- Per-user only, no global: rejected — admins cannot comply with regional policies (GDPR) without disabling via user-level mass action.
- Whitelist approach (analytics-optin): rejected — opt-in has lower adoption than opt-out and contradicts the goal of admin visibility.

**Rationale**: Opt-out is the standard in analytics; making it independent of global disable allows both user choice and admin control. Defaults align with adoption goals: enabled by default (admins get data) but users can opt out.

### D5: Daily aggregate table, not per-event rows

**Decision**: `oc_mydash_dashboard_views` stores one row per (dashboardUuid, viewBucket) pair, with viewCount and uniqueViewerCount incremented in place. No per-event rows.

**Alternatives considered:**

- Per-event table: insert a row on every view. Rejected — on a 50-dashboard instance with 100 daily views, this creates 5000 rows/day = 1.8M rows/year. Unbounded growth; purge job must scan millions of rows. Daily aggregates are 50 rows/day = 18k rows/year — 100× smaller.
- Time-series database (Grafana, InfluxDB): rejected — adds operational complexity and external dependency. Nextcloud's standard DB is sufficient for day-level aggregates.

**Rationale**: Daily buckets provide the granularity admins need (trends, comparisons) while keeping database size manageable. Sub-day analysis is out of scope.

### D6: Retention default 365 days, clamped to [30, 3650]

**Decision**: Default `mydash.analytics_retention_days = 365`. Admin can change it via settings. Minimum enforced is 30 days (ensures meaningful trend windows), maximum is 3650 days (prevents accidental unbounded retention).

**Alternatives considered:**

- No retention (keep forever): rejected — unbounded DB growth violates operational stability.
- Short default (30 days): rejected — admins want year-over-year comparisons (365 days is standard).
- Admin can set any value: rejected — without bounds, an admin might set 0 (deletes immediately) or 999,999 days (functionally unbounded). Explicit clamping prevents accidents.

**Rationale**: 365 days is a calendar year, supporting standard YoY analysis. Bounds prevent misconfiguration while allowing flexibility between "recent trends" (30d) and "decade scale" (3650d).

### D7: View events trigger on component mount, not on every page interaction

**Decision**: `DashboardView.vue` calls `POST /api/dashboards/{uuid}/view-event` exactly once in the `mounted()` hook. Debouncing is per-dashboard per-tab-session (not cross-tab) with a 1-second window.

**Alternatives considered:**

- Every widget interaction: rejected — each widget drag/resize/config change is not a "view" event.
- Intersection Observer (scroll into viewport): rejected — dashboards are typically small enough to fit on one screen; scroll tracking adds complexity.
- Client-side timer (report view every 5 min if still open): rejected — over-counts idle users and requires persistent state across navigation.

**Rationale**: Mount is the moment the user loads the dashboard; that's the view event we care about. Debouncing per-uuid (not globally) allows multiple concurrent dashboard views but prevents multi-tab inflation of the same dashboard. 1-second window is long enough for simultaneous tab loads, short enough to not affect realistic sequential views.

### D8: Frontend passes empty request body, backend validates dashboard exists

**Decision**: Vue component sends `POST /api/dashboards/{uuid}/view-event` with empty JSON body `{}`. Backend validates the dashboard exists and increments counters or returns 404.

**Alternatives considered:**

- Request body includes dashboard name or metadata: rejected — frontend already has the UUID; duplicate data adds no value.
- Backend tracks view events without existence check: rejected — allows infinite counter increments on fake UUIDs, inflating metrics.

**Rationale**: Empty body keeps the request lightweight; the UUID in the URL is sufficient. Existence check prevents metric pollution.

## Risks / Trade-offs

- **Risk:** Daily salt rotation means cross-day per-user analysis is impossible. → **Mitigation:** This is intentional (privacy trade-off). If future requirements demand per-user retention time, a separate privacy-preserving data store (differential privacy, aggregates only) would be needed, not a salt history.
- **Risk:** Cache entries are non-persistent — if Nextcloud cache is cleared, dedup resets and uniqueViewerCount may double-count users within the same day. → **Mitigation:** Cache is expected to persist within a day. If cache is cleared mid-day, some within-day dedup is lost, but it's an edge case; document in runbook. Acceptable trade-off vs. persisting hashes to the DB.
- **Risk:** Admin endpoints return exact counts; an attacker with multiple queries can estimate user activity (timing-based inference). → **Mitigation:** Aggregates already leak some information; full prevention requires differential privacy (out of scope). Current design is sufficient for internal admin use.
- **Risk:** `PurgeViewsJob` might fail to run, allowing data to accumulate beyond retention. → **Mitigation:** Job is registered with Nextcloud scheduler (same as all MyDash background jobs); monitoring via standard Nextcloud admin logs. If job fails, admins see the error; no automatic retry (manual intervention required to investigate).
- **Trade-off:** No real-time dashboard — data is bucketed daily. Same-day trending requires comparing today's partial count to yesterday's final count. Acceptable; real-time analytics are out of scope.
- **Trade-off:** All admins see all dashboards' analytics. No per-dashboard visibility control. Acceptable; aligns with existing MyDash admin model (no dashboard-level RBAC).

## Migration Plan

1. **Phase 1: Database and config** — Create migration `AddDashboardViewsTable`, register settings keys (`analytics_enabled`, `analytics_optout`, `analytics_retention_days`), register background jobs (`SaltRotationJob`, `PurgeViewsJob`).
2. **Phase 2: Backend services** — Implement `AnalyticsService` (record event, hash user, check dedup cache, increment counters), `SaltRotationService`, `PurgeService`. Implement `AdminAnalyticsController` endpoints (top, per-dashboard, summary, export).
3. **Phase 3: View-event endpoint** — Add `DashboardViewController` endpoint for `POST /api/dashboards/{uuid}/view-event`. Validate dashboard exists, call `AnalyticsService::recordViewEvent()`.
4. **Phase 4: Frontend instrumentation** — Modify `DashboardView.vue` to import and call view-event endpoint in `mounted()`, with per-uuid debouncing.
5. **Phase 5: Integration and tests** — Add Vitest and PHPUnit test coverage for all services and endpoints. Add Playwright end-to-end test: load dashboard, verify counter increments, verify dedup, verify CSV export.
6. **Phase 6: Documentation** — Add admin docs (settings, retention, CSV interpretation) and changelog entry.

**Rollback**: Disable `analytics_enabled` globally (setting to false) and remove the job registrations. No migration rollback needed (old columns remain but unused).

## Open Questions

- Should admins be able to delete/reset analytics for a specific dashboard? Current decision: no API for this; requires direct DB manipulation if needed. Revisit if requested by early adopters.
- Should the purge job log the exact number of rows deleted per dashboard? Current decision: log total count only (e.g., "Purged 5000 rows older than 2025-05-01"). Revisit if admins need per-dashboard breakdown for troubleshooting.
- Should the salt rotation be adjustable (e.g., hourly vs. daily)? Current decision: daily at UTC midnight (Nextcloud standard). Revisit only if privacy requirements change.
