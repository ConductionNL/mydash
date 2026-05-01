---
status: draft
---

# Dashboard View Analytics

## ADDED Requirements

### Requirement: REQ-ANLT-001 Dashboard Views Table Schema

The system MUST track dashboard view events in a dedicated relational table with daily aggregation buckets, privacy-preserving counters, and composite unique indexes.

#### Scenario: Create views table with correct schema

- GIVEN the system has no `oc_mydash_dashboard_views` table
- WHEN the schema migration runs
- THEN the system MUST create `oc_mydash_dashboard_views` with columns:
  - `id` (INT AUTO_INCREMENT, PRIMARY KEY)
  - `dashboardUuid` (VARCHAR 36, NOT NULL, foreign key reference to `oc_mydash_dashboards.uuid`)
  - `viewBucket` (DATE, NOT NULL — calendar date in UTC)
  - `viewCount` (INT DEFAULT 0, NOT NULL)
  - `uniqueViewerCount` (INT DEFAULT 0, NOT NULL)
- AND the system MUST create a composite unique index on `(dashboardUuid, viewBucket)`
- AND the system MUST create an index on `(viewBucket)` for efficient date-range queries

#### Scenario: One row per dashboard per day

- GIVEN a dashboard exists with UUID "uuid-123"
- WHEN a view event is recorded for "uuid-123" on 2026-05-01
- AND another view event is recorded for "uuid-123" on 2026-05-01
- THEN the system MUST maintain only ONE row with `(dashboardUuid = 'uuid-123', viewBucket = '2026-05-01')`
- AND subsequent view events on the same date MUST increment the counters in that single row (no new rows created)

#### Scenario: Two different days create separate rows

- GIVEN a dashboard "uuid-456" exists
- WHEN a view event is recorded on 2026-05-01
- AND another view event is recorded on 2026-05-02
- THEN the system MUST maintain TWO separate rows:
  - Row 1: `(dashboardUuid = 'uuid-456', viewBucket = '2026-05-01', viewCount = 1, uniqueViewerCount = X)`
  - Row 2: `(dashboardUuid = 'uuid-456', viewBucket = '2026-05-02', viewCount = 1, uniqueViewerCount = Y)`

#### Scenario: Schema supports multiple databases

- GIVEN the Nextcloud instance may use SQLite, MySQL, or PostgreSQL
- WHEN the migration runs on any of these databases
- THEN the table MUST be created with identical semantics
- AND the composite unique index MUST enforce uniqueness on all three backends

### Requirement: REQ-ANLT-002 View Event Endpoint

The system MUST expose an authenticated endpoint that records a view event when a user loads a dashboard.

#### Scenario: Authenticated user records a view event

- GIVEN a logged-in user "alice" loads a dashboard with UUID "uuid-101"
- WHEN she sends `POST /api/dashboards/uuid-101/view-event` with body `{}`
- THEN the system MUST return HTTP 204 (No Content)
- AND the system MUST increment the view counter in the `oc_mydash_dashboard_views` row for today
- AND no response body is returned

#### Scenario: Unauthenticated user is rejected

- GIVEN an unauthenticated request (no session or bearer token)
- WHEN the request sends `POST /api/dashboards/uuid-101/view-event`
- THEN the system MUST return HTTP 401 (Unauthorized)
- AND no view count is recorded

#### Scenario: Invalid dashboard UUID returns 404

- GIVEN no dashboard exists with UUID "uuid-nonexistent"
- WHEN an authed user sends `POST /api/dashboards/uuid-nonexistent/view-event`
- THEN the system MUST return HTTP 404 (Not Found)
- AND no row is created in `oc_mydash_dashboard_views`

#### Scenario: Empty request body is valid

- GIVEN a valid dashboard UUID "uuid-102"
- WHEN a user sends `POST /api/dashboards/uuid-102/view-event` with `Content-Type: application/json` and body `{}`
- THEN HTTP 204 MUST be returned
- AND the view counter MUST be incremented

### Requirement: REQ-ANLT-003 Unique Viewer Deduplication with Daily-Rotating Salt

