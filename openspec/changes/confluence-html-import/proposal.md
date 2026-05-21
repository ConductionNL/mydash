# Confluence HTML Export Importer

Organizations migrating from Atlassian Confluence (or supplementing it with MyDash) need a one-shot bulk import that converts existing Confluence page hierarchies into MyDash dashboards. Manual recreation of hundreds of pages is impractical. This capability lets a Nextcloud admin upload a Confluence "HTML Export" archive and automatically generate MyDash dashboards with the page content preserved, the page tree mirrored via the `dashboard-tree` capability, and Confluence Storage Format macros expanded into safe HTML.

## Affected code units

- `lib/Service/Confluence/ArchiveParser.php` — ZIP read, body extraction, hierarchy derivation
- `lib/Service/Confluence/DashboardFactory.php` — one dashboard per page
- `lib/Service/Confluence/MacroRenderer.php` — `<ac:structured-macro>` → HTML expansion
- `lib/Service/Confluence/LinkRewriter.php` — pageId → `/apps/mydash/dashboard/{uuid}` rewriting
- `lib/Service/Confluence/HtmlSanitizer.php` — allow-list HTML filtering (XSS prevention)
- `lib/Controller/ConfluenceImportController.php` — admin-guarded API routes
- `lib/Service/ConfluenceImportService.php` — orchestrates parse → create → backfill
- `lib/Command/ImportConfluenceCommand.php` — CLI support (`occ mydash:import:confluence`)

## Why a new capability

No existing MyDash subsystem handles bulk page import from external sources. The import logic is domain-specific to Confluence's HTML structure and macro format. While dashboard CRUD is handled by existing core, the import orchestration (archive parsing, hierarchy extraction, macro rendering, link rewriting) requires new specialized services.

## Approach

The importer divides into sequential phases:

1. **Archive parsing** — read ZIP, identify pages by file extension, extract breadcrumb + directory hierarchy
2. **Hierarchy derivation** — breadcrumb-first, with directory-nesting fallback for root detection
3. **Dashboard creation** — one dashboard per page, full-width text-display widget with page body
4. **Macro rendering** — convert `<ac:structured-macro>` elements (code, panels, expand) into rich HTML blocks
5. **HTML sanitization** — allow-list filter to strip XSS vectors and navigation elements
6. **Link rewriting** — `<a href="pageId.html">` → `<a href="/apps/mydash/dashboard/{uuid}">`
7. **Error resilience** — per-page failures do NOT abort import; capture errors in response
8. **Re-importability** — same archive can be imported multiple times with no deduplication; slug collisions resolved via page ID + timestamp

## Capabilities

**New Capabilities:**

- `confluence-importer` — bulk Confluence page → MyDash dashboard conversion

## Routes

- `POST /api/admin/import/confluence` — multipart `file` upload, optional `parentUuid` query param
- `POST /api/admin/import/confluence/dry-run` — parse + validate without persisting

## CLI

- `php occ mydash:import:confluence --file=/path/to/export.zip [--parent-path=/slug/chain] [--user=uid] [--dry-run]`

## Notes

- Synchronous request-thread execution (v1); async background job path is planned follow-up (`confluence-async-import`)
- Asset upload pipeline (`confluence-attachment-uploads`) is a separate follow-up — this change preserves image `src` filenames for asset linking but does NOT upload files
- Confluence Storage Format macro rendering is scoped to: info/note/warning/tip/error/panel, code, expand; unrecognized macros receive a fallback placeholder block
