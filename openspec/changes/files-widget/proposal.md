# Files Widget

The files widget is a built-in MyDash widget type that lets dashboard authors embed an inline Nextcloud Files browser directly on a dashboard. The widget reads the configured folder live at render time, applies view-time ACL so each viewer sees only files they may read, supports folder navigation via a breadcrumb, deep-links file clicks into the standard Files application, and exposes optional upload and delete actions gated by both placement-level toggles and per-viewer permission. The capability is one widget type, one renderer, one sub-form, one registry entry, and three HTTP endpoints (contents listing, multi-file upload, single-file delete) — small enough to ship and evolve independently while anchoring the future "shared workspace folder" experience that other widgets will build on top of.

## Affected code units

- `src/widgets/FilesWidget.vue` — the main widget renderer (list/grid/tree view modes, breadcrumb, search)
- `src/components/FilesWidgetConfig.vue` — the widget configuration sub-form (folder picker, display mode, filters, permissions)
- `src/composables/useFilesWidget.js` — helper for managing widget state (current navigation path, sorting, filtering)
- `src/services/FilesWidgetService.js` — HTTP client for contents, upload, delete endpoints
- `backend/OCA/MyDash/Controller/FilesWidgetController.php` — three new API endpoints (GET contents, POST upload, DELETE item)
- `backend/OCA/MyDash/Capabilities/FilesWidgetCapability.php` — widget registration with OCP\Dashboard\IManager
- Adds new capability `files-widget`

## Why a delta

MyDash today is a dashboard framework with text, image, and HTML widgets. Embedding a live Files browser directly on a dashboard is a requested feature that opens the door to "shared workspace" where multiple widget types work together around a common folder. The files widget is small, self-contained, and can land independently without affecting existing widgets or the dashboard architecture. It depends only on Nextcloud's core Files app (which is always present) and the MyDash placement + widget registry (which are stable).

## Approach

**Frontend:**
- Vue 3 `<FilesWidget>` component with configurable folder, view mode (`list` | `grid` | `tree`), thumbnails, MIME type filter, sort order
- Breadcrumb navigation with click-to-jump; folder clicks update local state and refetch
- File clicks deep-link to `?fileid=...` in the Nextcloud Files app (no inline preview)
- Optional upload dropzone (drag/drop + progress) and per-row delete actions (with confirm modal)
- Search filter (client-side, case-insensitive substring match)

**Backend:**
- `GET /api/widgets/files/{placementId}/contents?cursor=&limit=50` — paginated directory listing with user ACL
- `POST /api/widgets/files/{placementId}/upload` — multipart upload with conflict handling
- `DELETE /api/widgets/files/{placementId}/item?path=...` — move to trash (respects delete ACL)

**Data layer:**
- No schema migration: configuration lives in `oc_mydash_widget_placements.widgetContent` (JSON, discriminated on `type: 'files'`)
- Content schema has nine fields: `folderPath`, `fileId` (preferred), `viewMode`, `showThumbnails`, `mimeTypeFilter`, `allowUpload`, `allowDelete`, `sortBy`, `sortDescending`

## Capabilities

**New Capabilities:**

- `files-widget` (11 requirements covering registration, configuration, contents, ACL, navigation, deep-linking, upload, delete, folder not found, filtering, empty states)

## Notes

- The widget is read-heavy by design (contents fetched per breadcrumb click); writes (upload/delete) are gated by permission flags and user ACL
- Folder resolution prefers `fileId` (survives renames) over `folderPath` for robustness
- Empty states and 404 handling are defensive: no stack traces, no folder metadata leaks on 403
- The change is frontend + backend; no database schema changes; can be deployed and reverted independently
- Search is client-side to avoid server round-trips; server filters by MIME type and permission