The system MUST count unique viewers per day using privacy-preserving hashing with a salt that rotates daily, preventing cross-day user correlation.

#### Scenario: Same user on same day increments viewCount but not uniqueViewerCount twice

- GIVEN user "alice" (userId: 1) loads a dashboard "uuid-103" at 10:00 AM on 2026-05-01
- AND at 10:15 AM the same user loads the same dashboard again
- WHEN both view events are recorded
- THEN the system MUST have:
  - `viewCount = 2` (both events counted)
  - `uniqueViewerCount = 1` (alice counted once per day)

#### Scenario: Daily salt rotation prevents user re-identification across days

- GIVEN user "bob" (userId: 2) loads a dashboard on 2026-05-01 at 11:00 PM
- AND loads the same dashboard again on 2026-05-02 at 12:00 AM (next day)
- WHEN the system computes unique-viewer hashes:
  - On 2026-05-01, hash = SHA256("userId=2" + "salt-2026-05-01")
  - On 2026-05-02, hash = SHA256("userId=2" + "salt-2026-05-02")
- THEN the two hashes MUST be different
- AND the system MUST NOT be able to correlate bob's viewing across days
- AND `uniqueViewerCount` for 2026-05-01 is 1, and for 2026-05-02 is 1 (independent counts)
- NOTE: Salt rotates at UTC midnight; implementations MUST use UTC date for bucket, not local time

#### Scenario: Different users on same day increment uniqueViewerCount separately

- GIVEN user "alice" (userId: 1) loads dashboard "uuid-104" on 2026-05-01
- AND user "charlie" (userId: 3) loads the same dashboard on 2026-05-01
- WHEN view events are recorded
- THEN `uniqueViewerCount = 2` for (dashboardUuid='uuid-104', viewBucket='2026-05-01')

#### Scenario: Hash stored in cache, not in database

- GIVEN user "diana" (userId: 4) posts a view event for dashboard "uuid-105" on 2026-05-01
- WHEN the system increments uniqueViewerCount
- THEN the system MUST:
  - Compute hash = SHA256("userId=4" + "salt-2026-05-01")
  - Store hash in Nextcloud ICache with TTL = 86400 seconds (24 hours, until next UTC midnight)
  - NOT store the raw userId or hash in `oc_mydash_dashboard_views`
  - Increment uniqueViewerCount only if hash is NOT already in cache

### Requirement: REQ-ANLT-004 Per-User Analytics Opt-Out

The system MUST allow individual users to disable their participation in view-count tracking.

#### Scenario: User opts out; their events are ignored

- GIVEN user "eve" (userId: 5) sets their preference `mydash.user_setting.analytics_optout = true`
- WHEN she loads a dashboard "uuid-106" and posts `POST /api/dashboards/uuid-106/view-event`
- THEN the system MUST return HTTP 204
- AND the system MUST NOT increment any counters in `oc_mydash_dashboard_views`
- AND the system MUST NOT add her hash to the cache

#### Scenario: User opts back in; events resume

- GIVEN user "eve" previously had `analytics_optout = true`
- AND she changes it to `analytics_optout = false` (or deletes the setting)
- WHEN she loads dashboard "uuid-107" and posts a view event
- THEN the system MUST increment counters as normal

#### Scenario: Opt-out is per-user, not global

- GIVEN user "eve" has opted out
- AND user "frank" (userId: 6) has NOT opted out
- WHEN both load the same dashboard "uuid-108" on 2026-05-01
- THEN the system MUST:
  - Ignore eve's event entirely (no counter change)
  - Record frank's event normally (viewCount += 1, uniqueViewerCount += 1 if first time today)
  - Result: `viewCount = 1, uniqueViewerCount = 1`

### Requirement: REQ-ANLT-005 Global Admin Disable

The system MUST provide an admin-configurable setting to disable all view-event recording instance-wide.

#### Scenario: Global disable turns off all tracking

- GIVEN the admin sets `mydash.analytics_enabled = false`
- WHEN any user sends `POST /api/dashboards/{uuid}/view-event`
- THEN the system MUST return HTTP 204
- AND the system MUST NOT increment any counters
- AND the system MUST NOT add hashes to the cache

