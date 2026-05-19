# Tasks — files-widget

## 1. Widget registration and service layer

- [ ] 1.1 Create `lib/Service/FilesWidgetService.php` with core methods:
  - `getWidgetInfo(): array` — returns widget metadata (id, title, icon_url, v2 API support)
  - `getContentsForPlacement(int $placementId, string $currentPath, int $limit, string $cursor): array` — orchestrates folder traversal and permission checks
  - `traverseFolder(string $path, string $mimeFilter, string $sortBy, bool $sortDescending, int $limit, string $cursor): array` — returns paginated items with metadata
  - `checkFolderAccess(string $path, string $action): bool` — permission check (read, write, delete) for current user
  - `buildFileMetadata(FileInfo $file): array` — constructs item object with fileId, name, path, mimeType, size, modifiedAt, isFolder, canEdit, canDelete, thumbnailUrl

- [ ] 1.2 Register the widget in `AppInfo/Bootstrap.php` or lifecycle hook:
  - Hook into Nextcloud's dashboard widget registration
  - Call `IManager::registerWidget()` with widget metadata
  - Widget id: `mydash_files`, title: translatable `app.mydash.files_widget_title`, icon: folder icon URL

- [ ] 1.3 Create PHPUnit tests for `FilesWidgetService`:
  - `testTraverseFolderSuccess` — mock `IRootFolder`, verify file listing
  - `testTraverseFolderWithMimeFilter` — filter by `image/*`, verify only matching files returned
  - `testTraverseFolderPaginated` — verify cursor-based pagination with limit
  - `testCheckFolderAccessReadPermission` — user can read, verify true
  - `testCheckFolderAccessDenied` — user cannot read, verify false
  - `testBuildFileMetadataWithThumbnail` — file supports thumbnail, verify URL present

## 2. Backend controller and routing

- [ ] 2.1 Add endpoint method to `lib/Controller/WidgetController.php`:
  - `public function filesContents(int $placementId, string $currentPath = '/', int $limit = 50, string $cursor = ''): DataResponse`
  - Validate placement exists and user can view associated dashboard (return 403 if denied)
  - Fetch placement config (folderPath, fileId, mimeTypeFilter, sortBy, sortDescending)
  - Resolve folder via fileId or folderPath (prefer fileId)
  - Call `FilesWidgetService::getContentsForPlacement()` with filter and sort options
  - Return HTTP 200 with `{items: [...], nextCursor: "..."|null}`
  - Return HTTP 403 with `{error: "no_access"}` if folder unreadable
  - Return HTTP 404 with `{error: "folder_not_found"}` if folder deleted
  - Decorate with `#[NoCSRFRequired]` and `#[NoAdminRequired]`

- [ ] 2.2 Register route in `appinfo/routes.php`:
  - `GET /api/widgets/files/{placementId}/contents` → `WidgetController::filesContents()`
  - Route requirements: placementId (digits), query params: currentPath (string), limit (integer), cursor (string)

- [ ] 2.3 Create PHPUnit tests for controller:
  - `testFilesContentsSuccess` — mock service, verify HTTP 200 with paginated items
  - `testFilesContentsFolderNotFound` — return 404 when folder deleted
  - `testFilesContentsAccessDenied` — return 403 when user cannot read folder
  - `testFilesContentsWithMimeFilter` — verify filter applied
  - `testFilesContentsWithSortOrder` — verify sort options respected
  - `testFilesContentsPagination` — verify cursor and nextCursor fields

## 3. Placement configuration and schema migration

- [ ] 3.1 Add getter/setter methods in `WidgetPlacementService` or factory to safely parse `widgetContent` JSON:
  - `extractFilesConfig(WidgetPlacement $placement): array` — returns parsed config with defaults (folderPath, fileId, viewMode='list', showThumbnails=true, mimeTypeFilter=[], allowUpload=false, allowDelete=false, sortBy='name', sortDescending=false)
  - Validate and sanitize config (fileId must be numeric, folderPath must be valid absolute path, mimeTypeFilter entries must be valid MIME patterns)

- [ ] 3.2 Create PHPUnit test for placement config parsing:
  - `testExtractFilesConfigWithDefaults` — verify all default values applied
  - `testExtractFilesConfigValidation` — invalid fileId rejected, valid one accepted
  - `testExtractFilesConfigMimeFilter` — validate MIME type patterns

## 4. Folder traversal, permission checks, and metadata

