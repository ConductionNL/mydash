---
status: implemented
---

# Confluence HTML Export Importer Specification

## Purpose

Organisations migrating from Atlassian Confluence (or supplementing it
with MyDash) need a one-shot bulk import that converts existing
Confluence page hierarchies into MyDash dashboards. Manual recreation of
hundreds of pages is impractical. This capability lets a Nextcloud admin
upload a Confluence "HTML Export" archive and automatically generate
MyDash dashboards with the page content preserved, the page tree
mirrored via the `dashboard-tree` capability, and Confluence Storage
Format macros expanded into safe HTML.

## Architecture

```
ZIP upload (multipart)
   │
   ▼
ConfluenceImportController         ─ admin guard
   │
   ▼
ConfluenceImportService            ─ orchestrates parse → create → backfill
   │
   ├─▶ ArchiveParser               ─ ZIP read, body extract, hierarchy
   │      │
   │      └─ ParsedArchive { pages, attachments, images, warnings }
   │
   ├─▶ DashboardFactory            ─ one dashboard per page
   ├─▶ DashboardTreeService        ─ parent + depth + slug uniqueness
   ├─▶ MacroRenderer               ─ <ac:structured-macro> → HTML
   ├─▶ LinkRewriter                ─ pageId → /apps/mydash/dashboard/{uuid}
   └─▶ HtmlSanitizer               ─ allow-list filter (REQ-CFLI-012)
```

The CLI surface (`occ mydash:import:confluence`) delegates to the same
service.

## Requirements

### Requirement: REQ-CFLI-001 Archive structure parsing

The system MUST parse a Confluence HTML Export ZIP archive and extract
its directory structure to identify pages, attachments, and shared
images.

#### Scenario: Extract archive metadata

- GIVEN a Confluence HTML export ZIP with structure: `index.html`, `SPACE-KEY/page-id.html`, `attachments/page-id/image.png`, `images/icon.png`
- WHEN the importer reads the archive
- THEN the system MUST identify all files and their roles (index, page, attachment, image)
- AND MUST NOT modify the source ZIP

#### Scenario: Missing index.html is an error

- GIVEN a ZIP archive without `index.html`
- WHEN the importer attempts to parse it
- THEN the system MUST raise an `InvalidArgumentException` (mapped to HTTP 400 by the controller) with message `index.html not found in archive`
- AND no import MUST proceed

#### Scenario: Nested page directories are supported

- GIVEN pages stored in nested space directories: `SPACE1/page1.html`, `SPACE1/SUB/page2.html`
- WHEN the importer reads the archive
- THEN the system MUST recognize both page files by `.html` extension
- AND extract each file's content for processing

#### Scenario: Attachments and shared images are tracked

- GIVEN a ZIP containing `attachments/page-1/diagram.png` and `images/icon.png`
- WHEN the importer reads the archive
- THEN both paths MUST appear in the `ParsedArchive::$attachments` / `$images` lists
- AND the parser MUST report `attachmentCount = 2`

### Requirement: REQ-CFLI-002 Page hierarchy extraction

The system MUST derive the page tree (parent-child relationships,
sibling order) from two sources applied in priority order: (1) the
breadcrumb navigation inside each individual page file, and (2) the
directory nesting of the extracted ZIP. `index.html` is used solely to
assign sibling ordering, NOT to define parent-child relationships.

#### Scenario: Breadcrumb drives the parent assignment

- GIVEN a page file containing `<ol class="breadcrumbs"><li><a href="page-100.html">Section A</a></li><li>Current Page</li></ol>`
- WHEN the importer builds the page hierarchy
- THEN the system MUST parse the breadcrumb `<ol class="breadcrumbs">` or `<ol id="breadcrumbs">` to extract the parent chain
- AND MUST assign `parentPageId` to the basename of the second-to-last breadcrumb link

#### Scenario: Directory nesting fills the gap when breadcrumb is absent

