---
capability: dashboards
delta: true
status: draft
---

# Dashboards — Delta from change `dashboard-draft-published`

## ADDED Requirements

### Requirement: REQ-DASH-031 Publication-state schema

The system MUST track dashboard publication state via three new database columns: `publication_status` (string enum), `publish_at` (nullable datetime), and `published_at` (nullable datetime). These columns enable the draft → published → scheduled workflow on top of the existing `oc_mydash_dashboards` table without breaking pre-existing rows.

#### Scenario: Schema addition and migration backfill

- GIVEN a MyDash instance with existing dashboards before the publication-state migration
- WHEN migration `Version001011Date20260502130000` is applied
- THEN the schema MUST add three columns to `oc_mydash_dashboards`:
  - `publication_status VARCHAR(20) NOT NULL DEFAULT 'published'`
  - `publish_at DATETIME NULL`
  - `published_at DATETIME NULL`
- AND all existing dashboard rows MUST acquire `publication_status = 'published'` automatically via the column default (no explicit UPDATE statement is needed — design D1)
- AND a composite index `mydash_dash_user_pubstatus` on `(user_id, publication_status)` MUST be created
- NOTE: New dashboards created after the migration default to `'draft'` via application logic in `DashboardFactory::create()`, NOT via the column default. The column default exists only to backfill pre-existing rows safely.

#### Scenario: Timestamp formats

- GIVEN a dashboard with `publishAt` or `publishedAt` set
- WHEN the dashboard is serialized to JSON via `Dashboard::jsonSerialize()`
- THEN both timestamps MUST be returned as `Y-m-d H:i:s` strings (the canonical storage format used elsewhere on the entity)
- AND null timestamps MUST be present in the JSON envelope with the value `null`

#### Scenario: Scheduled state requires publishAt

- GIVEN a dashboard with `publicationStatus = 'scheduled'`
- THEN `publishAt` MUST be a non-null timestamp strictly greater than `now()` at the moment of the schedule call
- AND attempting to schedule a dashboard with a past or null `publishAt` MUST raise the canonical `InvalidArgumentException` mapped to HTTP 400

### Requirement: REQ-DASH-032 Publish action

The system MUST expose `POST /api/dashboards/{uuid}/publish` that transitions a dashboard to `published` and stamps `publishedAt = now()` the first time the transition occurs. The action MUST be idempotent and gated to the dashboard owner or a Nextcloud administrator.

#### Scenario: Publish a draft dashboard

- GIVEN user "alice" has a draft dashboard with `uuid = "d123"`
- WHEN alice sends `POST /api/dashboards/d123/publish`
- THEN the system MUST set `publicationStatus = 'published'`
- AND set `publishedAt = now()` (because it was previously null)
- AND clear `publishAt` to `null`
- AND return HTTP 200 with the updated dashboard payload

#### Scenario: Publish is idempotent

- GIVEN user "alice" has an already-published dashboard with `publishedAt = '2026-03-20 14:30:00'`
- WHEN alice sends `POST /api/dashboards/{uuid}/publish` again
- THEN the system MUST return HTTP 200 with the unchanged dashboard
- AND `publishedAt` MUST remain `'2026-03-20 14:30:00'` (not refreshed to the current time)

#### Scenario: Only owner or admin can publish

- GIVEN user "alice" has a draft dashboard
- WHEN user "bob" (non-owner, non-admin) sends `POST /api/dashboards/{alice's-uuid}/publish`
- THEN the system MUST return HTTP 403 with the canonical error message `Forbidden: owner or admin only`
- AND the dashboard MUST remain in draft state
- AND a Nextcloud administrator "root" MUST be able to publish alice's dashboard via the same endpoint

### Requirement: REQ-DASH-033 Unpublish action

The system MUST expose `POST /api/dashboards/{uuid}/unpublish` that returns a dashboard to draft state while preserving `publishedAt` for audit history. Owner-or-admin gated.

#### Scenario: Unpublish a published dashboard

