# Tasks — files-widget

## Tasks

### Backend API

- [ ] Task 1: Create `backend/OCA/MyDash/Controller/FilesWidgetController.php` with route binding for `{placementId}` and dependency injection for `IUserFolder`, `IAppManager`, `IRootFolder`, `ILogger`
- [ ] Task 2: Implement `GET /api/widgets/files/{placementId}/contents?cursor=&limit=50` endpoint: (a) resolve placement from DB, (b) get placement config (folderPath or fileId), (c) resolve folder via `IRootFolder`, (d) check viewer read permission (return 403 if denied), (e) list contents with ACL filter, (f) apply MIME type filter, (g) apply sort order (name, modified, size, type; asc/desc), (h) paginate with cursor, (i) compute `canEdit`/`canDelete` per item, (j) return JSON with items + nextCursor
- [ ] Task 3: Implement `POST /api/widgets/files/{placementId}/upload` endpoint: (a) resolve placement + folder, (b) check write permission (return 403 if denied), (c) accept multipart upload, (d) iterate files and write via `IRootFolder->getFile()` or create flow, (e) handle conflict via Nextcloud's rename logic (rename to `{name} (1).{ext}`), (f) return upload results (success/error per file)
- [ ] Task 4: Implement `DELETE /api/widgets/files/{placementId}/item?path=...` endpoint: (a) resolve placement + folder, (b) get item at path, (c) check delete permission (return 403 if denied), (d) move to trash via `ITrashManager` or `->delete()`, (e) return HTTP 204 success
- [ ] Task 5: Create `backend/OCA/MyDash/Capabilities/FilesWidgetCapability.php` and register widget with `OCP\Dashboard\IManager::registerWidget()` with id `mydash_files`, title, icon, and v2 API indication; hook into app's capability declaration
- [ ] Task 6: Add Psalm/PHPStan strict checks on new Controller and Capability files; ensure no undefined variable, type mismatch, or null reference errors
- [ ] Task 7: Unit tests for FilesWidgetController: (a) permission checks (403 for unreadable folder), (b) MIME type filtering (only matched types returned), (c) cursor pagination (multiple batches), (d) canEdit/canDelete flag accuracy, (e) empty folder (empty items array), (f) folder not found (404), (g) conflict handling on upload

### Frontend Components

