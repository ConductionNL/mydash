---
capability: dashboards
delta: true
status: draft
---

# Dashboards — Delta from change `dashboard-draft-published`

## ADDED Requirements

### Requirement: REQ-DASH-019 Publication-state schema

The system MUST track dashboard publication state via three new database columns: `publicationStatus` (enumerated), `publishAt` (nullable timestamp), and `publishedAt` (nullable timestamp). These columns enable the draft → published → scheduled workflow.

#### Scenario: Schema addition and migration backfill

- GIVEN a MyDash instance with existing dashboards before the publication-state migration
- WHEN the migration `VersionXXXXDate2026...AddPublicationState.php` is applied
- THEN the schema MUST add three columns to `oc_mydash_dashboards`:
  - `publicationStatus ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft'`
  - `publishAt TIMESTAMP NULL`
  - `publishedAt TIMESTAMP NULL`
- AND all existing dashboard rows MUST be backfilled with `publicationStatus = 'published'` to preserve visibility behaviour
- AND a composite index `idx_mydash_dash_user_pubstatus` on `(userId, publicationStatus)` MUST be created
- AND the migration MUST be reversible via `postSchemaChange` rollback

#### Scenario: Timestamp formats

- GIVEN a dashboard with `publishAt` or `publishedAt` set
- WHEN the dashboard is serialized to JSON
- THEN both timestamps MUST be in ISO-8601 format (e.g., "2026-03-20T14:30:00Z")
- AND null timestamps MUST be included in the JSON with null values

#### Scenario: Invariants on field population

- GIVEN a dashboard in `draft` state
- THEN both `publishAt` and `publishedAt` MUST be null (not set)
- AND the database MUST enforce this invariant via application logic (nullable columns allow storage, but service layer prevents misuse)

#### Scenario: Scheduled state requires publishAt

- GIVEN a dashboard with `publicationStatus = 'scheduled'`
- THEN `publishAt` MUST be set (a non-null timestamp in the future)
- AND attempting to schedule a dashboard with a past or null `publishAt` MUST fail

#### Scenario: Published state may have publishedAt

- GIVEN a dashboard with `publicationStatus = 'published'`
- THEN `publishedAt` MAY be set (records when it was first published) or null (for pre-existing dashboards from backfill)
- AND `publishAt` MUST be null (ignored for published state)

### Requirement: REQ-DASH-020 Draft visibility restrictions

A dashboard in `draft` state MUST be visible only to its owner and to Nextcloud administrators. Draft dashboards MUST NOT appear in any visible-dashboard listing for other users.

#### Scenario: Draft dashboard hidden from other users

- GIVEN user "alice" has created a draft dashboard "My Private Analysis"
- WHEN user "bob" calls `GET /api/dashboards/visible`
- THEN the draft dashboard "My Private Analysis" MUST NOT be included in the response
- AND bob has no way to discover or access the draft dashboard via the API

#### Scenario: Draft dashboard visible to owner

- GIVEN user "alice" has created a draft dashboard "My Private Analysis"
- WHEN alice calls `GET /api/dashboards/visible`
- THEN the response MUST include "My Private Analysis"
- AND it MUST carry `publicationStatus: 'draft'`

#### Scenario: Admin can see draft dashboards of other users

- GIVEN user "alice" has created a draft dashboard "My Private Analysis"
- AND user "root" is a Nextcloud administrator
- WHEN root calls `GET /api/dashboards/visible` or queries directly via admin endpoints
- THEN the dashboard MUST be visible to root (for administrative purposes)

#### Scenario: GET /api/dashboard (active) respects draft state

- GIVEN user "alice" has set her active dashboard to a draft state
- WHEN alice calls `GET /api/dashboard` (fetch her active dashboard)
- THEN the response MUST return the draft dashboard with `publicationStatus: 'draft'`
- AND the same dashboard MUST NOT appear in `GET /api/dashboards/visible` for other users

#### Scenario: Draft dashboards excluded from search/listing filters

- GIVEN user "alice" has draft dashboard "D1" and published dashboard "D2"
- WHEN any API or frontend logic iterates over visible dashboards
- THEN draft "D1" MUST be filtered out for all non-owner callers
- NOTE: Filtering happens at the mapper layer (`findVisibleToUser()` method)

### Requirement: REQ-DASH-021 Publish action

The system MUST expose an action to transition a dashboard from draft or scheduled state to published state, recording the publication timestamp.

#### Scenario: Publish a draft dashboard

