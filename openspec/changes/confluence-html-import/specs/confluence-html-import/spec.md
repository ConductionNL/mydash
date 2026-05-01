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

### Requirement: REQ-CFLI-002 Page hierarchy extraction from index.html

The system MUST parse Confluence's `index.html` (table of contents) to extract the page tree structure (parent-child relationships, page titles, Confluence page IDs).

#### Scenario: Extract page hierarchy from TOC

- GIVEN `index.html` with Confluence TOC structure listing pages with titles and IDs (e.g., `<a href="SPACE/page-123.html">Page Title</a>`)
- WHEN the importer parses the TOC
- THEN the system MUST extract: page ID (from filename or TOC entry), page title, parent page ID (if nested in TOC), and any depth/order indicators
- AND build an in-memory tree structure `{pageId, title, parentPageId, children: [...]}`

#### Scenario: Root pages have null parent

- GIVEN a TOC entry at the top level (not nested under another page)
- WHEN the importer extracts the hierarchy
- THEN the system MUST assign `parentPageId = null` to that page
- AND treat it as a root page in the destination dashboard hierarchy

#### Scenario: Nested pages inherit parent

- GIVEN a TOC with nested structure: "Section A" > "Subsection A.1"
- WHEN the importer extracts the hierarchy
- THEN pages under "Subsection A.1" MUST have `parentPageId = <uuid-of-section-a-1-dashboard>`
- AND the depth MUST be preserved

#### Scenario: Malformed TOC does not block import

- GIVEN `index.html` with malformed or incomplete TOC entries
- WHEN the importer encounters unparseable TOC sections
- THEN the system MUST log warnings for each unparseable entry
- AND MUST continue processing remaining entries
- AND MUST import the pages that CAN be extracted from actual page files

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

- GIVEN a Confluence page with body `<div id="main-content"><p>Page content</p></div>`
- WHEN the importer creates the dashboard
- THEN the system MUST extract the content of `<div id="main-content">` (if present; fallback to `<body>`)
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

### Requirement: REQ-CFLI-006 Confluence macro placeholder injection

The system MUST identify Confluence macros (non-representable block elements like `<ac:structured-macro>`) and replace them with human-readable placeholders so admins know what content was skipped.

#### Scenario: Replace structured-macro with placeholder

- GIVEN a page body with `<ac:structured-macro ac:name="code"><ac:parameter ac:name="language">java</ac:parameter>...</ac:structured-macro>`
- WHEN the importer sanitizes the content
- THEN the system MUST replace the macro with `<p><em>[Macro: code not imported]</em></p>`
- AND the macro name MUST be extracted and included in the placeholder text

#### Scenario: Unsupported HTML elements are stripped

- GIVEN a page with elements like `<math>`, `<svg>`, custom Confluence elements
- WHEN the importer sanitizes the HTML
- THEN the system MUST strip these elements entirely (not replaced with placeholders)
- AND MUST log a debug note for each stripped element

#### Scenario: Macro placeholders include context

- GIVEN multiple macros of different types in a single page
- WHEN the importer processes the page
- THEN each placeholder MUST include the macro name: `[Macro: sql not imported]`, `[Macro: jira not imported]`, etc.
- AND admins MUST be able to identify which macros were skipped and why

### Requirement: REQ-CFLI-007 Dry-run endpoint for import preview

The system MUST expose a `POST /api/admin/import/confluence/dry-run` endpoint that performs all parsing and validation WITHOUT creating any dashboards or uploading files.

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
- THEN the system MUST log warnings for unparseable TOC entries, missing images, broken links, etc.
- AND MUST return the full warnings list
- AND the admin MUST see these warnings BEFORE committing to the full import

#### Scenario: Dry-run does not modify the archive

- GIVEN a Confluence export ZIP
- WHEN the admin runs dry-run
- THEN the system MUST NOT delete, modify, or extract the uploaded ZIP to permanent storage
- AND MUST use a temporary directory that is cleaned up after dry-run completes

### Requirement: REQ-CFLI-008 Asynchronous import for large archives

The system MUST run imports synchronously for archives with <100 pages and asynchronously (background job) for larger archives, with job tracking and polling support.

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

#### Scenario: Confluence-specific markup is converted to placeholders

- GIVEN content with `<ac:macro ac:name="expand">`, `<at:macro at:name="code">`, or similar Confluence markup
- WHEN the importer sanitizes
- THEN these MUST be replaced with `<p><em>[Macro: expand not imported]</em></p>` etc.
- AND the page MUST still be importable (not fail entirely)

#### Scenario: Allow-list includes all common semantic tags

- GIVEN the allow-list: `<p>`, `<h1>`, `<h2>`, `<h3>`, `<h4>`, `<h5>`, `<h6>`, `<a>`, `<strong>`, `<em>`, `<ul>`, `<ol>`, `<li>`, `<img>`, `<table>`, `<tr>`, `<td>`, `<th>`, `<thead>`, `<tbody>`, `<blockquote>`, `<pre>`, `<code>`, `<br>`
- WHEN a page is imported with any of these tags
- THEN all MUST be preserved with their semantic meaning intact
- AND attributes on allowed tags (e.g., `<a href="...">`, `<img src="...">`) MUST be whitelisted per tag (href on `<a>`, src on `<img>`, etc.)