- GIVEN pages stored at `SPACE.html` (parent) and `SPACE/page-456.html` (child) with NO breadcrumb on the child
- WHEN the importer builds the hierarchy
- THEN `page-456` MUST be parented to `SPACE` via the directory-nesting fallback
- AND the breadcrumb-derived parent (when present) takes precedence

#### Scenario: index.html provides sibling order only

- GIVEN `index.html` containing an ordered link list (`<a href="SPACE/page-123.html">`, `<a href="SPACE/page-456.html">`, ...)
- WHEN the importer reads `index.html`
- THEN the system MUST extract the 0-based position of each `href` match to assign sibling ordering
- AND MUST NOT infer parent-child relationships from `index.html` link nesting
- AND `index.html` links MUST be matched via `href` attribute parsing (regex), not DOM tree depth

#### Scenario: Root pages have null parent

- GIVEN a page whose breadcrumb is absent and whose directory has no sibling parent file
- WHEN the importer extracts the hierarchy
- THEN the system MUST assign `parentPageId = null` to that page
- AND treat it as a root page in the destination dashboard hierarchy

### Requirement: REQ-CFLI-003 Confluence page → MyDash dashboard conversion

The system MUST convert each Confluence page into a MyDash dashboard
with the page's `name = <pageTitle>` and a single full-width text-display
widget containing the sanitised page body.

#### Scenario: Create dashboard from page

- GIVEN a Confluence page with title "Architecture Overview" and body HTML
- WHEN the importer converts the page to a dashboard
- THEN the system MUST create a dashboard with `name = "Architecture Overview"`
- AND MUST set `userId = <importing-user>` (or the `--user` CLI flag value)

#### Scenario: Dashboard inherits parent from page hierarchy

- GIVEN a Confluence page "Q1 Goals" with `parentPageId = "planning"` already imported as `<uuid-of-planning-dashboard>`
- WHEN the importer creates the dashboard
- THEN the system MUST set `parentUuid = <uuid-of-planning-dashboard>` (via the `dashboard-tree` capability)
- AND the dashboard tree MUST reflect the Confluence hierarchy

#### Scenario: Root Confluence pages become root dashboards

- GIVEN a top-level Confluence page with `parentPageId = null` AND no `--parent-path` / `parentUuid` argument
- WHEN the importer creates the dashboard
- THEN the dashboard MUST have `parentUuid = null`

#### Scenario: Caller-supplied root parent slots all roots beneath it

- GIVEN the admin invokes `POST /api/admin/import/confluence?parentUuid=<uuid>` with a valid dashboard UUID
- WHEN the importer creates root pages
- THEN every root Confluence page MUST be created with `parentUuid = <uuid>`
- AND a missing / unknown `parentUuid` MUST log a warning and fall back to root-level placement

#### Scenario: Text-display widget captures page body via selector waterfall

- GIVEN a Confluence page HTML file
- WHEN the importer extracts the page body
- THEN the system MUST apply the following XPath selector waterfall in order, using the first non-empty match:
  1. `//div[@id='main-content']`
  2. `//div[contains(@class, 'wiki-content')]`
  3. `//div[contains(@class, 'page-content')]`
  4. `//div[@id='content']`
  5. `//main`
  6. `//article`
  - Fallback: regex extraction of `<body>…</body>` content, then raw HTML as last resort
- AND before returning the selected node the system MUST strip the following navigation elements in place: `div#pagetreesearch`, `div.breadcrumbs`, `div.pageSection`, `form[name=pagetreesearchform]`, `form.aui`, `nav`, `div.page-metadata`
- AND MUST create a text-display widget at grid position `(0, 0)` with size `12 × 12` (full-width)
- AND MUST store the sanitised body in the placement `styleConfig.text` field with the standard `fontSize`, `color`, `backgroundColor`, `textAlign` defaults

#### Scenario: Widget HTML is sanitised before storage

