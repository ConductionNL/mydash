# Tasks — mydash-ai-dashboard-assistant

## Data Model

- [ ] Task 1: Create or extend `Case` schema in `lib/Settings/mydash_register.json` with properties: `identifier` (string, required), `status` (enum: open|in_progress|pending|closed|archived|rejected), `assignee` (relation to OR Contact), `createdBy` (uid), `subject` (string), `description` (text), `lastUpdated` (datetime, auto-set by OR), `dueDate` (datetime, optional)
- [ ] Task 2: Create or extend `Consultation` schema in `lib/Settings/mydash_register.json` with properties: `title` (string, required), `status` (enum: open|in_progress|closed), `owner` (relation to OR Organization), `createdBy` (uid), `description` (text), `deadline` (datetime, required), `totalResponses` (integer, read-only, computed), `responseBreakdown` (object: `{agree: int, neutral: int, disagree: int}`, read-only, computed from Responses schema)
- [ ] Task 3: Deduplication check — grep for existing Case/Consultation usage in `openregister/lib/` and `lib/Service/` across all Conduction apps; document findings in a comment; if Case/Consultation already exist in OR, reuse their schemas and register in MyDash via relation to OR's registers
- [ ] Task 4: Load seed data on app install — create `lib/Settings/mydash_register.json` with mock objects section containing 3 cases (example states: open, in_progress, overdue) and 2 consultations (active with responses, one closed); import via repair step using `ConfigurationService::importFromApp()` with `force: false` to skip on re-installs

## Backend Services

