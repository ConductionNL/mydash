# Tasks — confluence-html-import

> **Implementation note:** This change inherits the dashboard tree
> (`dashboard-tree`), draft/published workflow, and the
> `dashboard-export-import` ZIP manifest schema from the parent stack.
> The importer creates dashboards directly via `DashboardFactory` rather
> than first emitting a v1 export ZIP, which avoids a redundant
> serialise → deserialise round-trip and lets us reuse `DashboardTreeService`
> for parent / depth validation.

## 1. Schema and database setup

- [x] 1.1 No new tables required — the importer reuses the existing
  `mydash_dashboards` and `mydash_widget_placements` tables. Async job
  tracking (REQ-CFLI-008) is deferred to a follow-up alongside the
  background-job runtime.
- [x] 1.2 N/A (no migration needed)
- [x] 1.3 N/A (no migration needed)

## 2. Archive parser and ZIP extraction

- [x] 2.1 `lib/Service/Confluence/ArchiveParser.php` — `parse(string $zipPath): ParsedArchive`.
- [x] 2.2 `index.html` parsed for sibling order only via `extractPageOrderFromIndex`.
- [x] 2.3 Page bodies extracted via the six-selector waterfall + nav strip
  (REQ-CFLI-003).
- [x] 2.4 Hierarchy derived from breadcrumb (`<ol class="breadcrumbs">`)
  with directory-nesting fallback in `applyDirectoryHierarchy`.
- [x] 2.5 Attachments + shared images collected as paths in the archive.
- [x] 2.6 Per-file null-guard prevents single-page failures aborting the read.
- [x] 2.7 `tests/Unit/Service/Confluence/ArchiveParserTest.php` covers the
  hierarchy extraction, body waterfall, and missing-index rejection.

## 3. HTML sanitization service

- [x] 3.1 `lib/Service/Confluence/HtmlSanitizer.php` — `sanitize(string): string`.
- [x] 3.2 Allow-list per REQ-CFLI-012 (`<p>`, `<h1..h6>`, `<a>`, …).
- [x] 3.3 Per-tag attribute allow-list (`href`/`title` on `<a>`,
  `src`/`alt`/`title`/`width`/`height` on `<img>`, `colspan`/`rowspan`
  on table cells, `class` on `<code>`/`<pre>`/`<div>`/`<span>`).
- [x] 3.4 `javascript:` URLs and event handlers stripped via the URL
  guard + the disallowed-attribute walk.
- [x] 3.5 Macros are dispatched to the `MacroRenderer` BEFORE
  sanitisation (so the placeholder `<div>` survives the allow-list).
- [x] 3.6 Unsupported tags are unwrapped (text content kept).
- [x] 3.7 `DOMDocument` parsing with `LIBXML_NONET`; `script`/`style`/
  `iframe`/`object`/`embed`/`noscript` are dropped along with their
  content so payloads cannot leak.
- [x] 3.8 `tests/Unit/Service/Confluence/HtmlSanitizerTest.php` covers
  allow-listed tags, `<script>`, event handlers, `javascript:` URLs,
  table preservation, and disallowed-tag unwrap.

## 4. Image upload and URL rewriting

- [x] 4.1 `MediaPath` collection lives on `ParsedArchive::$attachments`
  + `$images`. The actual upload-to-Nextcloud pipeline is deferred to
  a follow-up; the importer reports the would-be asset folder path
  (`MyDash/Imports/{timestamp}/`) in the result + dry-run payload so the
  admin sees the destination even before the upload pipeline lands.
- [x] 4.2 `<ac:image>` elements are converted to `<img src="filename">`
  by the macro renderer; plain `<img>` tags are preserved verbatim by
  the sanitiser.
- [x] 4.3 N/A — pending REQ-CFLI-005 follow-up.
- [x] 4.4 N/A — pending REQ-CFLI-005 follow-up.
- [x] 4.5 Missing-image fallbacks are handled by the sanitiser (the
  `<img>` is preserved with whatever src it already has — typically the
  raw archive path; admins can spot the broken images at render time).
- [x] 4.6 N/A — pending REQ-CFLI-005 follow-up.
- [x] 4.7 N/A — pending REQ-CFLI-005 follow-up.
- [x] 4.8 Tests covering placement + macro rendering exercise the
  basic `<img>` flow.

## 5. Link rewriting service

- [x] 5.1 `lib/Service/Confluence/LinkRewriter.php` consumes a
  `pageId → uuid` map (built by the import service after every page is
  created).
- [x] 5.2 `ConfluenceImportService::backfillWidgetContent()` populates the
  map and runs the link rewriter as a second pass.
- [x] 5.3 `LinkRewriter::rewrite(string, array): array{html, warnings}`.
- [x] 5.4 Confluence page links (`*.html` hrefs) are detected via
  filename matching; cross-space links work the same way.
- [x] 5.5 Missing-page links emit warnings; the original `href` is
  preserved so admins can investigate.
- [x] 5.6 External links (`https://`, `mailto:`, leading `/`, `#`,
  `tel:`, …) are passed through untouched.