- GIVEN a page with `<div id="main-content"><p>Safe <script>alert(1)</script> text</p></div>`
- WHEN the importer extracts the body
- THEN the system MUST sanitise the HTML (strip `<script>` and its content) BEFORE storing in the widget
- AND the stored content MUST be safe for `v-html` rendering by the text-display widget

### Requirement: REQ-CFLI-004 Internal link rewriting

The system MUST rewrite internal Confluence links (`<a href="pageId.html">`)
to point to the corresponding imported MyDash dashboard.

#### Scenario: Rewrite link to sibling page

- GIVEN a page with `<a href="page-456.html">See Also</a>` where `page-456` was imported as dashboard UUID `uuid-456`
- WHEN the importer's link rewriter runs
- THEN the system MUST rewrite the link to `<a href="/apps/mydash/dashboard/uuid-456">See Also</a>`

#### Scenario: Cross-space links are rewritten

- GIVEN a page with `<a href="OTHER-SPACE/page-789.html">External Page</a>` where `page-789` was imported as UUID `uuid-789`
- WHEN the importer processes the link
- THEN the system MUST rewrite it to `<a href="/apps/mydash/dashboard/uuid-789">External Page</a>`

#### Scenario: Links to non-existent pages are logged

- GIVEN a page with `<a href="page-999.html">Missing Page</a>` where `page-999` does NOT appear in the import
- WHEN the importer processes the link
- THEN the system MUST emit a warning of the form `Link to unknown Confluence page: page-999.html`
- AND MUST preserve the link as-is so the admin can manually investigate

#### Scenario: External links are untouched

- GIVEN a page with `<a href="https://example.com">External</a>` (or `mailto:`, `tel:`, leading `/`, leading `#`)
- WHEN the importer processes the link
- THEN the system MUST NOT modify the `href`

### Requirement: REQ-CFLI-005 Image source preservation

The system MUST preserve `<img src>` references through the import so the
source filename / URL survives the sanitisation pass and a follow-up
upload pipeline can rewrite them.

> **Implementation status:** The current importer keeps `<img src>` unchanged (whitelisted by the sanitiser) and converts `<ac:image><ri:attachment ri:filename="x"/></ac:image>` to `<img src="x" alt="x">` so the asset filename is recoverable. The `MyDash/Imports/{timestamp}/` upload pipeline is reported in the import result + dry-run payload as the `assetFolder` field but the actual file write is deferred to a follow-up change (`confluence-attachment-uploads`).

#### Scenario: ac:image becomes a plain img tag

- GIVEN a page body with `<ac:image><ri:attachment ri:filename="diagram.png"/></ac:image>`
- WHEN the importer processes the macro
- THEN the system MUST emit `<img src="diagram.png" alt="diagram.png">`

#### Scenario: Plain HTML img tags survive sanitisation

- GIVEN a page body with `<img src="attachments/page-1/diagram.png" alt="">` already in the Confluence HTML
- WHEN the importer sanitises the body
- THEN the `<img>` tag MUST be preserved with its `src` and `alt` attributes
- AND the `src` MUST remain a relative archive path so the follow-up upload pipeline can resolve it

### Requirement: REQ-CFLI-006 Confluence macro rendering

The system MUST process Confluence `<ac:structured-macro>` elements
through registered renderers that produce rich HTML output. Recognised
macros are rendered into appropriate blocks; unrecognised macros receive
a fallback placeholder block.

#### Scenario: Panel-type macros render as styled blocks

- GIVEN a page body with `<ac:structured-macro ac:name="info">` (or `note`, `warning`, `tip`, `error`, `panel`)
- WHEN the importer processes the macro
- THEN the system MUST produce a `<div class="confluence-panel-{type}">…</div>` block (e.g. `confluence-panel-info`)
- AND MUST preserve the macro body content inside the styled block

#### Scenario: Code macro renders as preformatted block

