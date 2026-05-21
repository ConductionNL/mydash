# Tasks — confluence-html-import

## Tasks

### Archive Parser (REQ-CFLI-001, REQ-CFLI-002)

- [ ] Task 1: Create `lib/Service/Confluence/ArchiveParser.php` with public method `parse(string $zipPath): ParsedArchive` that:
  - Opens the ZIP file in read-only mode via `ZipArchive::RDONLY`
  - Validates `index.html` exists; raises `InvalidArgumentException` with message "index.html not found in archive" if missing
  - Identifies all `.html` files as pages; extract `<title>` and `<h1>` for page title
  - Builds a list of `attachments/` and `images/` entries
  - Returns a `ParsedArchive` object with properties: `pages[]`, `attachments[]`, `images[]`, `warnings[]`
  - Links to REQ-CFLI-001
  - @spec openspec/changes/confluence-html-import/tasks.md#task-1

- [ ] Task 2: Extend `ArchiveParser::parse()` to extract page hierarchy via breadcrumb-first + directory-nesting fallback:
  - For each page file, parse `<ol class="breadcrumbs">` or `<ol id="breadcrumbs">` to extract parent chain
  - Extract parent link href (second-to-last link in breadcrumb) and match to a page file basename
  - Assign `parentPageId` to that basename; if no breadcrumb, fall back to directory nesting (e.g., `SPACE/page.html` → parent is `SPACE.html`)
  - Assign `parentPageId = null` to root pages (no breadcrumb, no directory parent)
  - For sibling ordering, parse `index.html` link list via regex to extract 0-based positions
  - Store in each `ParsedPage::$parentPageId`, `$siblingOrder`
  - Warn if breadcrumb is malformed or parent page is not found
  - Links to REQ-CFLI-002
  - @spec openspec/changes/confluence-html-import/tasks.md#task-2

- [ ] Task 3: Unit tests for `ArchiveParser`:
  - Test valid archive structure (index.html, pages, attachments/images); verify page count, attachment count
  - Test missing index.html → `InvalidArgumentException` with correct message
  - Test nested page directories (SPACE1/SUB/page.html); verify all recognized as pages
  - Test breadcrumb-to-parent parsing; verify parentPageId assigned correctly
  - Test directory-nesting fallback when breadcrumb absent
  - Test sibling order extraction from index.html link list
  - Test root pages (no breadcrumb, no parent directory)
  - Vitest with fixtures (minimal valid ZIP, malformed HTML, nested structures)
  - @spec openspec/changes/confluence-html-import/tasks.md#task-3

### Page Body Extraction & Sanitization (REQ-CFLI-003, REQ-CFLI-012)

- [ ] Task 4: Create `lib/Service/Confluence/HtmlSanitizer.php` with public static method `sanitize(string $html): string` that:
  - Implements XPath selector waterfall for `<div id="main-content">`, `<div class="wiki-content">`, `<div class="page-content">`, `<div id="content">`, `<main>`, `<article>`
  - Falls back to regex extraction of `<body>…</body>` content, then raw HTML as last resort
  - Strips navigation elements in-place: `div#pagetreesearch`, `div.breadcrumbs`, `div.pageSection`, `form[name=pagetreesearchform]`, `form.aui`, `nav`, `div.page-metadata`
  - Implements strict allow-list: `<p>`, `<h1>`–`<h6>`, `<a>`, `<strong>`, `<em>`, `<b>`, `<i>`, `<ul>`, `<ol>`, `<li>`, `<img>`, `<table>`, `<tr>`, `<td>`, `<th>`, `<thead>`, `<tbody>`, `<blockquote>`, `<pre>`, `<code>`, `<br>`, `<span>`, `<div>`, `<details>`, `<summary>`
  - Filters attributes per tag: `href`/`title` on `<a>`, `src`/`alt`/`title`/`width`/`height` on `<img>`, `colspan`/`rowspan` on table cells, `class` on `<code>`/`<pre>`/`<div>`/`<span>`
  - Removes event handler attributes (`onclick`, `onerror`, etc.) and `javascript:` URL schemes
  - Drops entirely (tag + content): `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`, `<noscript>`
  - Unwraps disallowed tags but preserves text content
  - Links to REQ-CFLI-012
  - @spec openspec/changes/confluence-html-import/tasks.md#task-4

