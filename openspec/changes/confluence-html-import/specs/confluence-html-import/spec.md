---
status: draft
---

# Confluence HTML Export Importer

## ADDED Requirements

### Requirement: REQ-CFLI-001 Archive structure parsing

The system MUST parse a Confluence HTML Export ZIP archive and extract its directory structure to identify pages, attachments, and shared images.

#### Scenario: Extract archive metadata

- GIVEN a Confluence HTML export ZIP with structure: `index.html`, `SPACE-KEY/page-id.html`, `attachments/page-id/image.png`, `images/icon.png`
- WHEN the importer reads the archive
- THEN the system MUST identify all files and their roles (index, page, attachment, image)
- AND MUST extract the archive to a temporary directory for processing
- AND MUST NOT modify the source ZIP

#### Scenario: Missing index.html is an error

- GIVEN a ZIP archive without `index.html`
- WHEN the importer attempts to parse it
- THEN the system MUST return HTTP 400 with `{error: "index.html not found in archive"}`
- AND no import MUST proceed

#### Scenario: Nested page directories are supported

- GIVEN pages stored in nested space directories: `SPACE1/page1.html`, `SPACE1/SUB/page2.html`
- WHEN the importer reads the archive
- THEN the system MUST recognize both page files by `.html` extension
- AND extract each file's content for processing

#### Scenario: Attachments are located by page reference

- GIVEN a page file `SPACE/page-123.html` with `<img src="attachments/page-123/logo.png">`
- WHEN the importer processes the page
- THEN the system MUST locate `attachments/page-123/logo.png` in the archive
- AND treat it as a candidate for upload

### Requirement: REQ-CFLI-002 Page hierarchy extraction

The system MUST derive the page tree (parent-child relationships, sibling order) from two sources applied in priority order: (1) directory nesting within the extracted ZIP, and (2) the breadcrumb navigation inside each individual page file. `index.html` is used solely to assign sibling ordering, NOT to define parent-child relationships.

#### Scenario: Directory nesting establishes initial parent-child relationships

- GIVEN pages stored at `SPACE/page-123.html` and `SPACE/SUB/page-456.html`
- WHEN the importer builds the page hierarchy
- THEN `page-456` MUST be treated as a child of the page at the first directory level (`SPACE/`)
- AND directory depth determines the initial parent assignment before breadcrumb override is applied

#### Scenario: Breadcrumb overrides directory-based parent

- GIVEN a page file containing `<ol class="breadcrumbs"><li><a href="page-100.html">Section A</a></li><li>Current Page</li></ol>`
- WHEN the importer builds the page hierarchy
- THEN the system MUST parse the breadcrumb `<ol class="breadcrumbs">` or `<ol id="breadcrumbs">` to extract the parent chain
- AND the breadcrumb-derived parent MUST take precedence over the directory-derived parent
- AND the system MUST assign `parentPageId` to the ID extracted from the second-to-last breadcrumb link

#### Scenario: index.html provides sibling order only

- GIVEN `index.html` containing an ordered link list (`<a href="SPACE/page-123.html">`, `<a href="SPACE/page-456.html">`, ...)
- WHEN the importer reads `index.html`
- THEN the system MUST extract the 0-based position of each `href` match to assign sibling ordering
- AND MUST NOT infer parent-child relationships from `index.html` link nesting
- AND `index.html` links MUST be matched via `href` attribute parsing (regex on href attributes), not DOM tree depth

#### Scenario: Root pages have null parent

- GIVEN a page whose breadcrumb contains only its own title (no ancestor links)
- WHEN the importer extracts the hierarchy
- THEN the system MUST assign `parentPageId = null` to that page
- AND treat it as a root page in the destination dashboard hierarchy

#### Scenario: Malformed breadcrumb does not block import

- GIVEN a page with a missing or malformed breadcrumb `<ol>`
- WHEN the importer encounters the unparseable breadcrumb
- THEN the system MUST fall back to directory-nesting for parent assignment
- AND MUST log a warning for the affected page
- AND MUST continue processing remaining pages

### Requirement: REQ-CFLI-003 Confluence page → MyDash dashboard conversion

The system MUST convert each Confluence page into a MyDash dashboard with the page's `name = <pageTitle>` and a full-width text-display widget containing the page body.

#### Scenario: Create dashboard from page

