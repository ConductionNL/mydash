# Design — Confluence HTML Export Importer

## Context

Organizations migrating from Atlassian Confluence face a choice: manually recreate pages in MyDash (impractical at scale), or find an automated bulk import path. Confluence's HTML Export feature (available from the space settings menu) produces a ZIP archive with a predictable structure: `index.html`, `SPACE/page.html` files with breadcrumb navigation, `attachments/` and `images/` directories, and `<ac:structured-macro>` elements in Confluence Storage Format.

MyDash's core dashboard CRUD and tree APIs exist. The missing piece is the orchestration: parsing the Confluence archive, deriving the page hierarchy from breadcrumbs + directory nesting, rendering Storage Format macros into safe HTML, rewriting internal links, and handling import-time errors without aborting the entire batch.

## Goals / Non-Goals

**Goals:**

- Parse Confluence HTML Export archives and extract page metadata (title, body, parent-child hierarchy)
- Convert each page into a MyDash dashboard with a single full-width text-display widget
- Preserve the Confluence page tree via the `dashboard-tree` capability so related pages stay grouped
- Render Confluence Storage Format macros (info, note, warning, code, expand, etc.) into equivalent HTML blocks
- Rewrite internal page links so they point to the corresponding imported dashboards
- Sanitize all HTML with a strict allow-list to prevent XSS and maintain consistency
- Support both API (multipart upload) and CLI (`occ` command) entry points
- Implement dry-run validation without persisting dashboards
- Allow re-importing the same archive multiple times (no deduplication by Confluence page ID)
- Gracefully handle per-page failures without aborting the entire import

**Non-Goals:**

- Asynchronous background job processing (v1 is synchronous; `confluence-async-import` follow-up handles job-based execution for large archives)
- File upload and attachment migration (asset filenames are preserved for a separate follow-up `confluence-attachment-uploads` change)
- Custom field mapping or schema extensions (imports into the standard dashboard + text-display widget model only)
- User-initiated imports without admin role (admin-only guarded)
- Incremental/delta imports (each run is a fresh full import; deduplication is not attempted)

## Decisions

### D1: Hierarchy derivation — breadcrumb first, directory nesting as fallback

**Decision**: Extract parent-child relationships from three sources in priority order:
1. Breadcrumb `<ol class="breadcrumbs">` inside each page file → extract parent chain
2. ZIP directory nesting (e.g. `SPACE/SUB/page.html` implies `page.html` is under `SUB`) → fallback when breadcrumb is absent
3. `index.html` link order → assign sibling ordering only, NOT parent-child relationships

**Alternatives considered:**

- Use only `index.html` link nesting. Rejected — `index.html` typically lists all pages but may not reflect the full hierarchy if some pages have sub-pages only in their breadcrumb.
- Use only directory nesting. Rejected — admins may export partial hierarchies or rename directories, losing structure information.
- Extract hierarchy from `<li>` nesting in `index.html`. Rejected — nesting is visual (for display) and doesn't reliably reflect the true parent-child edges.

**Rationale**: Confluence pages store their parent link in the breadcrumb navigation, making it the authoritative source. Directory nesting is a secondary signal that helps when breadcrumbs are malformed or absent. `index.html` provides deterministic sibling ordering without requiring DOM tree depth inspection.

### D2: One dashboard per page, full-width text-display widget with page body

**Decision**: For each Confluence page, create one MyDash dashboard with:
- `name` = page title (extracted from `<title>` or `<h1>`)
- `userId` = importing user (from API context or `--user` CLI flag)
- One widget: text-display at grid position `(0, 0)` with size `(12, 12)` (full-width)
- Widget content: sanitized page body HTML, extracted via XPath selector waterfall

**Alternatives considered:**

- Multiple widgets per page (e.g., separate widgets for each section, each image). Rejected — the importer's job is to preserve the page content, not redesign the layout. Admins can edit dashboards post-import.
- Create a "source page" record in OpenRegister. Rejected — Confluence pages are external artifacts. The import creates MyDash artifacts only; tracking provenance is optional and out of scope.

