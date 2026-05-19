# Files Widget

## Why

MyDash users frequently need to access files stored in Nextcloud without leaving the dashboard. Currently, they must navigate away to the Files app to browse, preview, or upload files. A files widget embedded directly on the dashboard bridges this gap by providing an inline folder browser with configurable display modes, permission-aware access control, and simple upload/delete capabilities. This reduces context switching and accelerates common file-management workflows.

## What Changes

- Register a new dashboard widget with id `mydash_files` via `OCP\Dashboard\IManager` that appears in the widget picker.
- Add per-placement configuration stored in `widgetContent JSON` to specify the target folder (via absolute NC path or persistent fileId), display mode (list/grid/tree), filtering options, and access controls (upload/delete).
- Implement backend `GET /api/widgets/files/{placementId}/contents?cursor=&limit=50` to return paginated directory listings with per-file metadata (name, size, modified, mime type, thumbnails, permissions).
- Enforce view-time ACL: only files and folders the VIEWING user can read are listed. Return HTTP 403 with `{error: "no_access"}` if the configured folder is unreadable.
- Support folder navigation: clicking a sub-folder updates the widget's internal navigation state and refetches. Breadcrumb navigation shows the current path with click-to-jump-up.
- File clicks deep-link to the Nextcloud Files app (`/apps/files/?fileid={fileId}`) to open in the standard viewer/preview.
- Upload support: when `allowUpload = true` AND the viewer has write permission, an "Upload" button opens a multi-file dropzone. Uploads use Nextcloud's WebDAV / `IRootFolder` API.
- Delete support: when `allowDelete = true` AND the viewer has delete permission, row-level delete actions appear with confirm modal.
- Resilience: if the configured folder is deleted, the widget shows "Folder no longer exists" empty state rather than failing.
- Optional in-widget search: client-side substring filter on folder names (case-insensitive).
- Empty state: "This folder is empty."

## Capabilities

### New Capabilities

- `files-widget` — A new MyDash dashboard widget capability providing inline Nextcloud Files folder browsing with permission-aware access control, upload/delete, and multiple display modes.

## Impact

**Affected code:**

- `lib/Service/FilesWidgetService.php` — core logic for folder traversal, permission checks, pagination, and file metadata compilation.
- `lib/Controller/WidgetController.php` — new endpoint `GET /api/widgets/files/{placementId}/contents`.
- `src/components/widgets/FilesWidget.vue` — main widget component handling folder navigation, display modes, and state.
- `src/components/widgets/files/FilesList.vue` — list mode renderer.
- `src/components/widgets/files/FilesGrid.vue` — grid mode renderer with thumbnails.
- `src/components/widgets/files/FilesTree.vue` — tree/hierarchy mode renderer.
- `src/components/widgets/files/FileUploader.vue` — drag-drop uploader with progress tracking.
- `src/components/widgets/files/FileBreadcrumb.vue` — breadcrumb navigation with path jump-up.
- `src/components/widgets/eventpicker/FilesWidgetConfig.vue` — placement config UI for folder selection, view mode, and access control toggles.
- `appinfo/routes.php` — register the new files widget contents endpoint.
- `src/stores/widgets.js` — add widget-specific runtime state for current navigation path and fetch status.

**Affected APIs:**

- 1 new route: `GET /api/widgets/files/{placementId}/contents`
- 0 changes to existing routes.

**Dependencies:**

- `OCP\Files\IRootFolder` — file system traversal and folder resolution.
- `OCP\IUserSession` — current user context for permission checks.
- `OCP\Files\FileInfo` — file metadata (size, mime type, modified time).
- No new composer or npm dependencies.

**Migration:**

- Zero-impact: app config keys not required. All widget-specific state is stored in placement `widgetContent` JSON.
- No data backfill required. Existing placements without files widget config simply don't render the widget.
