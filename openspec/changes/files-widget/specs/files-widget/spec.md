---
status: draft
---

# Files Widget Specification

## ADDED Requirements

### Requirement: REQ-FLS-001 Widget registration

The system MUST register a MyDash dashboard widget with id `mydash_files` via `OCP\Dashboard\IManager::registerWidget()` so it appears in the widget picker alongside other Nextcloud dashboard widgets.

#### Scenario: Widget appears in picker

- GIVEN the files-widget capability is installed and enabled
- WHEN a user opens the MyDash widget picker dialog
- THEN the `mydash_files` widget MUST appear in the list with a title (e.g., "Files") and an icon
- AND the widget MUST be selectable for placement on a dashboard

#### Scenario: Multiple instances allowed

- GIVEN a dashboard already has one files widget placement
- WHEN the user adds a second files widget to the same dashboard
- THEN both placements MUST coexist with independent configurations
- AND each widget MUST track its own current navigation path independently

#### Scenario: Widget registration survives app reload

- GIVEN the widget is registered
- WHEN Nextcloud cache is cleared or the app is reloaded
- THEN the `mydash_files` widget MUST still be discoverable in the picker

#### Scenario: Widget registration includes metadata

- GIVEN the widget is registered
- WHEN the widget picker fetches widget metadata
- THEN the widget object MUST include at minimum: id, title, icon_url, and v2 API support indication

### Requirement: REQ-FLS-002 Placement configuration

The system MUST store per-placement widget configuration in the `oc_mydash_widget_placements.widgetContent` JSON field, allowing users to specify the target folder, display mode, filtering, and permission settings.

#### Scenario: Config for folder path

- GIVEN a user is configuring a files widget placement
- WHEN they select a folder via the picker
- THEN the configuration MUST store either `folderPath: string` (absolute NC path like `/Documents/Marketing`) OR `fileId: number` (persistent folder ID)
- AND `fileId` is PREFERRED over `folderPath` because it survives folder renames
- NOTE: Exactly one of `folderPath` or `fileId` MUST be present; they are mutually exclusive

#### Scenario: Config for view mode

- GIVEN a user is configuring a files widget placement
- WHEN they choose a display mode
- THEN the configuration MUST store a `viewMode: 'list'|'grid'|'tree'` field
- AND the default viewMode MUST be `'list'` when not explicitly set

#### Scenario: Config for thumbnails

- GIVEN a user is configuring a files widget placement
- WHEN they toggle thumbnail display
- THEN the configuration MUST store a `showThumbnails: boolean` field
- AND the default value MUST be `true`
- AND thumbnails only apply in `grid` and `list` modes; `tree` mode ignores this setting

#### Scenario: Config for MIME type filter

- GIVEN a user is configuring a files widget placement
- WHEN they restrict to specific file types
- THEN the configuration MUST store a `mimeTypeFilter: string[]` field (empty array = no filter, all files shown)
- AND valid examples include: `["image/*", "application/pdf", "text/plain"]`
- NOTE: Empty array or absence of this field means no filtering is applied

#### Scenario: Config for upload and delete permissions

- GIVEN a user is configuring a files widget placement
- WHEN they enable/disable upload and delete actions
- THEN the configuration MUST store `allowUpload: boolean` (default `false`) and `allowDelete: boolean` (default `false`)
- AND these flags are only honored if the VIEWING user has the corresponding permission on the folder
- NOTE: Presence of the flag in config does NOT grant permission; it only enables the UI if permissions exist

#### Scenario: Config for sorting

- GIVEN a user is configuring a files widget placement
- WHEN they set sort order
- THEN the configuration MUST store `sortBy: 'name'|'modified'|'size'|'type'` (default `'name'`) and `sortDescending: boolean` (default `false`)

#### Scenario: Config is JSON-serializable

- GIVEN a placement with files widget config
- WHEN the placement is fetched from the database
- THEN the `widgetContent` MUST deserialize cleanly to a JavaScript object
- AND all array and string fields MUST have no spurious whitespace or encoding issues

### Requirement: REQ-FLS-003 Contents endpoint

The system MUST expose `GET /api/widgets/files/{placementId}/contents?cursor=&limit=50` that returns paginated directory listings with file metadata, respecting the configured folder and user permissions.

