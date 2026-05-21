# Design — Files Widget

## Context

MyDash dashboards display widgets (text, images, HTML, and soon files). Users want to embed a live Nextcloud Files browser on a dashboard — specifically, to browse a shared folder and access files without leaving the dashboard. Today, the only way to browse files is to navigate to the Files app. This change adds a new widget type that renders an inline Files browser with optional upload/delete actions, permission-aware ACL, folder navigation, and deep-linking to the standard Files app for editing/previewing.

The Files widget anchors a future "shared workspace" experience where multiple widget types work together around a common folder (e.g., a file browser widget + a task list widget both bound to the same workspace folder).

## Goals / Non-Goals

**Goals:**

- Let dashboard authors add a Files widget to any dashboard and configure it to browse a specific folder
- Each viewer sees only files they have read permission on (view-time ACL)
- Users can navigate into subfolders via breadcrumb and folder clicks
- File clicks deep-link to the Nextcloud Files app for preview/edit (do NOT render inline)
- Optional upload/delete actions (gated by `allowUpload`/`allowDelete` config flags AND viewer permission)
- Support list, grid (with thumbnails), and tree view modes
- MIME type filtering (e.g., show only images)
- Sorting by name, modified date, size, type; ascending/descending
- Client-side search to filter by filename substring
- Graceful empty states when folder is deleted, access revoked, or empty
- Zero schema changes to `oc_mydash_widget_placements` (use existing `widgetContent` JSON column)

**Non-Goals:**

