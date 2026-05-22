---
status: draft
---
# Scheduled Exports

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Reports / (root)

**Rationale:** Export product  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

mydash dashboards and widgets are designed for live, in-browser consumption: a user opens the app, the widgets render from OpenRegister and OpenConnector sources, and they read the numbers. That model breaks down for three very common organisational rhythms. First, the recurring management report — the wethouder who wants the weekly bezwaarschriften-stand on her desk every Monday at 9:00 in PDF, the same way her predecessor got it on paper. Second, the regulated data exchange — the monthly CBS or VNG return where a fixed CSV/XLSX shape has to land on a specific SFTP drop at a specific date or a penalty kicks in. Third, the cross-system snapshot — the nightly PNG of a service-desk KPI dashboard that gets posted into a Teams channel so the standup starts from a shared picture instead of "let me open mydash for a second". Scheduled Exports gives mydash a first-class answer to all three.

The capability is built around five ideas. First, schedules are cron-shaped but presented with a friendly recurrence editor (every Monday 09:00, first of the month, every weekday at 17:00) so dashboard authors do not need to write cron expressions for the 95% case while operators still have raw cron available for the awkward 5%. Second, the renderer is decoupled from the delivery channel: a single scheduled export can render to PDF, PNG, CSV, or XLSX, and the same render result can be fanned out to multiple delivery channels (email, a Nextcloud Files folder, a webhook, an SFTP destination) without re-rendering. Third, each recipient is a first-class entity with its own filter context — the same "Weekly Bezwaren" export can render once per gemeente and email each gemeente its own filtered version, so a regional shared-service centre stops maintaining 23 nearly-identical scheduled exports by hand. Fourth, retention is per-export and enforced by a janitor job, so an organisation can keep the monthly compliance returns for seven years while auto-purging the daily PNG snapshots after fourteen days. Fifth, every run writes an audit row including the rendered artefact's hash, the recipients' delivery outcomes, and any retry attempts, so a compliance officer can prove a CBS return was posted before the deadline even if the SFTP receiver later denies receipt.

The feature layers on top of the existing widget and dashboard infrastructure without changing the widget data contract — a dashboard that has no scheduled exports behaves exactly as it does today. Headless rendering uses the existing in-app render pipeline (Vue components, the live OpenRegister query layer, the OpenConnector source resolver) running inside a server-side headless Chromium so that what arrives in the PDF matches what the user sees on screen, down to NL Design theming and per-user locale. Failure handling is first-class because the most common cause of a missed scheduled report is not the renderer crashing but a downstream MTA outage or an expired SFTP credential, and a silent failure is worse than no schedule at all.

## Data Model

