---
status: draft
---

# Image Widget Media Picker — New Capability

## ADDED Requirements

### Requirement: REQ-IMP-001 SourceType field for image-widget config

The image widget placement MUST store a `sourceType` field (ENUM: `'url'`, `'upload'`, `'files'`) in the placement's widget config. This field MUST default to `'url'` for backward compatibility with existing image widgets. The field MUST persist across widget updates and MUST be serialized in the placement API response.

#### Scenario: New image widget defaults to URL source

- GIVEN a user creates a new image widget placement with no `sourceType` explicitly set
- WHEN the placement is saved
- THEN the placement's config MUST have `sourceType: 'url'`

#### Scenario: SourceType persists across updates

- GIVEN a placement with `sourceType: 'files'` referencing a file
- WHEN the user updates the widget's title via PUT /api/widgets/{id}
- THEN the `sourceType` field MUST remain `'files'` after the update

#### Scenario: SourceType included in API response

- GIVEN a placement with `sourceType: 'upload'`
- WHEN the user fetches the placement via GET /api/widgets/{id}
- THEN the JSON response MUST include `"sourceType": "upload"`

#### Scenario: Invalid sourceType rejected on input

- GIVEN a user attempts to set `sourceType: 'clipboard'` (not a valid enum value)
- WHEN the placement is saved
- THEN the system MUST return HTTP 400 with a validation error
- AND the `sourceType` value MUST NOT change

### Requirement: REQ-IMP-002 File picker invocation

When the image widget edit form has `sourceType = 'files'`, the form MUST display a "Pick from Files" button that opens Nextcloud's native file picker. The file picker MUST be restricted to image MIME types: `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/svg+xml`. The picker MUST support only single-file selection, not multi-select.

#### Scenario: File picker button visible for files source

- GIVEN the user selects `sourceType: 'files'` in the image widget form
- WHEN the form renders
- THEN a "Pick from Files" button MUST be visible
- AND the URL input field MUST be hidden (only the file picker UI is shown for this source type)

#### Scenario: File picker opens on button click

- GIVEN the user clicks the "Pick from Files" button
- WHEN the file picker opens
- THEN it MUST display the user's Nextcloud Files directory
- AND MIME type filtering MUST be applied (only image files visible)

#### Scenario: File picker filters to image MIME types

- GIVEN the file picker is open
- WHEN the user navigates to a folder containing 10 files (3 images, 7 documents)
- THEN only the 3 image files MUST be selectable
- AND the 7 documents MUST appear disabled or hidden

#### Scenario: Single-file selection only

- GIVEN the file picker is open
- WHEN the user attempts to select multiple files (shift-click, Ctrl+click)
- THEN only the last file clicked MUST be selected
- AND the picker MUST NOT allow a multi-selection mode

### Requirement: REQ-IMP-003 File reference storage

When a file is selected via the file picker, the placement MUST store two fields: `fileId` (BIGINT) and `filePath` (VARCHAR, max 2048 bytes). Both MUST be persisted on the placement record in the database. The `filePath` MUST be the display path shown to the user (e.g. `/Photos/vacation.jpg`), suitable for human reading in edit forms and error messages.

#### Scenario: Selected file stores both ID and path

- GIVEN the user selects `/Photos/sunset.png` from the file picker
- WHEN the widget is saved
- THEN the placement MUST store:
  - `fileId: 12345` (the Nextcloud file ID)
  - `filePath: '/Photos/sunset.png'` (the human-readable path)

#### Scenario: File path survives widget title and style updates

- GIVEN a placement with `fileId: 12345` and `filePath: '/Photos/sunset.png'`
- WHEN the user updates the widget's custom title via PUT /api/widgets/{id}
- THEN both `fileId` and `filePath` MUST remain unchanged

#### Scenario: FilePath max length enforced

- GIVEN a user attempts to save a file with an extremely long path (>2048 bytes)
- WHEN validation occurs
- THEN the system MUST return HTTP 400 with a validation error
- AND the placement MUST NOT be saved

### Requirement: REQ-IMP-004 Preview URL generation for file-mode widgets