- [ ] Task 5: Unit tests for `HtmlSanitizer`:
  - Test XPath selector waterfall; verify first non-empty match is used
  - Test navigation element stripping; verify `<div class="breadcrumbs">` removed in-place
  - Test allow-listed tags preserved; test disallowed tags unwrapped (button → text survives)
  - Test script tags dropped with content; test event handlers stripped; test javascript: URLs removed
  - Test img tags with src/alt/title/width/height preserved; other attributes removed
  - Test per-tag attribute filtering (href on a, colspan on td, etc.)
  - Vitest with fixtures (Confluence HTML samples, XSS vectors)
  - @spec openspec/changes/confluence-html-import/tasks.md#task-5

### Macro Rendering (REQ-CFLI-006, REQ-CFLI-005)

- [ ] Task 6: Create `lib/Service/Confluence/MacroRenderer.php` with public method `render(DOMElement $macro): string` that:
  - Recognizes `<ac:structured-macro ac:name="...">` elements
  - Renders panel-type macros (info, note, warning, tip, error, panel) → `<div class="confluence-panel-{type}">…</div>`
  - Renders code macro with language parameter → `<pre><code class="language-{lang}">…</code></pre>`
  - Renders expand macro → `<details><summary>…</summary>…</details>` with title from `ac:parameter`
  - Falls back to `<div class="confluence-unsupported-macro">Unsupported macro: <code>{name}</code></div>` for unrecognized macros
  - Converts `<ac:image><ri:attachment ri:filename="x"/></ac:image>` → `<img src="x" alt="x">`
  - Links to REQ-CFLI-006, REQ-CFLI-005
  - @spec openspec/changes/confluence-html-import/tasks.md#task-6

- [ ] Task 7: Unit tests for `MacroRenderer`:
  - Test panel macros (info, note, warning) render as div with correct class
  - Test code macro with language parameter; verify language class applied
  - Test expand macro renders as details/summary
  - Test unrecognized macro (sql, jira, etc.) renders fallback placeholder
  - Test ac:image conversion to img tag
  - Test macro body content preserved
  - Vitest with Confluence HTML samples
  - @spec openspec/changes/confluence-html-import/tasks.md#task-7

### Link Rewriting (REQ-CFLI-004)

- [ ] Task 8: Create `lib/Service/Confluence/LinkRewriter.php` with public method `rewrite(string $html, array $pageIdToUuidMap): string` that:
  - Extracts `<a href="...">` links from HTML
  - For internal links (relative paths, no leading `/`, `#`, `http`, `mailto`, `tel`), look up the page ID (basename of href, without `.html`) in `$pageIdToUuidMap`
  - Rewrites matching links to `<a href="/apps/mydash/dashboard/{uuid}">`
  - Logs a warning for links to unknown pages (e.g., "Link to unknown Confluence page: page-999.html")
  - Preserves external links and mailto/tel schemes unchanged
  - Links to REQ-CFLI-004
  - @spec openspec/changes/confluence-html-import/tasks.md#task-8

- [ ] Task 9: Unit tests for `LinkRewriter`:
  - Test internal link rewriting (relative href → dashboard URL)
  - Test cross-space link rewriting (OTHER-SPACE/page.html)
  - Test external links (https, http) unchanged
  - Test mailto and tel links unchanged
  - Test links with leading / or # unchanged
  - Test missing page links preserved with warning logged
  - Vitest with HTML samples
  - @spec openspec/changes/confluence-html-import/tasks.md#task-9

### Dashboard Factory & Service Orchestration (REQ-CFLI-003, REQ-CFLI-009, REQ-CFLI-010)

- [ ] Task 10: Create `lib/Service/Confluence/DashboardFactory.php` with public method `createDashboardForPage(ParsedPage $page, string $userId, ?string $parentUuid = null): Dashboard` that:
  - Creates a new dashboard with `name = $page->title`, `userId`, `parentUuid`
  - Creates a text-display widget at grid position (0, 0) with size (12, 12)
  - Uses provided body HTML (already sanitized) as widget content via `styleConfig.text`
  - Sets standard text-display defaults (fontSize, color, backgroundColor, textAlign)
  - Persists via `DashboardRepository::save()` and `WidgetRepository::save()`
  - Handles per-page transaction: on exception, roll back and raise `ConfluenceImportException` with page ID + reason
  - Links to REQ-CFLI-003
  - @spec openspec/changes/confluence-html-import/tasks.md#task-10

