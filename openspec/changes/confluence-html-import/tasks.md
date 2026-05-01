# Tasks — confluence-html-import

## 1. Schema and database setup

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddConfluenceImportLog.php` adding table `oc_mydash_confluence_import_logs` with columns: `id INT PRIMARY KEY`, `jobId VARCHAR(36) UNIQUE`, `status VARCHAR(32)` (processing|completed|failed), `createdDashboardCount INT DEFAULT 0`, `skippedPageCount INT DEFAULT 0`, `errors LONGTEXT` (JSON), `createdAt DATETIME`, `completedAt DATETIME NULLABLE`
- [ ] 1.2 Add index `idx_mydash_cfli_job_id` on `jobId` for fast job lookups
- [ ] 1.3 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly

## 2. Archive parser and ZIP extraction

- [ ] 2.1 Create `lib/Service/ConfluenceArchiveParser.php` with method `parse(string $zipPath): ConfluenceArchiveData` returning object with `indexHtml`, `pages` (array of `{filename, pageId, title, content}`), `attachments` (array of `{pageId, filePath}`), `sharedImages` (array of filenames)
- [ ] 2.2 Parse `index.html` to extract table of contents: page titles, IDs, parent-child relationships (via nesting in TOC or filename patterns)
- [ ] 2.3 Extract all `.html` files from space directories into `pages` list (extract filename as pageId, extract `<title>` tag as page title, extract `<div id="main-content">` or `<body>` as page body)
- [ ] 2.4 Build in-memory tree structure from TOC: `{pageId, title, parentPageId: null|string, children: [...]}`
- [ ] 2.5 Identify all attachment paths (`attachments/<pageId>/*`) and shared image paths (`images/*`)
- [ ] 2.6 Add error handling: malformed ZIPs, missing index.html, unclosed HTML tags — log warnings but do not throw; allow parsing to continue for valid pages
- [ ] 2.7 Add unit tests for: 5-level hierarchy parsing, TOC with nested pages, malformed TOC recovery, missing attachments list

## 3. HTML sanitization service

- [ ] 3.1 Create `lib/Service/HtmlSanitizer.php` with method `sanitize(string $html): string` implementing a strict allow-list
- [ ] 3.2 Allow-list: `<p>`, `<h1..h6>`, `<a>`, `<strong>`, `<em>`, `<ul>`, `<ol>`, `<li>`, `<img>`, `<table>`, `<tr>`, `<td>`, `<th>`, `<thead>`, `<tbody>`, `<blockquote>`, `<pre>`, `<code>`, `<br>`
- [ ] 3.3 Whitelist attributes per tag: `href` on `<a>`, `src` on `<img>`, `colspan`/`rowspan` on table cells, `alt` on `<img>`
- [ ] 3.4 Strip event handlers (`onclick`, `onerror`, etc.) and `javascript:` URLs from all attributes
- [ ] 3.5 Detect Confluence macros: `<ac:structured-macro>`, `<at:macro>`, custom Confluence elements — replace with `<p><em>[Macro: <macroName> not imported]</em></p>`
- [ ] 3.6 Strip all other disallowed tags but preserve text content
- [ ] 3.7 Use `DOMDocument` or `SimpleDOMParser` for robust HTML parsing; handle malformed HTML gracefully (do not throw on parse failure)
- [ ] 3.8 Add unit tests for: safe tag preservation, script/event handler stripping, macro placeholder injection, malformed HTML recovery

## 4. Image upload and URL rewriting

- [ ] 4.1 Create `lib/Service/ConfluenceImageUploader.php` with method `uploadAndRewrite(string $html, array $attachments, string $timestamp): array` returning `{html: <rewritten-html>, uploadedFiles: [...], errors: [...]}`
- [ ] 4.2 Extract all `<img src="...">` tags from HTML
- [ ] 4.3 For each image: resolve the file path in the archive (e.g., `attachments/page-123/logo.png` or `images/logo.png`), check if file exists in `$attachments` list
- [ ] 4.4 If file exists: upload to Nextcloud folder `/MyDash/Imports/{timestamp}/{filename}` using `IAppData` or `IFolder` APIs; get the download URL and rewrite `src` to the NC URL
- [ ] 4.5 If file does NOT exist: log warning, replace `<img>` with `<em>[Image: <filename> not found]</em>`, continue processing
- [ ] 4.6 Handle quota limits: if upload fails due to quota, log error and replace with placeholder (do not abort import per REQ-CFLI-009)
- [ ] 4.7 Track all uploaded files and errors in return object
- [ ] 4.8 Add unit tests for: successful uploads, missing files, quota limits, multiple images in one page

## 5. Link rewriting service

- [ ] 5.1 Create `lib/Service/ConfluencePageMapper.php` to build in-memory map: `pageId => dashboardUuid`
- [ ] 5.2 After all dashboards are created, populate the map from the pageId-to-dashboard lookup (via stored metadata or post-creation scan)
- [ ] 5.3 Create `lib/Service/ConfluenceLinkRewriter.php` with method `rewriteLinks(string $html, ConfluencePageMapper $mapper): array` returning `{html: <rewritten-html>, warnings: [...]}`
- [ ] 5.4 Extract all `<a href="...">` tags; identify Confluence page links (e.g., `href="page-123.html"` or `href="SPACE/page-456.html"`)
- [ ] 5.5 For each Confluence link: look up pageId in mapper, rewrite to `/apps/mydash/dashboard/{dashboardUuid}`, or log warning if pageId not found
- [ ] 5.6 External links (`https://`, `mailto:`, etc.) MUST be left untouched
- [ ] 5.7 Add unit tests for: sibling links, cross-space links, missing pages, external links unchanged

## 6. Main import service

- [ ] 6.1 Create `lib/Service/ConfluenceImportService.php` with public methods: `dryRun(SplFileInfo $file): DryRunResult`, `import(SplFileInfo $file, ?string $parentPath): ImportResult`, `importAsync(SplFileInfo $file, ?string $parentPath): AsyncJobResult`
- [ ] 6.2 `dryRun()`: parse archive, analyze tree, count pages and attachments, return `{pageCount, attachmentCount, estimatedDashboards, warnings, estimatedAssetSize}` WITHOUT creating anything
- [ ] 6.3 `import()`: full synchronous import for archives <100 pages:
  - [ ] 6.3.1 Parse archive and extract hierarchy
  - [ ] 6.3.2 If `parentPath` supplied, resolve path to dashboard UUID via tree service
  - [ ] 6.3.3 Create dashboards in tree order (parents before children) using DashboardFactory
  - [ ] 6.3.4 Build pageId-to-uuid map as dashboards are created
  - [ ] 6.3.5 For each dashboard: sanitize page body HTML, upload images, rewrite links
  - [ ] 6.3.6 Create text-display widget for each dashboard with sanitized content at (0, 0) sized 12×12
  - [ ] 6.3.7 Catch per-page errors (malformed HTML, upload failures, link resolution failures), log them, continue to next page
  - [ ] 6.3.8 Return `{createdDashboardCount, skippedPageCount, errors, importLogUrl}`
- [ ] 6.4 `importAsync()`: queue background job for archives >=100 pages, return `{jobId, status: "processing"}`
- [ ] 6.5 Add logging: log each page creation, each image upload, each error with context (page ID, filename, error message)
- [ ] 6.6 Use timestamp (ISO 8601 format) for asset folder naming to ensure uniqueness across runs
- [ ] 6.7 Add unit tests for: small import (synchronous), large import (queued), dryrun accuracy, error handling

## 7. Background job implementation

- [ ] 7.1 Create `lib/BackgroundJob/ConfluenceImportJob.php` extending `OCP\BackgroundJob\Job`
- [ ] 7.2 Store job arguments: file path, parent path (optional), user ID of requester
- [ ] 7.3 In `run()` method: call `ConfluenceImportService::import()`, update `oc_mydash_confluence_import_logs` table with progress and final result
- [ ] 7.4 After import completes: set status to `completed` or `failed`, store `createdDashboardCount`, `skippedPageCount`, `errors` (as JSON)
- [ ] 7.5 Store a log URL or generate import summary for admin review
- [ ] 7.6 If import fails mid-process: set status to `failed`, log the exception, clean up partial imports if needed (or leave orphaned dashboards with a marker for admin review)

## 8. Controller and API endpoints

- [ ] 8.1 Create `lib/Controller/ConfluenceImportController.php` with methods:
  - [ ] 8.1.1 `dryRun()` mapped to `POST /api/admin/import/confluence/dry-run` — accepts multipart file, returns dry-run preview
  - [ ] 8.1.2 `import()` mapped to `POST /api/admin/import/confluence` — accepts multipart file + optional `parentPath` query param, returns ImportResult or queues async job
  - [ ] 8.1.3 `getJob(string $jobId)` mapped to `GET /api/admin/import/confluence/jobs/{jobId}` — returns job status and progress
- [ ] 8.2 Require admin role (`@IsAdminRequired` or equivalent) on all endpoints
- [ ] 8.3 Validate `file` parameter: must be a valid ZIP, must not exceed max upload size
- [ ] 8.4 Return HTTP 400 for validation errors (missing file, invalid ZIP, etc.)
- [ ] 8.5 Return HTTP 202 (Accepted) for async imports; HTTP 200 for synchronous imports or dry-run
- [ ] 8.6 Add error handling: multipart parse errors, ZIP read errors, file write errors — return appropriate HTTP codes
- [ ] 8.7 Add integration tests for: dryrun endpoint, sync import endpoint, async job queuing, job polling

## 9. CLI command

- [ ] 9.1 Create `lib/Command/ConfluenceImportCommand.php` extending `OCP\Console\Command\Command`
- [ ] 9.2 Add options: `--file` (required), `--parent-path` (optional), `--dry-run` (optional)
- [ ] 9.3 Validate options: file must exist and be readable
- [ ] 9.4 Call `ConfluenceImportService` methods based on options (dryRun if `--dry-run`, import otherwise)
- [ ] 9.5 Output progress to `OutputInterface`: page count, dashboard count, errors
- [ ] 9.6 Exit with code 0 on success, non-zero on failure
- [ ] 9.7 For async imports: queue job and output job ID for polling
- [ ] 9.8 Add integration tests for: all option combinations, error handling, output format

## 10. Routes registration

- [ ] 10.1 Register two new routes in `appinfo/routes.php`:
  - [ ] 10.1.1 `POST /api/admin/import/confluence` → `ConfluenceImportController::import()`
  - [ ] 10.1.2 `POST /api/admin/import/confluence/dry-run` → `ConfluenceImportController::dryRun()`
  - [ ] 10.1.3 `GET /api/admin/import/confluence/jobs/{jobId}` → `ConfluenceImportController::getJob()`
- [ ] 10.2 Ensure routes are under `/api/` namespace and admin-protected

## 11. Integration and end-to-end tests

- [ ] 11.1 Create a sample Confluence export ZIP with 50 pages in a 3-level hierarchy, 10 attachments
- [ ] 11.2 Add E2E test: upload sample ZIP via API, verify all 50 dashboards created, parents linked correctly, images uploaded, links rewritten
- [ ] 11.3 Add E2E test: run CLI import command with the same ZIP, verify dashboards created (new UUIDs)
- [ ] 11.4 Add E2E test: dryrun endpoint, verify counts accurate, verify no databases modified
- [ ] 11.5 Add E2E test: page with malformed HTML, verify skipped and logged, other pages imported
- [ ] 11.6 Add E2E test: async import of 150-page archive, verify job queued, status polling works, completion detected

## 12. Documentation and logging

- [ ] 12.1 Update `openspec/specs/admin-settings/spec.md` to reference new import capability (optional, depends on spec structure)
- [ ] 12.2 Add `README.md` or section to app docs explaining import workflow, supported features, known limitations
- [ ] 12.3 Ensure all import operations are logged via `ILogger` with appropriate levels (info for milestones, warning for recoverable errors, error for failures)
- [ ] 12.4 Include context in log entries: page ID, page count progress, image filenames, link targets, etc.

## 13. Quality and testing

- [ ] 13.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — ensure all new code passes with 0 warnings
- [ ] 13.2 Achieve >80% code coverage on new services (measured via PHPUnit and code coverage tools)
- [ ] 13.3 Add performance test: import 500-page archive, measure time and memory, ensure sub-10-minute execution for async
- [ ] 13.4 Test on sqlite, mysql, postgres databases — ensure migration is database-agnostic
- [ ] 13.5 Manual smoke test: upload real Confluence export (with permission), verify import quality
