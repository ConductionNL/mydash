# Tasks — mydash-file-access-widget

## Tasks

- [ ] Task 1: **Backend — FileAccessService** — Create `lib/Service/FileAccessService.php` with methods: `getFilesForDossier(string $dossierId, IUser $user): array` fetching all files linked to the dossier via OpenRegister relations + filtering via `PropertyRbacHandler` to apply per-file read permissions. Return array of `{id, name, size, type, modifiedAt, register, schema, objectId}`. Add PHPDoc linking to `openspec/changes/mydash-file-access-widget/tasks.md#task-1` per ADR-003.

- [ ] Task 2: **Backend — FileAccessController** — Create `lib/Controller/FileAccessController.php` with `list(string $dossierId): JSONResponse` method. Inject `IUserSession`, `FileAccessService`, and `IGroupManager`. Verify user is logged in (401 if not). Fetch dossier from OpenRegister (403 if not found or user has no read access). Call `FileAccessService::getFilesForDossier()`. Return `{files: [...], total: N}`. Add `#[NoAdminRequired]` attribute (endpoint is for any logged-in user). Implement per-dossier authorization check before returning files (ADR-005 Rule 3). Add route to `appinfo/routes.php`: `get` + `/api/file-access/list` → `FileAccessController#list`. Add `@spec` tag to class and method.

- [ ] Task 3: **Backend — Error handling and logging** — Wrap `FileAccessService` calls in try/catch. On exception, return generic `{message: 'Operation failed'}` (never return `$e->getMessage()` per ADR-015). Log real error with `$this->logger->error()`. Return HTTP 500 on unexpected error, 403 on authorization failure. Verify no stack traces or internal paths leak to the response.

- [ ] Task 4: **Backend — PHPUnit tests** — Create `tests/Unit/Service/FileAccessServiceTest.php` covering: (a) returns only readable files (RBAC filter applied), (b) empty array when dossier has no files, (c) empty array when user has zero permissions on all files, (d) throws exception on missing dossier (caught + handled by controller). Create `tests/Unit/Controller/FileAccessControllerTest.php` covering: (a) happy path returns filtered file list, (b) 401 when user not logged in, (c) 403 when user cannot read dossier, (d) 500 on service exception. Use env variable placeholders for test data (no hardcoded credentials).

- [ ] Task 5: **Frontend — useFileAccess composable** — Create `src/composables/useFileAccess.js` exporting `useFileAccess()` composable. Manage state: `{loading, error, files, retry()}`. On mount, call `axios.get('/apps/mydash/api/file-access/list', {params: {dossierId}})`. On success, populate `files` array. On error (500/503), set `error.message` and expose `retry()` method (resets `error`, retries fetch). Handle network timeout with exponential backoff (cap at 30s). Never throw on API errors — always set state so the component can display gracefully.

- [ ] Task 6: **Frontend — Pinia filesPlugin** — Create `src/store/plugins/filesPlugin.js` extending the files store. Export `fetchDossierFiles(dossierId)` action that wraps `useFileAccess()`. Store result in Pinia state. Implement getters: `getDossierFiles(dossierId)`, `getFilesLoading()`, `getFilesError()`. The plugin integrates with `createObjectStore()` so dashboard components can call `store.fetchDossierFiles(dossierId)` in one line.

- [ ] Task 7: **Frontend — DashboardFileAccessWidget component** — Create `src/components/DashboardFileAccessWidget.vue` as a GridStack-compatible widget. Template structure: `<div class="mydash-file-widget">` → `<CnDetailCard :title="t('mydash', 'Files')">` → file list table (or `<NcEmptyContent>` if empty/error). Props: `dossierId` (reactive), `size: {w, h}` (grid position). On `dossierId` change, call `store.fetchDossierFiles()`. Bind to composable state: `loading`, `error`, `files`. On error, show button `:click="() => store.fetchDossierFiles(dossierId)"` to retry. Use `axios.defaults.headers.common['requesttoken']` (auto-attached by `@nextcloud/axios`) for CSRF. No manual fetch; always go through store.

