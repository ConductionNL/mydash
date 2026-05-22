---
capability: scheduled-exports
delta: true
status: draft
---

# Scheduled Exports — New Capability

## ADDED Requirements

### Requirement: REQ-SCH-001 Recurrence editor produces deterministic next-run timestamps

The system MUST compute `nextRunAt` from `recurrence` and `runTimezone` deterministically, honouring DST transitions and leap-day edge cases, and MUST recompute `nextRunAt` after every fire.

#### Scenario: Weekly recurrence falls on the correct date

- GIVEN a `weekly` recurrence with `daysOfWeek=[Monday]`, `timeOfDay=09:00`, `runTimezone=Europe/Amsterdam`
- WHEN `nextRunAt` is computed on a Friday at 12:00 local time
- THEN `nextRunAt` SHALL be the upcoming Monday at 09:00 Europe/Amsterdam

#### Scenario: Monthly recurrence on day 31 clamps to last day

- GIVEN a `monthly` recurrence with `dayOfMonth=31`, `timeOfDay=08:00`
- WHEN the current month is February
- THEN `nextRunAt` SHALL fall on the last day of February at 08:00 (clamped, not skipped)

#### Scenario: Cron expression across spring DST transition

- GIVEN a `cron` recurrence `0 9 * * 1` evaluated across the spring DST transition
- WHEN the Monday-at-09:00 fire would land in the missing hour
- THEN the fire SHALL occur at 09:00 local time (post-transition wall-clock), not 08:00 or 10:00 UTC-offset-equivalent

### Requirement: REQ-SCH-002 Background runner fires schedules without an active browser session

The system MUST evaluate `scheduled_export` rows from a Nextcloud background job, render and dispatch independently of any logged-in user, and MUST execute renders under the export's `createdBy` identity for ACL purposes.

#### Scenario: Background job fires and updates timestamps

- GIVEN an enabled export with `nextRunAt` in the past
- WHEN the background job runs
- THEN the runner SHALL invoke render, dispatch, and update `lastRunAt`, `lastRunStatus`, and `nextRunAt`

#### Scenario: Disabled creator blocks the run

- GIVEN an enabled export whose `createdBy` user has been disabled
- WHEN the background job runs
- THEN the run SHALL be skipped, `lastRunStatus` SHALL be set to `failed`, and an admin notification SHALL be queued

#### Scenario: Overlap prevention — no concurrent runs

- GIVEN an export whose previous run is still `running` past its expected runtime
- WHEN the next fire window arrives
- THEN the new fire SHALL be skipped (no overlap) and a `partial` run-row SHALL record the skip reason

### Requirement: REQ-SCH-003 Multi-format render from a single fire

The system MUST render the configured `subjectId` once per `render_target` and MUST produce PDF, PNG, CSV, and XLSX outputs that match the on-screen rendering for the same filter context.

#### Scenario: Three render targets produce three artefacts

- GIVEN an export with `subjectType=dashboard`, three render targets (`pdf`, `png`, `xlsx`)
- WHEN the runner fires
- THEN three artefacts SHALL be produced with distinct sha256s, all referencing the same `correlationId`

#### Scenario: CSV of a chart widget contains tabular data

- GIVEN a `csv` render target on a `subjectType=widget` export where the widget is a chart
- WHEN the runner fires
- THEN the CSV SHALL contain the chart's underlying tabular data (headers + rows), not a rasterised representation

#### Scenario: XLSX with per-widget sheets

- GIVEN an `xlsx` render target on a dashboard with five widgets and `sheetPerWidget=true`
- WHEN the runner fires
- THEN the resulting workbook SHALL have five sheets named after each widget plus a cover sheet with the export name and run timestamp

### Requirement: REQ-SCH-004 Per-recipient filter context

The system MUST apply each recipient's `filterContext` (if set) before rendering for that recipient, producing recipient-specific artefacts, and MUST share render output across recipients whose filter contexts are identical.

#### Scenario: Two recipients with identical filter contexts share one render

- GIVEN an export with two recipients, both with `filterContext={gemeenteCode: "GM0355"}`
- WHEN the runner fires
- THEN a single render SHALL be produced and SHALL be delivered to both recipients

#### Scenario: Two recipients with distinct filter contexts produce two renders

- GIVEN an export with two recipients with `filterContext={gemeenteCode: "GM0355"}` and `{gemeenteCode: "GM0344"}` respectively
- WHEN the runner fires
- THEN two renders SHALL be produced, each filtered to its respective gemeente, and the audit row SHALL list both artefacts

#### Scenario: Invalid filter context fails gracefully

- GIVEN a recipient with a `filterContext` that the subject's data source cannot satisfy (e.g. unknown field)
- WHEN the runner attempts to render for that recipient
- THEN that recipient's delivery SHALL be marked `failed` with a validation error and OTHER recipients SHALL still be processed

### Requirement: REQ-SCH-005 Email, Nextcloud Files, webhook, and SFTP delivery channels

The system MUST deliver render artefacts through the configured channel, using the Nextcloud mailer for `email`, writing into the user's Files area for `nextcloudFiles`, POSTing a signed multipart payload for `webhook`, and uploading via SFTP for `sftp`.

#### Scenario: Email delivery attaches PDF to recipients

- GIVEN a recipient with `channel=email`, three addresses, and a PDF render target
- WHEN delivery runs
- THEN one email SHALL be sent with the PDF attached and all three addresses on the To: header, and the delivery row SHALL record the mailer's message-id

#### Scenario: Nextcloud Files delivery respects overwrite setting