- GIVEN a Confluence page with title "Architecture Overview" and body HTML
- WHEN the importer converts the page to a dashboard
- THEN the system MUST create a dashboard with `name = "Architecture Overview"`
- AND MUST set `createdBy = <system-admin-user>` or configured owner
- AND MUST return HTTP 201 with the new dashboard object

#### Scenario: Dashboard inherits parent from page hierarchy

- GIVEN a Confluence page "Q1 Goals" with `parentPageId = <uuid-of-planning-page>`
- WHEN the importer creates the dashboard
- THEN the system MUST set `parentUuid = <uuid-of-planning-dashboard>` (requires `dashboard-tree` capability)
- AND the dashboard tree MUST reflect the Confluence hierarchy

#### Scenario: Root Confluence pages become root dashboards

- GIVEN a top-level Confluence page with `parentPageId = null`
- WHEN the importer creates the dashboard
- THEN the dashboard MUST have `parentUuid = null` or be a root dashboard
- AND MUST NOT be nested under any parent

#### Scenario: Text-display widget captures page body

- GIVEN a Confluence page HTML file
- WHEN the importer extracts the page body
- THEN the system MUST apply the following selector waterfall in order, using the first non-empty result:
  1. `//div[@id='main-content']`
  2. `//div[contains(@class, 'wiki-content')]`
  3. `//div[contains(@class, 'page-content')]`
  4. `//div[@id='content']`
  5. `//main`
  6. `//article`
  - Fallback: regex extraction of `<body>…</body>` content, then raw HTML as last resort
- AND before returning the selected node the system MUST strip the following navigation elements in place: `div#pagetreesearch`, `div.breadcrumbs`, `div.pageSection`, `form[name=pagetreesearchform]`, `form.aui`, `nav`, `div.page-metadata`
- AND MUST create a text-display widget with `type = "text"`, `content.text = <sanitized-html>`
- AND MUST place the widget at grid position (0, 0) with size (12, 12) — full dashboard width
- AND MUST set `content.fontSize = "14px"`, `content.color = "var(--color-main-text)"`, `content.backgroundColor = "transparent"`, `content.textAlign = "left"`

#### Scenario: Widget HTML is sanitized before storage

- GIVEN a page with `<div id="main-content"><p>Safe <script>alert(1)</script> text</p></div>`
- WHEN the importer extracts the body
- THEN the system MUST sanitize the HTML (strip `<script>`, etc.) BEFORE storing in the widget
- AND the stored content MUST be safe for rendering via `v-html`

### Requirement: REQ-CFLI-004 Internal link rewriting

The system MUST rewrite internal Confluence links (`<a href="pageId.html">`) to point to the corresponding imported MyDash dashboard.

> **NOTE — MyDash addition:** No link-rewriting code exists in the ported reference. The entire link-rewriting subsystem described here must be built from scratch. The reference leaves internal Confluence `<a href="pageId.html">` links as-is in exported HTML blocks; there is no `pageId→uuid` map and no href-rewrite pass anywhere in the reference codebase.

#### Scenario: Rewrite link to sibling page

- GIVEN a page with `<a href="page-456.html">See Also</a>` where `page-456` was imported as dashboard UUID `uuid-456`
- WHEN the importer processes the page body
- THEN the system MUST rewrite the link to `<a href="/apps/mydash/dashboard/uuid-456">See Also</a>`
- AND the dashboard MUST be navigable via the rewritten link

#### Scenario: Cross-space links are rewritten

- GIVEN a page with `<a href="OTHER-SPACE/page-789.html">External Page</a>` where `page-789` was imported as UUID `uuid-789`
- WHEN the importer processes the link
- THEN the system MUST rewrite it to `<a href="/apps/mydash/dashboard/uuid-789">External Page</a>`

#### Scenario: Links to non-existent pages are logged

- GIVEN a page with `<a href="page-999.html">Missing Page</a>` where `page-999` does NOT exist in the Confluence archive
- WHEN the importer processes the link
- THEN the system MUST log a warning `"Link to non-existent page page-999 in page ..."`
- AND MUST preserve the link as-is (unchanged href) so the admin can manually investigate
- AND MUST continue processing the page

#### Scenario: External links (not Confluence pages) are untouched