At render time, the widget MUST generate a preview image URL for the referenced Nextcloud file using the appropriate Nextcloud preview service. The system MUST use `IURLGenerator::linkToRoute()` to generate a URL that respects the viewer's access permissions to the file. The preview URL MUST support both scenarios: files owned by the viewer and files shared with the viewer.

#### Scenario: Viewer can access file, generates preview URL

- GIVEN a placement with `sourceType: 'files'`, `fileId: 12345`, and the viewer owns the file
- WHEN the widget renders
- THEN the system MUST call `IURLGenerator::linkToRoute('core.preview.getPreview', ['fileId' => 12345, ...])`
- AND the generated URL MUST point to a valid preview image

#### Scenario: Viewer cannot access file, returns null

- GIVEN a placement with `fileId: 999999` (a file the viewer has no permission to read)
- WHEN the widget renders and attempts to generate the preview URL
- THEN the URL generation service MUST return `null`
- AND the widget MUST fall back to the broken-image fallback (REQ-IMP-005)

#### Scenario: Shared file preview URL uses public share route

- GIVEN a file is shared with the viewer via a share link
- AND the placement references the file ID
- WHEN the widget renders
- THEN the preview URL MUST be generated using the appropriate shared-file preview route
- AND the image MUST display correctly without requiring additional authentication

#### Scenario: Preview URL handles file not found gracefully

- GIVEN a placement references a file ID that no longer exists in Nextcloud
- WHEN the widget renders and attempts to generate the preview URL
- THEN the URL generation MUST not throw an exception
- AND the widget MUST proceed to the broken-image fallback

### Requirement: REQ-IMP-005 Broken-image fallback for file-mode widgets

When a file-mode image widget's referenced file is inaccessible (deleted, permissions revoked, or network failure), the widget MUST display the same broken-image placeholder as REQ-IMG-004 (from the base image-widget capability): a 48 px camera icon plus the translated message `t('Image failed to load')`, centered. The widget MUST NOT crash, unmount, or throw a 500 HTTP error. The broken-image fallback MUST be identical regardless of source type (`url`, `upload`, or `files`).

#### Scenario: Deleted file shows broken-image fallback

- GIVEN a placement with `sourceType: 'files'` referencing a file that was deleted from Nextcloud
- WHEN the widget renders
- THEN the `<img>` element's `error` event fires (broken image from preview URL 404)
- AND the widget MUST swap to the empty-URL placeholder with text `'Image failed to load'`

#### Scenario: Permission revoked shows fallback without crash

- GIVEN a file was previously accessible, then the viewer's permissions were revoked
- WHEN the widget renders on the next page load
- THEN the preview URL will fail to load
- AND the `error` event handler MUST catch this gracefully
- AND the grid and surrounding widgets MUST remain fully functional

#### Scenario: Broken-image state persists until file is restored

- GIVEN a widget is in broken-image fallback state
- WHEN the file is restored to Nextcloud with the same ID
- THEN the user must refresh the page to re-attempt loading the preview
- NOTE: Re-validation on a schedule is out of scope for this change

### Requirement: REQ-IMP-006 File deletion handling

When the referenced Nextcloud file is deleted, the widget MUST retain its `fileId` and `filePath` reference on the placement (the placement is NOT auto-deleted). This allows the widget to show a clear "image unavailable" state (the broken-image fallback) rather than silently clearing the placement or showing a generic empty state. The broken-image fallback (REQ-IMP-005) provides the appropriate user feedback.

#### Scenario: Deleted file retains placement metadata

- GIVEN a placement with `sourceType: 'files'`, `fileId: 12345`, and `filePath: '/Photos/sunset.png'`
- WHEN the file with ID 12345 is deleted from Nextcloud
- THEN the placement record MUST still exist with unchanged `fileId` and `filePath`
- AND the widget MUST render the broken-image fallback on next page load

#### Scenario: Admin can clear orphaned reference manually

- GIVEN a placement with a deleted file's orphaned reference
- WHEN the widget editor form opens
- THEN the user CAN click "Pick from Files" to select a new file
- OR the user CAN switch `sourceType` back to `'url'` to set a new URL
- AND the orphaned fields (`fileId`, `filePath`) MUST be cleared on save