- GIVEN a recipient with `channel=nextcloudFiles`, `channelConfig.targetPath="/Reports/Weekly"`, and a CSV render target
- WHEN delivery runs
- THEN the CSV SHALL be written to `/Reports/Weekly/{filenamePattern}` in the recipient user's Files area, overwriting any existing file with the same name only if `overwrite=true` is set in `channelConfig`

#### Scenario: SFTP delivery with host-key verification

- GIVEN a recipient with `channel=sftp` and a valid `channelConfig` (host, port, username, key-or-password, remote path)
- WHEN delivery runs
- THEN the artefact SHALL be uploaded to the remote path and the delivery row SHALL record the SFTP server's post-upload size confirmation

### Requirement: REQ-SCH-006 Retry with exponential backoff and dead-letter

The system MUST retry failed deliveries with exponential backoff up to `retryPolicy.maxAttempts` and MUST move permanently-failed deliveries into a dead-letter state that surfaces in the UI and triggers an admin notification.

#### Scenario: Webhook retry with exponential backoff

- GIVEN a webhook recipient whose first delivery returns HTTP 503
- WHEN the runner schedules the retry
- THEN the retry SHALL be attempted after `initialBackoffSeconds` (with optional jitter) and SHALL double on each subsequent failure up to `maxBackoffSeconds`

#### Scenario: Dead-letter after max retries exhausted

- GIVEN a recipient whose retries all fail
- WHEN `maxAttempts` is reached
- THEN the delivery SHALL be marked `failed-permanent`, an admin notification SHALL be sent, and a "Retry" action SHALL be available in the run-detail UI

#### Scenario: Recovery from transient failure

- GIVEN a recipient with a temporary failure that succeeds on attempt three
- WHEN the third attempt returns 200
- THEN `attemptCount` SHALL be 3, `status` SHALL be `success`, and the run-row SHALL roll up to `success` (not `partial`) if all other deliveries also succeeded

### Requirement: REQ-SCH-007 Retention policy enforced by janitor

The system MUST delete render artefacts after `retention.keepArtefactsForDays` days and MUST delete run-rows after `retention.keepAuditForDays` days, except where the export is explicitly configured to keep artefacts forever.

#### Scenario: Artefacts expire; audit metadata retained

- GIVEN an export with `retention.keepArtefactsForDays=14` and a run-row from 20 days ago
- WHEN the janitor runs
- THEN the artefact files SHALL be deleted from the Files area and the run-row's `renderArtefacts[].storagePath` SHALL be nulled while the audit metadata SHALL be retained

#### Scenario: Null retention means forever

- GIVEN an export with `retention.keepArtefactsForDays=null`
- WHEN the janitor runs
- THEN no artefact SHALL be deleted regardless of age

#### Scenario: Audit rows expire and are purged

- GIVEN a run-row older than `retention.keepAuditForDays`
- WHEN the janitor runs
- THEN the run-row SHALL be deleted (and any remaining artefact files purged together with it)

### Requirement: REQ-SCH-008 Audit row per run with hash and delivery outcome

The system MUST persist a `scheduled_export_run` row for every fire (manual or scheduled) capturing the render artefact hashes, the delivery outcomes per recipient including provider message ids, retry counts, and any error bodies.

#### Scenario: Successful run with one PDF and two recipients

- GIVEN a fire that produces one PDF artefact and dispatches to two recipients successfully
- WHEN the run completes
- THEN the run-row SHALL contain one entry in `renderArtefacts` (with non-empty `sha256`) and two entries in `deliveries` each with `status=success` and a non-null `providerMessageId`

#### Scenario: Partial run with one failed delivery

- GIVEN a fire where one of two recipients fails permanently
- WHEN the run completes
- THEN the run-row SHALL have `status=partial` and the failed delivery entry SHALL include `attemptCount`, the final `error` body, and `status=failed-permanent`

### Requirement: REQ-SCH-009 Manual "Run now" and "Preview" actions

The system MUST allow a user with edit permission on the export to trigger an immediate run (which writes a run-row with `triggeredBy=manual`) and to preview the rendered output for a chosen recipient without dispatching.

#### Scenario: Manual run with user tracking

- GIVEN a user with edit permission on export E
- WHEN they invoke "Run now"
- THEN the runner SHALL execute E immediately, the run-row SHALL record `triggeredBy=manual` and `triggeredByUser=<user>`, and the next scheduled fire SHALL not be affected

#### Scenario: Preview without dispatch

- GIVEN the same user invokes "Preview" for recipient R
- WHEN the preview runs
- THEN the render SHALL be returned in the response (or streamed for binary formats) and NO delivery SHALL be attempted and NO run-row SHALL be written

### Requirement: REQ-SCH-010 Header signing and credential vaulting

The system MUST HMAC-SHA256-sign webhook payloads with a per-recipient secret in the `X-MyDash-Signature` header, MUST store SFTP credentials encrypted at rest, and MUST route webhook/SFTP credentials through OpenConnector when `openconnectorSourceId` is set on the recipient.

#### Scenario: Webhook payload is signed

- GIVEN a webhook recipient with `channelConfig.signingSecret` set
- WHEN a payload is dispatched
- THEN the request SHALL include `X-MyDash-Signature: sha256=<hex>` computed over the body using the secret

#### Scenario: SFTP credentials are redacted on read

- GIVEN an SFTP recipient persisted via the API
- WHEN the channel is read back
- THEN the password/keymaterial SHALL be returned as a redacted placeholder (`********`) and the original value SHALL be retrievable only by the dispatcher

#### Scenario: Credentials routed through OpenConnector

- GIVEN a recipient with `openconnectorSourceId=S`
- WHEN delivery runs
- THEN the dispatch SHALL be issued through OpenConnector source S (so the credential lives in the OC vault and the dispatch appears in the OC call-log) instead of using mydash-local credentials