**Rationale**: One widget keeps the dashboard UI clean and focuses the change on faithful content preservation. Admins can split/rearrange post-import. The XPath waterfall handles varying Confluence HTML structures without requiring per-space configuration.

### D3: HTML sanitization with strict allow-list to prevent XSS and maintain consistency

**Decision**: Before storing page body in the widget, sanitize the HTML using a strict allow-list:
- **Allowed tags**: `<p>`, `<h1>`–`<h6>`, `<a>`, `<strong>`, `<em>`, `<b>`, `<i>`, `<ul>`, `<ol>`, `<li>`, `<img>`, `<table>`, `<tr>`, `<td>`, `<th>`, `<thead>`, `<tbody>`, `<blockquote>`, `<pre>`, `<code>`, `<br>`, `<span>`, `<div>`, `<details>`, `<summary>`
- **Attributes by tag**: `href`/`title` on `<a>`, `src`/`alt`/`title`/`width`/`height` on `<img>`, `colspan`/`rowspan` on table cells, `class` on `<code>`/`<pre>`/`<div>`/`<span>`
- **Always dropped**: `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`, `<noscript>` AND their content
- **Always stripped**: Event handler attributes (`onclick`, `onerror`, etc.) and `javascript:` URL schemes

**Alternatives considered:**