### Requirement: REQ-IMP-007 Widget form source type UI

The image widget edit form MUST display a "Source Type" radio group with three mutually exclusive options: "URL/Link", "Upload", and "Pick from Files". Only the input fields relevant to the selected source type MUST be visible. Switching between source types MUST NOT erase previously entered values for other source types (values MUST be retained in component state, allowing the user to switch back without re-entering).

#### Scenario: Radio group shows all three source options

- GIVEN the image widget edit form opens
- WHEN the form renders
- THEN a radio group MUST be visible with labels: "URL/Link", "Upload", "Pick from Files"
- AND one option MUST be pre-selected based on the current placement's `sourceType`

#### Scenario: URL form fields shown only for URL source

- GIVEN the user selects the "URL/Link" radio option
- WHEN the form updates
- THEN the URL input field and link input field MUST be visible
- AND the file upload input and file picker button MUST be hidden

#### Scenario: Upload form fields shown only for upload source

- GIVEN the user selects the "Upload" radio option
- WHEN the form updates
- THEN the file `<input type="file">` MUST be visible
- AND the URL/link inputs and file picker button MUST be hidden

#### Scenario: File picker shown only for files source

- GIVEN the user selects the "Pick from Files" radio option
- WHEN the form updates
- THEN the "Pick from Files" button and file path display MUST be visible
- AND the URL input and file upload input MUST be hidden

#### Scenario: Switching sources retains previous values

- GIVEN the user enters a URL in the URL field, then switches to "Upload"
- WHEN the user switches back to "URL/Link"
- THEN the previously entered URL MUST still be in the input field
- AND no data loss MUST occur

### Requirement: REQ-IMP-008 SVG source handoff to sanitisation

When a file-mode image widget references an SVG file (`image/svg+xml`), the widget MUST NOT render the SVG as a raw `<img>` element before sanitisation. Instead, the SVG file reference MUST be passed to the separate `svg-sanitisation` capability (a sibling change) for XSS protection. This capability specifies the handoff point; the actual SVG sanitisation is implemented in the `svg-sanitisation` change.

#### Scenario: SVG file triggers sanitisation handoff

- GIVEN a placement with `sourceType: 'files'` and the selected file has MIME type `image/svg+xml`
- WHEN the widget edit form saves the placement
- THEN the `fileId` and `filePath` MUST be stored as normal
- AND the widget rendering layer MUST route the SVG reference to the `svg-sanitisation` capability
- AND the raw file content MUST NOT be served directly as an `<img>` src before sanitisation

#### Scenario: Non-SVG images bypass sanitisation requirement

- GIVEN a placement with a PNG file (`image/png`)
- WHEN the widget renders
- THEN the image MUST be displayed directly via the preview URL
- AND the `svg-sanitisation` capability is NOT invoked

#### Scenario: SVG from URL source also requires sanitisation

- GIVEN a placement with `sourceType: 'url'` pointing to an external SVG file
- WHEN the widget renders
- THEN the URL MUST be routed through the `svg-sanitisation` capability
- NOTE: This requirement applies equally to both `url` and `files` sources; it documents the hand-off point for SVG handling

## Non-Functional Requirements

- **Backward Compatibility**: Existing image widgets with `sourceType: 'url'` (default) MUST continue to render unchanged. No breaking changes to REQ-IMG-001..005.
- **Performance**: File picker invocation (opening) MUST complete within 1 second. Preview URL generation MUST not block rendering; if generation fails, fallback to broken-image state within 500 ms.
- **Access Control**: Preview URL generation MUST respect Nextcloud's file ACLs; viewers without permission MUST receive `null` URL, not a 403 error. The widget gracefully falls back (REQ-IMP-005).
- **Data Integrity**: `fileId` and `filePath` MUST be stored transactionally with the placement; an incomplete save MUST not leave orphaned references.
- **Localization**: All new form labels ("Pick from Files", "Select image file", etc.) and error messages MUST be available in English and Dutch (nl/en) per the i18n requirement.
- **Accessibility**: The "Pick from Files" button MUST be keyboard-accessible and have an appropriate `aria-label`. The file picker dialog MUST follow Nextcloud's accessibility guidelines.
