# Tasks — scheduled-exports

## Tasks

### Data Model & Services

- [ ] Task 1: Add OpenRegister schemas to `lib/Settings/mydash_register.json`: `scheduled_export`, `render_target`, `recipient`, `scheduled_export_run` with all fields per context-brief.md (including recurrence discriminator as embedded object)
- [ ] Task 2: Implement `RecurrenceResolver` service class computing `nextRunAt` from recurrence object and `runTimezone`, respecting DST (spring-forward / fall-back) and leap-day edge cases; covers all four types (`weekly`, `monthly`, `daily`, `cron`)
- [ ] Task 3: Implement `ScheduledExportService` with methods: `findVisible($userId)`, `findOne($id, $userId)`, `save($export)`, `delete($id, $userId)`, `setEnabled($id, $enabled)` — delegate object CRUD to OpenRegister `ObjectService`, not custom Entity/Mapper
- [ ] Task 4: Implement `RenderPipeline` service class with method `render($export, $filterContext): RenderResult[]` handling PDF (headless Chromium, A4 portrait/landscape), PNG (viewport size, DPI), CSV (tabular data extraction), XLSX (per-widget sheets); returns array of `{format, sizeBytes, sha256, storagePath}`
- [ ] Task 5: Implement `DeliveryDispatcher` service class with method `dispatch($export, $artefacts, $recipients): DeliveryResult[]` handling email (Nextcloud mailer), nextcloudFiles (Files API), webhook (signed POST), SFTP (phpseclib) — each delivery attempt writes immediately to audit row
- [ ] Task 6: Implement `RetryHandler` service class with methods `scheduleRetry($deliveryId, $retryPolicy)` and `retryDelivery($deliveryId): DeliveryResult` — exponential backoff with jitter, cap at `maxBackoffSeconds`, mark permanent-failed after `maxAttempts`
- [ ] Task 7: Implement `AuditLog` service class persisting `scheduled_export_run` rows via OpenRegister `ObjectService` with `renderArtefacts` array (format, sha256, path) and `deliveries` array (recipientId, channel, attemptCount, status, error)

### Background Jobs

- [ ] Task 8: Implement `OCA\MyDash\BackgroundJob\ScheduledExportRunner` registered at one-minute granularity; fetch all enabled exports with `nextRunAt <= now()`, check for overlapping previous run (skip if running), invoke render → dispatch → audit, recompute `nextRunAt`, handle disabled creator with admin notification
- [ ] Task 9: Implement `OCA\MyDash\BackgroundJob\ScheduledExportJanitor` registered at one-hour granularity; delete `renderArtefacts` files after `retention.keepArtefactsForDays` (null = forever), null out `storagePath` in run-rows, delete entire run-rows after `retention.keepAuditForDays` via OpenRegister retention hooks

### Controller & Routes

- [ ] Task 10: Implement `ScheduledExportController` with endpoints:
  - `GET /api/exports` → list visible exports with pagination/sorting (delegate to ObjectService)
  - `POST /api/exports` → create export with required fields; validate recurrence; returns 201 with new UUID
  - `GET /api/exports/{id}` → read single export; 404 if not visible
  - `PATCH /api/exports/{id}` → update export fields; recompute `nextRunAt` if recurrence changed
  - `DELETE /api/exports/{id}` → soft-delete (OpenRegister standard soft-delete semantics)
  - `POST /api/exports/{id}/run` → manual run (immediate execution, trigger by user, write `triggeredBy=manual` + `triggeredByUser`)
  - `POST /api/exports/{id}/preview` → render for chosen recipient without dispatch, stream response for binary formats
  - `GET /api/exports/{id}/runs` → list run-rows for audit history with pagination
  - `GET /api/exports/{id}/runs/{runId}` → read single run-row details including all deliveries
  - `POST /api/exports/{id}/runs/{runId}/retry` → retry all failed deliveries in that run
  - All endpoints require `#[NoAdminRequired]` where applicable; permission checks via OpenRegister (export must be visible to user)
- [ ] Task 11: Register all 11 routes in `appinfo/routes.php`

### Frontend

- [ ] Task 12: Create export-editor modal component (`src/modals/ScheduledExportModal.vue`) with form sections: basic (name, description, subject picker), recurrence (weekly/monthly/daily/cron picker with friendly UI), render targets (add/remove targets, format options per format), recipients (add/remove, channel picker, filter context editor, channel config forms)
- [ ] Task 13: Create export-list page with `CnDataTable` showing: name, subject, next-run timestamp, last-run status (icon), actions (edit, delete, "Run now", "View runs"). Integrate into dashboard menu as "Scheduled Exports" action.
- [ ] Task 14: Create run-history panel showing list of past fires with columns: triggered-at, status (success/partial/failed), recipient count, delivery outcomes summary. Clicking a run opens detail modal.
- [ ] Task 15: Create run-detail modal (`src/modals/RunDetailModal.vue`) showing: export name, fire timestamp, status, list of deliveries (per-recipient channel, status, attempt count, error if failed), list of render artefacts (format, size, download link), "Retry" button if any delivery failed
- [ ] Task 16: Create preview modal (`src/modals/PreviewModal.vue`) accepting render format choice (pdf/png/csv/xlsx) and recipient selection, displaying render output (PDF/PNG embedded, CSV/XLSX as download link)
- [ ] Task 17: Frontend store action `useExportsStore.runExportNow(exportId)` POSTs to `/api/exports/{id}/run` and polls run-status until completion (show progress toast)
- [ ] Task 18: Frontend store action `useExportsStore.previewExport(exportId, recipientId, format)` POSTs to `/api/exports/{id}/preview` and returns streaming response
- [ ] Task 19: Add "Scheduled Exports" menu item to dashboard toolbar (alongside Share, Bulk, Comments); clicking opens export-list modal