- [ ] 4.1 Implement in `FilesWidgetService::traverseFolder()`:
  - Resolve folder via `IRootFolder::getById()` (if fileId) or `IRootFolder::get()` (if path)
  - Return 404 if folder not found
  - Check read permission via `FileInfo::isReadable()`
  - Return 403 if user cannot read folder
  - Iterate folder children via `DirectoryIterator` or `IRootFolder`
  - For each child, build file metadata via `buildFileMetadata()`
  - Apply MIME filter if present: only include children where `$mimeType` matches patterns in filter
  - Apply sort: use `sortBy` (name/modified/size/type) and `sortDescending` to order results
  - Apply pagination: skip first N items based on cursor, return up to limit items
  - Compute nextCursor based on next item index (or null if at end)
  - Return `{items: [...], nextCursor: ...}`

- [ ] 4.2 Implement `FilesWidgetService::buildFileMetadata()`:
  - Extract: fileId, name, path (relative to widget root), mimeType, size, modifiedAt
  - Compute: isFolder, thumbnailUrl (if applicable and showThumbnails enabled)
  - Check permissions: canEdit (write), canDelete (delete)
  - Return normalized item object

- [ ] 4.3 Implement `FilesWidgetService::checkFolderAccess()`:
  - Use `IUserSession::getUser()` to get current user
  - Use `FileInfo::isReadable()` for read check
  - Use `FileInfo::isUpdateable()` for write/edit check
  - Use `FileInfo::isDeletable()` for delete check
  - Return boolean

- [ ] 4.4 Create PHPUnit tests:
  - `testTraverseFolderFiltered` — verify MIME filter applied correctly
  - `testTraverseFolderSorted` — verify items sorted by name/modified/size
  - `testBuildFileMetadataImage` — image file gets thumbnail URL
  - `testBuildFileMetadataFolder` — folder marked as isFolder=true
  - `testCheckFolderAccessMultipleChecks` — test read, write, delete independently

## 5. Folder-not-found and access-denied resilience

- [ ] 5.1 Implement fallback handling in controller:
  - Catch `NotFoundException` from `IRootFolder::getById()` or `get()` → return HTTP 404
  - Catch permission exceptions or use `isReadable()` check → return HTTP 403
  - Log gracefully (INFO level, not ERROR)

- [ ] 5.2 Create PHPUnit tests:
  - `testFolderDeletedDuringSession` — file existed, is deleted, returns 404
  - `testShareRevokedDuringSession` — folder was readable, permission revoked, returns 403

## 6. Frontend widget component (Vue 3 SFC)

- [ ] 6.1 Create `src/components/widgets/FilesWidget.vue`:
  - Props: `placement: object` (with widgetContent config)
  - Data: `items: []`, `currentPath: '/'`, `loading: false`, `error: null`, `nextCursor: null`, `searchQuery: ''`
  - Computed: `viewMode`, `showThumbnails`, `mimeTypeFilter`, `allowUpload`, `allowDelete` (from placement config with defaults)
  - Computed: `filteredItems` — apply client-side search filter to items (substring match, case-insensitive)
  - Method `fetchContents()`: async call to `GET /api/widgets/files/{placementId}/contents?currentPath=...&limit=50&cursor=...`
    - Handle loading state, errors, pagination
  - Method `handleFolderClick(fileId, name)`: update currentPath, call fetchContents()
  - Method `handleFileClick(fileId)`: open `/apps/files/?fileid={fileId}` in new tab
  - Method `handleBreadcrumbClick(path)`: navigate to breadcrumb path, call fetchContents()
  - Method `handleUploadFiles(files)`: emit to FileUploader component or call upload endpoint
  - Method `handleDeleteFile(fileId, name)`: show confirm modal, call delete endpoint if confirmed
  - Lifecycle: `onMounted` → `fetchContents()`, optional `watch` on placement config → reset and refetch
  - Render: router between list/grid/tree components based on viewMode
  - Render: breadcrumb, search input, empty states, error states, file uploader
  - Render: loading spinner during fetch

- [ ] 6.2 Create `src/components/widgets/files/FilesList.vue`:
  - Props: `items: []`, `showThumbnails: boolean`, `allowDelete: boolean`, `sortBy: string`, `sortDescending: boolean`
  - Emits: `click:file`, `click:folder`, `click:delete`
  - Render: table with columns: icon/thumbnail, name, modified, size
  - Each row clickable on name (emit click:file or click:folder)
  - Delete button on right (if allowDelete and canDelete)
  - Sort indicators on column headers (current sort highlighted)

- [ ] 6.3 Create `src/components/widgets/files/FilesGrid.vue`:
  - Props: `items: []`, `showThumbnails: boolean`, `allowDelete: boolean`
  - Emits: `click:file`, `click:folder`, `click:delete`
  - Render: CSS grid of file cards
  - Each card shows: thumbnail (if showThumbnails) or file icon, name (clipped), modified date
  - Click card → emit click:file or click:folder
  - Hover card → show delete button (if allowDelete)