- [ ] Task 8: Create `src/widgets/FilesWidget.vue` Vue 3 SFC with `<template>`, `<script setup>`, `<style scoped>`: (a) breadcrumb section (clickable path segments), (b) view mode switcher (list/grid/tree buttons), (c) search input field, (d) results area rendering list/grid/tree view, (e) upload zone (visible if allowUpload + write permission), (f) loading state (spinner), (g) empty states (no access, folder not found, empty folder, empty search)
- [ ] Task 9: Implement list view mode in FilesWidget.vue: table-like rows with columns: icon (file-type or thumbnail), name, modified date, size; file click triggers deep-link; folder click triggers breadcrumb navigation; delete icon visible on each row if allowDelete + permission
- [ ] Task 10: Implement grid view mode in FilesWidget.vue: card/tile layout with file-type icon or thumbnail; name below card; click behavior same as list; thumbnail rendering conditional on showThumbnails + grid mode
- [ ] Task 11: Implement tree view mode in FilesWidget.vue: collapsible folder tree; click expand/collapse to load subfolder contents; breadcrumb reflects current depth; tree view ignores showThumbnails
- [ ] Task 12: Create search input field in FilesWidget.vue: (a) text input with placeholder "Search files...", (b) on input change, filter items client-side by name substring (case-insensitive), (c) show "No files matching X" message if no results, (d) preserve sort order when filtering, (e) searching affects both files and folders
- [ ] Task 13: Create breadcrumb component (or inline in FilesWidget): (a) render path segments from root to current folder, (b) each segment is clickable, (c) click updates widget's current navigation path and refetches contents, (d) root segment shows icon or "[root]" label, (e) clicking root resets to configured folder
- [ ] Task 14: Create `src/components/FilesWidgetConfig.vue` for the widget configuration sub-form: (a) folder picker (via Nextcloud's folder browser API or similar), (b) dropdowns for viewMode (list/grid/tree), showThumbnails toggle, (c) MIME type filter input (comma-separated or multi-select), (d) sortBy + sortDescending, (e) allowUpload + allowDelete toggles, (f) save/reset buttons, (g) preview of selected folder path + fileId
- [ ] Task 15: Create `src/composables/useFilesWidget.js` with state management: (a) reactive state: { currentPath, viewMode, searchQuery, sortBy, sortDescending, items, loading, error, nextCursor }, (b) computed: { canShowUpload, canShowDelete, breadcrumbPath, filteredItems }, (c) methods: { fetchContents(cursor?), navigateToFolder(folderPath), uploadFiles(fileList), deleteItem(path), applySearch(query) }
- [ ] Task 16: Implement upload zone in FilesWidget.vue: (a) drag-drop listener with visual feedback (highlight on drag-over), (b) click-to-browse file picker, (c) on drop/select, iterate files and call uploadFiles() from composable, (d) show per-file progress (name + percentage), (e) on success, refetch folder contents and clear upload UI; on error, show error message
- [ ] Task 17: Implement delete action in FilesWidget.vue: (a) delete icon/button on each item (visible if allowDelete + permission), (b) on click, show confirmation modal ("Are you sure you want to delete X?"), (c) on confirm, call deleteItem() and refetch contents, (d) on error, show error message

### Frontend API Client

- [ ] Task 18: Create `src/services/FilesWidgetService.js` HTTP client: (a) `getContents(placementId, cursor?, limit?, currentPath?)` → GET /api/widgets/files/{placementId}/contents, (b) `uploadFiles(placementId, fileList, currentPath?)` → POST /api/widgets/files/{placementId}/upload (multipart), (c) `deleteItem(placementId, itemPath)` → DELETE /api/widgets/files/{placementId}/item?path=...
- [ ] Task 19: Error handling in FilesWidgetService.js: (a) catch HTTP 403 and map to `error: "no_access"`, (b) catch HTTP 404 and map to `error: "folder_not_found"`, (c) catch other 4xx/5xx and return generic error, (d) log to console in development
- [ ] Task 20: Thumbnail URL generation in FilesWidget.vue: for each item with mimeType matching `image/*` or `video/*`, set thumbnailUrl to Nextcloud's preview endpoint (e.g., `/core/preview?fileId=...&x=...&y=...`)

### Integration & Documentation

- [ ] Task 21: Playwright test: (a) add 3 files to a test folder, (b) place a files widget configured to that folder, (c) verify list view shows all 3 files with correct columns, (d) click on one file and verify it opens `/apps/files/?fileid=...`, (e) verify upload works by dragging a file to the widget, (f) verify delete works with confirm modal
- [ ] Task 22: Playwright test for ACL: (a) create a shared folder with user A (owner) and user B (can read), (c) user B places a files widget; verify they see contents, (d) user A revokes user B's access, (e) user B refreshes widget; verify 403 "no access" state
- [ ] Task 23: Playwright test for navigation: (a) folder with subfolders A/B/C, (b) place widget at root, (c) click A then B then C; verify breadcrumb and contents update, (d) click "B" in breadcrumb; verify back to B, (e) click root; verify back to root, (f) place two widgets pointing to different roots; navigate in one and verify the other is unaffected
- [ ] Task 24: Playwright test for MIME filter: (a) folder with image.png, document.pdf, notes.txt, (b) widget with `mimeTypeFilter: ["image/*"]`, (c) verify only image.png appears, (d) remove filter, (e) verify all 3 files appear
- [ ] Task 25: Playwright test for grid view and thumbnails: (a) folder with images, (b) widget in grid mode with showThumbnails: true, (c) verify thumbnails are rendered, (d) toggle to grid with showThumbnails: false, (e) verify file-type icons only (no thumbnails)
- [ ] Task 26: Vitest unit tests for FilesWidgetService: (a) HTTP mocking via `vi.mock()`, (b) getContents with cursor returns paginated results, (c) uploadFiles sends multipart request, (d) deleteItem sends DELETE request, (e) error mapping (403 → no_access, 404 → folder_not_found)
- [ ] Task 27: Vitest unit tests for useFilesWidget composable: (a) fetchContents populates items and nextCursor, (b) navigateToFolder updates currentPath and refetches, (c) applySearch filters items by substring (case-insensitive), (d) uploadFiles calls service.uploadFiles() and refetches, (e) deleteItem calls service.deleteItem() and refetches
- [ ] Task 28: ESLint clean on all new JS/Vue files; no console warnings or errors
- [ ] Task 29: i18n: (a) extract user-facing strings: "Files" (widget title), "Upload", "Delete", breadcrumb labels, empty states, error messages, (b) add to `src/locales/en.json` and `src/locales/nl.json`, (c) use `$t('key')` in Vue components
- [ ] Task 30: Documentation (README or CHANGELOG): (a) describe widget capability, (b) list requirements REQ-FLS-001 through REQ-FLS-011, (c) document configuration options, (d) note: no inline preview (deep-link to Files app), (e) note: view-time ACL enforced
- [ ] Task 31: Code review checklist: (a) no direct `grid.addWidget` calls outside placement helper, (b) all HTTP endpoints return correct status codes + error shapes, (c) permission checks on backend (no bypasses), (d) Vue components use `setup()` + Composition API, (e) Playwright tests are flake-free (use `waitFor`, no hard sleeps)
- [ ] Task 32: Verification: (a) `openspec validate` exits clean, (b) `npm run lint:strict` passes, (c) `npm run test:vitest` passes, (d) `npm run test:playwright -- files-widget` passes, (e) new files have no PHPCS/PHPMD/PHPStan regressions

## Verification

`openspec validate` exits clean. Files widget appears in widget picker, configuration persists, contents endpoints return ACL-filtered data, navigation and upload/delete work as specified in REQ-FLS-001 through REQ-FLS-011.

## Tests (company-wide ADR-009)

Vitest (Tasks 26–27), Playwright (Tasks 21–25). Backend unit tests (Task 7). No integration tests with other widget types (standalone in v1).

## Documentation (company-wide ADR-010)

Inline Vue/PHP comments per Task 8–17. README/CHANGELOG entry per Task 30.

## i18n (company-wide ADR-005)

User-facing strings extracted and localized in `en`+`nl` per Task 29. No hardcoded labels in component templates.