- GIVEN user "alice" has a published dashboard with `publishedAt = '2026-03-20 14:30:00'`
- WHEN alice sends `POST /api/dashboards/{uuid}/unpublish`
- THEN the system MUST set `publicationStatus = 'draft'`
- AND `publishedAt` MUST remain `'2026-03-20 14:30:00'` (preserved for audit)
- AND `publishAt` MUST be cleared to `null`
- AND return HTTP 200 with the updated dashboard

#### Scenario: Unpublish hides dashboard from non-owners

- GIVEN user "alice" had previously published dashboard `D` and bob could see it via `GET /api/dashboards/visible`
- WHEN alice unpublishes `D`
- THEN bob's next `GET /api/dashboards/visible` MUST NOT include `D`
- AND alice MUST still see `D` in her own listing (owner-visibility preserved)

#### Scenario: Unpublish is idempotent

- GIVEN user "alice" has a draft dashboard
- WHEN alice sends `POST /api/dashboards/{uuid}/unpublish` (already draft)
- THEN the system MUST return HTTP 200 with the unchanged dashboard
- AND no state change MUST occur

### Requirement: REQ-DASH-034 Schedule action

The system MUST expose `POST /api/dashboards/{uuid}/schedule` accepting `{publishAt: ISO-8601}` to schedule a dashboard for automatic publication at a future moment. The system MUST treat scheduled dashboards whose `publishAt <= now()` as published on every read (lazy materialisation), with no dependency on a background job for correctness.

#### Scenario: Schedule a draft dashboard

- GIVEN user "alice" has a draft dashboard with `uuid = "d123"`
- AND the current time is `'2026-03-20 10:00:00'`
- WHEN alice sends `POST /api/dashboards/d123/schedule` with body `{"publishAt": "2026-04-01T10:00:00Z"}`
- THEN the system MUST set `publicationStatus = 'scheduled'`
- AND set `publishAt = '2026-04-01 10:00:00'` (normalised to the storage format)
- AND return HTTP 200 with the updated dashboard

#### Scenario: Cannot schedule with past date

- GIVEN the current time is `'2026-03-20 10:00:00'`
- WHEN user "alice" sends `POST /api/dashboards/{uuid}/schedule` with body `{"publishAt": "2026-03-19T10:00:00Z"}`
- THEN the system MUST return HTTP 400 with error message `publishAt must be a future timestamp`
- AND the dashboard state MUST NOT change
- AND the error message MUST be available in both Dutch and English (l10n entries `publishAt must be a future timestamp` registered in `l10n/{en,nl}.{js,json}`)

#### Scenario: Cannot schedule with empty / unparseable publishAt

- GIVEN any logged-in user
- WHEN they send `POST /api/dashboards/{uuid}/schedule` with body `{}` or `{"publishAt": "not-a-date"}`
- THEN the system MUST return HTTP 400 with the same `publishAt must be a future timestamp` message
- AND the dashboard state MUST NOT change

#### Scenario: Scheduled dashboard becomes visible when publishAt passes (lazy materialisation)

- GIVEN user "alice" scheduled a dashboard for `'2026-03-20 14:30:00'`
- AND the current server time is `'2026-03-20 14:35:00'`
- WHEN any user (including non-owners) calls `GET /api/dashboards/visible`
- THEN the dashboard MUST appear in the response with `publicationStatus = 'published'` (materialised at read time)
- AND the database row MAY still carry `publication_status = 'scheduled'` (lazy — no DB write required for correctness)

#### Scenario: Future-scheduled dashboard hidden from non-owners

- GIVEN alice scheduled a dashboard for `'2026-04-01 10:00:00'`
- AND the current server time is `'2026-03-20 10:00:00'`
- WHEN bob calls `GET /api/dashboards/visible`
- THEN bob MUST NOT see the scheduled dashboard
- AND alice (owner) MUST still see it with `publicationStatus = 'scheduled'` and the future `publishAt` timestamp

#### Scenario: Optional eager materialisation via DashboardService

