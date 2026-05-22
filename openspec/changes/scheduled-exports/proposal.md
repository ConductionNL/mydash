# Scheduled Exports

## Why

MyDash dashboards are designed for live in-browser consumption, but three common organisational rhythms require a different model. First, the recurring management report — a wethouder wanting a weekly bezwaarschriften PDF every Monday at 09:00. Second, the regulated data exchange — a monthly CBS or VNG return that must land on SFTP by a specific date or face penalties. Third, the cross-system snapshot — a nightly PNG of a KPI dashboard posted to Teams so standup starts from a shared picture. Today, MyDash cannot answer these use cases. Scheduled Exports closes that gap.

## What Changes

Five new entities in OpenRegister form the core:

- **`scheduled_export`**: Export configuration holding schedule, recipients, render targets, retention policy. Owned per-user or per-group, shared via standard MyDash permissions.
- **`render_target`**: One output format (PDF, PNG, CSV, XLSX) with format-specific options (page size, viewport, delimiter). Multiple per export; decouples rendering from delivery.
- **`recipient`**: Delivery destination with channel-specific config (email addresses, file path, webhook URL, SFTP credentials). Includes per-recipient filter context so the same export renders once per gemeente and emails each its filtered copy.
- **`scheduled_export_run`**: Audit row written on every fire capturing render artefact hashes, delivery outcomes per recipient, retry counts, and any errors. Proves what was delivered and when.
- **`scheduled_export_recurrence` (embedded in `scheduled_export`)**: Recurrence rule discriminating on type (weekly, monthly, daily, cron), resolved to deterministic `nextRunAt` timestamps respecting DST and leap-day edge cases.

Two background jobs orchestrate the lifecycle:

- **`ScheduledExportRunner`**: Evaluates enabled exports with `nextRunAt` in the past, renders to each recipient's filter context, dispatches to all channels in parallel, writes audit rows. Granularity: one minute.
- **`ScheduledExportJanitor`**: Deletes render artefacts after `retention.keepArtefactsForDays` and run-rows after `retention.keepAuditForDays`. Granularity: one hour.

Render artefacts are stored in the user's Nextcloud Files area under `Apps/MyDash/Exports/{exportId}/` so they appear in the standard file UI. Delivery spans four channels (email, Nextcloud Files, webhook, SFTP) with per-recipient signing secrets and encrypted credential vaulting. Failures retry with exponential backoff up to `maxAttempts` and surface in the UI with a "Retry" action.

## Capabilities

### New Capabilities

- **`scheduled-exports`**: Full lifecycle — create/read/update/delete scheduled exports, preview renders, run manually, view audit history, manage retention. Export editor sits inside the dashboard menu alongside Share and Bulk actions.

### Modified Capabilities

- **`dashboards` / `widgets`**: No changes to the widget data contract or dashboard rendering. Scheduled exports layer on top using the existing live OpenRegister query layer and server-side headless Chromium rendering.

## Impact

**Affected code:**

- New PHP service layer: `ScheduledExportService`, `RecurrenceResolver`, `RenderPipeline`, `DeliveryDispatcher`, `RetryHandler`, `AuditLog`.
- New background jobs: `ScheduledExportRunner`, `ScheduledExportJanitor`.
- New controller: `ScheduledExportController` handling CRUD, preview, manual run.
- New OpenRegister schemas (via `lib/Settings/mydash_register.json`).
- Frontend: export editor modal (list, create, edit, delete), run-history panel, preview modal.
- Configuration: retry policy defaults, retention window defaults.

**Affected APIs:**

- `POST /api/exports` — create export
- `GET /api/exports` — list visible exports
- `GET /api/exports/{id}` — read export
- `PATCH /api/exports/{id}` — update export
- `DELETE /api/exports/{id}` — soft-delete export
- `POST /api/exports/{id}/run` — trigger immediate run
- `POST /api/exports/{id}/preview` — render without dispatching
- `GET /api/exports/{id}/runs` — list audit rows
- `GET /api/exports/{id}/runs/{runId}` — read audit row
- `POST /api/exports/{id}/runs/{runId}/retry` — retry failed delivery

**Dependencies:**

- OpenRegister: objects, relations, schemas, retention-rule hooks.
- OpenConnector: optional routing of webhook/SFTP delivery through credential vault.
- Nextcloud mailer: email dispatch.
- Headless Chromium (Puppeteer/Playwright): PDF/PNG rendering.
- phpseclib: SFTP upload.

**Migration:**

- OpenRegister imports schemas from `lib/Settings/mydash_register.json` on install.
- Seed data includes 3–5 example exports per render-target and delivery-channel combination for QA.
- No custom schema migrations required; register lifecycle is generic.

## Notes

- Render outputs are not duplicated in OpenRegister; binaries live in Files only. Audit rows capture sha256 so compliance officers can verify delivery without retaining bytes after retention window.
- The render pipeline reuses the existing Vue component layer, live OpenRegister queries, and OpenConnector source resolver so on-screen rendering and scheduled-export PDF/PNG rendering produce identical output down to NL Design tokens and locale-aware formatting.
- DST and leap-day handling follow Quartz scheduler conventions (preserve wall-clock time across DST, fire once per missed fire window) rather than inverting cron semantics, aligned with what operators familiar with cron + anacron already expect.
- Webhook signing and SFTP host-key verification are mandatory for security-sensitive use cases (regulated data exchange). Signed payloads follow GitHub/Stripe convention (HMAC-SHA256 in `X-MyDash-Signature` header).