- [ ] 6.4 Create `src/components/widgets/files/FilesTree.vue`:
  - Props: `items: []`, `allowDelete: boolean`, `currentPath: string`
  - Emits: `click:folder`, `click:file`, `click:delete`
  - Render: hierarchical tree with expand/collapse
  - Folder items show expand/collapse arrow
  - Click folder → emit click:folder with new path
  - Delete button on hover (if allowDelete)

- [ ] 6.5 Create `src/components/widgets/files/FileBreadcrumb.vue`:
  - Props: `currentPath: string`
  - Emits: `navigate:path`
  - Render: breadcrumb items split by `/` with home icon for root
  - Each segment clickable → emit navigate:path with that segment
  - Highlight current last segment

- [ ] 6.6 Create `src/components/widgets/files/FileUploader.vue`:
  - Props: `placementId: number`, `currentPath: string`, `enabled: boolean` (based on allowUpload && canEdit)
  - Data: `uploads: []` (track progress per file)
  - Render: drag-drop zone + "Upload" button
  - On drop or file select: initiate upload via `PUT /api/widgets/files/{placementId}/upload?currentPath=...`
  - Send files as multipart/form-data
  - Emit: `upload:complete` when all done, `upload:error` on failure
  - Show progress bar per file
  - Show error message if upload fails

- [ ] 6.7 Create empty-state, error, and failure components:
  - `EmptyState.vue` — "This folder is empty." or "Folder no longer exists." or "You don't have access to this folder."
  - `ErrorState.vue` — "Failed to load folder contents" with retry button

- [ ] 6.8 Create Playwright E2E tests:
  - `testFilesWidgetListView` — widget renders file list with names, sizes, dates
  - `testFilesWidgetGridView` — widget renders file grid with thumbnails
  - `testFilesWidgetTreeView` — widget renders collapsible tree, expand/collapse works
  - `testFilesWidgetFolderNavigation` — click folder → contents update, breadcrumb updates
  - `testFilesWidgetBreadcrumbJump` — click breadcrumb segment → navigate to that level
  - `testFilesWidgetFileClick` — click file → opens in new tab with fileid parameter
  - `testFilesWidgetSearch` — type search → filter items by substring, clear search → restore
  - `testFilesWidgetUpload` — drag files → upload progress shown, files appear after
  - `testFilesWidgetDelete` — click delete → confirm modal, file removed after confirm
  - `testFilesWidgetEmptyState` — empty folder → shows empty message
  - `testFilesWidgetAccessDenied` — no access to folder → shows access denied message

## 7. Widget configuration UI component