#### Scenario: Global re-enable resumes tracking

- GIVEN analytics were previously disabled
- AND the admin sets `mydash.analytics_enabled = true`
- WHEN a user posts a view event
- THEN tracking MUST resume as normal

#### Scenario: Default is enabled

- GIVEN a fresh MyDash installation with no explicit setting
- WHEN a user posts a view event
- THEN the system MUST treat `mydash.analytics_enabled` as `true` (default)
- AND tracking MUST proceed normally

### Requirement: REQ-ANLT-006 Top Dashboards Query Endpoint

The system MUST expose an admin-only endpoint that returns the most-viewed dashboards in a configurable date range.

#### Scenario: List top 10 dashboards by view count (7-day period)

- GIVEN 5 dashboards exist with cumulative 7-day view counts: 100, 50, 30, 20, 10
- WHEN an admin sends `GET /api/admin/analytics/dashboards/top?period=7d&limit=10`
- THEN the system MUST return HTTP 200 with an array of dashboard objects
- AND the array MUST be sorted by `viewCount` descending: [100, 50, 30, 20, 10]
- AND each object MUST include: `dashboardUuid`, `name` (from `oc_mydash_dashboards`), `viewCount` (sum of 7 days), `uniqueViewerCount` (sum of 7 days)
- AND the array MUST contain exactly 5 objects (fewer than the requested limit)

#### Scenario: Limit parameter is respected

- GIVEN 20 dashboards exist
- WHEN an admin sends `GET /api/admin/analytics/dashboards/top?period=30d&limit=5`
- THEN the system MUST return exactly 5 dashboards (the top 5)

#### Scenario: Period parameter filters date range

- GIVEN dashboards have view rows for dates: 2026-04-01, 2026-04-15, 2026-05-01
- WHEN an admin sends `GET /api/admin/analytics/dashboards/top?period=7d&limit=10`
  - (period=7d means last 7 days, which is 2026-04-24 to 2026-05-01, inclusive)
- THEN the system MUST sum only rows where `viewBucket >= '2026-04-24'`
- AND rows from 2026-04-01 and 2026-04-15 MUST NOT be included

#### Scenario: Non-admin receives 403

- GIVEN a logged-in user "grace" (userId: 7) who is NOT an admin
- WHEN she sends `GET /api/admin/analytics/dashboards/top?period=7d`
- THEN the system MUST return HTTP 403 (Forbidden)

#### Scenario: Valid periods are 7d, 30d, 90d

- GIVEN an admin requests with `?period=7d`, `?period=30d`, or `?period=90d`
- THEN the system MUST accept and calculate the correct date range
- NOTE: Period calculation is inclusive of the end date (today, UTC) and exclusive of dates before start

### Requirement: REQ-ANLT-007 Per-Dashboard Analytics Query

The system MUST expose an admin-only endpoint that returns a daily breakdown of view counts for a specific dashboard.

#### Scenario: Daily breakdown for a dashboard (30-day period)

- GIVEN dashboard "uuid-109" has view rows for 2026-04-01 through 2026-05-01
- WHEN an admin sends `GET /api/admin/analytics/dashboards/uuid-109?period=30d`
- THEN the system MUST return HTTP 200 with an array of daily records:
  ```json
  [
    { "viewBucket": "2026-04-25", "viewCount": 5, "uniqueViewerCount": 3 },
    { "viewBucket": "2026-04-26", "viewCount": 8, "uniqueViewerCount": 4 },
    ...
    { "viewBucket": "2026-05-01", "viewCount": 12, "uniqueViewerCount": 6 }
  ]
  ```
- AND only rows where `viewBucket >= CURRENT_DATE - 30 days` MUST be included
- AND the array MUST be sorted by `viewBucket` ascending (oldest first)

#### Scenario: Missing days are omitted

- GIVEN a dashboard has view rows for 2026-04-20, 2026-04-22, 2026-04-25 (days 21 and 23-24 missing)
- WHEN an admin queries with `period=7d`
- THEN the response MUST include only the 3 dates that have rows
- AND the system MUST NOT fabricate rows with `viewCount = 0` for missing dates