- No sanitization (trust Confluence's export). Rejected — Confluence HTML is user-generated content and admins may have embedded arbitrary scripts.
- More permissive allow-list. Rejected — every tag/attribute beyond the documented set increases XSS surface area and conflicts with the text-display widget's styling constraints.

**Rationale**: The allow-list matches the text-display widget's documented vocabulary. Removing disallowed tags but preserving text ensures content is not lost (just unwrapped). Dropping `<script>` completely prevents payload leakage as plain text.

### D4: Per-page error capture and resilience — import continues on individual page failure

**Decision**: Wrap each dashboard creation in a transaction. If a single page fails (malformed HTML, slug collision, etc.):
1. Roll back that page's transaction
2. Append `{pageId: "...", reason: "..."}` to the response `errors` array
3. Log a warning with page ID + exception
4. Continue importing remaining pages

**Alternatives considered:**

- Abort on first error. Rejected — a single malformed page should not fail the entire 100-page import.
- Silently skip failed pages without reporting. Rejected — admins need to know which pages didn't import so they can investigate.

**Rationale**: Batch operations (imports, exports, bulk edits) should maximize success and surface failures explicitly. Rollback-per-item prevents half-imported rows from poisoning the dashboard tree.

### D5: Re-importability with slug collision resolution — same archive, multiple times

**Decision**: Allow the same Confluence archive to be imported multiple times without merging/deduplication:
1. Each import run generates a fresh UUID for each dashboard
2. On slug collision, append the Confluence page ID to the slug; if that collides, append a timestamp segment
3. `assetFolder` in the response includes an ISO 8601 timestamp, ensuring each run gets a unique asset staging location

**Alternatives considered:**

- Track imported Confluence page IDs in a local table and merge on re-import. Rejected — adds persistent state and complicates re-importing partial archives (e.g., the same pages imported in different projects).
- Raise an error on slug collision. Rejected — this breaks re-importability and forces admins to manually rename pages before re-importing.

**Rationale**: Re-importability is valuable for test/staging workflows and recovering from interrupted imports. Slug collision resolution via page ID + timestamp is deterministic and preserves the imported page identity.

### D6: Synchronous request-thread execution for v1; async is planned follow-up

**Decision**: v1 importer MUST run synchronously in the request thread for archives of any size. An asynchronous background-job path is OUT OF SCOPE and is the `confluence-async-import` planned follow-up.

**Alternatives considered:**

- Start a Nextcloud `JobList` background job and return 202 + job ID immediately. Rejected — adds dependency on the job queue and defers error reporting to the response. Better to ship v1 synchronously with clear error handling, then optimize for larger archives in a follow-up.

**Rationale**: Synchronous execution keeps error reporting simple (all errors in response.errors), avoids job-queue dependencies, and lets admins verify import success immediately. Large archives (100s of pages) may take seconds, which is acceptable for an admin operation that typically runs once. Async can be layered on later without breaking the sync path.

### D7: Macro rendering scope — info/note/warning/code/expand/panel only; unrecognized macros get fallback

**Decision**: Implement renderers for these Confluence Storage Format macros:
- **Panel-type** (`info`, `note`, `warning`, `tip`, `error`, `panel`) → `<div class="confluence-panel-{type}">…</div>`
- **Code** → `<pre><code class="language-{lang}">…</code></pre>` with optional language parameter
- **Expand** → `<details><summary>…</summary>…</details>`
- **Unrecognized macros** → `<div class="confluence-unsupported-macro">Unsupported macro: <code>{name}</code></div>`

Admins can identify which macros were skipped from the placeholder blocks and manually recreate complex content if needed.

**Alternatives considered:**

- Render ALL Confluence macros. Rejected — Confluence has 100+ macros; supporting all is out of scope. A small high-value set covers ~95% of typical use cases.
- Silently drop unrecognized macros (no placeholder). Rejected — admins won't notice lost content.

**Rationale**: High-value subset covers the most common formatting. Fallback placeholder is visible and guides post-import remediation.

### D8: Image source preservation (filenames) for follow-up asset upload

**Decision**: Preserve `<img src>` references and convert Confluence `<ac:image>` macro elements into `<img>` tags so asset filenames are discoverable:
- `<ac:image><ri:attachment ri:filename="diagram.png"/></ac:image>` → `<img src="diagram.png" alt="diagram.png">`
- Plain `<img src="attachments/page-1/diagram.png">` tags survive sanitization unchanged

The follow-up `confluence-attachment-uploads` change will implement the actual file write using these preserved filenames.

**Rationale**: Decouples import into two changes: (1) parse + create dashboards, (2) upload assets. Simpler, more testable, and doesn't block on asset storage infrastructure.

## Risks / Trade-offs

- **Risk:** Synchronous import blocks the request thread. → **Mitigation:** Async is documented as a planned follow-up; v1 is adequate for typical archive sizes (100s of pages complete in seconds).
- **Risk:** Breadcrumb-first hierarchy extraction may fail if pages have malformed breadcrumbs. → **Mitigation:** Directory-nesting fallback handles missing breadcrumbs; a warning is emitted for each page with no detectable parent.
- **Risk:** Slug collisions on re-import. → **Mitigation:** Deterministic collision resolution via page ID + timestamp ensures re-imports never crash.
- **Risk:** Admin users can import arbitrary HTML. → **Mitigation:** Strict allow-list sanitization removes XSS vectors. Admin-only guard (framework-enforced) prevents unprivileged imports.
- **Trade-off:** Macro rendering is narrow (8 macro types). → **Mitigation:** Fallback placeholder blocks make unsupported macros visible. Narrow scope keeps v1 shipping quickly; expansions come in follow-ups.

## Reuse Analysis

- **Dashboard CRUD** — via existing `DashboardRepository` + `DashboardService`. No new abstractions.
- **Dashboard tree** — via `DashboardTreeService::setParent()` (existing capability). This change uses the standard API.
- **Text-display widget** — standard `WidgetFactory` + grid placement API. No widget-type customizations.
- **HTML sanitization** — new `HtmlSanitizer` service (Confluence-specific allow-list). No overlap with existing platform sanitizers.
- **ZIP reading** — PHP's `ZipArchive` standard library. No external dependencies.

## Migration Plan

1. **Phase 1:** Implement `ArchiveParser` + `HtmlSanitizer` + unit tests
2. **Phase 2:** Implement `DashboardFactory` + `MacroRenderer` + `LinkRewriter`
3. **Phase 3:** Implement `ConfluenceImportService` (orchestrator) + `ConfluenceImportController` (API routes)
4. **Phase 4:** Implement CLI command + dry-run validation
5. **Phase 5:** Integration tests covering end-to-end import with error scenarios + re-importability

## Rollback

Pure backend change, no schema migrations. Reverting the PR removes the import feature with no data loss.

## Open Questions

- Should the importer create a manifest/import record in OpenRegister to track provenance? Deferred to a follow-up if needed.
- Should re-imports offer a merge mode (update existing dashboards) or import-as-new only? Decided: import-as-new for v1; merge is a follow-up.