- GIVEN a page body with `<ac:structured-macro ac:name="code"><ac:parameter ac:name="language">java</ac:parameter><ac:plain-text-body><![CDATA[…]]></ac:plain-text-body></ac:structured-macro>`
- WHEN the importer processes the macro
- THEN the system MUST produce a `<pre><code class="language-java">…</code></pre>` block

#### Scenario: Expand macro renders as collapsible block

- GIVEN a page body with `<ac:structured-macro ac:name="expand">`
- WHEN the importer processes the macro
- THEN the system MUST produce a `<details><summary>…</summary>…</details>` block

#### Scenario: Unrecognised macros receive fallback placeholder

- GIVEN a page body with `<ac:structured-macro ac:name="sql">` or any other unregistered macro name
- WHEN the importer processes the macro
- THEN the system MUST produce `<div class="confluence-unsupported-macro">Unsupported macro: <code>sql</code></div>`
- AND admins MUST be able to identify which macros were skipped

### Requirement: REQ-CFLI-007 Dry-run endpoint for import preview

The system MUST expose a `POST /api/admin/import/confluence/dry-run`
endpoint that performs all parsing and validation WITHOUT creating any
dashboards or uploading files.

#### Scenario: Dry-run returns page count and asset folder

- GIVEN a Confluence export with 150 pages and 42 attachments
- WHEN the admin sends `POST /api/admin/import/confluence/dry-run` with the ZIP file
- THEN the system MUST parse the archive and return HTTP 200 with `{pageCount: 150, attachmentCount: 42, estimatedDashboards: 150, warnings: [...], assetFolder: "MyDash/Imports/<iso8601>"}`
- AND NO dashboards, widgets, or files MUST be created

#### Scenario: Dry-run surfaces parser warnings

- GIVEN a Confluence export with some unparseable pages
- WHEN the admin runs dry-run
- THEN the `warnings` array MUST contain a string per failed page

### Requirement: REQ-CFLI-008 Synchronous import (async deferred)

The system MUST run imports synchronously in the request thread for the
v1 importer. An asynchronous background-job path is a planned follow-up
change and is intentionally out of scope here so the initial slice can
ship without depending on the Nextcloud `JobList` runtime.

#### Scenario: Sync import returns counts

- GIVEN a Confluence export of any size
- WHEN the admin sends `POST /api/admin/import/confluence` with the ZIP file
- THEN the system MUST process the import in the request thread
- AND MUST return HTTP 200 with `{createdDashboardCount, skippedPageCount, errors, warnings, assetFolder}`

#### Scenario: Async path is documented as future work

- GIVEN a Confluence export of more than 100 pages
- WHEN the admin sends `POST /api/admin/import/confluence`
- THEN the system MUST still process the import synchronously
- AND the `confluence-async-import` follow-up change is the canonical place to add 202 + job-id + polling

### Requirement: REQ-CFLI-009 Error resilience: single-page failures do not abort import

The system MUST skip pages that fail to create and continue importing
remaining pages, surfacing per-page errors in the response.

#### Scenario: Per-page failure is captured in the response

- GIVEN a Confluence import where page `page-001` fails (malformed body, slug collision, etc.)
- WHEN the importer runs
- THEN the system MUST roll back that single dashboard's transaction (so no half-imported row survives)
- AND MUST append `{pageId: "page-001", reason: <message>}` to the response `errors` array
- AND MUST log a warning with the page ID + exception via `LoggerInterface`
- AND MUST continue creating the remaining pages

#### Scenario: Final report includes all errors

- GIVEN an import of 100 pages where 5 fail
- WHEN the import completes
- THEN the response MUST report `createdDashboardCount: 95, skippedPageCount: 5`
- AND the `errors` array MUST contain one entry per failed page

### Requirement: REQ-CFLI-010 Re-importability: no deduplication by page ID

The system MUST allow the same Confluence archive to be imported
multiple times, creating new dashboards each run (no merge/dedup by
Confluence page ID).

#### Scenario: Second import creates new dashboards