### Testing

- [ ] Task 20: PHPUnit — `RecurrenceResolver` table-driven tests for all four recurrence types, DST edge cases (spring 2026, fall 2026), leap-day (Feb 28→29→1), cron parsing
- [ ] Task 21: PHPUnit — `ScheduledExportService` covering CRUD, permission filtering (only exports visible to user), soft-delete, enable/disable toggling
- [ ] Task 22: PHPUnit — `RenderPipeline` mocking Chromium renderer, testing all four formats (PDF page size/orientation, PNG viewport DPI, CSV headers/rows, XLSX per-widget sheets)
- [ ] Task 23: PHPUnit — `DeliveryDispatcher` mocking email/Files/webhook/SFTP channels, verifying signed webhook headers, redacted SFTP password responses, per-recipient filter context applied
- [ ] Task 24: PHPUnit — `RetryHandler` exponential backoff timing, max-attempts rollover to permanent-failed, jitter application
- [ ] Task 25: PHPUnit — `ScheduledExportRunner` background job behavior: fires enabled exports with `nextRunAt <= now()`, skips overlapping previous run, marks disabled creator as failed with admin notification, recomputes `nextRunAt` post-fire
- [ ] Task 26: PHPUnit — `ScheduledExportJanitor` retention enforcement: deletes artefacts after `keepArtefactsForDays`, deletes run-rows after `keepAuditForDays`, handles null (forever) retention
- [ ] Task 27: Playwright — full workflow: create export with weekly recurrence → save → manual "Run now" → check run-row appears in history → preview render (PDF) → edit export → toggle enabled off → verify next fire is skipped
- [ ] Task 28: Playwright — filter context workflow: create export with two recipients (gemeente A, gemeente B) with distinct `filterContext` → manual run → verify two renders in audit row → verify each recipient gets its filtered version
- [ ] Task 29: Playwright — failure + retry: create webhook recipient with bad URL → manual run → verify delivery marked failed → click "Retry" → verify retry scheduled → advance time → verify successful delivery on retry
- [ ] Task 30: Playwright — retention enforcement: create export with 14-day artefact retention → fire now → advance time 15 days → check janitor purges artefacts but retains audit metadata

### Quality Gates & Documentation

- [ ] Task 31: Composer checks: `composer check:strict`, `composer check:lint` — no errors
- [ ] Task 32: ESLint + Stylelint on all `.vue` and `.js` files — green
- [ ] Task 33: SPDX docblock on every PHP class/method per ADR-005 (`@license AGPL-3.0-or-later`, `@copyright ...`)
- [ ] Task 34: Hydra gates green: `hydra-gate-spdx`, `hydra-gate-modal-isolation` (export modals live in `src/modals/`, not inlined), `hydra-gate-route-auth` (all routes have permission checks)
- [ ] Task 35: OpenAPI schema generation for new 11 routes (Newman/Postman regen)
- [ ] Task 36: i18n translations `nl_NL` + `en_US` for: UI strings (menu items, button labels, form placeholders), validation messages, admin notifications, schema descriptions
- [ ] Task 37: Update `CHANGELOG.md` with feature summary: "Scheduled Exports v1 — recurring exports with multi-format rendering and multi-channel delivery (email, Files, webhook, SFTP)"
- [ ] Task 38: Document in `design.md` why stale recurrence preferences are skipped per-request rather than via background job (DST/leap-day edge cases are transient; recompute on every read for consistency)

## Verification

`openspec validate` exits clean. All 10 REQ-SCH requirements green in test matrix. Run-rows audit trail captures correct sha256 and delivery outcomes. Retention enforcement purges old artefacts and audit rows per policy.

## Tests (company-wide ADR-008)

PHPUnit per Tasks 20–26. Playwright per Tasks 27–30. All tests must pass CI before merge.

## Documentation (company-wide ADR-009)

Changelog entry (Task 37). Public docs (out of scope for OpenSpec; docudesk team owns user-facing guides).

## i18n (company-wide ADR-025)

`nl_NL` and `en_US` translations per Task 36.

## Seed Data (company-wide ADR-001)

Three realistic example exports per design.md Seed Data section, loaded via `lib/Settings/mydash_register.json` on install.