A `scheduled_export` object holds the export configuration. Fields: `id` (UUID), `name` (string, human label shown in the list and in delivered messages), `description` (optional markdown shown in email body and in cover-page of PDF), `subjectType` (enum: `dashboard`, `widget`), `subjectId` (UUID, references a dashboard or a widget), `enabled` (boolean, default true), `recurrence` (object, see below), `renderTargets` (array of render-target objects, at least one required), `recipients` (array of recipient objects, at least one required), `retention` (object: `keepArtefactsForDays` integer with org-level default 30, `keepAuditForDays` integer with org-level default 365, both nullable for "keep forever"), `runTimezone` (IANA string, defaults to dashboard's timezone or the org default), `nextRunAt` (timestamp, derived from recurrence), `lastRunAt`, `lastRunStatus` (enum: `success`, `partial`, `failed`, `running`), `createdBy`, `createdAt`, `updatedAt`.

The `recurrence` object discriminates on `type`. Four types are supported in v1: `weekly` (`daysOfWeek` array, `timeOfDay` HH:MM), `monthly` (`dayOfMonth` integer 1-31 or `lastDay` boolean, `timeOfDay`), `daily` (`timeOfDay`), and `cron` (raw five-field cron string for power users). All four resolve to a single `nextRunAt` timestamp computed in `runTimezone` and recomputed after each run; rules that miss a fire window (because the job runner was down) are evaluated at most once per missed slot to avoid a thundering catch-up on resume.

A `render_target` object describes one render output. Fields: `id`, `format` (enum: `pdf`, `png`, `csv`, `xlsx`), `formatOptions` (format-specific: PDF page size, orientation, header/footer toggles; PNG viewport size and DPI; CSV delimiter and quote char; XLSX sheet-per-widget toggle), `filenamePattern` (template string with `{name}`, `{date}`, `{recipient}`, `{ext}` placeholders).

A `recipient` object discriminates on `channel`. Fields shared by all: `id`, `enabled`, `channel` (enum: `email`, `nextcloudFiles`, `webhook`, `sftp`), `filterContext` (optional object — same shape as the cross-widget filter bus, so a recipient can be "this email goes to gemeente Zeist with `gemeenteCode=GM0355` pinned"), `channelConfig` (channel-specific subfields), `retryPolicy` (optional override of the global `{maxAttempts:5, initialBackoffSeconds:60, maxBackoffSeconds:3600, jitter:true}`).

A `scheduled_export_run` object is written on every fire. Fields: `id`, `exportId`, `startedAt`, `finishedAt`, `status`, `triggeredBy` (enum: `schedule`, `manual`), `triggeredByUser` (nullable, set for manual runs), `renderArtefacts` (array of `{format, sizeBytes, sha256, storagePath, expiresAt}`), `deliveries` (array of `{recipientId, channel, attemptCount, status, deliveredAt, providerMessageId, error}`), `correlationId` (UUID echoed into provider headers for downstream tracing).

All five objects live in the existing mydash register so they share lifecycle, ACL, and audit infra with widgets and dashboards. The scheduler is a background job `OCA\MyDash\BackgroundJob\ScheduledExportRunner` registered through the Nextcloud job runner at the granularity of one minute; the janitor is `OCA\MyDash\BackgroundJob\ScheduledExportJanitor` and runs hourly. Render artefacts are stored in the user's Nextcloud Files area under `Apps/MyDash/Exports/{exportId}/` so they appear in the standard file UI; binary content is not duplicated in OpenRegister.

## Requirements

### REQ-SCH-001 — Recurrence editor produces deterministic next-run timestamps

The system SHALL compute `nextRunAt` from `recurrence` and `runTimezone` deterministically, honouring DST transitions and leap-day edge cases, and SHALL recompute `nextRunAt` after every fire.

- GIVEN a `weekly` recurrence with `daysOfWeek=[Monday]`, `timeOfDay=09:00`, `runTimezone=Europe/Amsterdam`
  WHEN `nextRunAt` is computed on a Friday at 12:00 local time
  THEN `nextRunAt` SHALL be the upcoming Monday at 09:00 Europe/Amsterdam.
- GIVEN a `monthly` recurrence with `dayOfMonth=31`, `timeOfDay=08:00`
  WHEN the current month is February
  THEN `nextRunAt` SHALL fall on the last day of February at 08:00 (the system clamps to the last day rather than skipping the month).
- GIVEN a `cron` recurrence `0 9 * * 1` evaluated across the spring DST transition
  WHEN the Monday-at-09:00 fire would land in the missing hour
  THEN the fire SHALL occur at 09:00 local time (post-transition wall clock), not at 08:00 or 10:00 UTC-offset-equivalent.

### REQ-SCH-002 — Background runner fires schedules without an active browser session

The system SHALL evaluate `scheduled_export` rows from a Nextcloud background job, render and dispatch independently of any logged-in user, and SHALL execute renders under the export's `createdBy` identity for ACL purposes.

- GIVEN an enabled export with `nextRunAt` in the past
  WHEN the background job runs
  THEN the runner SHALL invoke render, dispatch, and update `lastRunAt`, `lastRunStatus`, and `nextRunAt`.
- GIVEN an enabled export whose `createdBy` user has been disabled
  WHEN the background job runs
  THEN the run SHALL be skipped, `lastRunStatus` SHALL be set to `failed`, and an admin notification SHALL be queued.
- GIVEN an export whose previous run is still `running` past its expected runtime
  WHEN the next fire window arrives
  THEN the new fire SHALL be skipped (no overlap) and a `partial` run-row SHALL record the skip reason.

### REQ-SCH-003 — Multi-format render from a single fire

The system SHALL render the configured `subjectId` once per `render_target` and SHALL produce PDF, PNG, CSV, and XLSX outputs that match the on-screen rendering for the same filter context.

- GIVEN an export with `subjectType=dashboard`, three render targets (`pdf`, `png`, `xlsx`)
  WHEN the runner fires
  THEN three artefacts SHALL be produced with distinct sha256s, all referencing the same `correlationId`.
- GIVEN a `csv` render target on a `subjectType=widget` export where the widget is a chart
  WHEN the runner fires
  THEN the CSV SHALL contain the chart's underlying tabular data (headers + rows), not a rasterised representation.
- GIVEN an `xlsx` render target on a dashboard with five widgets and `sheetPerWidget=true`
  WHEN the runner fires
  THEN the resulting workbook SHALL have five sheets named after each widget plus a cover sheet with the export name and run timestamp.

### REQ-SCH-004 — Per-recipient filter context

The system SHALL apply each recipient's `filterContext` (if set) before rendering for that recipient, producing recipient-specific artefacts, and SHALL share render output across recipients whose filter contexts are identical.

- GIVEN an export with two recipients, both with `filterContext={gemeenteCode: "GM0355"}`
  WHEN the runner fires
  THEN a single render SHALL be produced and SHALL be delivered to both recipients.
- GIVEN an export with two recipients with `filterContext={gemeenteCode: "GM0355"}` and `{gemeenteCode: "GM0344"}` respectively
  WHEN the runner fires
  THEN two renders SHALL be produced, each filtered to its respective gemeente, and the audit row SHALL list both artefacts.
- GIVEN a recipient with a `filterContext` that the subject's data source cannot satisfy (e.g. unknown field)
  WHEN the runner attempts to render for that recipient
  THEN that recipient's delivery SHALL be marked `failed` with a validation error and OTHER recipients SHALL still be processed.

### REQ-SCH-005 — Email, Nextcloud Files, webhook, and SFTP delivery channels

The system SHALL deliver render artefacts through the configured channel, using the Nextcloud mailer for `email`, writing into the user's Files area for `nextcloudFiles`, POSTing a signed multipart payload for `webhook`, and uploading via SFTP for `sftp`.

- GIVEN a recipient with `channel=email`, three addresses, and a PDF render target
  WHEN delivery runs
  THEN one email SHALL be sent with the PDF attached and all three addresses on the To: header, and the delivery row SHALL record the mailer's message-id.
- GIVEN a recipient with `channel=nextcloudFiles`, `channelConfig.targetPath="/Reports/Weekly"`, and a CSV render target
  WHEN delivery runs
  THEN the CSV SHALL be written to `/Reports/Weekly/{filenamePattern}` in the recipient user's Files area, overwriting any existing file with the same name only if `overwrite=true` is set in `channelConfig`.
- GIVEN a recipient with `channel=sftp` and a valid `channelConfig` (host, port, username, key-or-password, remote path)
  WHEN delivery runs
  THEN the artefact SHALL be uploaded to the remote path and the delivery row SHALL record the SFTP server's post-upload size confirmation.

### REQ-SCH-006 — Retry with exponential backoff and dead-letter

The system SHALL retry failed deliveries with exponential backoff up to `retryPolicy.maxAttempts` and SHALL move permanently-failed deliveries into a dead-letter state that surfaces in the UI and triggers an admin notification.

- GIVEN a webhook recipient whose first delivery returns HTTP 503
  WHEN the runner schedules the retry
  THEN the retry SHALL be attempted after `initialBackoffSeconds` (with optional jitter) and SHALL double on each subsequent failure up to `maxBackoffSeconds`.
- GIVEN a recipient whose retries all fail
  WHEN `maxAttempts` is reached
  THEN the delivery SHALL be marked `failed-permanent`, an admin notification SHALL be sent, and a "Retry" action SHALL be available in the run-detail UI.
- GIVEN a recipient with a temporary failure that succeeds on attempt three
  WHEN the third attempt returns 200
  THEN `attemptCount` SHALL be 3, `status` SHALL be `success`, and the run-row SHALL roll up to `success` (not `partial`) if all other deliveries also succeeded.

### REQ-SCH-007 — Retention policy enforced by janitor

The system SHALL delete render artefacts after `retention.keepArtefactsForDays` days and SHALL delete run-rows after `retention.keepAuditForDays` days, except where the export is explicitly configured to keep artefacts forever.

- GIVEN an export with `retention.keepArtefactsForDays=14` and a run-row from 20 days ago
  WHEN the janitor runs
  THEN the artefact files SHALL be deleted from the Files area and the run-row's `renderArtefacts[].storagePath` SHALL be nulled while the audit metadata SHALL be retained.
- GIVEN an export with `retention.keepArtefactsForDays=null`
  WHEN the janitor runs
  THEN no artefact SHALL be deleted regardless of age.
- GIVEN a run-row older than `retention.keepAuditForDays`
  WHEN the janitor runs
  THEN the run-row SHALL be deleted (and any remaining artefact files purged together with it).

### REQ-SCH-008 — Audit row per run with hash and delivery outcome

The system SHALL persist a `scheduled_export_run` row for every fire (manual or scheduled) capturing the render artefact hashes, the delivery outcomes per recipient including provider message ids, retry counts, and any error bodies.

- GIVEN a fire that produces one PDF artefact and dispatches to two recipients successfully
  WHEN the run completes
  THEN the run-row SHALL contain one entry in `renderArtefacts` (with non-empty `sha256`) and two entries in `deliveries` each with `status=success` and a non-null `providerMessageId`.
- GIVEN a fire where one of two recipients fails permanently
  WHEN the run completes
  THEN the run-row SHALL have `status=partial` and the failed delivery entry SHALL include `attemptCount`, the final `error` body, and `status=failed-permanent`.

### REQ-SCH-009 — Manual "Run now" and "Preview" actions

The system SHALL allow a user with edit permission on the export to trigger an immediate run (which writes a run-row with `triggeredBy=manual`) and to preview the rendered output for a chosen recipient without dispatching.

- GIVEN a user with edit permission on export E
  WHEN they invoke "Run now"
  THEN the runner SHALL execute E immediately, the run-row SHALL record `triggeredBy=manual` and `triggeredByUser=<user>`, and the next scheduled fire SHALL not be affected.
- GIVEN the same user invokes "Preview" for recipient R
  WHEN the preview runs
  THEN the render SHALL be returned in the response (or streamed for binary formats) and NO delivery SHALL be attempted and NO run-row SHALL be written.

### REQ-SCH-010 — Header signing and credential vaulting

The system SHALL HMAC-SHA256-sign webhook payloads with a per-recipient secret in the `X-MyDash-Signature` header, SHALL store SFTP credentials encrypted at rest, and SHALL route webhook/SFTP credentials through OpenConnector when `openconnectorSourceId` is set on the recipient.

- GIVEN a webhook recipient with `channelConfig.signingSecret` set
  WHEN a payload is dispatched
  THEN the request SHALL include `X-MyDash-Signature: sha256=<hex>` computed over the body using the secret.
- GIVEN an SFTP recipient persisted via the API
  WHEN the channel is read back
  THEN the password/keymaterial SHALL be returned as a redacted placeholder (`********`) and the original value SHALL be retrievable only by the dispatcher.
- GIVEN a recipient with `openconnectorSourceId=S`
  WHEN delivery runs
  THEN the dispatch SHALL be issued through OpenConnector source S (so the credential lives in the OC vault and the dispatch appears in the OC call-log) instead of using mydash-local credentials.

## Standards & Sources

The recurrence vocabulary is anchored in standard cron semantics (POSIX-shaped five-field expressions) so that an operator who already maintains cron-driven jobs has nothing new to learn for the power-user case, while the friendly weekly/monthly/daily editor follows iCalendar RRULE conventions (RFC 5545) for the common-case shapes — specifically the `BYDAY`/`BYMONTHDAY` resolution semantics and the "clamp to last day of month" behaviour that calendaring code has standardised on. Cron timezone resolution and DST handling follow the Quartz scheduler conventions (preserve wall-clock time across DST, fire once per missed fire window rather than skipping or doubling), which is the de-facto reference implementation in this space and aligns with what an operator who has used cron+anacron or Quartz expects.

PDF rendering uses headless Chromium (via Puppeteer or Playwright) so that the rendered PDF matches the on-screen DOM, including NL Design tokens, MDI icons, and locale-aware date/number formatting; alternative server-side PDF generators (wkhtmltopdf, dompdf) are explicitly avoided because they do not faithfully render the Vue-based mydash widget components. PDF/A-2b is offered as an output mode for the archival-record case, conforming to ISO 19005-2 — relevant when the export is the system of record for a regulated return. XLSX output follows ECMA-376 Office Open XML Spreadsheet (the format MS Excel and LibreOffice Calc both consume natively) and CSV output follows RFC 4180 (with configurable delimiter and quote char to interoperate with Dutch-locale Excel installs that default to semicolon-delimited).

Webhook signing follows the GitHub/Stripe convention: HMAC-SHA256 over the raw request body, hex-encoded, placed in `X-MyDash-Signature` with a `sha256=` prefix so receivers can disambiguate signing schemes if they migrate. A `X-MyDash-Timestamp` header is included to let receivers reject replay attacks beyond a tolerance window; the timestamp is signed as part of the canonicalised payload. SFTP delivery uses libssh2 (via PHP's bundled SFTP client) and supports both password and key-based auth; host-key verification is mandatory (`StrictHostKeyChecking=yes` semantics) and the known-hosts entries live next to the recipient config so a host-key rotation on the receiver side is visible as a configuration drift rather than silently broken.

Retention is informed by Archiefwet 1995 / Archiefbesluit retention rules for Dutch government data: the default retention windows (30 days for artefacts, 365 days for audit metadata) are conservative defaults that any specific record class can override upward; nothing here is meant to substitute for a proper documented retention schedule, but the per-export overrides let an organisation align with their TMLO/MDTO categorisation per-record (docudesk owns the retention-schedule modelling itself; mydash's job is to honour the windows). The audit-row shape is informed by NEN-ISO 27001:2022 controls 8.15 (logging) and 5.34 (privacy and protection of PII) — the rendered artefact's sha256 is captured so that a compliance officer can prove what was delivered without having to retain the artefact bytes after the retention window expires.

Email body templating uses the Nextcloud mailer's templated rendering with both an HTML and a plain-text body so screen-readers and old MTA filters both work; the attachment-vs-link toggle is per-recipient because a 12 MB monthly PDF should not land in everyone's inbox — instead the email links to the Files-area location and a one-click download. eIDAS / NTA 9087 signed-PDF output is out of scope for v1 — docudesk owns digital signing of documents that need it, and a scheduled export that needs eIDAS signing routes through docudesk's signing flow as a post-render step (the integration is named in the cross-app section but the wiring is a v2 follow-up).

## Cross-app integration

- **openregister**: scheduled_export, render_target, recipient, and run-rows are stored as register objects through the existing mydash register, so they share lifecycle, ACL, soft-delete, and audit-log infrastructure with widgets and dashboards. The janitor uses OpenRegister's retention-rule hooks to enforce the per-export retention windows instead of reimplementing a separate purge loop.
- **openconnector**: webhook and SFTP delivery routes through OpenConnector sources when `openconnectorSourceId` is set on a recipient, so credentials live in the OC vault and dispatches appear in the OC call-log with response bodies for debugging. This also lets an organisation apply OC's rate-limit policies at the source level (e.g. throttle deliveries to a flaky CBS endpoint) without per-recipient configuration.
- **Nextcloud Files**: the `nextcloudFiles` channel writes into the user's Files area under a configurable path. Files-share permissions on the target folder transitively control who can read the delivered artefact, so an organisation can share `/Reports/Weekly` with the management team once and let every weekly export drop into that shared folder.
- **docudesk**: when a render target is `pdf` and `signingProfile` is set on the recipient (v2), the rendered PDF SHOULD be routed through docudesk for eIDAS-aware signing before delivery. v1 ships without this integration but the recipient config schema reserves the field so v2 is a non-breaking addition.
- **openconnector + Nextcloud notifications**: dead-letter recipients trigger a Nextcloud notification to the export's owner and (optionally) to an admin group; the notification deep-links to the run-detail page with the failed delivery highlighted.
- **AI Chat Companion (ADR-034)**: the chat companion MAY answer "did the weekly report go out?" by querying the run-rows register directly; no new API needed.

## Target users

- **Dashboard authors / data analysts** create scheduled exports on dashboards or widgets they own — the export editor sits inside the dashboard menu (alongside Share, Bulk, and Comments actions) so they never leave the dashboard context. They pick a friendly recurrence, choose render targets, add recipients, and rely on sensible defaults for retention and retry.
- **Compliance / records officers** consume the audit log to prove a scheduled return left mydash within the regulatory window — they need the sha256, the recipient list, the delivery timestamps, and the provider-side acknowledgement id. They do not edit exports but they need read-only visibility into all runs across the instance.
- **Operations / IT administrators** configure the channels at the org level (one OpenConnector source for the corporate SFTP drop, one for the Slack workspace) and set the default retention windows. They watch the dead-letter queue and act when a recipient hits permanent failure, typically because a credential expired or a remote endpoint changed shape.
- **Managers / stakeholders** are the recipients — they get an email Monday morning with the PDF attached, or they open a shared Files folder and find this week's CSV waiting. They do not log in to mydash for the routine case; the export brings the data to them.