- GIVEN a page with `<a href="https://example.com">External</a>`
- WHEN the importer processes the link
- THEN the system MUST NOT modify the `href` (it is not a Confluence page reference)
- AND the link MUST remain `<a href="https://example.com">External</a>`

### Requirement: REQ-CFLI-005 Image upload and source rewriting

The system MUST upload Confluence page attachments and shared images to a Nextcloud folder and rewrite `<img src>` attributes to point to the new NC URLs.

> **NOTE — MyDash addition:** The ported reference does NOT upload anything to Nextcloud. It registers `MediaDownload` objects for Storage Format `<ac:image>` elements only, but the resolution is not implemented and no file-write to Nextcloud occurs. Plain HTML `<img src="attachments/...">` tags are not scanned at all. The `MyDash/Imports/{timestamp}/` destination folder and the full upload pipeline described below are new work that must be designed and built.

#### Scenario: Upload attachment image to NC folder

- GIVEN a page with `<img src="attachments/page-123/diagram.png">` where the file exists in the archive
- WHEN the importer processes the image
- THEN the system MUST upload the file to `MyDash/Imports/{importTimestamp}/diagram.png` in Nextcloud
- AND MUST rewrite `src` to the NC download URL: `https://<nc-domain>/remote.php/dav/files/<user>/MyDash/Imports/{timestamp}/diagram.png` (or equivalent public share URL)
- AND the image MUST be accessible in the rendered dashboard widget

#### Scenario: Shared images from images/ directory

- GIVEN a page with `<img src="images/icon.png">` where `images/icon.png` exists in the archive
- WHEN the importer processes the image
- THEN the system MUST upload to `MyDash/Imports/{importTimestamp}/icon.png`
- AND MUST rewrite `src` to the NC URL

#### Scenario: Missing image files are logged

- GIVEN a page with `<img src="attachments/page-123/missing.png">` where the file does NOT exist in the archive
- WHEN the importer processes the image
- THEN the system MUST log a warning `"Image file not found: attachments/page-123/missing.png"`
- AND MUST replace the image element with a placeholder: `<em>[Image: missing.png not found]</em>`
- AND MUST continue processing the page

#### Scenario: Image upload respects Nextcloud quota

- GIVEN a total attachment size of 2 GB and a Nextcloud user quota of 1 GB
- WHEN the importer attempts to upload attachments
- THEN the system MUST respect the quota and log an error for files that cannot be uploaded due to quota limits
- AND MUST continue importing other pages (per REQ-CFLI-009 error resilience)

#### Scenario: Each import run uses a new timestamp folder

- GIVEN two consecutive imports of the same Confluence archive
- WHEN the first import uploads images to `MyDash/Imports/2026-04-01T10-30-00/`
- AND the second import runs later
- THEN the second import MUST use a new folder: `MyDash/Imports/2026-04-01T10-45-00/`
- AND MUST NOT overwrite files from the first import
- AND created dashboards from the two runs MUST be independent (per REQ-CFLI-010)

### Requirement: REQ-CFLI-006 Confluence macro rendering and placeholder injection

The system MUST process Confluence `<ac:structured-macro>` elements through registered handlers that produce rich widget output. Recognised macros are rendered into appropriate blocks; unrecognised macros receive a fallback placeholder block.

#### Scenario: Panel-type macros render as styled blocks

- GIVEN a page body with `<ac:structured-macro ac:name="info">` (or `note`, `warning`, `tip`, `error`, `panel`)
- WHEN the importer processes the macro
- THEN the system MUST produce a text widget block styled with the CSS class `confluence-panel-{type}` (e.g., `confluence-panel-info`)
- AND MUST preserve the macro body content inside the styled block

#### Scenario: Code macro renders as preformatted block

- GIVEN a page body with `<ac:structured-macro ac:name="code"><ac:parameter ac:name="language">java</ac:parameter>…</ac:structured-macro>`
- WHEN the importer processes the macro
- THEN the system MUST produce a `<pre><code class="language-java">…</code></pre>` block
- AND MUST NOT replace it with a placeholder

#### Scenario: Expand macro renders as collapsible block

- GIVEN a page body with `<ac:structured-macro ac:name="expand">`
- WHEN the importer processes the macro
- THEN the system MUST produce a `<details><summary>…</summary>…</details>` block
- AND MUST NOT replace it with a placeholder