- [ ] Task 11: Create `lib/Service/ConfluenceImportService.php` with public method `import(string $zipPath, ?string $parentUuid = null, string $userId = null): ImportResult` that:
  - Calls `ArchiveParser::parse()` to extract pages + hierarchy
  - For each page, extracts body via `HtmlSanitizer::sanitize()` with selector waterfall
  - Processes macros via `MacroRenderer::render()`
  - Builds a `pageId → uuid` map as dashboards are created
  - Calls `DashboardFactory::createDashboardForPage()` per page; if exception, capture in `errors[]` and continue
  - After all dashboards created, call `LinkRewriter::rewrite()` on each widget content + persist
  - Returns `ImportResult` with: `createdDashboardCount`, `skippedPageCount`, `errors[]`, `warnings[]`, `assetFolder` (timestamp-based path)
  - Assigns root Confluence pages to `$parentUuid` if provided; log warning if `$parentUuid` is invalid
  - Links to REQ-CFLI-003, REQ-CFLI-009, REQ-CFLI-010
  - @spec openspec/changes/confluence-html-import/tasks.md#task-11

- [ ] Task 12: Extend `ConfluenceImportService` with public method `dryRun(string $zipPath): DryRunResult` that:
  - Calls `ArchiveParser::parse()` to extract pages + hierarchy + attachments
  - Returns `DryRunResult` with: `pageCount`, `attachmentCount`, `estimatedDashboards`, `warnings[]`, `assetFolder` (timestamp)
  - Does NOT create any dashboards or persist any data
  - Links to REQ-CFLI-007
  - @spec openspec/changes/confluence-html-import/tasks.md#task-12

- [ ] Task 13: Ensure unique slug generation for re-importability:
  - Add method `ensureUniqueSlug(string $baseSlug, string $pageId): string` to `ConfluenceImportService`
  - On slug collision, append page ID: `{baseSlug}-{pageId}`
  - If that still collides (edge case), append ISO 8601 timestamp: `{baseSlug}-{pageId}-{timestamp}`
  - Uses `DashboardRepository::findBySlug()` to check for existing slugs
  - Links to REQ-CFLI-010
  - @spec openspec/changes/confluence-html-import/tasks.md#task-13

### Controller & Routes (REQ-CFLI-007, REQ-CFLI-008, REQ-CFLI-009)

- [ ] Task 14: Create `lib/Controller/ConfluenceImportController.php` with:
  - `#[AuthorizedAdminSetting(Application::APP_ID)]` on all methods (admin-only guard)
  - Public method `import(Request $request): DataResponse` that:
    - Extracts multipart `file` from request
    - Optional `parentUuid` query param
    - Gets authenticated user ID via `IUserSession`
    - Calls `ConfluenceImportService::import()`
    - Returns HTTP 200 with `ImportResult` serialized as JSON
    - Returns HTTP 400 on `InvalidArgumentException` (e.g., missing index.html)
    - Returns HTTP 500 on unexpected exception with generic message + logged details
  - Public method `dryRun(Request $request): DataResponse` that:
    - Extracts multipart `file` from request
    - Calls `ConfluenceImportService::dryRun()`
    - Returns HTTP 200 with `DryRunResult` serialized as JSON
  - Links to REQ-CFLI-007, REQ-CFLI-008
  - @spec openspec/changes/confluence-html-import/tasks.md#task-14

- [ ] Task 15: Register routes in `appinfo/routes.php`:
  - `POST /api/admin/import/confluence` → `confluence_import#import`
  - `POST /api/admin/import/confluence/dry-run` → `confluence_import#dryRun`
  - Both multipart form-data endpoints
  - Links to REQ-CFLI-007, REQ-CFLI-008
  - @spec openspec/changes/confluence-html-import/tasks.md#task-15

### CLI Command (REQ-CFLI-011)

