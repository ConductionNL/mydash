# Confluence HTML Export Importer

## Why

Organizations migrating from Atlassian Confluence or supplementing it with MyDash need a one-shot bulk-import capability that converts their existing Confluence page hierarchies into MyDash dashboards. Manual recreation of hundreds of pages is impractical. This change enables admins to upload a Confluence "HTML Export" archive and automatically generate MyDash dashboards with the page content preserved.

## What Changes

- Add a new admin-only import endpoint `POST /api/admin/import/confluence` accepting a multipart Confluence HTML export ZIP file. The importer converts each Confluence page into a MyDash dashboard with:
  - Dashboard name = Confluence page title
  - Dashboard parent = parent Confluence page's dashboard (mirroring the Confluence hierarchy, requires `dashboard-tree` capability)
  - Dashboard widget = one full-width text-display widget containing the sanitized page body HTML
- Add a companion `POST /api/admin/import/confluence/dry-run` endpoint for impact preview (page count, attachment count, warnings, but no database changes).
- Add a background job system for large imports (>100 pages), returning 202 with job-id and exposing `GET /api/admin/import/confluence/jobs/{jobId}` for polling.
- Add a CLI command `php occ mydash:import:confluence --file=/path/export.zip [--parent-path=/imports]` for headless/cron imports.
- Add sanitization (allow-list: `<p>`, `<h1..h6>`, `<a>`, `<strong>`, `<em>`, `<ul>`, `<ol>`, `<li>`, `<img>`, `<table>`, `<tr>`, `<td>`, `<th>`, `<thead>`, `<tbody>`, `<blockquote>`, `<pre>`, `<code>`, `<br>`; strip macros and Confluence-specific markup).
- Add image upload: `<img>` references to attachments and shared images are uploaded to Nextcloud folder `MyDash/Imports/{timestamp}/` with src rewritten to NC URLs.
- Add link rewriting: internal Confluence links (`<a href="pageId.html">`) are rewritten to dashboard deep links (`/apps/mydash/dashboard/{imported-uuid}`).
- Add error resilience: a single page parse failure does NOT abort the import; failed pages are logged and skipped.
- Add re-importability: running the importer twice creates new dashboards and new asset folders (no deduplication).

## Capabilities

### New Capability

- `confluence-html-import`: one-shot bulk-import from Confluence HTML export archives. New requirements REQ-CFLI-001..010.

### Modified Capabilities

- `dashboards` (optional): if `dashboard-tree` is present, the importer uses parent dashboard relationships to mirror Confluence hierarchy.
- `admin-settings`: the import endpoints are admin-only (requires NC admin role).

## Impact

**Affected code:**

- `lib/Service/ConfluenceImportService.php` — main import logic: ZIP parsing, page hierarchy extraction, HTML sanitization, image upload, link rewriting
- `lib/Service/ConfluenceArchiveParser.php` — ZIP extraction, index.html parsing, page metadata extraction
- `lib/Service/HtmlSanitizer.php` — HTML allow-list sanitization, macro placeholder injection
- `lib/Service/ConfluenceImageUploader.php` — fetch images from archive, upload to Nextcloud folder, track src rewrites
- `lib/Service/ConfluencePageMapper.php` — in-memory pageId-to-uuid map for link rewriting
- `lib/Controller/ConfluenceImportController.php` — `/api/admin/import/confluence` (import + dry-run)
- `lib/BackgroundJob/ConfluenceImportJob.php` — async job for large imports, called via job queue
- `lib/Db/ConfluenceImportLog.php` — entity for storing import job metadata (jobId, status, createdCount, errors, logUrl)
- `lib/Db/ConfluenceImportLogMapper.php` — mapper for import logs
- `lib/Command/ConfluenceImportCommand.php` — CLI command handler
- `appinfo/routes.php` — register 2 new admin routes
- `appinfo/backgroundjobs.php` — register async job class (if not auto-discovered)

**Affected APIs:**

- `POST /api/admin/import/confluence` — new admin endpoint (multipart, accepts `file` + optional `parentPath`)
- `POST /api/admin/import/confluence/dry-run` — new admin endpoint (same signature, no database changes)
- `GET /api/admin/import/confluence/jobs/{jobId}` — new admin endpoint (returns job status + progress)

**Dependencies:**

- No new composer dependencies; uses built-in ZIP handling (`ZipArchive`)
- Frontend: no UI change (import is admin-only, CLI-driven)

**Migration:**

- Zero-impact: no schema changes to existing dashboards. New `oc_mydash_confluence_import_logs` table added for job tracking.

## Success Criteria

- Admin can upload a 500-page Confluence export and see all 500 dashboards created with correct hierarchy
- Image attachments are uploaded to NC and links are rewritten
- A page with unparseable HTML is logged as error but does not block other pages
- Running the importer twice creates new dashboards (no merge/dedup)
- CLI command works headless with `--file` and `--parent-path` flags
- Dry-run correctly reports estimated dashboard count and warnings without modifying database
