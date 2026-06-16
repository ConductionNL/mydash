# Image Widget Media Picker

## Why

The existing image-widget capability (REQ-IMG-001..005) supports two image sources: direct URL paste and direct file upload to the app's resource storage. Neither source leverages the user's existing Nextcloud Files, which creates friction: images the user already has stored in Files must be either downloaded, re-uploaded, or their URL pasted manually. This capability adds a third source path via Nextcloud's native file picker, allowing users to reference images already stored in their Nextcloud instance without re-uploading or manual URL entry.

## What Changes

- Extend the image-widget to track a `sourceType ENUM('url','upload','files')` field on the placement's widget config, stored alongside existing image-widget settings.
- When `sourceType = 'files'`, the widget edit modal surfaces a "Pick from Files" button that opens the Nextcloud file picker (`@nextcloud/dialogs` or `OCA.Files.FilePicker`) restricted to image MIME types.
- Selected files are referenced by their Nextcloud file ID and path (stored as `fileId BIGINT` and `filePath VARCHAR(2048)` on the placement).
- At render time, the widget generates a preview URL using `IURLGenerator::linkToRoute('files_sharing.PublicPreview.getPreview')` or the `core.preview.getPreview` route (depending on viewer-vs-owner access scenario).
- Viewer-side access checks: if the viewer cannot read the referenced file, the widget shows the same broken-image fallback as URL-mode (REQ-IMG-004) — never throws 500.
- File deletion: when the source NC file is deleted, the widget keeps its `fileId` reference and shows "image unavailable" rather than silently emptying.
- Existing URL-mode widgets (REQ-IMG-001..005) continue unchanged.
- The widget edit form gains a third radio option: "Pick from Files" with the file picker button and resolved file path display.
- SVG sources (`image/svg+xml`) must be sanitised via the separate `svg-sanitisation` capability before render (referenced but not implemented here).

## Capabilities

### New Capability

- `image-widget-media-picker`: adds REQ-IMP-001..008 to extend the existing `image-widget` with a third media source (Nextcloud Files picker).

### Modified Capability

- `image-widget`: implicitly modified by `image-widget-media-picker` (the picker feature extends the form and widget config). The parent spec is not changed in this capability; changes to `image-widget/spec.md` will follow as a separate delta-style update once both capabilities are approved.

## Impact

**Affected code:**

- `lib/Db/WidgetPlacement.php` — add optional `fileId` and `filePath` fields (nullable BIGINT and VARCHAR) for file-mode placements.
- `lib/Service/ImageWidgetService.php` — new `resolveFilePreviewUrl()` method using `IURLGenerator` to generate preview URLs respecting viewer access.
- `src/components/widgets/ImageWidget.vue` — extend form to include `sourceType` radio group and conditional file-picker button; add `@nextcloud/dialogs` or vanilla `OCA.Files.FilePicker` integration.
- `src/components/widgets/ImageWidgetForm.vue` — handle `sourceType = 'files'` branch with file path display and picker invocation.
- Migration to schema: add `fileId` (BIGINT NULL) and `filePath` (VARCHAR(2048) NULL) columns to `oc_mydash_widget_placements`.

**Affected APIs:**

- Existing `/api/resources` (URL/upload) endpoints unchanged.
- New internal service method: `ImageWidgetService::resolveFilePreviewUrl(int $fileId, string $filePath): string`.
- No new public API endpoints (all logic stays within the widget and forms).

**Dependencies:**

- `@nextcloud/dialogs` (file picker dialogue — already used in Nextcloud UI layer) OR `OCP\Files\IMimeTypeDetector` and `OCA.Files.FilePicker` (existing NC JS).
- `IURLGenerator` (already available in Nextcloud).
- No new composer dependencies.

**Migration:**

- Non-breaking: adds two nullable columns to `oc_mydash_widget_placements`. Existing image-widget placements get `fileId = NULL` and `filePath = NULL`; they continue to work via the `url` field and `sourceType` defaults to `'url'`.

## Handover Notes

- File picker MIME type filtering: restrict to `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/svg+xml`.
- SVG handling: SVG sources pass through to the separate `svg-sanitisation` capability for XSS protection before final render (the change proposal references but does not implement sanitisation).
- Broken image fallback: reuse the existing empty-URL placeholder + message from REQ-IMG-004.
- UI responsivity: the file picker integration should gracefully handle network delays (file picker may be async on slow connections).