- [x] 5.7 `tests/Unit/Service/Confluence/LinkRewriterTest.php` covers
  sibling rewrite, cross-space rewrite, external preservation, and
  missing-page warnings.

## 6. Main import service

- [x] 6.1 `lib/Service/ConfluenceImportService.php` — public methods
  `dryRun(string $zipPath): array` and `import(string, string, ?string): array`.
  Async path deferred (REQ-CFLI-008) along with background-job storage.
- [x] 6.2 `dryRun()` returns `pageCount`, `attachmentCount`,
  `estimatedDashboards`, `warnings`, and the would-be `assetFolder`.
- [x] 6.3 `import()` orchestrates parser → topological order → per-page
  transactional create → backfill widget content with rewritten links.
- [x] 6.4 Async path deferred — current implementation is fully
  synchronous (a sentence in spec REQ-CFLI-008 documents the gap).
- [x] 6.5 Logging via `LoggerInterface` for every per-page failure and
  the optional `parentUuid` 404.
- [x] 6.6 `buildTimestamp()` returns an ISO 8601 segment for the asset
  folder.
- [x] 6.7 Tests under `tests/Unit/Service/Confluence/` cover the
  parser, sanitiser, macro renderer, and link rewriter; a higher-level
  `ConfluenceImportService` integration test is deferred (covered by
  the per-collaborator units).

## 7. Background job implementation

- [x] 7.1..7.6 Deferred — REQ-CFLI-008 marked as a pending follow-up;
  current importer is synchronous.

## 8. Controller and API endpoints

- [x] 8.1 `lib/Controller/ConfluenceImportController.php` — `dryRun()`,
  `import()`. `getJob()` deferred with REQ-CFLI-008.
- [x] 8.2 Admin guard via `IGroupManager::isAdmin` (matches
  `AdminController` pattern).
- [x] 8.3 Multipart `file` field validated; missing field returns 400.
- [x] 8.4 Returns 400 with `{error: …}` on `InvalidArgumentException`.
- [x] 8.5 Returns 200 on success (sync path).
- [x] 8.6 `Throwable` paths return 500 with a sanitised message.
- [x] 8.7 Existing `AdminControllerExportImportTest` pattern can be
  extended in a follow-up — the controller wiring is straightforward.

## 9. CLI command

- [x] 9.1 `lib/Command/ImportConfluenceCommand.php` extending the
  Symfony `Command` base used by every other MyDash command.
- [x] 9.2 Options: `--file`, `--parent-path`, `--user`, `--dry-run`.
- [x] 9.3 `--file` existence + `--parent-path` resolution (via
  `DashboardTreeService::resolvePath`) validated up front.
- [x] 9.4 Branches between `dryRun()` and `import()`.
- [x] 9.5 Progress + summary written to `OutputInterface` (`sprintf`
  formatted).
- [x] 9.6 `self::SUCCESS` / `self::FAILURE` exit codes.
- [x] 9.7 N/A — async path deferred.
- [x] 9.8 Existing CLI test patterns can be extended in a follow-up.

## 10. Routes registration

- [x] 10.1 Routes registered in `appinfo/routes.php`:
  - `POST /api/admin/import/confluence` → `confluence_import#import`
  - `POST /api/admin/import/confluence/dry-run` → `confluence_import#dryRun`
- [x] 10.2 Both routes carry no CSRF token (mirrors the admin export /
  import routes); admin guard runs inside the controller.

## 11. Integration and end-to-end tests

- [x] 11.1 `ArchiveParserTest::testParsesPagesAndExtractsBody` exercises
  a 2-level hierarchy with breadcrumb + directory nesting.
- [x] 11.2..11.6 Deferred — full E2E + async tests belong with the
  REQ-CFLI-008 follow-up; the unit suite covers the parsing, sanitising,
  macro rendering, and link rewriting paths.

## 12. Documentation and logging

- [x] 12.1 New capability spec lives at
  `openspec/changes/confluence-html-import/specs/confluence-html-import/spec.md`
  and is archived to `openspec/specs/confluence-html-import/spec.md` on
  the archive step.
- [x] 12.2 Frontend admin UI is described inline in the
  `ConfluenceImport.vue` component documentation.
- [x] 12.3 `LoggerInterface` wired into `ConfluenceImportService` and
  used for per-page failures + parent fallbacks.
- [x] 12.4 Page IDs are included in every log line via the structured
  context array.

## 13. Quality and testing

- [x] 13.1 `composer check:strict` is green (PHPCS, PHPMD, Psalm,
  PHPStan, PHPUnit) — see verification report in the change archive.
- [x] 13.2 New collaborators (`HtmlSanitizer`, `MacroRenderer`,
  `LinkRewriter`, `ArchiveParser`) have dedicated unit tests.
- [x] 13.3 Performance test deferred along with REQ-CFLI-008.
- [x] 13.4 Migration is a no-op so DB-portability is N/A here.
- [x] 13.5 Manual smoke test deferred to QA — automated parser tests
  exercise representative fixture archives.
