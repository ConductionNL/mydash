# Design — Dashboard View Analytics

## Context

MyDash currently has no way to track which dashboards are being used. This change adds aggregate, privacy-preserving view counts per dashboard (daily buckets) with admin query endpoints and a Vue frontend instrumentation layer. The capability is defined in `dashboard-view-analytics/spec.md` as REQ-ANLT-001 through REQ-ANLT-011.

The source this feature is ported from uses a static hash strategy: `hash('sha256', $userId . $config->getSystemValue('secret', ''))` (see `intravox-source/lib/Service/AnalyticsService.php:288-291`). The Nextcloud instance secret is never rotated, meaning the same user always produces the same hash for all time. The "daily boundary" in the source comes from a `view_date = today()` composite key in an `intravox_uv` table — not from any salt rotation.

This creates a meaningful privacy risk: if the `intravox_uv` table (or its equivalent) were ever leaked, a party who learns the static salt could re-identify which user hash corresponds to which user across years of stored rows. For an analytics feature that explicitly advertises privacy preservation, that is an unacceptable residual risk.

MyDash diverges from the source on this point deliberately. The daily boundary the source achieves through `view_date` is preserved — but the hash is made non-static by rotating the salt each UTC day and keeping no salt history. This means cross-day re-identification from the database alone becomes computationally infeasible.

## Goals / Non-Goals

**Goals:**

- Track aggregate view counts (total views + unique viewer count) per dashboard per UTC day.
- Use a daily-rotating salt so the viewer hash is only valid within a 24-hour window.
- Deduplicate unique viewers via an in-process cache (no per-user-per-event rows persisted to the database).
- Provide admin query endpoints for top dashboards, per-dashboard breakdown, instance summary, and CSV export.
- Support per-user opt-out and global admin disable.
- Auto-purge rows older than a configurable retention period (default 365 days).

**Non-Goals:**

- Cross-day re-identification of users from analytics tables — this MUST NOT be possible from the stored data alone.
- Per-event logging of individual user views (no raw user IDs or hashes stored in the database at any point).
- Real-time analytics or WebSocket push — aggregates are batch-computed daily.
- Unauthenticated view tracking — only authenticated users' view events are counted.
- Analytics for non-dashboard entities (widgets, tiles, admin pages).

## Decisions

### D1: Unique-viewer dedup salt — daily-rotating, NOT static

**Decision**: Compute viewer hash as `sha256(userId || dailySalt)` where `dailySalt` is a 32-byte random value generated at 00:00 UTC and stored in `IConfig` under `mydash.analytics_dailysalt`. When the salt rotates, the previous value is overwritten — no salt history is kept. This means cross-day hashes for the same user are computationally uncorrelated.

**Alternatives considered:**