#### Scenario: Fetch folder contents

- GIVEN a files widget placement configured with a readable folder
- AND the requesting user has read permission on that folder
- WHEN the frontend sends GET /api/widgets/files/{placementId}/contents?cursor=&limit=50
- THEN the system MUST return HTTP 200 with a JSON object: `{items: [{...}], nextCursor: "..."|null}`
- AND each item MUST include: fileId (number), name (string), path (string, relative to widget root), mimeType (string), size (number, bytes), modifiedAt (ISO 8601), isFolder (boolean), thumbnailUrl (string, nullable), canEdit (boolean), canDelete (boolean)
- AND items MUST be sorted according to placement config (sortBy, sortDescending)

#### Scenario: Pagination with cursor

- GIVEN folder contents exceed the limit (e.g., 150 files, limit=50)
- WHEN the frontend sends GET /api/widgets/files/{placementId}/contents?cursor=&limit=50
- THEN the response MUST include `nextCursor: "opaque_string_value"` to fetch the next batch
- AND the frontend MUST send GET /api/widgets/files/{placementId}/contents?cursor=opaque_string_value&limit=50 to fetch the next batch
- AND the final batch MUST have `nextCursor: null`
- NOTE: Cursor implementation is opaque to the frontend; the backend may use file ID offsets, timestamps, or other strategies

#### Scenario: Permission-aware item listing

- GIVEN a folder with files: A (user can read), B (user cannot read), C (user can read, can edit), D (user can read, can delete)
- WHEN the user fetches folder contents
- THEN items A, C, D MUST appear with accurate `canEdit` and `canDelete` flags
- AND item B MUST be ABSENT from the list (not disclosed to user without read permission)

#### Scenario: MIME type filter applied

- GIVEN a placement configured with `mimeTypeFilter: ["image/*"]`
- AND the folder contains: image.png, document.pdf, notes.txt
- WHEN the frontend fetches folder contents
- THEN only image.png MUST be returned
- AND document.pdf and notes.txt MUST be filtered out

#### Scenario: Empty folder

- GIVEN a placement configured with an empty folder
- WHEN the frontend fetches folder contents
- THEN the system MUST return HTTP 200 with `{items: [], nextCursor: null}`

#### Scenario: Folder not found

- GIVEN a placement configured with a folder that no longer exists
- WHEN the frontend fetches folder contents
- THEN the system MUST return HTTP 404 with `{error: "folder_not_found", message: "The configured folder no longer exists"}`

### Requirement: REQ-FLS-004 View-time access control

The system MUST enforce view-time permission checks: only files and folders the VIEWING user can read are listed. If the configured folder is unreadable, the widget MUST NOT expose its existence or contents.

#### Scenario: User cannot read folder

- GIVEN a placement configured with a folder that the requesting user cannot read
- AND another user can read that folder
- WHEN the requesting user fetches folder contents
- THEN the system MUST return HTTP 403 with `{error: "no_access", message: "You don't have access to this folder"}`
- AND the widget frontend MUST display an empty-state "You don't have access to this folder."
- NOTE: No folder metadata (name, structure) is disclosed

#### Scenario: User loses permission after widget creation

- GIVEN a user created a files widget pointing to a folder they could read
- AND the folder owner later revokes the user's read permission
- WHEN the user refreshes the dashboard and the widget attempts to fetch contents
- THEN HTTP 403 `no_access` MUST be returned
- AND the widget MUST gracefully show the access-denied empty state

#### Scenario: Mixed permissions within folder

- GIVEN a folder with a subfolder that the user cannot read
- AND other files in the main folder that the user can read
- WHEN the user fetches folder contents
- THEN readable files MUST be listed
- AND the unreadable subfolder MUST be absent from the list (not shown as a grayed-out or inaccessible item)

#### Scenario: Upload/delete checks at action time

- GIVEN a placement with `allowUpload: true` and `allowDelete: true`
- AND the folder is readable but NOT writable by the user
- WHEN the widget renders
- THEN the "Upload" button MUST NOT appear (no upload UI)
- AND delete actions MUST NOT appear on items
- AND if the user somehow submits a DELETE request, the backend MUST return HTTP 403

### Requirement: REQ-FLS-005 Breadcrumb navigation and folder traversal

