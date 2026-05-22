# Design — Scheduled Exports

## Context

MyDash dashboards and widgets are designed for live, in-browser consumption: users open the app and read the numbers. Three common organisational patterns require pushing data out instead:

1. **Recurring management reports**: A weekly bezwaarschriften PDF sent to a wethouder's inbox every Monday at 09:00.
2. **Regulated data exchange**: A monthly CBS or VNG return in CSV/XLSX format that must land on an SFTP drop by a specific deadline or incur penalties.
3. **Cross-system snapshots**: A nightly PNG of a service-desk KPI dashboard posted to Teams so the standup starts from a shared picture.

Today, organisations work around this limitation by exporting dashboards manually, scheduling cron jobs that hit the MyDash API with brittle scraping logic, or maintaining separate reporting tools that duplicate data logic. Scheduled Exports closes this gap by making MyDash the source of truth for all three patterns.

## Goals / Non-Goals

**Goals:**

- Decouple rendering from delivery: one render produces multiple formats (PDF, PNG, CSV, XLSX) and can be fanned out to multiple channels (email, Files, webhook, SFTP) without re-rendering.
- Support per-recipient filtering: the same export can render once per gemeente and email each gemeente its filtered copy, reducing manual dashboard maintenance.
- Provide first-class failure handling: retries with exponential backoff, dead-letter visibility, and admin notifications.
- Capture compliance-grade audit trails: every run writes artefact hashes, delivery outcomes, and provider acknowledgement IDs so compliance officers can prove what was sent and when.
- Keep the UX simple for the common case: a friendly recurrence editor (every Monday 09:00) that hides cron complexity for the 95% case while preserving raw cron for power users.

**Non-Goals:**

- eIDAS or NTA 9087 digital signing (v2 integration with docudesk; v1 ships PDF/A-2b as the archival format).
- AI-powered scheduling suggestions based on dashboard access patterns.
- Custom render post-processing (cropping, watermarking, redaction) — that is the domain of docudesk.
- Scheduled export of arbitrary external data sources (only MyDash dashboards and widgets).

## Decisions

### D1: Recurrence vocabulary is cron + calendar

**Decision**: Support four recurrence types: `cron` (raw five-field expression for power users), `weekly` (BYDAY RFC 5545 semantics), `monthly` (BYMONTHDAY with last-day fallback), and `daily`.

**Rationale**: The common cases (every Monday 09:00, first of the month, every weekday at 17:00) are more discoverable through a friendly editor than asking users to write `0 9 * * 1` or `0 8 1 * *`. The cron escape hatch serves the 5% case (e.g., `0 2 * * 1-5` = 02:00 weekdays, for a report that skips weekends to save processing). Quartz scheduler convention (preserve wall-clock time across DST, fire once per missed fire window) aligns with what operators already expect.

**Alternatives considered:**

- RFC 5545 RRULE only (too verbose for non-calendar users; cron is the lingua franca for operators).
- UI-only picker with no power-user escape hatch (insufficient for edge cases).

### D2: Rendering decouples from delivery

**Decision**: A `render_target` produces one output format. A single fire produces one rendered artefact per unique filter context, which is then delivered to all recipients with matching filter contexts.

**Rationale**: Organisations deliver the same data via multiple channels (weekly PDF to email + a CSV to SFTP for archival). Rendering once and re-using across channels saves CPU and guarantees bit-identical delivery. Per-recipient filtering (município-level exports for a multi-municipality org) avoids maintaining 23 nearly-identical scheduled exports by hand.

**Alternatives considered:**

- One render per recipient-channel pair (simpler state machine but wasteful CPU and storage duplication).
- Render targets and recipients fully independent (higher flexibility but makes the UX overwhelming).

### D3: Render artefacts live in Files, audit rows in OpenRegister

**Decision**: Binary artefacts (PDF, PNG files) are written to the user's Nextcloud Files area under `Apps/MyDash/Exports/{exportId}/`. Audit rows (`scheduled_export_run` objects) live in OpenRegister alongside the export config, capturing artefact paths and delivery outcomes.

**Rationale**: Files integration gives users familiar drag-and-drop, sharing, and search without MyDash reinventing file management. OpenRegister integration gives compliance officers read-only access to audit trails through the standard permission model. Decoupling the binary storage from the audit metadata means retention policies can keep audit records forever while purging binary files after e.g. 14 days (per compliance requirements).

**Alternatives considered:**

- Store binaries in OpenRegister as base64-encoded blobs (violates ADR-001 pattern of not embedding large media in register objects).
- Store audit rows in a custom database table (no standard permission model or soft-delete lifecycle).

### D4: Delivery channels are atomic per channel, retries are per recipient

**Decision**: A delivery attempt targets one recipient on one channel. Retries are scoped per recipient. If recipient A's webhook fails and recipient B's email succeeds, both outcomes are captured in the same run-row with distinct retry counts.