- GIVEN a first import of a Confluence archive that created 100 dashboards
- WHEN the admin runs the same import again
- THEN the system MUST create 100 NEW dashboards (not update the existing ones)
- AND each new dashboard MUST have a freshly-generated UUID
- AND on slug collision the importer MUST suffix the slug with the page ID (and a timestamp segment when even that collides) so the second run never crashes on the slug-uniqueness guard

#### Scenario: Asset folder timestamp differs across runs

- GIVEN two imports of the same archive
- WHEN both runs complete
- THEN each result `assetFolder` MUST contain a unique ISO 8601 timestamp segment

### Requirement: REQ-CFLI-011 CLI command for headless import

The system MUST expose a Nextcloud OCC command `php occ mydash:import:confluence`
for imports via CLI / cron, with options for the input file, an
optional parent path, the importing user, and a dry-run flag.

#### Scenario: CLI import with file option

- GIVEN an admin with shell access to the Nextcloud server
- WHEN they run `php occ mydash:import:confluence --file=/tmp/export.zip`
- THEN the system MUST run the synchronous import path
- AND emit a one-line summary `Imported N dashboards, skipped M, errors: K, asset folder: …`
- AND exit with code 0 on success, non-zero on failure

#### Scenario: CLI import with parent-path option

- GIVEN an admin wants to import under a specific parent dashboard
- WHEN they run `php occ mydash:import:confluence --file=/tmp/export.zip --parent-path=/finance/2026`
- THEN the system MUST resolve the slug-chain path via `DashboardTreeService::resolvePath`
- AND use the resolved UUID as the parent for every root Confluence page
- AND a missing path MUST exit non-zero with `Parent path not found: …`

#### Scenario: CLI handles missing file gracefully

- GIVEN `php occ mydash:import:confluence --file=/nonexistent.zip`
- WHEN the file does not exist
- THEN the system MUST output `File not found: …` and exit non-zero

#### Scenario: CLI supports --dry-run

- GIVEN `php occ mydash:import:confluence --file=/tmp/export.zip --dry-run`
- WHEN the flag is present
- THEN the system MUST emit the dry-run preview line and exit code 0
- AND MUST NOT create any dashboards

### Requirement: REQ-CFLI-012 HTML sanitisation with allow-list

The system MUST sanitise all imported page HTML using a strict allow-list
of formatting tags, stripping everything else to prevent XSS and
maintain consistency with the text-display widget.

#### Scenario: Allow-listed tags are preserved

- GIVEN a page with `<p>text</p> <strong>bold</strong> <em>italic</em> <a href="/link">link</a> <ul><li>item</li></ul>`
- WHEN the importer sanitises the HTML
- THEN every tag MUST be preserved with its semantic meaning intact

#### Scenario: Disallowed tags are unwrapped

- GIVEN a page with `<button>click</button>` (or other non-allow-listed tags)
- WHEN the importer sanitises
- THEN the wrapper MUST be removed but the text content MUST survive

#### Scenario: Script-bearing tags are dropped with their content

- GIVEN HTML with `<script>alert(1)</script>` (or `<style>`, `<iframe>`, `<object>`, `<embed>`, `<noscript>`)
- WHEN the importer sanitises
- THEN the tag AND its inner text MUST be removed (so payloads cannot leak as plain text)

#### Scenario: Event handlers and javascript: URLs are stripped

- GIVEN HTML with `<a href="javascript:alert(1)" onclick="alert(2)">x</a> <img onerror="alert(3)">`
- WHEN the importer sanitises
- THEN every event handler attribute MUST be removed
- AND `href` / `src` values starting with `javascript:` MUST be dropped

#### Scenario: Allow-list covers the documented vocabulary