The system MUST support folder navigation: clicking a sub-folder updates the widget's current path (in client state, not config) and refetches. A breadcrumb above the listing shows the current path with click-to-jump-up.

#### Scenario: Breadcrumb displays current path

- GIVEN a user navigates to folder `/Documents/Marketing/Q2`
- WHEN the widget renders
- THEN the breadcrumb MUST display: `[root] / Documents / Marketing / Q2`
- AND each breadcrumb segment MUST be clickable

#### Scenario: Click breadcrumb to jump up

- GIVEN the breadcrumb displays `[root] / Documents / Marketing / Q2`
- WHEN the user clicks on `Marketing`
- THEN the widget MUST refetch contents for `/Documents/Marketing`
- AND the breadcrumb MUST update to `[root] / Documents / Marketing`

#### Scenario: Click folder to descend

- GIVEN the widget displays contents of `/Documents`
- WHEN the user clicks on a folder named `Marketing`
- THEN the widget MUST refetch contents for `/Documents/Marketing`
- AND the breadcrumb MUST update to `[root] / Documents / Marketing`
- AND sorting and MIME filter MUST be reapplied to the new folder

#### Scenario: Navigation state isolated per widget instance

- GIVEN a dashboard with two files widgets (each pointing to different root folders)
- WHEN the user navigates in widget A to subfolder `./subfolder`
- THEN widget B MUST remain at its own root folder
- AND the two widgets MUST NOT affect each other's current path

#### Scenario: Root folder breadcrumb

- GIVEN a user is viewing the configured root folder
- WHEN the widget renders the breadcrumb
- THEN it MUST display at least `[root]` or a home icon
- AND clicking it MUST not navigate (or reset navigation to the configured root)

### Requirement: REQ-FLS-006 File click deep-linking

When a user clicks on a file, the widget MUST open the standard Nextcloud file viewer/preview by deep-linking to the Nextcloud Files app. The widget MUST NOT attempt to render previews inline.

#### Scenario: Click file to open in Files app

- GIVEN a file with fileId `12345` in the widget listing
- WHEN the user clicks on the file
- THEN the widget MUST open `/apps/files/?fileid=12345` in a new tab or window
- AND the Nextcloud Files app MUST load the file preview/viewer in its standard way

#### Scenario: Click folder does not deep-link

- GIVEN a folder with fileId `12346` in the widget listing
- WHEN the user clicks on the folder
- THEN the widget MUST NOT deep-link; instead, it MUST navigate within the widget (see REQ-FLS-005)
- AND the breadcrumb and contents MUST update

#### Scenario: Deep-link preserves file context

- GIVEN a file `/Documents/Marketing/budget.xlsx` with fileId `555`
- WHEN the user clicks it in the widget
- THEN the Files app MUST show the file with full preview/editing capabilities
- AND the file's location in the Nextcloud file tree MUST be preserved

### Requirement: REQ-FLS-007 Upload capability

When `allowUpload = true` AND the viewer has write permission on the folder, an "Upload" button MUST surface a multi-file dropzone. Uploads MUST go through Nextcloud's standard WebDAV / `IRootFolder` API and show per-file progress.

#### Scenario: Upload button appears when allowed

- GIVEN a placement with `allowUpload: true`
- AND the viewer has write permission on the configured folder
- AND the folder is not locked
- WHEN the widget renders
- THEN an "Upload" button or drop zone MUST be visible

#### Scenario: Upload button hidden when not allowed

- GIVEN a placement with `allowUpload: false`
- OR the viewer lacks write permission
- WHEN the widget renders
- THEN the "Upload" button MUST NOT appear

#### Scenario: Drag-drop upload multiple files

- GIVEN the upload zone is visible
- WHEN the user drags 3 files and drops them on the widget
- THEN the system MUST accept all 3 files for upload
- AND each file MUST show individual upload progress
- AND on success, all 3 files MUST appear in the folder listing

#### Scenario: Upload progress indication

- GIVEN a 50 MB file is being uploaded
- WHEN the upload is in progress
- THEN the widget MUST display a progress bar or percentage (e.g., "25/50 MB uploaded")
- AND the user MUST be able to see which files are queued, in progress, or completed

#### Scenario: Upload conflict handling

