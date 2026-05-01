# Design — Confluence HTML import

## Context

The proposal (proposal.md) and spec (REQ-CFLI-001..012) were written before a
ground-truth read of the existing importer in
`intravox-source/lib/Service/Import/`. This document records what the source
actually does so that the spec can be corrected before implementation begins.

## Goals / Non-Goals

**Goals**
- Document the actual parsing strategy used by the existing importer.
- Surface every assumption in the spec that contradicts the source.
- Give implementers a concrete, accurate baseline.

**Non-Goals**
- Change the spec (this document drives those changes, but does not apply them).
- Cover the Confluence REST API importer (`ConfluenceApiImporter.php`), which is
  a separate code path.

---

## Decisions

### D1: TOC structure assumption

**Decision**: `index.html` is NOT parsed for hierarchy. It is parsed only to
extract page *order* (0-based index from link position). Hierarchy is derived
from two other sources, applied in priority order: (1) directory structure of
the extracted ZIP, and (2) breadcrumb `<ol class="breadcrumbs">` /
`<ol id="breadcrumbs">` navigation inside each individual page file.
The index link-list is read with a bare regex (`preg_match_all`) on `href`
attributes — no DOMDocument, no nested-`<ul>` interpretation.

**Source evidence**:
- `intravox-source/lib/Service/Import/ConfluenceHtmlImporter.php:418-470`
  (`buildPageHierarchy`) — outer loop over files, not index structure
- `intravox-source/lib/Service/Import/ConfluenceHtmlImporter.php:481-517`
  (`extractPageOrderFromIndex`) — index.html parsed only for ordering
- `intravox-source/lib/Service/Import/ConfluenceHtmlImporter.php:526-561`
  (`extractParentFromBreadcrumb`) — breadcrumb `<ol>` is the primary hierarchy
  signal

Spec REQ-CFLI-002 scenario "Extract page hierarchy from TOC" assumes the index
drives parent-child relationships and uses `href="SPACE/page-123.html"` link
nesting for depth. That assumption is wrong.

---

### D2: Body content selector

**Decision**: `DOMDocument` + `DOMXPath` with a waterfall of six selectors:
1. `//div[@id='main-content']`
2. `//div[contains(@class, 'wiki-content')]`
3. `//div[contains(@class, 'page-content')]`
4. `//div[@id='content']`
5. `//main`
6. `//article`

Before the first non-empty result is returned, unwanted navigation elements are
stripped from the node in place:
`div#pagetreesearch`, `div.breadcrumbs`, `div.pageSection`,
`form[name=pagetreesearchform]`, `form.aui`, `nav`, `div.page-metadata`.

Fallback: regex `/<body>(.*?)<\/body>/is` with further inline regex stripping,
then raw HTML as last resort.

**Source evidence**:
- `intravox-source/lib/Service/Import/ConfluenceHtmlImporter.php:288-349`
  (`extractBody`)
- `intravox-source/lib/Service/Import/ConfluenceHtmlImporter.php:382-403`
  (`removeUnwantedElements`)

The spec (REQ-CFLI-003 scenario "Text-display widget captures page body")
correctly names `<div id="main-content">` as the primary selector and
`<body>` as the fallback. The additional selectors and the nav-stripping step
are not mentioned and should be documented.

---

### D3: Macro handling

**Decision**: `ConfluenceImporter` (the Storage Format parser) recognises
Confluence namespace elements by their XML namespace URI
`http://www.atlassian.com/schema/confluence/4/ac/`. The body HTML is wrapped in
a div with that namespace declaration, then loaded via `DOMDocument::loadXML`
(with `LIBXML_NONET` for XXE protection) with fallback to `loadHTML`.

Five registered handlers process `<ac:structured-macro>`:

| Handler | `supports()` | Output |
|---|---|---|
| `PanelMacroHandler` | `info`, `note`, `warning`, `tip`, `error`, `panel` | `PanelBlock` → `text` widget with `confluence-panel-{type}` CSS class |
| `CodeMacroHandler` | `code` | `CodeBlock` → `<pre><code class="language-X">` text widget |
| `AttachmentMacroHandler` | `attachments`, `viewfile`, `image` | `HtmlBlock` placeholder / static link |
| `ExpandMacroHandler` | `expand` | `HtmlBlock` with `<details><summary>` |
| `DefaultMacroHandler` | all others (fallback) | `HtmlBlock` with `<div class="confluence-unsupported-macro">⚠️ Unsupported…</div>` |

`<ac:image>` elements (not macros) are handled separately via
`AttachmentMacroHandler::processImageElement()`, which reads `<ri:attachment
ri:filename>` or `<ri:url ri:value>` and registers a `MediaDownload`.

Standard HTML elements in the body are delegated to `HtmlToWidgetConverter`,
which creates typed blocks (HeadingBlock, DividerBlock, CodeBlock, PanelBlock,
ImageBlock, HtmlBlock).