- GIVEN user "alice" has a draft dashboard with `uuid: "d123"`
- WHEN alice sends `POST /api/dashboards/d123/publish`
- THEN the system MUST transition the dashboard to `publicationStatus = 'published'`
- AND set `publishedAt = now()` (if not already set)
- AND set `publishAt = null` (clear any scheduled-publication time)
- AND return HTTP 200 with the updated dashboard object

#### Scenario: Publish is idempotent

- GIVEN user "alice" has a published dashboard with `publishedAt = '2026-03-20T14:30:00Z'`
- WHEN alice sends `POST /api/dashboards/{uuid}/publish` again
- THEN the system MUST return HTTP 200 (success, not 409 conflict)
- AND `publishedAt` MUST remain '2026-03-20T14:30:00Z' (unchanged, not updated to current time)
- AND the dashboard MUST remain published

#### Scenario: Only owner or admin can publish

- GIVEN user "alice" has a draft dashboard
- WHEN user "bob" sends `POST /api/dashboards/{alice's-uuid}/publish`
- THEN the system MUST return HTTP 403
- AND the dashboard MUST remain draft
- AND admin "root" CAN publish alice's dashboard

#### Scenario: Publish a scheduled dashboard

- GIVEN user "alice" has a scheduled dashboard with `publishAt = '2026-04-01T10:00:00Z'`
- WHEN alice sends `POST /api/dashboards/{uuid}/publish` before the scheduled time
- THEN the system MUST transition to `publicationStatus = 'published'`
- AND set `publishedAt = now()` (immediate publication, not the scheduled time)
- AND the dashboard MUST be visible to other users immediately

#### Scenario: Activity log records publication

- GIVEN user "alice" publishes a draft dashboard named "Q1 Reports"
- WHEN the publish action completes
- THEN the system MUST log activity with type `dashboard_published` and subject containing the dashboard name
- AND the activity MUST be visible in the Nextcloud activity stream

### Requirement: REQ-DASH-022 Unpublish action

The system MUST expose an action to revert a published or scheduled dashboard back to draft state, preserving the publication history.

#### Scenario: Unpublish a published dashboard

- GIVEN user "alice" has a published dashboard with `uuid: "d123"` and `publishedAt = '2026-03-20T14:30:00Z'`
- WHEN alice sends `POST /api/dashboards/d123/unpublish`
- THEN the system MUST transition to `publicationStatus = 'draft'`
- AND `publishedAt` MUST remain '2026-03-20T14:30:00Z' (preserved for audit history)
- AND `publishAt` MUST be null
- AND return HTTP 200 with the updated dashboard

#### Scenario: Unpublish hides dashboard from non-owners

- GIVEN user "alice" publishes a dashboard
- AND user "bob" is viewing the dashboard
- WHEN alice unpublishes the dashboard via `POST /api/dashboards/{uuid}/unpublish`
- THEN the dashboard MUST NOT appear in bob's next `GET /api/dashboards/visible` response
- AND bob cannot access the dashboard via direct API call (HTTP 403 or 404)

#### Scenario: Only owner or admin can unpublish

- GIVEN user "alice" has a published dashboard
- WHEN user "bob" sends `POST /api/dashboards/{alice's-uuid}/unpublish`
- THEN the system MUST return HTTP 403
- AND the dashboard MUST remain published

#### Scenario: Unpublish is idempotent

- GIVEN user "alice" has a draft dashboard
- WHEN alice sends `POST /api/dashboards/{uuid}/unpublish` (already draft)
- THEN the system MUST return HTTP 200
- AND the dashboard MUST remain draft
- AND no state change occurs

#### Scenario: Activity log records unpublication

- GIVEN user "alice" unpublishes a published dashboard named "Q1 Reports"
- WHEN the unpublish action completes
- THEN the system MUST log activity with type `dashboard_unpublished` and subject containing the dashboard name

### Requirement: REQ-DASH-023 Schedule action

The system MUST expose an action to schedule a dashboard for automatic publication at a future date and time.

#### Scenario: Schedule a draft dashboard

- GIVEN user "alice" has a draft dashboard with `uuid: "d123"`
- AND the current time is "2026-03-20T10:00:00Z"
- WHEN alice sends `POST /api/dashboards/d123/schedule` with body `{"publishAt": "2026-04-01T10:00:00Z"}`
- THEN the system MUST transition to `publicationStatus = 'scheduled'`
- AND set `publishAt = '2026-04-01T10:00:00Z'`
- AND return HTTP 200 with the updated dashboard

#### Scenario: Cannot schedule with past date