- GIVEN one or more rows have `publication_status = 'scheduled'` and `publish_at <= now()`
- WHEN any caller invokes `DashboardService::materialiseScheduledDashboards()` (e.g. from a future cron job)
- THEN every due row MUST be flipped to `publication_status = 'published'` in the database
- AND `published_at` MUST be set to the current time when previously null
- AND the method MUST return the number of dashboards materialised
- NOTE: Lazy read-time materialisation remains the correctness contract (REQ-DASH-034 scenario "lazy materialisation"); this method is a cosmetic optimisation for cleaner audit data.

### Requirement: REQ-DASH-035 Migration backfill to published state

The publication-state migration MUST preserve the visibility of every dashboard that existed before the change. Pre-existing rows MUST default to `published` so users continue to see what they saw immediately before the upgrade.

#### Scenario: Existing dashboards default to published after migration

- GIVEN a MyDash instance with N existing dashboards before the migration
- WHEN `Version001011Date20260502130000::changeSchema()` runs
- THEN the `publication_status` column MUST be added with `DEFAULT 'published'`
- AND every existing row MUST acquire `'published'` via the column default — no explicit `UPDATE` statement is required (design D1)

#### Scenario: New dashboards default to draft despite the column default

- GIVEN the migration has run (column default is `'published'`)
- WHEN any user creates a new dashboard via `POST /api/dashboard`
- THEN the new dashboard MUST be persisted with `publicationStatus = 'draft'` because `DashboardFactory::create()` overrides the default before insertion
- AND the dashboard MUST NOT appear in `GET /api/dashboards/visible` for any non-owner non-admin caller until explicitly published

### Requirement: REQ-DASH-036 Draft visibility restrictions

A dashboard in `draft` state MUST be visible only to its owner and to Nextcloud administrators. Draft dashboards MUST NOT appear in any visible-dashboard listing for any other user.

#### Scenario: Draft dashboard hidden from other users

- GIVEN user "alice" has a draft dashboard `D`
- WHEN user "bob" calls `GET /api/dashboards/visible`
- THEN `D` MUST NOT be present in the response

#### Scenario: Draft dashboard visible to owner

- GIVEN user "alice" has a draft dashboard `D`
- WHEN alice calls `GET /api/dashboards/visible`
- THEN `D` MUST be present in the response with `publicationStatus = 'draft'`

#### Scenario: Admin can see draft dashboards of other users

- GIVEN user "alice" has a draft dashboard `D`
- AND "root" is a Nextcloud administrator
- WHEN root calls `GET /api/dashboards/visible`
- THEN `D` MUST be present in the response (admin-override visibility)

### Requirement: REQ-DASH-037 Frontend store mirrors publication state

The Pinia dashboard store MUST track `publicationStatus`, `publishAt`, and `publishedAt` for every dashboard fetched from `/api/dashboards/visible` or `/api/dashboard`. Store actions MUST exist for publish / unpublish / schedule and MUST patch the local copy in place on success so the UI reflects the new state without a full reload.

#### Scenario: Store exposes status constants

- GIVEN the dashboard store module is imported
- THEN it MUST export `STATUS_DRAFT`, `STATUS_PUBLISHED`, and `STATUS_SCHEDULED` constants matching the PHP entity values

#### Scenario: Client-side lazy materialisation hint

- GIVEN a scheduled dashboard with `publishAt` in the past relative to the browser clock
- WHEN any caller invokes `dashboardStore.effectivePublicationStatus(dashboard)`
- THEN the method MUST return `'published'` even if the stored `publicationStatus` is still `'scheduled'`
- NOTE: This is a UX hint only — the backend remains the source of truth and applies the same materialisation server-side.

#### Scenario: Publish / unpublish / schedule actions patch local state

- GIVEN any dashboard `D` is loaded in the store
- WHEN `dashboardStore.publishDashboard(D.uuid)` resolves successfully
- THEN the local copy in `dashboards[]` (and `activeDashboard` when matching) MUST receive the updated `publicationStatus`, `publishAt`, and `publishedAt` without a separate `loadDashboards()` round-trip