**Rationale**: Different channels have different failure semantics (MTA temporary unavailability vs. credential expiry vs. network timeout). Atomic per-channel allows fine-grained retry policies and error classification without coupling unrelated channels. Per-recipient retry state lets a transient email failure not block the webhook retry.

**Alternatives considered:**

- One monolithic retry for the entire run (too coarse; one flaky recipient blocks retries for all others).
- No retries, dead-letter failures only (ignores transient MTA/network issues common in production).

### D5: Credentials are vaulted in OpenConnector when available

**Decision**: SFTP and webhook `channelConfig` fields can reference an `openconnectorSourceId` instead of storing credentials locally. When set, dispatch routes through OpenConnector's vault and call-log.

**Rationale**: Centralised credential management (OpenConnector vault) eliminates duplicate credential storage and gives organisations a single place to rotate SFTP/API keys. OpenConnector's call-log provides audit trails of what was sent to external systems. Local credential fallback supports small orgs that do not run OpenConnector.

**Alternatives considered:**

- OpenConnector only, no local fallback (breaks offline/disconnected deployments).
- Local credential storage only (duplicate vaults, credential sprawl).

### D6: Janitor enforcement via OpenRegister retention-rule hooks

**Decision**: Deletion of old artefacts and audit rows is implemented via OpenRegister's `retention-rule` hooks rather than a custom purge loop in the ScheduledExportJanitor.

**Rationale**: OpenRegister already manages object lifecycle (soft-delete, permanent deletion) and has tested edge cases (concurrent reads, long-running purges, cascading deletes). Reusing those hooks via the register's hook API keeps the janitor simple (register-aware, not custom-SQL).

**Alternatives considered:**

- Custom SQL in the janitor (reimplements OpenRegister purge logic, harder to test and maintain).

### D7: Manual "Run now" and "Preview" are first-class actions

**Decision**: The export editor includes a "Run now" button (immediate fire, writes run-row with `triggeredBy=manual`) and a "Preview" action (render without dispatch, returns output in response).

**Rationale**: Dashboard authors need to validate a new export works before relying on it for a scheduled report. Preview lets them see the rendered PDF without flooding a recipient's inbox. Manual run is useful for testing retry logic or re-running a missed schedule after a system outage.

**Alternatives considered:**

- Preview only (users must wait until the next scheduled fire to validate; hurts confidence).
- Run now only, no preview (no way to validate output without sending it to recipients).

## Seed Data

The `lib/Settings/mydash_register.json` import includes three example scheduled exports:

1. **Weekly Bezwaren Report (PDF)**
   - Subject: dashboard named "Bezwaarschriften Overzicht"
   - Recurrence: every Monday at 09:00 Europe/Amsterdam
   - Render target: PDF, A4, portrait, with header/footer
   - Recipient: email to `bezwaren@gemeente.nl`
   - Retention: keep artefacts 30 days (default), audit 365 days
   - Filter context: none (renders all data)

2. **Monthly Compliance Return (XLSX)**
   - Subject: dashboard "CBS Retourmeldingen"
   - Recurrence: monthly on day 1 at 08:00
   - Render targets: XLSX with one sheet per widget
   - Recipients: (a) SFTP to `sftp.cbs.nl:/dropbox/gemeente-0355/` with host-key verification, (b) email to `compliance-officer@gemeente.nl`
   - Retention: keep both artefacts and audit indefinitely (compliance record)
   - Filter context: `gemeenteCode: 'GM0355'` for SFTP, none for email

3. **Daily Service Desk Snapshot (PNG)**
   - Subject: widget "Service Desk KPIs"
   - Recurrence: every weekday at 08:00
   - Render target: PNG, 1920×1080, 96 DPI
   - Recipient: webhook to `https://teams.microsoft.com/hooks/...` (signed with HMAC-SHA256)
   - Retention: keep artefacts 7 days (daily snapshots pile up fast), audit 30 days
   - Filter context: none

All seed objects use realistic Dutch values (gemeente codes per BAG, valid timestamps in Europe/Amsterdam timezone, real email domains and SFTP hosts).

## Reuse Analysis

This change leverages existing OpenRegister abstractions per ADR-001:

- **Object CRUD & lifecycle**: Use `ObjectService::saveObject()`, `findAll()`, `deleteObject()` — no custom Entity/Mapper.
- **Relations**: Cross-references between `scheduled_export` → `dashboard`/`widget` via register relations.
- **Permissions & RBAC**: Export ownership and sharing use the standard dashboard/widget permission model (OpenRegister enforces object-level read/write).
- **Retention policies**: `OpenRegister\Hook\RetentionRuleInterface` drives automated purging of old run-rows and artefact cleanup.
- **Audit logging**: Run-rows are the audit records (captured via standard register audit hooks, not custom logging).
- **Frontend CRUD UI**: Reuse `CnFormDialog` for export editor, `CnDataTable` for run-history list, `CnDetailPage` for audit-row details.
- **i18n**: Register schema descriptions and UI strings via existing company-wide i18n setup (ADR-025).
- **OpenConnector integration**: Optional credential vaulting via `openconnectorSourceId` references in recipient config.