#### Scenario: Non-existent dashboard returns 404

- GIVEN no dashboard with UUID "uuid-nonexistent"
- WHEN an admin sends `GET /api/admin/analytics/dashboards/uuid-nonexistent?period=7d`
- THEN the system MUST return HTTP 404

#### Scenario: Non-admin receives 403

- GIVEN a logged-in non-admin user
- WHEN they send `GET /api/admin/analytics/dashboards/uuid-109?period=7d`
- THEN the system MUST return HTTP 403 (Forbidden)

### Requirement: REQ-ANLT-008 Instance Summary Endpoint

The system MUST expose an admin-only endpoint that returns aggregate statistics across the entire instance, including totals and top-5 dashboards.

#### Scenario: Summary returns instance-wide totals and top-5

- GIVEN the instance has 50 dashboards with a cumulative 30-day view count of 5000 and unique viewers 1200
- WHEN an admin sends `GET /api/admin/analytics/summary?period=30d`
- THEN the system MUST return HTTP 200 with a JSON object:
  ```json
  {
    "totalViewCount": 5000,
    "totalUniqueViewers": 1200,
    "dashboardCount": 50,
    "period": "30d",
    "top5": [
      { "dashboardUuid": "uuid-A", "name": "...", "viewCount": 500, "uniqueViewerCount": 200 },
      { "dashboardUuid": "uuid-B", "name": "...", "viewCount": 450, "uniqueViewerCount": 190 },
      ...
    ]
  }
  ```
- AND `top5` MUST be sorted by `viewCount` descending

#### Scenario: Period parameter changes calculation

- GIVEN the instance totals are:
  - 7d: 500 views, 200 unique
  - 30d: 2000 views, 600 unique
- WHEN an admin sends `GET /api/admin/analytics/summary?period=7d`
- THEN the response MUST include `totalViewCount: 500, totalUniqueViewers: 200`
- AND when they send `GET /api/admin/analytics/summary?period=30d`
- THEN the response MUST include `totalViewCount: 2000, totalUniqueViewers: 600`

#### Scenario: Non-admin receives 403

- GIVEN a logged-in non-admin user
- WHEN they send `GET /api/admin/analytics/summary?period=7d`
- THEN the system MUST return HTTP 403 (Forbidden)

### Requirement: REQ-ANLT-009 Analytics Data Retention and Purge

The system MUST automatically purge view-count rows older than a configurable retention period to prevent unbounded database growth.

#### Scenario: Default retention is 365 days

- GIVEN a fresh installation with no explicit retention setting
- WHEN the daily purge job runs
- THEN rows with `viewBucket < CURRENT_DATE - 365 days` MUST be deleted
- AND rows within the last 365 days MUST be preserved

#### Scenario: Admin can configure retention period

- GIVEN an admin sets `mydash.analytics_retention_days = 90`
- WHEN the purge job runs
- THEN only rows with `viewBucket < CURRENT_DATE - 90 days` MUST be deleted
- AND the new retention becomes effective on the next job run

#### Scenario: Retention minimum and maximum bounds

- GIVEN the admin attempts to set `mydash.analytics_retention_days = 10` (below minimum 30)
- THEN the system MUST either:
  - Reject the setting change with an error message, OR
  - Silently clamp to minimum 30 days
- AND when the admin attempts `mydash.analytics_retention_days = 5000` (above maximum 3650)
- THEN the system MUST clamp to maximum 3650 days
- NOTE: Admins can configure retention between 30 and 3650 days inclusive

#### Scenario: Purge job is automatic and idempotent

- GIVEN the daily purge job has run once and deleted old rows
- WHEN it runs again (on the next day or re-triggered)
- THEN no errors MUST occur
- AND rows deleted in the previous run MUST remain deleted (no resurrection)
- NOTE: Job MUST be registered with Nextcloud scheduler and run daily at a fixed time (e.g., 02:00 UTC)

#### Scenario: Purge logs execution