- GIVEN a user uploads a file `report.pdf` but `report.pdf` already exists in the folder
- WHEN the upload completes
- THEN the system MUST follow Nextcloud's standard conflict behavior (rename to `report (1).pdf` or return a conflict prompt via the frontend)

#### Scenario: Upload fails gracefully

- GIVEN a user uploads a 5 GB file to a folder with only 1 GB free space
- WHEN the upload attempt exceeds available space
- THEN the backend MUST return an error (e.g., HTTP 507 or 400)
- AND the widget MUST display the error for that file without blocking other uploads
- AND the folder listing MUST NOT be corrupted

### Requirement: REQ-FLS-008 Delete capability

When `allowDelete = true` AND the viewer has delete permission on items, row-level delete actions MUST appear with a confirm modal. Deletes MUST respect Nextcloud's trash/recycle bin where applicable.

#### Scenario: Delete button appears when allowed

- GIVEN a placement with `allowDelete: true`
- AND the viewer has delete permission on a file
- WHEN the widget renders that file
- THEN a delete icon or button MUST appear on the item (e.g., trash icon)

#### Scenario: Delete button hidden when not allowed

- GIVEN a placement with `allowDelete: false`
- OR the viewer lacks delete permission on a file
- WHEN the widget renders that file
- THEN the delete button MUST NOT appear for that item

#### Scenario: Delete with confirmation

- GIVEN a file with a delete button visible
- WHEN the user clicks the delete button
- THEN a confirmation modal MUST appear with text like "Are you sure you want to delete report.pdf?"
- AND the user MUST be able to cancel or confirm
- AND canceling MUST not delete the file

#### Scenario: Delete moves to trash

- GIVEN a file in the configured folder
- WHEN the user confirms deletion
- THEN the file MUST be moved to Nextcloud's trash/recycle bin (not permanently deleted)
- AND the file MUST immediately disappear from the widget listing
- AND subsequent folder refresh MUST not show the deleted file

#### Scenario: Delete folder recursively

- GIVEN a subfolder within the configured folder
- WHEN the user confirms deletion of the subfolder
- THEN the system MUST delete the folder and all its contents (recursively)
- AND the folder MUST disappear from the breadcrumb and current folder listing

#### Scenario: Delete on permission-denied folder

- GIVEN a widget pointing to a folder
- AND the user has delete permission on a file, but the folder itself becomes read-only after widget creation
- WHEN the user attempts to delete
- THEN the backend MUST return HTTP 403
- AND the widget MUST display an error (e.g., "You no longer have permission to delete this item")

### Requirement: REQ-FLS-009 Folder-not-found tolerance

If the configured folder is deleted or becomes inaccessible (e.g., share revoked), the widget MUST show an empty-state message rather than an error. The widget MUST NOT expose a 500 error to the user.

#### Scenario: Folder deleted

- GIVEN a placement configured with folder fileId `999`
- AND the folder is deleted by another user
- WHEN the current user refreshes the widget
- THEN the widget MUST display "Folder no longer exists" empty state
- AND the backend MUST return HTTP 404 with `{error: "folder_not_found"}`
- AND the widget MUST handle this gracefully (no crash, no stack trace)

#### Scenario: Share revoked after widget creation

- GIVEN a user created a files widget pointing to a shared folder
- AND the share owner revokes the share
- WHEN the user refreshes the widget
- THEN the widget MUST display "You don't have access to this folder" (same as REQ-FLS-004)
- AND the backend MUST return HTTP 403

#### Scenario: File in configured folder deleted, folder exists

- GIVEN a widget is displaying a folder with 3 files
- AND another user deletes one of the files
- WHEN the current user refreshes the folder contents
- THEN the deleted file MUST NOT appear in the listing
- AND the other 2 files MUST still appear normally

### Requirement: REQ-FLS-010 MIME type filtering and view modes

The system MUST support MIME type filtering (empty filter = all files) and multiple display modes (list, grid, tree). Filtering MUST be applied server-side in the contents endpoint.

#### Scenario: No MIME filter (all files shown)

- GIVEN a placement with empty or missing `mimeTypeFilter` field
- WHEN the widget fetches folder contents
- THEN all files and folders (regardless of type) MUST be returned
- AND the backend MUST not apply any filtering