- [ ] 7.1 Create `src/components/widgets/eventpicker/FilesWidgetConfig.vue`:
  - Used by `WidgetAddEditModal.vue` when configuring a files widget placement
  - Form sections:
    - **Folder**: folder picker (loads available folders from user, returns fileId as primary choice, folderPath as secondary)
    - **View Mode**: radio buttons or dropdown (list, grid, tree)
    - **Show Thumbnails**: toggle (default true)
    - **MIME Filter**: multi-input for MIME type patterns (e.g., image/*, text/plain) — add/remove buttons
    - **Sort By**: dropdown (name, modified, size, type)
    - **Sort Descending**: toggle
    - **Allow Upload**: toggle (default false)
    - **Allow Delete**: toggle (default false)
  - Validation: fileId or folderPath must be present (exactly one), MIME patterns must be valid format
  - On save: emit `update:widgetContent` with serialized config object

- [ ] 7.2 Create `src/components/widgets/eventpicker/FolderPicker.vue` (sub-component):
  - Load available folders from Files API or use a folder selection dialog
  - Return selected folder with fileId as primary
  - Show folder name and path
  - Multi-select or single-select (spec says single root folder per widget)

- [ ] 7.3 Integrate into `WidgetAddEditModal.vue`:
  - When user selects `mydash_files` widget from picker
  - Display `FilesWidgetConfig.vue` instead of default generic config panel
  - Pass selected widget metadata to config component

- [ ] 7.4 Create Playwright test:
  - `testFilesWidgetConfigUI` — open config modal, select folder, choose view mode, set filters, save

## 8. Upload endpoint

- [ ] 8.1 Add endpoint method to `lib/Controller/WidgetController.php`:
  - `public function uploadFiles(int $placementId, string $currentPath = '/'): DataResponse`
  - Validate placement exists and user can view dashboard
  - Resolve folder via placement config
  - Check `allowUpload` in config AND user has write permission (403 if not)
  - Iterate `$_FILES['files']` (or use `IRequest::getUploadedFile()` if available)
  - For each file, save to folder via `IRootFolder::newFile()` or WebDAV
  - Return HTTP 200 with list of uploaded file metadata, or HTTP 400 with errors per file
  - Decorate with `#[NoCSRFRequired]` (for CORS/API use)

- [ ] 8.2 Register route in `appinfo/routes.php`:
  - `POST /api/widgets/files/{placementId}/upload` → `WidgetController::uploadFiles()`

- [ ] 8.3 Create PHPUnit tests:
  - `testUploadFilesSuccess` — upload 2 files, verify both appear in folder
  - `testUploadFilesAccessDenied` — user lacks write permission, return 403
  - `testUploadFilesConflict` — file exists, renamed to (1), verify new name in response
  - `testUploadFilesSpaceExceeded` — user quota exceeded, return 507 or 400

## 9. Delete endpoint

- [ ] 9.1 Add endpoint method to `lib/Controller/WidgetController.php`:
  - `public function deleteFile(int $placementId, int $fileId): DataResponse`
  - Validate placement exists and user can view dashboard
  - Resolve file via fileId
  - Check user has delete permission on file (403 if not)
  - Call `FileInfo::delete()` or move to trash
  - Return HTTP 200 on success, or HTTP 403/404 on failure
  - Decorate with `#[NoCSRFRequired]`

- [ ] 9.2 Register route in `appinfo/routes.php`:
  - `DELETE /api/widgets/files/{placementId}/files/{fileId}` → `WidgetController::deleteFile()`

- [ ] 9.3 Create PHPUnit tests:
  - `testDeleteFileSuccess` — file deleted, returns 200
  - `testDeleteFileAccessDenied` — user lacks delete permission, returns 403
  - `testDeleteFileNotFound` — file deleted by another user, returns 404

## 10. Internationalization (i18n)

- [ ] 10.1 Add Dutch (nl) and English (en) translation keys:
  - `app.mydash.files_widget_title` — "Bestanden" / "Files"
  - `app.mydash.files_empty_state` — "Deze map is leeg." / "This folder is empty."
  - `app.mydash.files_no_access` — "Je hebt geen toegang tot deze map." / "You don't have access to this folder."
  - `app.mydash.files_not_found` — "Map bestaat niet meer." / "Folder no longer exists."
  - `app.mydash.files_loading` — "Map laden…" / "Loading folder…"
  - `app.mydash.files_error` — "Fout bij laden van mapinhoud." / "Failed to load folder contents."
  - `app.mydash.files_retry` — "Opnieuw proberen" / "Retry"
  - `app.mydash.files_upload_button` — "Bestand uploaden" / "Upload File"
  - `app.mydash.files_delete_button` — "Verwijderen" / "Delete"
  - `app.mydash.files_delete_confirm` — "Weet je zeker dat je {name} wilt verwijderen?" / "Are you sure you want to delete {name}?"
  - `app.mydash.files_search_placeholder` — "Zoeken in deze map…" / "Search this folder…"

- [ ] 10.2 Add translation files:
  - `l10n/nl.json` — Dutch translations
  - `l10n/en.json` — English translations (fallback)

## 11. Quality gates and testing

- [ ] 11.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan):
  - Fix any pre-existing issues in touched files
  - New PHP code must pass all checks

- [ ] 11.2 Run ESLint on all Vue/JS files:
  - `npm run lint` on `src/components/widgets/`
  - Fix any warnings or errors

- [ ] 11.3 Run Stylelint on component stylesheets

- [ ] 11.4 Confirm all hydra gates pass locally before opening PR

- [ ] 11.5 Add SPDX-License-Identifier and SPDX-FileCopyrightText headers to every new PHP file (inside docblock)

- [ ] 11.6 PHPUnit test coverage:
  - Aim for 80%+ line coverage on `FilesWidgetService`
  - All public methods tested
  - Edge cases (empty folders, null fields, permission denials) covered

- [ ] 11.7 Playwright E2E test coverage:
  - Files widget renders in dashboard
  - All three view modes (list, grid, tree) render correctly
  - Folder navigation works
  - Breadcrumb navigation works
  - File click deep-links to Files app
  - Upload and delete (if enabled) work
  - Empty states and error states display correctly
  - Search filters items

- [ ] 11.8 Manual testing on local Nextcloud instance:
  - Create a dashboard with files widget
  - Configure widget with various folder paths and filters
  - Verify all view modes work
  - Test upload and delete (if enabled)
  - Test permission denial scenarios (no access, no write, no delete)
  - Test folder deletion and share revocation resilience

## 12. Documentation and changelog

- [ ] 12.1 Update `CHANGELOG.md` with:
  - New feature: "Add files widget for inline Nextcloud folder browsing"
  - List view modes, MIME filtering, upload/delete features

- [ ] 12.2 Update `README.md` (if applicable) with widget description

- [ ] 12.3 Add code comments to `FilesWidgetService` explaining permission checks, pagination strategy, and resilience logic