**Source evidence**:
- `intravox-source/lib/Service/Import/ConfluenceImporter.php:36-41` (handler
  registration)
- `intravox-source/lib/Service/Import/ConfluenceImporter.php:198-236`
  (`processStorageFormatNodes` — namespace dispatch)
- `intravox-source/lib/Service/Import/Confluence/Macros/DefaultMacroHandler.php:26-48`
- `intravox-source/lib/Service/Import/Confluence/Macros/PanelMacroHandler.php`
- `intravox-source/lib/Service/Import/Confluence/Macros/CodeMacroHandler.php`
- `intravox-source/lib/Service/Import/Confluence/Macros/ExpandMacroHandler.php`
- `intravox-source/lib/Service/Import/Confluence/Macros/AttachmentMacroHandler.php`

Spec REQ-CFLI-006 scenario "Replace structured-macro with placeholder" describes
`<p><em>[Macro: code not imported]</em></p>`. The real output is a
`<div class="confluence-unsupported-macro">` block with an emoji and a
`<code>` tag — not a plain `<em>` paragraph. The `code` macro is not a
placeholder at all; it renders into a `<pre><code>` block. Similarly `panel`,
`expand`, `attachments`, and `viewfile` have richer outputs than the spec
implies.

---

### D4: Attachment and image upload destination

**Decision**: The HTML importer (`ConfluenceHtmlImporter`) does NOT upload
anything to Nextcloud directly. It registers `MediaDownload` objects (URL +
target filename + page slug) on the `IntermediateFormat`. The controller
(`ImportController::importFromConfluenceHtml`) converts the intermediate format
to an IntraVox `export.json`, writes it to a temp folder, creates a ZIP, and
passes that to the existing `ImportService::import()`. Actual Nextcloud file
operations happen inside `ImportService`, not inside the Confluence parser.

For `<ac:image ri:filename>` (Storage Format attachments): the URL field is set
to the bare `$filename` string as a placeholder — "actual download URL will be
resolved during import" is a comment in the code but the resolution is not yet
implemented.

For `<img src="attachments/page-id/file.png">` found via `HtmlToWidgetConverter`
or `extractBody`: these are raw HTML `<img>` tags left in the HtmlBlock content.
There is no code that scans plain HTML `<img src="attachments/...">` and
uploads the referenced files. The `MediaDownload` mechanism only fires for
`<ac:image>` elements processed through the Storage Format path.

**Source evidence**:
- `intravox-source/lib/Service/Import/Confluence/Macros/AttachmentMacroHandler.php:98-139`
  (`processImageElement` — sets `$url = $filename` as placeholder)
- `intravox-source/lib/Controller/ImportController.php:178-209`
  (`importFromConfluenceHtml` — no file upload, delegates to `ImportService`)
- `intravox-source/lib/Service/Import/IntermediateFormat.php:266-275`
  (`MediaDownload` — url, targetFilename, pageSlug only)

Spec REQ-CFLI-005 ("Upload attachment image to NC folder" and the
`MyDash/Imports/{timestamp}/` folder) describes behaviour that does not exist
in the current code. Implementing it requires new logic in `ImportService` or a
dedicated uploader.

---

### D5: Internal link rewriting

**Decision**: There is no link-rewriting code in the importer. Confluence
internal links (`<a href="pageId.html">`) are left as-is in the exported HTML
blocks. The `IntermediatePage` has a `parentUniqueId` field for hierarchy, but
there is no `pageId→uuid` map and no pass over `<a href>` attributes.

**Source evidence**:
- `intravox-source/lib/Service/Import/ConfluenceImporter.php` — no href
  rewriting anywhere
- `intravox-source/lib/Service/Import/ConfluenceHtmlImporter.php` — no href
  rewriting anywhere
- `intravox-source/lib/Service/Import/IntermediateFormat.php:35-85`
  (`IntermediatePage` — no page-id map field)

Spec REQ-CFLI-004 (all scenarios) describes behaviour that does not exist. The
entire link-rewriting subsystem must be built from scratch.

---

### D6: Sync vs async and dry-run

**Decision**: The existing controller (`importFromConfluenceHtml`) is fully
synchronous — it processes in the request thread and returns a single JSON
response with `success`, `stats`, `pages`, and `message`. There is no page-count
threshold, no background job dispatch, no job-id, and no polling endpoint.
There is also no dry-run path (no `dry-run` parameter, no flag, no separate
endpoint).

The `ImportPagesCommand` CLI command exists but operates on the IntraVox native
JSON format, not Confluence ZIP archives. There is no `occ mydash:import:confluence`
command.

**Source evidence**:
- `intravox-source/lib/Controller/ImportController.php:127-221`
  (`importFromConfluenceHtml` — single synchronous path)