- [ ] Task 5: Create `lib/Service/SummaryService.php` — stateless service for AI-powered summarization; constructor injects `IAppConfig`, `LoggerInterface`, and `ChatService` (optional, graceful if missing); public methods: `generateCaseSummary(array $cases, IUser $user): string` (returns narrative paragraph) and `generateConsultationSummary(array $consultations): string`
- [ ] Task 6: In `SummaryService::generateCaseSummary()`, call ChatService with prompt: "Summarize these open cases for a case worker. Include total count, status metrics, and 1-2 priority actions." with the case data as context. Wrap in try/catch; return fallback text "Summary unavailable" on any error (auth, quota, timeout). Log errors at `warn` level (not `error` — expected when quota exhausted)
- [ ] Task 7: In `SummaryService::generateConsultationSummary()`, call ChatService with prompt: "Summarize consultation feedback. Include overall sentiment, key themes, and engagement level." Return fallback "Summary unavailable" on error. Ensure output is a single paragraph, not bullet points
- [ ] Task 8: Create `lib/Controller/WidgetController.php` with `#[NoAdminRequired]` GET endpoints: `casesAction(): JSONResponse` and `consultationsAction(): JSONResponse`. Each endpoint: (1) fetches authenticated user, (2) calls `ObjectService::findObjects('MyDash', 'Case'/'Consultation', ['limit' => 999, '_rbac' => true])` to get user-scoped objects, (3) calls `SummaryService` for AI summary, (4) caches result in APCu with 5-min TTL and key `mydash_summary_{userId}_{type}`, (5) returns JSON with: `{count: int, breakdown: object, summary: string, lastUpdated: datetime}`
- [ ] Task 9: Authorization enforcement — add `#[AuthorizedAdminSetting(Application::APP_ID)]` to any settings-write endpoints (future); verify all `#[NoAdminRequired]` endpoints in WidgetController call per-object auth checks (in this case, OR's RBAC is the check; document this in a PHPDoc comment above the method)
- [ ] Task 10: Spec traceability — add `@spec openspec/changes/mydash-ai-dashboard-assistant/tasks.md#task-{N}` PHPDoc tags to `SummaryService` class (file-level) and both controller methods; reference the specific tasks they implement
- [ ] Task 11: SPDX headers — add `// SPDX-License-Identifier: EUPL-1.2` to all new PHP files (`SummaryService.php`, `WidgetController.php`); verify `appinfo/info.xml` declares `<licence>agpl</licence>` (ADR-014)
- [ ] Task 12: PHPUnit tests for `SummaryService` — test `generateCaseSummary()` with mock case data (5 cases, mixed statuses); verify summary includes count and priority action. Test graceful degradation: mock ChatService to throw exception, verify fallback text returned. Test `generateConsultationSummary()` with 45 responses; verify summary is a single paragraph
- [ ] Task 13: Integration tests (curl / Newman) — POST to `GET /api/widgets/cases-summary` with valid auth, verify `{count: 5, breakdown: {...}, summary: string}` response. Test `GET /api/widgets/consultation-summary`. Test error handling: call endpoint with no auth (expect 401), with expired token (expect 401)

## Frontend Components

- [ ] Task 14: Create `src/components/CasesSummaryWidget.vue` — Vue 2 Options API component; use `CnWidgetWrapper` as root; render: (1) KPI card with total count (large number), (2) status breakdown bar chart using `CnChartWidget` (bar type), (3) AI summary paragraph, (4) "Last updated" timestamp, (5) "View all cases" link. Props: none (fetch own data on mount)
- [ ] Task 15: In `CasesSummaryWidget.vue`, call `GET /api/widgets/cases-summary` on component mount via `axios` from `@nextcloud/axios`; store response in `data.summary`; wrap call in `try/catch` with error messaging to user. Loading state: show `NcLoadingIcon` while fetching; empty state: show "No open cases" if `count === 0`
- [ ] Task 16: In `CasesSummaryWidget.vue`, render status breakdown as a horizontal bar chart via `CnChartWidget` with type `bar`; data series: `[{name: 'Open', value: breakdown.open}, {name: 'In Progress', value: breakdown.in_progress}, {name: 'Pending', value: breakdown.pending}]`. Use NL Design System colors: green for open, yellow for in_progress, orange for pending
- [ ] Task 17: In `CasesSummaryWidget.vue`, render the AI summary as a paragraph inside the widget. If summary is "Summary unavailable", show a subtle message with icon. Add a "Refresh" button in the widget header (use `header-actions` slot per ADR-018) to manually fetch fresh data
- [ ] Task 18: Create `src/components/ConsultationSummaryWidget.vue` — Vue 2 Options API; render: (1) list of active consultations with response counts, (2) per-consultation response breakdown (stacked progress bar or pie), (3) AI-generated sentiment summary, (4) "Deadline soon" badges for consultations due within 7 days, (5) "View responses" link per consultation
- [ ] Task 19: In `ConsultationSummaryWidget.vue`, call `GET /api/widgets/consultation-summary` on mount; render consultation list with response counts; for each consultation, compute percentage breakdown and render as stacked progress bar (or use `CnChartWidget` with pie type)
- [ ] Task 20: In `ConsultationSummaryWidget.vue`, display deadline warnings — iterate consultations, compute days until deadline, show badge if `days < 7`. Use NL Design System alert color for the badge
- [ ] Task 21: Add i18n translations to `l10n/en.json` and `l10n/nl.json`: "Open cases", "In progress", "Pending", "No open cases", "View all cases", "Summary unavailable", "Active consultations", "View responses", "Deadline soon", "Last updated {time}". Use sentence case (ADR-007)
- [ ] Task 22: Register both widgets in the MyDash widget registry (dashboard config or Vue store) so they appear in the "Add widget" menu. Verify widgets appear in the grid, are draggable, and resize correctly in edit mode
- [ ] Task 23: Styling — use CSS custom properties from NL Design System tokens (var(--color-primary-element), var(--color-success), etc.). Add `scoped` attribute to `<style>` blocks. Verify responsive layout at 320px, 768px, 1440px viewports. Test theme switching via nldesign app

## Component Composition & Integration

- [ ] Task 24: Component composition check — verify `CasesSummaryWidget.vue` and `ConsultationSummaryWidget.vue` do NOT wrap self-contained components (like `CnChartWidget`) in additional `CnDetailCard` containers (ADR-017). Use `CnWidgetWrapper` at the root; render chart and summary directly inside
- [ ] Task 25: Pinia store integration — register both widget data sources in the object store if needed, or fetch directly via API on component mount. If using `createObjectStore`, name types `cases-summary` and `consultations-summary` (kebab-case per ADR-015)
- [ ] Task 26: Deduplication check for components — grep `src/` for existing summary widgets or case/consultation components; if similar components exist, extend them rather than creating duplicates. Document findings in a code comment

## Testing & QA

- [ ] Task 27: Browser test (Playwright) — "User views case summary on dashboard" — load dashboard, verify CasesSummaryWidget appears, displays count + breakdown + summary (or "unavailable"), click "View all cases", navigate to cases list, back button returns to dashboard
- [ ] Task 28: Browser test (Playwright) — "User views consultation summary on dashboard" — load dashboard, verify ConsultationSummaryWidget appears, displays consultation list with response counts, deadline badges visible where applicable, click "View responses", navigate to response detail, back button works
- [ ] Task 29: Browser test (Playwright) — "Graceful degradation when API is down" — mock the widget API endpoint to return 503, load dashboard, verify both widgets display empty/fallback state (metrics only, no summary), no error toast to user
- [ ] Task 30: Performance test — verify widget API responses are cached: load dashboard, check network tab for API call (1 call). Refresh page within 5 minutes, verify NO new API call (cached). Wait 5+ minutes, refresh again, verify new API call fires
- [ ] Task 31: Permission test — create two users with different OpenRegister object permissions; log in as user A, verify only user A's cases appear in summary; log in as user B, verify user B's cases appear (different count). Verify no cross-user data leak
- [ ] Task 32: i18n smoke test — switch Nextcloud language to Dutch (nl), load dashboard, verify all widget text is in Dutch (from `l10n/nl.json`). Switch to English, verify English text. Verify dates/numbers format correctly per locale

## Quality Gates & Documentation

- [ ] Task 33: SPDX compliance — run `reuse lint` or grep for missing headers: `grep -rL 'SPDX-License-Identifier' src/ lib/ --include='*.php' --include='*.vue' --include='*.js'`. Add headers to any file missing one. Verify `appinfo/info.xml` has `<licence>agpl</licence>`
- [ ] Task 34: Pre-commit checks — run `composer check:strict` (PHPCS + PHPStan + Psalm); run `npm run lint` (ESLint). Verify no new violations introduced. All tests pass: `composer test` (PHPUnit) + Playwright suite
- [ ] Task 35: Spec traceability verification — grep for `@spec openspec/changes/mydash-ai-dashboard-assistant` in all new/modified PHP files and Vue components; verify every class/method has a `@spec` tag referencing a task in tasks.md
- [ ] Task 36: API documentation — verify endpoints are documented or self-evident: `GET /api/widgets/cases-summary` returns `{count, breakdown, summary, lastUpdated}`, `GET /api/widgets/consultation-summary` returns `{consultations: [{id, title, responses, responseBreakdown, deadline, daysUntilDeadline, summary}]}`. Add OpenAPI spec or inline docblock comments
- [ ] Task 37: User-facing documentation — add section to `docs/widgets.md` or `docs/README.md` explaining the two new widgets: what they display, how to interpret the summary, how to refresh data, and what to do if "Summary unavailable" appears. Include a screenshot of each widget on the dashboard
- [ ] Task 38: Changelog entry — document the new widgets in `CHANGELOG.md` or `docs/RELEASES.md`: "Add Cases Summary and Consultation Summary dashboard widgets with AI-powered summarization"

## Seed Data & Installation

- [ ] Task 39: Seed data generation task — on app install (repair step), call `SettingsLoadService::importFromApp('mydash', 'mydash_register.json', '1.0.0', force: false)` to load the 3 example cases + 2 example consultations into OR. Verify idempotency: re-installing the app does not create duplicate objects (matched by `slug` field in `@self` envelope)
- [ ] Task 40: Verify installation flow — clean install of mydash, verify seed data loads, view dashboard, both widgets appear and display the example data (counts + summaries based on mock data)

## Verification

`openspec validate` exits clean. Dashboard loads without errors. Both widgets display case and consultation summaries with graceful fallback to metrics-only when AI is unavailable. User permissions are respected; cross-user data leaks are prevented. All tests pass: PHPUnit, ESLint, Playwright. REUSE compliance verified. Spec traceability tags present on all code units.

## Tests (company-wide ADR-008)

PHPUnit for `SummaryService` (3+ methods tested, including error paths). Integration tests (curl) for API endpoints (happy path + error cases: 401, 503). Playwright browser tests for widget rendering, caching, permission filtering, and graceful degradation. Performance tests verify 5-min cache is active.

## Documentation (company-wide ADR-009)

Inline PHPDoc and component comments linking to this spec. User-facing docs in `docs/widgets.md` with screenshots. Changelog entry. API endpoint behavior documented via docblocks or OpenAPI.

## i18n (company-wide ADR-007)

All user-visible strings translated: English keys in `l10n/en.json`, Dutch translations in `l10n/nl.json`. Sentence case enforced. Zero missing keys between the two files. Locale-aware date/number formatting via Nextcloud core.