- GIVEN the allow-list:
  `<p>`, `<h1>`, `<h2>`, `<h3>`, `<h4>`, `<h5>`, `<h6>`, `<a>`,
  `<strong>`, `<em>`, `<b>`, `<i>`, `<ul>`, `<ol>`, `<li>`, `<img>`,
  `<table>`, `<tr>`, `<td>`, `<th>`, `<thead>`, `<tbody>`, `<blockquote>`,
  `<pre>`, `<code>`, `<br>`, `<span>`, `<div>`, `<details>`, `<summary>`
- WHEN a page is imported with any of these tags
- THEN every tag MUST be preserved
- AND attributes MUST be filtered to the per-tag allow-list (`href`/`title` on `<a>`, `src`/`alt`/`title`/`width`/`height` on `<img>`, `colspan`/`rowspan` on table cells, `class` on `<code>`/`<pre>`/`<div>`/`<span>`)

## Implementation traceability

- REQ-CFLI-001..002 (archive structure + hierarchy): `lib/Service/Confluence/ArchiveParser.php` reads the ZIP via `ZipArchive::RDONLY`, builds the per-page `ParsedPage` records, and overlays breadcrumb + directory hierarchy. `tests/Unit/Service/Confluence/ArchiveParserTest.php`.
- REQ-CFLI-003 (page → dashboard + body waterfall): `ConfluenceImportService::createDashboardForPage()` + `ArchiveParser::extractBody()`. The text-display widget is created in `buildPlaceholderWidget()` with the documented grid + style defaults.
- REQ-CFLI-004 (link rewriting): `lib/Service/Confluence/LinkRewriter.php` consumes the `pageId → uuid` map built by `ConfluenceImportService::backfillWidgetContent()`. `tests/Unit/Service/Confluence/LinkRewriterTest.php`.
- REQ-CFLI-005 (image src preservation): `lib/Service/Confluence/MacroRenderer.php::renderImages()` for `<ac:image>`; the `HtmlSanitizer` allow-list keeps plain `<img>` tags. The `MyDash/Imports/{timestamp}/` upload pipeline is reported in the result `assetFolder` field; the file-write itself is the `confluence-attachment-uploads` follow-up.
- REQ-CFLI-006 (macro rendering): `lib/Service/Confluence/MacroRenderer.php` handles `code`, `info` / `note` / `warning` / `tip` / `error` / `panel`, `expand`, and the `<div class="confluence-unsupported-macro">` fallback. `tests/Unit/Service/Confluence/MacroRendererTest.php`.
- REQ-CFLI-007 (dry-run): `POST /api/admin/import/confluence/dry-run` → `ConfluenceImportController::dryRun()` → `ConfluenceImportService::dryRun()`.
- REQ-CFLI-008 (sync import + async deferred): controller + service path is fully synchronous; async lives in the `confluence-async-import` planned follow-up.
- REQ-CFLI-009 (error resilience): per-page transaction in `ConfluenceImportService::createDashboardForPage()` plus `errors[]` accumulation in `ConfluenceImportService::import()`.
- REQ-CFLI-010 (re-importability): `ConfluenceImportService::ensureUniqueSlug()` appends the page ID (and timestamp on second collision) so re-runs always succeed; `buildTimestamp()` ensures a fresh asset folder.
- REQ-CFLI-011 (CLI): `lib/Command/ImportConfluenceCommand.php`, registered in `appinfo/info.xml`.
- REQ-CFLI-012 (sanitisation): `lib/Service/Confluence/HtmlSanitizer.php`. `tests/Unit/Service/Confluence/HtmlSanitizerTest.php`.

## Routes

- `POST /api/admin/import/confluence` → `confluence_import#import` (multipart `file`, optional `parentUuid` query)
- `POST /api/admin/import/confluence/dry-run` → `confluence_import#dryRun` (multipart `file`)

Both routes are admin-only via the runtime `IGroupManager::isAdmin`
check inside `ConfluenceImportController` (mirrors the existing
`/api/admin/export` and `/api/admin/import` routes). No CSRF token is
required — the routes accept multipart uploads from CLI tools alongside
the admin UI.