- GIVEN the current time is "2026-03-20T10:00:00Z"
- WHEN user "alice" sends `POST /api/dashboards/{uuid}/schedule` with body `{"publishAt": "2026-03-19T10:00:00Z"}`
- THEN the system MUST return HTTP 400 with error message containing "future" or "scheduled time"
- AND the dashboard state MUST NOT change
- AND the error message MUST be available in both Dutch and English

#### Scenario: Scheduled dashboard behaves as draft until publishAt

- GIVEN user "alice" has scheduled a dashboard for "2026-04-01T10:00:00Z"
- AND the current time is "2026-03-20T10:00:00Z"
- WHEN user "bob" calls `GET /api/dashboards/visible`
- THEN the scheduled dashboard MUST NOT appear in bob's response (behaves as draft)
- AND bob cannot access it

#### Scenario: Only owner or admin can schedule

- GIVEN user "alice" has a draft dashboard
- WHEN user "bob" sends `POST /api/dashboards/{alice's-uuid}/schedule` with a future date
- THEN the system MUST return HTTP 403
- AND the dashboard state MUST NOT change

#### Scenario: Activity log records scheduling

- GIVEN user "alice" schedules a dashboard named "Q2 Planning" for "2026-04-01T10:00:00Z"
- WHEN the schedule action completes
- THEN the system MUST log activity with type `dashboard_scheduled` with timestamp in the subject

### Requirement: REQ-DASH-024 Lazy materialisation of scheduled dashboards

The system MUST treat a scheduled dashboard as published when `publishAt <= now()`, without requiring an explicit external trigger. This materialisation happens on every read operation, ensuring correctness even if a background job fails.

#### Scenario: Scheduled dashboard becomes visible when publishAt passes

- GIVEN user "alice" has scheduled a dashboard for "2026-03-20T14:30:00Z"
- AND the current server time is "2026-03-20T14:35:00Z" (after the scheduled publication time)
- WHEN user "bob" calls `GET /api/dashboards/visible`
- THEN the dashboard MUST appear in the response
- AND bob MUST be able to view it (materialised as published)

#### Scenario: Lazy materialisation does not update database immediately

- GIVEN user "alice" has scheduled a dashboard for "2026-03-20T14:30:00Z"
- AND the current server time is "2026-03-20T14:35:00Z"
- WHEN bob reads the dashboard via `GET /api/dashboards/{uuid}`
- THEN the response MUST show `publicationStatus: 'published'` (client sees it as published)
- AND the database row MAY still contain `publicationStatus: 'scheduled'` (lazy materialisation, not eager)

#### Scenario: Scheduled dashboard transitions instantly at the second it becomes due

- GIVEN a scheduled dashboard with `publishAt = '2026-03-20T14:30:00Z'`
- AND the server clock at that instant has just reached "2026-03-20T14:30:00Z"
- WHEN any user (owner or not) queries for visible dashboards
- THEN the dashboard MUST appear (materialised as published)
- AND there is no grace period or delay

#### Scenario: Optional background job can eagerly materialise

- GIVEN `lib/Cron/PublicationMaterialisation.php` is optionally enabled in `appinfo/info.xml`
- WHEN the cron job runs every 5 minutes
- THEN it MAY find all `publicationStatus = 'scheduled'` rows with `publishAt <= now()` and update them to `published`
- AND set `publishedAt = now()` for cleaner audit logs
- NOTE: This is optional for correctness; lazy materialisation at read time is sufficient

### Requirement: REQ-DASH-025 Migration backfill to published state

The system MUST ensure that all dashboards created before this change are treated as published, preserving existing visibility behaviour.

#### Scenario: Existing dashboards default to published after migration

- GIVEN a MyDash instance with 100 existing dashboards before the migration
- WHEN the migration `VersionXXXXDate2026...AddPublicationState.php` is applied
- THEN the `publicationStatus` column MUST be added with `DEFAULT 'draft'`
- AND the migration MUST backfill ALL existing rows with `publicationStatus = 'published'` via explicit UPDATE statement
- AND existing visibility rules MUST be preserved (all pre-existing dashboards remain visible to their intended audience)

#### Scenario: Backfill only affects pre-existing rows

- GIVEN a dashboard created before the migration has `publicationStatus = 'published'` after backfill
- WHEN a new dashboard is created after the migration via `POST /api/dashboard`
- THEN the new dashboard MUST default to `publicationStatus = 'draft'` (not 'published')
- AND the old and new dashboards have different initial states

#### Scenario: Visibility semantics unchanged for pre-migration dashboards

- GIVEN user "alice" shared a dashboard with user "bob" before this change
- WHEN the migration runs and backfills alice's dashboard to `publicationStatus = 'published'`
- THEN bob MUST continue to see the dashboard (same visibility as before)
- AND the share relationship is unaffected