- GIVEN the daily purge job runs
- WHEN rows are deleted
- THEN the system MUST log:
  - The number of rows deleted
  - The date cutoff used (e.g., "Purged 1250 rows older than 2025-05-01")
- AND no personally-identifying information MUST appear in logs

### Requirement: REQ-ANLT-010 CSV Export Endpoint

The system MUST expose an admin-only endpoint that exports analytics data in CSV format for external analysis.

#### Scenario: CSV export contains dashboard statistics

- GIVEN analytics data exists for multiple dashboards
- WHEN an admin sends `GET /api/admin/analytics/export?period=30d`
- THEN the system MUST return HTTP 200 with `Content-Type: text/csv`
- AND the response MUST have a `Content-Disposition: attachment; filename=dashboard-analytics-2026-05-01.csv` header
- AND the CSV MUST contain columns: `dashboardUuid`, `dashboardName`, `viewBucket`, `viewCount`, `uniqueViewerCount`
- AND rows MUST be sorted by `dashboardUuid`, then `viewBucket` ascending
- AND the CSV MUST include a header row

#### Scenario: CSV respects period parameter

- GIVEN analytics data exists for a 90-day window
- WHEN an admin sends `GET /api/admin/analytics/export?period=7d`
- THEN the CSV MUST include only rows where `viewBucket >= CURRENT_DATE - 7 days`
- AND rows outside the period MUST be excluded

#### Scenario: Non-admin receives 403

- GIVEN a logged-in non-admin user
- WHEN they send `GET /api/admin/analytics/export?period=30d`
- THEN the system MUST return HTTP 403 (Forbidden)
- AND no CSV is generated

#### Scenario: CSV filename includes export date

- GIVEN an admin exports on 2026-05-01
- WHEN the CSV is downloaded
- THEN the filename MUST be `dashboard-analytics-2026-05-01.csv` (today's UTC date in YYYY-MM-DD format)

### Requirement: REQ-ANLT-011 Frontend View-Event Instrumentation

The system MUST call the view-event endpoint from the Vue frontend when a dashboard is loaded, with debouncing to prevent multi-tab inflation.

#### Scenario: Dashboard component calls view-event on mount

- GIVEN a user navigates to a dashboard view in MyDash
- WHEN the DashboardView.vue component mounts
- THEN the component MUST call `POST /api/dashboards/{uuid}/view-event` exactly once
- AND the request MUST be sent asynchronously (not blocking render)
- AND the HTTP 204 response MUST be handled silently (no UI change)

#### Scenario: Debouncing prevents multi-tab double-counting

- GIVEN a user opens the same dashboard in two browser tabs simultaneously
- WHEN both tabs load and try to post view events within 1 second
- THEN only ONE `POST /api/dashboards/{uuid}/view-event` MUST be sent
- AND the second tab's event MUST be debounced/cancelled
- NOTE: Debounce window is 1 second per dashboard instance; debounce is per-tab-session, not cross-tab

#### Scenario: Different dashboards are not debounced against each other

- GIVEN a user opens dashboard "uuid-110" in one tab
- AND dashboard "uuid-111" in another tab (same window/session)
- WHEN both tabs mount simultaneously
- THEN both `POST /api/dashboards/{uuid-110}/view-event` and `POST /api/dashboards/{uuid-111}/view-event` MUST be sent
- AND debouncing applies per-uuid, not globally

#### Scenario: Reload on same dashboard triggers new event

- GIVEN a user is viewing dashboard "uuid-112" in a tab
- AND they reload the page (F5)
- WHEN the page reloads and DashboardView.vue mounts again
- THEN a new `POST /api/dashboards/{uuid-112}/view-event` MUST be sent
- AND this new event is NOT suppressed by the previous one (debounce is per-mount, not per-session)

#### Scenario: View-event is not sent if analytics is disabled

- GIVEN the admin has set `mydash.analytics_enabled = false`
- WHEN a user loads a dashboard (and the frontend sees the setting, e.g., via config endpoint)
- THEN the component MUST NOT call `POST /api/dashboards/{uuid}/view-event`
- AND no request MUST be sent to the backend