- [ ] Task 16: Create `lib/Command/ImportConfluenceCommand.php` extending `Command` that:
  - Registers OCC command `mydash:import:confluence`
  - Accepts option `--file=/path/to/export.zip` (required)
  - Accepts option `--parent-path=/slug/chain` (optional)
  - Accepts option `--user=uid` (optional; defaults to current session user or admin)
  - Accepts flag `--dry-run`
  - On `--dry-run`, calls `ConfluenceImportService::dryRun()` and outputs line: `Parse OK: N pages, M attachments, asset folder: MyDash/Imports/…`
  - On normal import, calls `ConfluenceImportService::import()`
  - If `--parent-path` provided, calls `DashboardTreeService::resolvePath()` to get UUID; logs warning if not found, falls back to root
  - Outputs summary line: `Imported N dashboards, skipped M, errors: K, asset folder: …`
  - On any error, outputs error message and exits non-zero
  - On success, exits code 0
  - Links to REQ-CFLI-011
  - @spec openspec/changes/confluence-html-import/tasks.md#task-16

- [ ] Task 17: Register CLI command in `appinfo/info.xml` under `<commands>` section
  - @spec openspec/changes/confluence-html-import/tasks.md#task-17

### Integration Tests

- [ ] Task 18: Create `tests/Integration/Service/ConfluenceImportServiceTest.php`:
  - Fixture: minimal valid Confluence HTML export ZIP (5 pages, 2-level hierarchy, 1 attachment)
  - Test end-to-end import; verify 5 dashboards created, hierarchy preserved, body HTML sanitized
  - Test dry-run on same ZIP; verify counts returned, no dashboards created
  - Test per-page error resilience: fixture with one malformed page; verify 4 dashboards created, 1 error captured
  - Test re-importability: import same ZIP twice; verify 10 new dashboards created (not 5 + update)
  - Test slug collision resolution: fixture with duplicate page titles; verify second import uses title-{pageId} slugs
  - Test link rewriting: fixture with internal links; verify links rewritten to /apps/mydash/dashboard/{uuid}
  - Test macro rendering: fixture with info/code/expand macros; verify rendered correctly
  - Test asset folder timestamp: two imports should have different assetFolder values
  - Playwright for UI validation if needed (POST to API, verify response, list dashboards)
  - @spec openspec/changes/confluence-html-import/tasks.md#task-18

### Quality Gates

- [ ] Task 19: PHPStan / Psalm strict analysis:
  - Run `composer check:strict` on all new files
  - Verify no regressions in existing code
  - @spec openspec/changes/confluence-html-import/tasks.md#task-19

- [ ] Task 20: PHPCS / PHPMD code standards:
  - All files conform to PSR-12 (via phpcs)
  - No PHPMD violations
  - @spec openspec/changes/confluence-html-import/tasks.md#task-20

- [ ] Task 21: Test coverage:
  - Vitest for ArchiveParser, HtmlSanitizer, MacroRenderer, LinkRewriter
  - Integration tests for ConfluenceImportService
  - Verify coverage for all REQ scenarios
  - @spec openspec/changes/confluence-html-import/tasks.md#task-21

- [ ] Task 22: i18n audit:
  - Check for user-facing strings in controller responses; add to `nl` and `en` translation files
  - Error messages MUST NOT leak internal details (stack traces, SQL, file paths)
  - @spec openspec/changes/confluence-html-import/tasks.md#task-22

- [ ] Task 23: Security review:
  - Verify HTML sanitization blocks all OWASP XSS vectors
  - Verify file upload (ZIP) does not execute as code
  - Verify admin-only guard is applied at controller middleware level
  - Verify per-object authorization is not needed (import is admin-only, no user-owned objects)
  - @spec openspec/changes/confluence-html-import/tasks.md#task-23

## Verification

`openspec validate` exits clean. Import endpoint accepts ZIP files and returns counts/errors/warnings. Dry-run does not create dashboards. Re-imports create new dashboards without crashing on slug collisions. CLI command works with `--file`, `--parent-path`, `--user`, `--dry-run`.

## Tests (company-wide ADR-009)

Vitest for service layer unit tests per Tasks 3, 5, 7, 9. Integration test per Task 18 with real ZIP fixture. No Playwright required (no UI interaction; validation via API).

## Documentation (company-wide ADR-010)

Inline `@spec` PHPDoc tags per task. Changelog entry covering bulk import capability, supported macros, and links to follow-up `confluence-async-import` and `confluence-attachment-uploads` changes.

## i18n (company-wide ADR-007)

Error messages in `nl` and `en` per Task 22. No translatable user-facing content expected beyond error responses.