#### Scenario: Attachment and viewfile macros render as placeholders

- GIVEN a page body with `<ac:structured-macro ac:name="attachments">` or `<ac:structured-macro ac:name="viewfile">`
- WHEN the importer processes the macro
- THEN the system MUST produce a static HTML placeholder link block
- AND MUST NOT attempt dynamic file listing

#### Scenario: Unrecognised macros receive fallback placeholder

- GIVEN a page body with `<ac:structured-macro ac:name="sql">` or any other unregistered macro name
- WHEN the importer processes the macro
- THEN the system MUST produce `<div class="confluence-unsupported-macro">⚠️ Unsupported macro: <code>sql</code></div>`
- AND the macro name MUST be included in the placeholder
- AND admins MUST be able to identify which macros were skipped

#### Scenario: Unsupported HTML elements are stripped

- GIVEN a page with elements like `<math>`, `<svg>`, custom Confluence elements
- WHEN the importer sanitizes the HTML
- THEN the system MUST strip these elements entirely (not replaced with placeholders)
- AND MUST log a debug note for each stripped element

### Requirement: REQ-CFLI-007 Dry-run endpoint for import preview

The system MUST expose a `POST /api/admin/import/confluence/dry-run` endpoint that performs all parsing and validation WITHOUT creating any dashboards or uploading files.

> **NOTE — MyDash addition:** No dry-run path exists in the reference implementation. The reference controller is fully synchronous with a single endpoint and no `dry-run` parameter, flag, or separate route. This is a new endpoint to build.

#### Scenario: Dry-run returns page count

- GIVEN a Confluence export with 150 pages
- WHEN the admin sends POST /api/admin/import/confluence/dry-run with the ZIP file
- THEN the system MUST parse the archive, analyze the page tree, and return HTTP 200 with:
  ```json
  {
    "pageCount": 150,
    "attachmentCount": 42,
    "estimatedDashboards": 150,
    "warnings": ["Link to non-existent page page-999 in page Architecture"],
    "estimatedAssetSize": "25 MB"
  }
  ```
- AND NO dashboards, widgets, or files MUST be created

#### Scenario: Dry-run identifies parsing warnings

- GIVEN a Confluence export with some malformed pages
- WHEN the admin runs dry-run
- THEN the system MUST log warnings for unparseable breadcrumbs, missing images, broken links, etc.
- AND MUST return the full warnings list
- AND the admin MUST see these warnings BEFORE committing to the full import

#### Scenario: Dry-run does not modify the archive

- GIVEN a Confluence export ZIP
- WHEN the admin runs dry-run
- THEN the system MUST NOT delete, modify, or extract the uploaded ZIP to permanent storage
- AND MUST use a temporary directory that is cleaned up after dry-run completes

### Requirement: REQ-CFLI-008 Asynchronous import for large archives

The system MUST run imports synchronously for archives with <100 pages and asynchronously (background job) for larger archives, with job tracking and polling support.

> **NOTE — MyDash addition:** The reference controller is fully synchronous — it processes in the request thread and returns a single JSON response. There is no page-count threshold, no background job dispatch, no job-id, and no polling endpoint. The sync/async split and all job-tracking described below must be built from scratch. Note: async jobs must not rely on `ITempManager` temp paths, which are cleaned on request end.

#### Scenario: Small import runs synchronously

- GIVEN a Confluence export with 50 pages
- WHEN the admin sends POST /api/admin/import/confluence with the ZIP file
- THEN the system MUST process the import in the request thread
- AND MUST return HTTP 200 with:
  ```json
  {
    "createdDashboardCount": 50,
    "skippedPageCount": 0,
    "errors": [],
    "importLogUrl": "/apps/mydash/admin/import-logs/log-uuid-123"
  }
  ```

#### Scenario: Large import is queued as background job

- GIVEN a Confluence export with 500 pages
- WHEN the admin sends POST /api/admin/import/confluence with the ZIP file
- THEN the system MUST queue an async job and return HTTP 202 (Accepted) with:
  ```json
  {
    "jobId": "import-uuid-456",
    "status": "processing",
    "message": "Import queued for background processing"
  }
  ```