#### Scenario: MIME filter by type

- GIVEN a placement with `mimeTypeFilter: ["image/*", "video/*"]`
- AND the folder contains: photo.jpg, video.mp4, document.pdf, spreadsheet.xlsx
- WHEN the widget fetches folder contents
- THEN only photo.jpg and video.mp4 MUST be returned
- AND document.pdf and spreadsheet.xlsx MUST be filtered out (not disclosed)

#### Scenario: MIME filter by specific MIME type

- GIVEN a placement with `mimeTypeFilter: ["application/pdf"]`
- AND the folder contains: budget.pdf, report.docx, notes.pdf
- WHEN the widget fetches folder contents
- THEN both budget.pdf and notes.pdf MUST be returned
- AND report.docx MUST be filtered out

#### Scenario: List mode renders rows

- GIVEN a placement with `viewMode: 'list'`
- WHEN the widget renders
- THEN items MUST be displayed as rows with columns: icon/thumbnail (if showThumbnails), name, modified date, size
- AND each row MUST be clickable (file → deep-link; folder → navigate)

#### Scenario: Grid mode with thumbnails

- GIVEN a placement with `viewMode: 'grid'` and `showThumbnails: true`
- AND the folder contains: image.png, document.pdf, video.mp4
- WHEN the widget renders
- THEN each item MUST be displayed as a card/tile with thumbnail image
- AND thumbnails for images and videos MUST be visible
- AND document.pdf MUST show a file-type icon (no rendered preview inline)

#### Scenario: Grid mode without thumbnails

- GIVEN a placement with `viewMode: 'grid'` and `showThumbnails: false`
- WHEN the widget renders
- THEN each item MUST display as a card/tile with file-type icon (no thumbnail)
- AND the grid MUST load faster (no thumbnail rendering)

#### Scenario: Tree mode hierarchical display

- GIVEN a placement with `viewMode: 'tree'`
- AND the folder structure: `root/ > docs/ > 2024/ > [files]` and `root/ > images/ > [files]`
- WHEN the widget renders
- THEN a collapsible tree structure MUST be displayed
- AND clicking expand/collapse on `docs` and `images` MUST show/hide their contents
- AND the current breadcrumb MUST reflect the deepest navigated folder

#### Scenario: Sort order applied per view mode

- GIVEN a placement with `sortBy: 'modified'` and `sortDescending: true`
- WHEN the widget renders in list, grid, or tree mode
- THEN items MUST be sorted by modification date, newest first
- AND the sort order MUST be consistent across all view modes

### Requirement: REQ-FLS-011 Empty states and in-widget search

The system MUST display appropriate empty states and support optional in-widget client-side search to filter folder contents by name substring (case-insensitive).

#### Scenario: Empty folder state

- GIVEN a widget pointing to an empty folder
- WHEN the widget renders
- THEN it MUST display "This folder is empty." message
- AND no files or folders MUST be listed
- AND navigation and breadcrumb MUST still function normally

#### Scenario: Empty search results

- GIVEN a folder with files: "budget.pdf", "report.txt", "notes.md"
- AND the user types "invoice" in an in-widget search field
- WHEN the search is applied
- THEN no items MUST be displayed
- AND a message like "No files matching 'invoice'" MUST appear
- AND clearing the search MUST restore the full listing

#### Scenario: In-widget search is client-side substring filter

- GIVEN a folder with files: "marketing_budget.xlsx", "budget_2024.xlsx", "Q2_report.pdf"
- WHEN the user types "budget" in the search field
- THEN both "marketing_budget.xlsx" and "budget_2024.xlsx" MUST be shown (substring match)
- AND "Q2_report.pdf" MUST be hidden
- NOTE: Search is case-insensitive ("BUDGET", "Budget", "budget" all match)

#### Scenario: Search preserves sort order

- GIVEN items sorted by modified date (newest first)
- WHEN the user applies a search filter
- THEN matching items MUST remain sorted by modified date
- AND the sort order MUST not be disrupted by the filter

#### Scenario: Search filters both files and folders

- GIVEN a folder with subfolder "marketing" and file "marketing_plan.pdf"
- WHEN the user types "market" in the search field
- THEN both the folder and file MUST be displayed (both match)