- **Static instance-secret salt (the source's approach)**: `hash('sha256', $userId . $config->getSystemValue('secret'))`. Rejected for v1 because the static salt means the same user always produces the same hash across all days. A leak of the analytics table combined with knowledge of the salt (e.g., from a separate config leak) would allow re-identification of any user's full view history indefinitely. Analytics tables are low-sensitivity and often included in broader DB exports — the risk of a joint leak is non-trivial.

**Source evidence (what the source does, not what we adopt)**:

- `intravox-source/lib/Service/AnalyticsService.php:288-291` — `hashUserId()` uses static `config.secret` as the salt.
- `intravox-source/lib/Service/AnalyticsService.php:296-306` — `trackUserView()` inserts into `intravox_uv` with `(page_id, user_hash, view_date)`; uniqueness boundary is the `view_date` column, not salt rotation.

**Rationale**: The daily boundary for unique-viewer counting is retained (one count per user per dashboard per UTC day) but the mechanism shifts to salt rotation instead of a stored uv row. Admins viewing "last 7 days" or "last 30 days" aggregates do not need cross-day user correlation — they need accurate daily counts. The cost (no ability to identify if the same physical user appeared on both 2026-05-01 and 2026-05-02) is accepted as a privacy feature, not a limitation.

### D2: Salt rotation mechanism — daily cron, no historical retention

**Decision**: A daily background job (`SaltRotationJob`) generates a fresh 32-byte random salt at 00:00 UTC using `random_bytes(32)` and stores the hex-encoded value in `IConfig` under `mydash.analytics_dailysalt`. The previous salt is overwritten with no backup. The dedup cache TTL (see D3) is set to expire at the next UTC midnight, ensuring the cache and salt lifetimes are aligned.

**Rationale**: Overwriting (not appending) the salt means no historical salt table exists to leak. This is a deliberate design constraint — once a day has passed, its viewer hashes are permanently uncorrelated with user IDs. A single `IConfig` key needs no migration and is readable by any process without coordination.

### D3: Tables — `oc_mydash_dashboard_views` (daily aggregates only, no per-event log)

**Decision**: ONE table with columns `(id PK, dashboardUuid VARCHAR(36), viewBucket DATE, viewCount INT, uniqueViewerCount INT)` and a composite unique index on `(dashboardUuid, viewBucket)`. There is no per-user-per-day event log table. Unique-viewer deduplication is handled exclusively through a Nextcloud `ICache` entry keyed on `(dashboardUuid, viewerHash)` with a TTL expiring at the next UTC midnight.

**Alternatives considered:**

- **Match source: separate `oc_mydash_dashboard_view_log` (or `intravox_uv`-equivalent) table**: Rejected. Keeping per-event rows (even hashed) creates a long-lived table whose rows are individually attributable within a day's salt window. The cache-based approach gives identical dedup semantics with no residual per-user rows in the database.

**Source evidence**:

- `intravox-source/lib/Service/AnalyticsService.php:44-46` — source has both `intravox_uv` (per-event uv log) and `intravox_page_stats` (aggregate). We collapse this to one aggregate table + cache.

**Rationale**: Aggregate rows are the only data needed for every admin query endpoint. Cache-based dedup is cheaper than a DB insert-or-ignore per view event and leaves no hashes at rest. The cache is ephemeral by design — it does not survive process restarts longer than its TTL, which is acceptable because a restart mid-day may cause a small uniqueViewerCount undercount for that day.

### D4: Retention — 365 days for aggregates (admin-tunable, min 30, max 3650)

**Decision**: Aggregate rows with `viewBucket < CURRENT_DATE - retentionDays` are purged by a daily background job (`PurgeViewsJob`). The default is 365 days. Admins override via `mydash.analytics_retention_days`. Values below 30 are clamped to 30; values above 3650 are clamped to 3650.

**Rationale**: 365 days supports year-over-year comparisons ("this month vs. same month last year"), which is the most common admin reporting horizon. The minimum of 30 keeps the admin dashboard charts meaningful. The maximum of 3650 (10 years) covers compliance use cases without permitting unbounded growth by default.

### D5: Opt-out — per-user setting, default off (opted in)

**Decision**: User setting `mydash.user_setting.analytics_optout` (boolean, default `false` — opted in). When `true`, the `POST /api/dashboards/{uuid}/view-event` handler short-circuits immediately with HTTP 204, writing no cache entry and touching no database row.

**Rationale**: Privacy regulation (AVG/GDPR) requires a meaningful opt-out path. Short-circuiting before any hash is computed ensures zero data is generated for opted-out users, not just zero data stored — no transient identifiers are created mid-request.

### D6: Global admin disable — `mydash.analytics_enabled` (default `true`)

**Decision**: When an admin sets `mydash.analytics_enabled = false`, every `POST /view-event` returns HTTP 204 with a complete no-op. The frontend checks a config endpoint on mount and suppresses the request entirely when analytics is disabled, avoiding unnecessary HTTP round-trips.

**Rationale**: Organisations with stricter internal policies may need to disable all view tracking. A single `IConfig` key is simpler than per-scope or per-group toggles and is effective immediately on the next request without cache invalidation.

### D7: Front-end debouncing — once per dashboard mount, 1-second window

**Decision**: `DashboardView.vue` calls `POST /api/dashboards/{uuid}/view-event` once on `mounted()`, debounced with a 1-second window keyed on `dashboardUuid`. A reload triggers a new event (debounce is per-mount, not per-session). Multi-tab opens of different dashboard UUIDs are NOT debounced against each other.

**Rationale**: The 1-second debounce eliminates double-counts from near-simultaneous tab opens of the same dashboard while remaining invisible to normal single-tab navigation. Keying on UUID ensures concurrent tabs of different dashboards each generate their own event, which is the correct behaviour.

## Spec Changes Implied

- **REQ-ANLT-003 (unique-viewer dedup)**: Pin the daily-rotating-salt approach with no historical retention. Add a NOTE documenting deliberate divergence from the source's static-secret approach and the privacy rationale.
- **REQ-ANLT-002 (view-event endpoint)**: Pin the per-`(dashboardUuid, viewerHash)` cache key for dedup; specify cache TTL = seconds until next UTC midnight.
- **REQ-ANLT-009 (retention)**: Confirm 365-day default; document the 30/3650 clamp bounds explicitly.
- **New scenario under REQ-ANLT-003**: Salt rotation — verify that hashes computed with yesterday's salt cannot be reproduced after rotation (no history kept).

## Open Follow-ups

- Whether to expose an admin endpoint returning the current daily salt expiry time for monitoring purposes. Current position: no — publishing salt metadata is marginally useful but creates a target for timing-based attacks. Trivial to add if explicitly requested.
- Whether bot and crawler view events should be filtered by User-Agent. User-Agent sniffing is unreliable and easily spoofed; defer until customers report inflated counts from known crawlers.
- Whether the per-user opt-out setting should surface in the standard Nextcloud Personal Settings UI. Yes — this is sibling-capability work (`user-privacy-settings`) and not in scope for this change.
- Cache invalidation on process restart mid-day: accepted as a known minor undercount. Document in the capability README so operators understand the trade-off before tuning OPcache aggressiveness.
