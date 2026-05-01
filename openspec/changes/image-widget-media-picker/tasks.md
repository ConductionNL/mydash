# Tasks — image-widget-media-picker

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddFilePickerColumns.php` adding `fileId BIGINT NULL` and `filePath VARCHAR(2048) NULL` to `oc_mydash_widget_placements`
- [ ] 1.2 Migration is reversible (drop columns in `postSchemaChange` rollback path)
- [ ] 1.3 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Add `fileId` field to `WidgetPlacement` entity (nullable BIGINT, getter/setter with Entity `__call` pattern — no named args)
- [ ] 2.2 Add `filePath` field to `WidgetPlacement` entity (nullable VARCHAR(2048), getter/setter)
- [ ] 2.3 Update `WidgetPlacement::jsonSerialize()` to include both fields (null in output when not a file-mode placement)

## 3. Service layer

- [ ] 3.1 In `ImageWidgetService`, add new method `resolveFilePreviewUrl(int $fileId, string $filePath, ?string $userId = null): ?string` that:
  - Accepts an optional `$userId` (defaults to current auth user)
  - Uses `IURLGenerator::linkToRoute()` to generate a preview URL
  - For shared files, prefers `files_sharing.PublicPreview.getPreview` if viewer lacks direct read access
  - Falls back to `core.preview.getPreview` for files the viewer owns or has access to
  - Returns `null` if the file cannot be resolved or is inaccessible (widget will show broken-image fallback)
- [ ] 3.2 In `ImageWidgetService::renderImage()` (or equivalent render method), add logic to:
  - Check `placement.sourceType` — if `'files'`, call `resolveFilePreviewUrl()` instead of using `placement.url`
  - On `null` return, fall back to the broken-image placeholder as per REQ-IMG-004
- [ ] 3.3 Add PHPUnit test covering: file found and user has access (returns valid URL), file not found (returns null), user lacks access (returns null)

## 4. Frontend widget form

- [ ] 4.1 In `src/components/widgets/ImageWidgetForm.vue` (or the AddWidgetModal's image sub-form):
  - Add a radio group for `sourceType` with three options: "URL/Link", "Upload", "Pick from Files"
  - Conditional rendering: only show the corresponding input group for the selected source type
- [ ] 4.2 For `sourceType = 'url'`: keep existing URL and link inputs as-is (REQ-IMG-005 unchanged)
- [ ] 4.3 For `sourceType = 'upload'`: keep existing file `<input type="file">` and resource-uploads POST pipeline as-is (REQ-IMG-005 unchanged)
- [ ] 4.4 For `sourceType = 'files'`: add:
  - A "Pick from Files" button that opens the Nextcloud file picker (`@nextcloud/dialogs` or `OCA.Files.FilePicker`)
  - File picker restricted to MIME types: `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/svg+xml`
  - Display the selected file's path (e.g. `/Photos/vacation.jpg`) below the button, read-only
  - Store selected file's `fileId` and `filePath` in the placement config on save
- [ ] 4.5 File picker error handling: if picker fails or is cancelled, keep the current selection; show inline error message only on picker failure (not on user cancel)
- [ ] 4.6 Validation: form still requires either `url` (for url/upload modes) or `filePath` (for files mode) — return validation error if both are empty after selection

## 5. Preview URL handling

- [ ] 5.1 At render time, the widget's URL resolution logic (in `ImageWidget.vue` or renderer) checks:
  - If `sourceType = 'files'` and `fileId` is set: call the backend `resolveFilePreviewUrl()` API (or backend resolves on load and sends `previewUrl` in the placement payload)
  - Otherwise, use `url` field as-is
- [ ] 5.2 For backend-resolved approach: add `GET /api/widgets/{placementId}/preview-url` endpoint that returns `{"url": "..."}` (unauthenticated or uses viewer's permissions to check file access)
- [ ] 5.3 If viewer cannot access the file, the endpoint returns HTTP 404 or `{"url": null}`; frontend falls back to broken-image placeholder per REQ-IMG-004

## 6. SVG sanitisation handoff

- [ ] 6.1 When `sourceType = 'files'` and selected MIME type is `image/svg+xml`, the widget MUST NOT render the SVG directly
- [ ] 6.2 Instead, pass the `fileId` and `filePath` to the separate `svg-sanitisation` capability (implementation deferred to that change)
- [ ] 6.3 Note in the spec and tasks that SVG-from-files MUST be sanitised before render to prevent XSS

## 7. Frontend store

- [ ] 7.1 Ensure `src/stores/dashboards.js` (or widget placement store) includes `fileId` and `filePath` in the placement object serialisation/deserialisation
- [ ] 7.2 No additional getters/actions needed — the existing placement update flow handles file-mode placements transparently

## 8. PHPUnit tests

- [ ] 8.1 `ImageWidgetServiceTest::testResolveFilePreviewUrl` — file exists and user has access (returns valid preview URL)
- [ ] 8.2 `ImageWidgetServiceTest::testResolveFilePreviewUrlFileNotFound` — file ID is invalid (returns null)
- [ ] 8.3 `ImageWidgetServiceTest::testResolveFilePreviewUrlAccessDenied` — user lacks read permission (returns null)
- [ ] 8.4 `WidgetPlacementMapperTest::roundTripFileFields` — `fileId` and `filePath` persist and are retrieved correctly
- [ ] 8.5 `ImageWidgetRendererTest::testRenderWithFileSource` — when `sourceType = 'files'`, renderer uses file preview URL, not `url` field

## 9. Playwright E2E tests

- [ ] 9.1 User opens image widget edit modal, selects "Pick from Files" radio
- [ ] 9.2 "Pick from Files" button opens the file picker and filters to image MIME types
- [ ] 9.3 User selects a file from their Nextcloud Files; modal displays the file path
- [ ] 9.4 Form saves; widget renders the selected file as a preview
- [ ] 9.5 File is later deleted in Nextcloud; widget shows broken-image fallback (no 500 error)
- [ ] 9.6 URL-mode widget (REQ-IMG-001..005) still works unchanged
- [ ] 9.7 SVG file selected; widget does NOT render raw SVG (awaits svg-sanitisation capability)

## 10. Quality gates

- [ ] 10.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 10.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 10.3 i18n keys for all new UI strings ("Pick from Files", "Select image file", etc.) in both `nl` and `en` per the i18n requirement
- [ ] 10.4 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 10.5 Run all 10 `hydra-gates` locally before opening PR