- [ ] Task 8: **Frontend — File list rendering** — Inside `CnDetailCard` body, render a table/list: columns = `[filename, type, size, modifiedAt, actions]`. Each row is a file. "Open" action is an `<a>` tag with `href` pointing to the file via `FileService.getFileUrl(file.objectId)` or equivalent Nextcloud download endpoint. Set `target="_blank"` so file opens in new tab. Use responsive breakpoints (320px = name+size only, 768px = name+type+size, 1024px+ = full table). Wrap in Nextcloud's `NcEmptyContent` for "No files" and error states.

- [ ] Task 9: **Frontend — i18n — English** — Add translation keys to `l10n/en.json`: `"Files"` (card title), `"No files"` (empty state), `"Failed to load files"` (error state), `"Retry"` (button), `"File name"`, `"File type"`, `"Size"`, `"Modified"`, `"Open"` (link/button text). All in sentence case per ADR-007. Verify no hardcoded strings remain in the template.

- [ ] Task 10: **Frontend — i18n — Dutch** — Add Dutch translations to `l10n/nl.json` with identical keys (same as English). Example: `"Files"` → `"Bestanden"`, `"No files"` → `"Geen bestanden"`. Keys must match exactly between en.json and nl.json (ADR-007 requirement).

- [ ] Task 11: **Frontend — Responsive design** — Apply NL Design System CSS custom properties throughout (`--color-primary-element`, `--color-text`, `--space-s`, `--space-m`, etc.). Use media queries for responsive layout (320px, 768px, 1024px breakpoints). Use `scoped` attribute on `<style>` block per ADR-010. Test in browser at 320px (mobile), 768px (tablet), 1920px (desktop). Verify no hardcoded colors or spacing values. Test dark mode support (CSS variables should adapt automatically).

- [ ] Task 12: **Frontend — Dashboard widget registration** — In the main dashboard page component or widget-list registration, add the file-access widget to the GridStack widget menu. Set default size (e.g., `w: 6, h: 5` for a reasonable file list height). Verify the widget appears in the "Add widget" dropdown or toolbar. The widget is persisted/positioned via the existing REQ-WDG-008 dashboard persistence mechanism.

- [ ] Task 13: **Integration — Ensure no custom file logic** — Verify `DashboardFileAccessWidget` does NOT contain: file upload, custom file storage, custom download logic, file deletion, file permission management. All file operations MUST go through OpenRegister's `FileService` or Nextcloud's file endpoints (download/preview). No `<input type="file">` elements. No custom multipart handling. Widget is read-only on files.

- [ ] Task 14: **Deduplication check (ADR-012)** — Search `openspec/specs/` for overlapping "file access", "file display", "document viewer" features. Search `openregister/lib/Service/` for existing file-listing services. Document findings: if similar logic exists in OR or other specs, explain why we're not consuming it or extending it. If no overlap, document "no overlap found". Add findings to a `deduplication-check.md` file in the change directory or as a comment in this tasks.md.

- [ ] Task 15: **Playwright integration test — Happy path** — Write `tests/integration/file-access-widget.spec.js` (or equivalent). Scenario: (a) Load dashboard with a dossier that has 3 readable files, (b) file-access widget fetches and displays all 3 files, (c) click "Open" on one file, (d) file opens in a new tab with correct content. Verify position/layout at 1920px viewport.

- [ ] Task 16: **Playwright test — Access denied** — Scenario: (a) Load dashboard with a dossier containing 3 files (user readable), add 1 file (user not readable), (b) widget displays only 3 files, (c) the non-readable file never appears in DOM or in "hidden" state. Verify no "access denied" error shown to user.

- [ ] Task 17: **Playwright test — Responsive layout** — Scenario: (a) Load dashboard at 320px width, verify file list is single-column and no horizontal scroll, (b) load at 768px, verify two-column or alternative layout, (c) load at 1920px, verify full table layout. Capture screenshots for doc/PR.

- [ ] Task 18: **Playwright test — Error handling** — Scenario: (a) Mock backend to return HTTP 500, (b) widget shows "Failed to load files" error state, (c) click "Retry" button, (d) widget retries (can mock a second response as success). Verify error UX is clean and non-alarming.