- `intravox-source/lib/Command/ImportPagesCommand.php:32-44`
  (command name `intravox:import`, no Confluence ZIP support)

Spec REQ-CFLI-007 (dry-run endpoint), REQ-CFLI-008 (async + polling), and
REQ-CFLI-011 (CLI `occ mydash:import:confluence`) all describe features that
do not exist and must be built.

---

### D7: Partial-failure tolerance

**Decision**: Partial-failure tolerance exists but is coarse. In
`ConfluenceHtmlImporter::importFromZip`, when `parseHtmlFile` returns `null`
(due to unreadable file or empty body), that file is silently skipped and the
loop continues. However, there is no try/catch around the per-file parsing loop,
no error accumulation, and no final `errors` list in the returned
`IntermediateFormat`. Exceptions propagate out of `importFromZip` and are caught
at the controller level, returning HTTP 500 for the whole request.

**Source evidence**:
- `intravox-source/lib/Service/Import/ConfluenceHtmlImporter.php:44-72`
  (inner loop — null-check only, no try/catch, no error list)
- `intravox-source/lib/Controller/ImportController.php:210-219`
  (catch block returns 500 for any exception)

Spec REQ-CFLI-009 ("single-page failures do not abort import", `errors` array
in response, `skippedPageCount`) requires adding per-page try/catch, an error
accumulator, and surfacing it in the response. The skeleton is almost there
(null return from `parseHtmlFile`) but the error-collection half is missing.

---

## Spec changes implied

- **REQ-CFLI-002** ("hierarchy from TOC"): Replace the `index.html`-as-hierarchy
  assumption with the two-source model: directory nesting first, breadcrumb
  `<ol class="breadcrumbs">` override second. Clarify that `index.html` is used
  only to assign sibling order, not parent-child relationships.

- **REQ-CFLI-003** scenario "Text-display widget captures page body": Add the
  full selector waterfall (6 selectors) and list the nav elements stripped
  before extraction.

- **REQ-CFLI-004** (link rewriting): Mark as "new feature to build" — no
  existing code. Spec is correct in intent but the implementation baseline is
  zero, not partial.

- **REQ-CFLI-005** (image upload): Mark as "new feature to build". The
  `MediaDownload` type exists for Storage Format `<ac:image>` elements but plain
  HTML `<img src="attachments/...">` is not scanned, and no upload to Nextcloud
  occurs today. The `MyDash/Imports/{timestamp}/` destination folder must be
  a new design decision.

- **REQ-CFLI-006** scenarios: Correct the placeholder format from
  `<p><em>[Macro: X not imported]</em></p>` to the actual
  `<div class="confluence-unsupported-macro">⚠️ Unsupported…</div>` output.
  Document that `code`, `info/note/warning/tip/error/panel`, and `expand`
  are rendered (not placeholder'd), and only unrecognised macros get the
  fallback.

- **REQ-CFLI-007** (dry-run): Fully new endpoint; no existing code path.

- **REQ-CFLI-008** (async / background job): Fully new; controller is sync-only
  today.

- **REQ-CFLI-009** (error resilience): The null-guard exists; the try/catch
  and error-accumulation do not. State this gap explicitly.

- **REQ-CFLI-011** (CLI command): The command class does not exist. The existing
  `ImportPagesCommand` (`intravox:import`) operates on IntraVox native format
  only.

---

## Open follow-ups

1. **Attachment upload design**: The `MediaDownload` + `ImportService` path is
   the natural extension point for Storage Format images. For plain HTML
   `<img src="attachments/...">` the same mechanism needs a scan pass before or
   during `extractBody`. Decide whether upload happens inside the parser or in
   `ImportService`.

2. **Link-rewriting pass ordering**: To rewrite `<a href="page-id.html">` the
   importer must build the full `pageId→uuid` map before emitting any widget
   HTML. This implies a two-pass strategy or deferred rewriting after all pages
   are parsed. Neither is in scope in the existing code.

3. **`index.html` hierarchy ambiguity**: Confluence exports come in two shapes:
   flat (all pages in one directory, hierarchical via breadcrumbs only) and
   nested (subdirectories per space / page). The breadcrumb override works for
   both, but the directory-nesting path assumes exactly one directory level.
   Deep nesting (`SPACE/parent/child/grandchild.html`) may not resolve
   correctly with the current `pathParts[0]` logic in `normalizeHref`.

4. **`index.html` vs `overview.html` vs `toc.html`**: `findHtmlFiles` skips
   all three filenames. If a space has an `overview.html` that is a real content
   page (some Confluence versions produce this), it will be silently ignored.
   Verify against real Confluence export samples.

5. **Async threshold**: The spec proposes <100 pages = sync, ≥100 = async.
   The controller has no such threshold. When implemented this needs to account
   for NC's `ITempManager` temp files, which are cleaned on request end —
   a background job cannot use the same temp path.