- Inline file preview or editing within the widget (use Files app deep-links)
- Shared workspace UI orchestration (multiple widgets bound to a folder) — that comes in a follow-up; this widget is standalone
- Fine-grained sharing permissions per widget placement (use Nextcloud folder share ACL)
- Arbitrary file operations (rename, move, copy) — only upload and delete
- Full-text search (server-side) — only client-side name substring match
- Custom thumbnail generation (use Nextcloud's existing preview system)
- Offline-first or sync (always fetch at render time)

## Decisions

### D1: Configuration stored in `widgetContent` JSON, not a separate column

**Decision:** Placement configuration lives in the existing `oc_mydash_widget_placements.widgetContent` JSON column with a discriminated shape `{type: 'files', content: {...}}`. No schema migration.

**Alternatives considered:**

- New table `oc_mydash_files_widget_config` for file-specific settings. Rejected — adds joins and complexity; MyDash is already designed for JSON polymorphism via `type` discriminator.
- Hardcode configuration in `manifest.json` or per-app config. Rejected — each placement needs its own folder path and view preferences.

**Rationale:** Leverage the existing pattern. The `widgetContent` column already supports polymorphic widget types; using it keeps the schema stable and deployment risk low.

### D2: Prefer `fileId` over `folderPath` for folder resolution

**Decision:** The placement configuration accepts both `folderPath` (string, e.g. `/Documents/Marketing`) and `fileId` (number, persistent file ID). Resolution checks `fileId` first (if present, use it); if absent, fall back to `folderPath`. `fileId` is PREFERRED and RECOMMENDED in UI.

**Alternatives considered:**

- Folderpath only. Rejected — folder renames break the widget silently.
- FileId only. Rejected — users may not know the file ID; path is more discoverable.
- Auto-migrate on rename (track renames in a background job). Rejected — adds cronjob complexity; `fileId` is simpler.

**Rationale:** `fileId` is a Nextcloud stable identifier; `folderPath` is human-readable but fragile. Accepting both lets users switch from path to ID if a folder is renamed (and UI can prompt a refresh if ID resolution fails).

### D3: View-time ACL enforcement; no cascading permission checks

**Decision:** Each time contents are fetched, the backend checks if the viewer can READ the folder and each item. Items the viewer cannot read are ABSENT from the response (not shown as grayed-out or inaccessible). Upload and delete endpoints check write/delete permission at action time. No pre-check of "this widget will be visible to the user" at config time.

**Alternatives considered:**

- Pre-compute a "can view" flag when the placement is created and store it. Rejected — permissions change; the flag would become stale.
- Return inaccessible items with a "no access" status. Rejected — violates principle of least disclosure; hidden files should not be named.
- Cascade permission checks to all subfolders. Rejected — expensive; user can navigate and hit a 403 if needed.

**Rationale:** View-time checks are cheaper than pre-computation and respect Nextcloud's live-permission model. Absence (rather than grayed-out) items is cleaner UX and more secure.

### D4: File clicks open Files app (deep-link), not inline preview

**Decision:** Clicking a file in the widget opens `/apps/files/?fileid=...` in a new browser tab/window. The Files app loads the file with its standard preview/edit UI. The widget does NOT render previews or editors inline.

**Alternatives considered:**

- Inline file preview modal. Rejected — widget component size is limited; most files need full-screen preview; duplication of Files app's preview code.
- Inline modal with minimal preview + "Open in Files app" button. Rejected — complicated for little benefit; deep-link is simpler and matches user expectations from modern apps.

**Rationale:** Deep-linking keeps the widget simple and unifies the preview experience with the Files app. Users expect to see the full file viewer.

### D5: Upload through Nextcloud's standard `IRootFolder` or WebDAV API

**Decision:** The `POST /api/widgets/files/{placementId}/upload` endpoint accepts multipart file uploads and writes them to the configured folder via Nextcloud's backend APIs (`IRootFolder`). Conflict handling (file already exists) follows Nextcloud's standard behavior (rename to `{name} (1).{ext}`).

**Alternatives considered:**

- Stream through browser directly to WebDAV. Rejected — no CSRF protection; widget would need to expose raw WebDAV credentials.
- Custom upload handler with custom conflict policy. Rejected — diverges from Nextcloud's standard; users expect consistent behavior.

**Rationale:** Routing through the backend gives us a single point for ACL, conflict handling, and quota enforcement. Reusing Nextcloud's standard upload logic keeps the behavior consistent with the Files app.

### D6: Delete moves to trash, not permanent

**Decision:** The `DELETE /api/widgets/files/{placementId}/item` endpoint moves the item to Nextcloud's trash/recycle bin. Permanent deletion is not offered.

**Alternatives considered:**

- Offer a choice: "Move to trash" vs "Permanently delete". Rejected — adds UI complexity; trash is the safe default.
- Permanent delete only. Rejected — data loss risk; users expect undo via trash.

**Rationale:** Trash is a safety mechanism. If a user deletes by mistake, they can recover from trash (or admin can restore). This aligns with Nextcloud's default file deletion behavior.

### D7: MIME type filtering happens server-side in the contents endpoint

**Decision:** The placement config includes `mimeTypeFilter: string[]` (e.g., `["image/*", "application/pdf"]`). The server-side `GET /contents` endpoint filters items before returning. Empty array or missing field means no filter (all files returned).

**Alternatives considered:**

- Client-side filter in the widget component. Rejected — defeats ACL (server still lists all items); inefficient for large folders.
- Regex patterns. Rejected — glob patterns (`image/*`) are simpler and match Nextcloud's existing filters.

**Rationale:** Server-side filtering is more efficient and secure (filter + ACL in one pass). Glob patterns are familiar to Nextcloud users.

### D8: Search is client-side, case-insensitive substring match

**Decision:** The widget includes a search input field. Typing a query filters the current folder's items (already fetched from server) by name substring, case-insensitive. Search does NOT fetch new data or query subfolders.

**Alternatives considered:**

- Server-side full-text search. Rejected — too heavy; widget is for browsing a single folder.
- Server-side regex or prefix match. Rejected — client-side is simpler for a single folder's items; no extra round-trip.

**Rationale:** One folder's items are small enough to filter in-browser. Client-side avoids round-trip latency. Substring match is discoverable (types "budget" to find "marketing_budget.xlsx").

### D9: Three view modes: list, grid, tree

**Decision:** The widget supports `viewMode: 'list' | 'grid' | 'tree'`. List shows rows (icon/name/date/size). Grid shows cards/tiles with optional thumbnails. Tree shows a collapsible folder hierarchy. Default is `list`.

**Alternatives considered:**

- List only, with lazy-load tree in a modal. Rejected — tree mode is useful for large folder hierarchies; not worth a separate modal interaction.
- Kanban or timeline views. Rejected — out of scope for a files widget; special-purpose views belong in dedicated apps.

**Rationale:** List is the most discoverable; grid is visually appealing for images; tree is essential for exploring deep folder hierarchies. All three are common file manager patterns.

### D10: Thumbnails only in list and grid modes; tree mode ignores `showThumbnails`

**Decision:** The `showThumbnails: boolean` config only applies in `list` and `grid` modes. `tree` mode never renders thumbnails (it's optimized for hierarchy, not visuals).

**Alternatives considered:**

- Thumbnails in all modes. Rejected — tree mode with thumbnails is cluttered and slow.
- Separate thumbnail config per mode. Rejected — too many knobs; users expect one toggle.

**Rationale:** Tree mode has different performance/UX characteristics; one global toggle is sufficient.

## Risks / Trade-offs

- **Risk:** Widget fetches on every breadcrumb click (no caching). Slow on large folders. → **Mitigation:** Pagination (cursor-based). Reasonable folder sizes (<1000 items) load in <1s. Can add caching (ETags, server-side) in a follow-up if needed.
- **Risk:** Upload/delete buttons are visible even if permission check will fail. → **Mitigation:** Backend ACL check happens before write; frontend shows error (e.g., "Permission denied"). Can optimize frontend to pre-check permission on fetch response.
- **Risk:** MIME filter with glob wildcards may be surprising (e.g., `image/*` excludes `image/svg+xml` if not listed). → **Mitigation:** Document glob syntax in config UI; show examples.
- **Risk:** Client-side search doesn't work on large folder contents (>10K items). → **Mitigation:** Pagination limits items per fetch to 50; search scope is one page. Server-side search can be added later if needed.
- **Trade-off:** No shared-workspace UI orchestration in v1 (e.g., "this widget and that widget are bound to the same folder"). Acceptable because the widget is useful standalone; orchestration is a future add-on.

## Migration Plan

1. **Backend API lands first** — implement the three endpoints (`GET contents`, `POST upload`, `DELETE item`), register the widget capability, add tests.
2. **Frontend components + composables** — implement `FilesWidget.vue`, `FilesWidgetConfig.vue`, `useFilesWidget.js`.
3. **Integration tests** — Playwright tests covering navigation, upload, delete, ACL, empty states.
4. **Rollback:** Pure app change (no core Nextcloud schema changes). Disabling the app or reverting the PR restores the previous state.

## Open Questions

- Should the widget cache folder metadata (name, permissions) to avoid redundant lookups? Current decision: fetch fresh on each breadcrumb click (simpler, correct, acceptable latency). Revisit if users report slowness.
- Should upload/delete show confirmation with the file count before writing? Current decision: delete shows a confirm modal; upload shows per-file progress. Revisit if UX testing suggests users want a pre-submit confirmation.
- Should pagination be cursor-based (opaque token) or offset-based (skip N items)? Current decision: cursor (opaque, efficient). Revisit if there are challenges with token generation or state tracking.