- [ ] Task 19: **Quality — SPDX headers** — Add SPDX license identifier to ALL new files: PHP files get `// SPDX-License-Identifier: EUPL-1.2` after `<?php`, Vue/JS files get `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line (Vue) or `// SPDX-License-Identifier: EUPL-1.2` (JS). Verify with `grep -rL 'SPDX-License-Identifier' src/ lib/` to catch missing headers.

- [ ] Task 20: **Quality — PHPDoc + @spec tags** — Every PHP class gets a file-level docblock with `@author`, `@copyright`, `@license`, `@link`, `@spec openspec/changes/mydash-file-access-widget/tasks.md#task-N`. Every public method gets a class-level `@spec` tag. Per ADR-003, this enables code → docblock → spec traceability.

- [ ] Task 21: **Quality — composer check:strict** — Run `composer check:strict` (phpcs, phpmd, phpstan, psalm). Verify 0 new violations in touched files. Rerun after each commit to catch regressions early. No warnings allowed in lib/ (strict mode).

- [ ] Task 22: **Quality — npm run lint** — Run `npm run lint` (ESLint). Verify 0 new violations in touched Vue/JS files. Check for: unused imports, undefined variables, inconsistent indentation. Rerun after each commit.

- [ ] Task 23: **Quality — Smoke test — backend** — Call the new endpoint manually: `curl -H "OCS-APIRequest: true" https://localhost/apps/mydash/api/file-access/list?dossierId=test-id`. Verify response is valid JSON. Test with valid dossierId (returns file list) and invalid dossierId (returns 403 or 404). Test without auth (returns 401).

- [ ] Task 24: **Quality — Smoke test — frontend** — Start dev server. Navigate to dashboard. Select a dossier with files. Verify widget loads and displays files. Click an "Open" file link. Verify file opens in new tab. Verify no console errors. Test at multiple viewport sizes (use Chrome DevTools).

- [ ] Task 25: **Documentation — User guide** — Add section to user-facing docs (if `docs/` exists) explaining the file-access widget: where to find it, how to add it to dashboard, how to open files. Include screenshot of the widget in action. Note any limitations (e.g., file filters deferred to Phase 2).

- [ ] Task 26: **Verification** — Run `openspec validate` on this change directory. Verify proposal.md, design.md, specs/*/spec.md, and tasks.md all pass schema validation. No missing sections or format errors.

## Verification

All tasks marked `[x]` are complete when:
- Code is written + committed (not just drafted)
- Tests pass (`composer test` + `npm run test` + `npm run test:e2e`)
- Quality gates pass (`composer check:strict` + `npm run lint`)
- Manual smoke tests pass (API call + frontend interaction)
- SPDX headers + @spec tags present on all new files
- No console warnings or errors in the browser
- Playwright scenarios capture expected behavior in screenshots

## Tests (company-wide ADR-008)

**PHPUnit:** `tests/Unit/Service/FileAccessServiceTest.php` + `tests/Unit/Controller/FileAccessControllerTest.php` (≥3 methods each, covering happy path + error scenarios, ≥80% line coverage).

**Vitest (if applicable):** Store plugins + composables tested for fetch + error states.

**Playwright:** Integration tests per Tasks 15–18 (happy path, access denial, responsive, error handling).

**Newman/Postman (if applicable):** REST API collection for the file-list endpoint (optional; CLI smoke test in Task 23 is sufficient for Phase 1).

## Documentation (company-wide ADR-009)

- User guide in `docs/` with screenshots
- Code comments linking to design decisions (design.md) where rationale is non-obvious
- PHPDoc file-level `@spec` links enable code → spec traceability

## i18n (company-wide ADR-007)

- All user-visible strings (`"Files"`, `"Open"`, `"No files"`, etc.) are in `l10n/en.json` and `l10n/nl.json`
- Keys are English (e.g., `"Files"`, not `"Bestanden"`)
- Dutch translations go in the `nl.json` value
- Both files have identical keys (verified by pre-commit or CI gate)