No duplication detected. All core abstractions (CRUD, permissions, retention, audit) are already provided by OpenRegister / @conduction/nextcloud-vue. This change adds domain-specific orchestration (recurrence resolution, render pipeline, delivery dispatch) and opt-in integrations (OpenConnector vault, headless rendering).

## Risks / Trade-offs

**Risk: Headless Chromium resource consumption**
- **Severity**: Medium. Rendering a large dashboard to PDF in headless Chromium consumes CPU and memory.
- **Mitigation**: Render jobs are queued and fire one at a time (no parallel rendering on the same instance). Organisations running many large exports can scale Chromium pool horizontally via container orchestration (ADR-013 Container Pool). Rendering happens off the critical path (background job, not user request).

**Risk: DST ambiguity in recurrence**
- **Severity**: Low. Spring-forward (missing hour) or fall-back (repeated hour) can cause a scheduled fire to land at an unexpected wall-clock time if not carefully handled.
- **Mitigation**: Quartz scheduler semantics (preserve wall-clock time, fire once per missed slot) are explicitly documented. The recurrence resolver is covered by scenario tests for DST transitions (spring 2026 and fall 2026 in test matrix).

**Risk: Retention enforcement race conditions**
- **Severity**: Low. If a run-row is being read while the janitor deletes it, the read may fail.
- **Mitigation**: Use OpenRegister's soft-delete semantics (mark as deleted, then hard-delete after a grace period). Reads filter out soft-deleted rows automatically. Hard-delete happens in a separate janitor pass.

**Risk: Retry storms if webhook receiver is temporarily unavailable**
- **Severity**: Low. Exponential backoff with jitter mitigates; max backoff caps at 1 hour, max attempts at 5.
- **Mitigation**: Dead-letter queue surfaces permanently-failed recipients in the UI. Admins can adjust `maxAttempts` and `maxBackoffSeconds` per recipient if the default is too aggressive for their environment.

**Trade-off: Per-recipient filtering adds complexity to the render pipeline**
- Rendering N recipients with distinct filter contexts requires N separate render passes. This is justified by the use case (multi-gemeente orgs), but it is slower than a single global render. Organisations with a few recipients see negligible impact.

**Trade-off: Compliance audit trails are permanent but artefact retention is configurable**
- An organisation might purge a monthly PDF after 30 days but keep the sha256 audit record for 365 days. This is intentional (archivist cares about proof, not bytes) but requires discipline in retention-schedule documentation.

## Migration Plan

1. **Schemas + register import** — add `lib/Settings/mydash_register.json` with four schemas (scheduled_export, render_target, recipient, scheduled_export_run) and seed data. Repair step calls `ConfigurationService::importFromApp()`.
2. **Backend services** — implement `ScheduledExportService`, `RecurrenceResolver`, `RenderPipeline`, `DeliveryDispatcher`, `AuditLog` with unit test coverage per ADR-008.
3. **Background jobs** — register `ScheduledExportRunner` (every minute) and `ScheduledExportJanitor` (every hour) via Nextcloud job abstraction.
4. **Controller + routes** — add `ScheduledExportController` with 11 endpoints (CRUD, run, preview, audit, retry); register in `appinfo/routes.php`.
5. **Frontend** — build export-editor modal (list/create/edit/delete), run-history panel, preview modal. Integrate into dashboard menu.
6. **Hydra gates** — ensure all PHP has SPDX docblocks, no orphaned auth checks, no inline modals.
7. **i18n** — add `nl_NL` + `en_US` translations for all UI strings and schema descriptions.
8. **Integration tests** — Playwright covering the full workflow: create export → validate recurrence → manual run → preview → check run-row.
9. **Rollback** — pure code change, no data migrations. Removing the PR disables the feature cleanly; existing exports are soft-deleted if the export register is removed.

## Open Questions

1. **Should the render pipeline support concurrent rendering across multiple Chromium instances?** Current decision: no (single queue). Revisit if rendering becomes a bottleneck (scale via container orchestration, not in-app concurrency).
2. **Should preview output be streamed (for large PDFs) or buffered?** Current decision: buffer in memory (simpler API, fine for typical dashboard sizes). If preview frequently times out, switch to streaming.
3. **Should failed deliveries notify the export owner or an admin group?** Current decision: both (owner is responsible for their export, admins troubleshoot systemic issues). Configurable per recipient.