- AND the job MUST process the import in the background (using Nextcloud's JobList / cron)

#### Scenario: Poll job status via GET endpoint

- GIVEN a background import job with `jobId = "import-uuid-456"`
- WHEN the admin sends GET /api/admin/import/confluence/jobs/import-uuid-456
- THEN the system MUST return HTTP 200 with:
  ```json
  {
    "jobId": "import-uuid-456",
    "status": "processing",
    "progress": {"current": 250, "total": 500},
    "createdDashboardCount": 250,
    "skippedPageCount": 0,
    "errors": []
  }
  ```

#### Scenario: Job completion is reported via status endpoint

- GIVEN a background job that has finished
- WHEN the admin polls GET /api/admin/import/confluence/jobs/import-uuid-456
- THEN the system MUST return HTTP 200 with `status = "completed"` and final counts
- AND MUST include an `importLogUrl` for detailed logs

### Requirement: REQ-CFLI-009 Error resilience: single-page failures do not abort import

The system MUST skip pages that fail to parse and continue importing remaining pages, logging errors for admin review.

> **NOTE — Partial port:** The reference contains a null-guard that silently skips pages where `parseHtmlFile` returns `null` (unreadable file or empty body), and the loop continues. However, there is no `try/catch` around the per-file parsing loop, no error accumulator, and no `errors` list in the response — any thrown exception propagates to the controller and returns HTTP 500 for the entire request. Implementing the full resilience contract below requires adding per-page `try/catch`, an error-accumulation structure, and surfacing collected errors in the response.

#### Scenario: Page with malformed HTML is skipped

- GIVEN a Confluence page with severely broken HTML (unclosed tags, invalid nesting)
- WHEN the importer attempts to parse the page
- THEN the system MUST catch the parsing error, log it as a warning with the page ID and filename
- AND MUST continue processing other pages
- AND MUST include the error in the final `errors` list

#### Scenario: Page with invalid parent reference is skipped

- GIVEN a page with `parentPageId = "non-existent-parent-id"`
- WHEN the importer attempts to resolve the parent
- THEN the system MUST log an error `"Parent dashboard not found for page ..."`
- AND MUST create the page as a root dashboard (orphan) OR skip it (admin configurable)
- AND MUST allow other pages to be imported

#### Scenario: Final report includes all errors

- GIVEN an import of 100 pages where 5 fail to parse
- WHEN the import completes
- THEN the system MUST return:
  ```json
  {
    "createdDashboardCount": 95,
    "skippedPageCount": 5,
    "errors": [
      {"pageId": "page-001", "reason": "Malformed HTML"},
      {"pageId": "page-002", "reason": "Parent not found"},
      ...
    ]
  }
  ```
- AND the admin MUST see the full list of skipped pages and reasons

### Requirement: REQ-CFLI-010 Re-importability: no deduplication by page ID

The system MUST allow the same Confluence archive to be imported multiple times, creating new dashboards each run (no merge/dedup by Confluence page ID).

#### Scenario: Second import creates new dashboards

- GIVEN a first import of a Confluence archive that created 100 dashboards
- WHEN the admin runs the same import again
- THEN the system MUST create 100 NEW dashboards (not update the existing ones)
- AND the new dashboards MUST have different UUIDs from the first run
- AND the admin MUST manually manage/delete old imports if desired

#### Scenario: Assets are uploaded to separate timestamped folders

- GIVEN two imports of the same archive run on the same day
- WHEN the first import uploads images to `MyDash/Imports/2026-04-01T10-30-00/`
- AND the second import runs at `2026-04-01T10-45-00`
- THEN the second import MUST upload to a new folder: `MyDash/Imports/2026-04-01T10-45-00/`
- AND image `src` attributes MUST point to the correct folder for each import
- AND no file overwrite occurs

#### Scenario: Dashboard UUIDs are unique across runs

- GIVEN two imports of the same Confluence archive
- WHEN both imports create a dashboard for the same Confluence page
- THEN each dashboard MUST have a unique UUID
- AND both dashboards MUST be independently editable and deletable

### Requirement: REQ-CFLI-011 CLI command for headless import

The system MUST expose a Nextcloud OCC command `php occ mydash:import:confluence` for imports via CLI/cron, with options for file path and parent dashboard path.

> **NOTE — MyDash addition:** No `occ mydash:import:confluence` command exists in the reference. The reference contains an `ImportPagesCommand` that operates on the native IntraVox JSON format only, not on Confluence ZIP archives. The `occ mydash:import:confluence` command must be built from scratch.

#### Scenario: CLI import with file option

- GIVEN an admin with shell access to the Nextcloud server
- WHEN they run `php occ mydash:import:confluence --file=/tmp/export.zip`
- THEN the system MUST:
  - Load the ZIP from the specified path
  - Run the full import (synchronous or async, depending on size)
  - Output progress to stdout: `Importing 150 pages...`, `Created 150 dashboards, 0 errors`
  - Exit with code 0 on success, non-zero on failure

#### Scenario: CLI import with parent-path option

- GIVEN an admin wanting to import under a specific parent dashboard
- WHEN they run `php occ mydash:import:confluence --file=/tmp/export.zip --parent-path=/Finance/2026`
- THEN the system MUST:
  - Resolve the path `/Finance/2026` to a dashboard UUID (via the path resolution API)
  - Use that UUID as the parent for all root Confluence pages in the import
  - Create a subtree under `/Finance/2026` with the imported pages

#### Scenario: CLI import handles missing file gracefully

- GIVEN an admin running `php occ mydash:import:confluence --file=/nonexistent.zip`
- WHEN the file does not exist
- THEN the system MUST output `Error: File /nonexistent.zip not found`
- AND MUST exit with non-zero code
- AND MUST NOT attempt to import

#### Scenario: CLI import supports dry-run flag

- GIVEN an admin running `php occ mydash:import:confluence --file=/tmp/export.zip --dry-run`
- WHEN the `--dry-run` flag is present
- THEN the system MUST output the dry-run preview (page count, warnings, etc.)
- AND MUST NOT create any dashboards or files
- AND MUST exit with code 0

### Requirement: REQ-CFLI-012 HTML sanitization with allow-list

The system MUST sanitize all imported page HTML using a strict allow-list of formatting tags, stripping everything else to prevent XSS and maintain consistency with the text-display widget.

#### Scenario: Allow-listed tags are preserved

- GIVEN a page with `<p>text</p> <strong>bold</strong> <em>italic</em> <a href="/link">link</a> <ul><li>item</li></ul>`
- WHEN the importer sanitizes the HTML
- THEN all these tags MUST be preserved exactly
- AND the rendered widget MUST display the formatted content

#### Scenario: Disallowed tags are stripped

- GIVEN a page with `<div class="highlight">text</div> <span style="color:red">red</span> <button>click</button>`
- WHEN the importer sanitizes the HTML
- THEN the system MUST strip the disallowed tags, preserving only the text content: `text red click` (or structured as allowed tags)
- AND the sanitized HTML MUST be safe for `v-html` rendering

#### Scenario: Event handlers and javascript: URLs are stripped

- GIVEN HTML with `<a href="javascript:alert(1)" onclick="alert(2)">x</a> <img onerror="alert(3)">`
- WHEN the importer sanitizes
- THEN all event handlers (`onclick`, `onerror`, etc.) and `javascript:` URLs MUST be removed
- AND the result MUST be safe (e.g., `<a>x</a>` or `<img>` with no handlers)

#### Scenario: Confluence-specific markup falls through to macro handler

- GIVEN content with `<ac:structured-macro ac:name="expand">` or similar Confluence markup
- WHEN the importer processes the content
- THEN recognised macros MUST be rendered per REQ-CFLI-006 (not treated as generic disallowed elements)
- AND unrecognised macros MUST receive the `<div class="confluence-unsupported-macro">` fallback per REQ-CFLI-006
- AND the page MUST still be importable (not fail entirely)

#### Scenario: Allow-list includes all common semantic tags

- GIVEN the allow-list: `<p>`, `<h1>`, `<h2>`, `<h3>`, `<h4>`, `<h5>`, `<h6>`, `<a>`, `<strong>`, `<em>`, `<ul>`, `<ol>`, `<li>`, `<img>`, `<table>`, `<tr>`, `<td>`, `<th>`, `<thead>`, `<tbody>`, `<blockquote>`, `<pre>`, `<code>`, `<br>`
- WHEN a page is imported with any of these tags
- THEN all MUST be preserved with their semantic meaning intact
- AND attributes on allowed tags (e.g., `<a href="...">`, `<img src="...">`) MUST be whitelisted per tag (href on `<a>`, src on `<img>`, etc.)
