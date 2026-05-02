---
status: implemented
---

# Resource Uploads Specification

## Purpose

The `resource-uploads` capability owns a small mini file API for binary assets that MyDash widgets reference directly: dashboard icons, image-widget images, link-button icons, etc. Resources are stored in MyDash's app-data folder (NOT the user's Files), addressed by a stable URL, and uploaded admin-only via a base64-data-URL JSON request. Serving and SVG sanitisation are specified in sibling capabilities (`resource-serving`, `svg-sanitisation`).

## Data Model

Resources are flat files in app data — there is no companion DB row. Layout:

```
<appdata_root>/resources/
├─ resource_<uniqid>.png
├─ resource_<uniqid>.svg
└─ ...
```

Each upload produces:
- A new file with `resource_<uniqid>.<ext>` name (where `<ext>` is the normalised extension)
- A stable public URL `/apps/mydash/resource/<filename>` (served by the `resource-serving` capability)

## Requirements

### Requirement: Admin-only base64 upload endpoint (REQ-RES-001)

The system MUST expose `POST /api/resources` accepting a raw JSON body of the shape `{base64: 'data:image/<type>;base64,...'}`. The endpoint MUST be admin-only — non-admin requests MUST receive HTTP 403 with `{status: 'error', error: 'forbidden'}`. The endpoint MUST NOT accept multipart form data — only a base64-encoded data URL.

#### Scenario: Admin uploads a PNG

- **GIVEN** an authenticated admin user
- **WHEN** they POST `{"base64": "data:image/png;base64,iVBORw0KGgo..."}` (valid 200×200 PNG)
- **THEN** the system MUST return HTTP 200 with `{status: 'success', url: '/apps/mydash/resource/resource_<uniqid>.png', name: 'resource_<uniqid>.png', size: <bytes>}`
- **AND** a file MUST exist at the corresponding app-data path

#### Scenario: Non-admin rejected

- **GIVEN** an authenticated non-admin user
- **WHEN** they POST any body to `/api/resources`
- **THEN** the system MUST return HTTP 403 with `{status: 'error', error: 'forbidden'}`
- **AND** no file MUST be written

#### Scenario: Multipart upload rejected

- **WHEN** a request arrives with `Content-Type: multipart/form-data` instead of JSON
- **THEN** the system MUST return HTTP 415 with `{status: 'error', error: 'unsupported_media_type', message: 'Use JSON body with base64 field'}`

### Requirement: Allowed declared types (REQ-RES-002)

The data-URL prefix MUST declare one of the allowed image types. Allowed types: `jpeg`, `jpg`, `png`, `gif`, `svg`, `webp` (case-insensitive). Anything else MUST return HTTP 400 with `{status: 'error', error: 'invalid_image_format'}`. A missing or unparseable data-URL prefix MUST return HTTP 400 with `{status: 'error', error: 'invalid_data_url'}`.

The persisted file extension MUST be the normalised lowercase form (jpeg → jpeg, jpg → jpg, svg+xml → svg).

#### Scenario: Disallowed type rejected

- **WHEN** the client POSTs `{"base64": "data:image/bmp;base64,..."}`
- **THEN** the system MUST return HTTP 400 with `error: 'invalid_image_format'`
- **AND** no file MUST be written

#### Scenario: Missing data-URL prefix rejected

- **WHEN** the client POSTs `{"base64": "iVBORw0KGgo..."}` (raw base64, no `data:` prefix)
- **THEN** the system MUST return HTTP 400 with `error: 'invalid_data_url'`

#### Scenario: Mixed-case declared type accepted

- **WHEN** the client POSTs `{"base64": "data:image/PNG;base64,..."}` (uppercase declared type)
- **THEN** the system MUST treat it as `png`
- **AND** the persisted extension MUST be lowercase `.png`

### Requirement: Size and integrity validation (REQ-RES-003)

The system MUST decode the base64 payload and enforce a 5 MB cap on the decoded bytes. For raster types (jpeg/png/gif/webp), the system MUST run `getimagesizefromstring` on the decoded bytes and reject if it returns false (corrupted) OR if the detected MIME does not match the declared type. SVG validation is delegated to the `svg-sanitisation` capability's sanitiser. The size cap MUST be enforced before invoking `getimagesizefromstring` so the server cannot be tricked into loading an oversize blob into the image library.

#### Scenario: Oversize payload rejected

- **WHEN** the client POSTs a base64 payload that decodes to 6 MB
- **THEN** the system MUST return HTTP 400 with `{status: 'error', error: 'file_too_large', message: 'Maximum size is 5MB'}`
- **AND** no file MUST be written
- **AND** `getimagesizefromstring` MUST NOT be called

#### Scenario: MIME mismatch rejected

- **WHEN** the client POSTs `{"base64": "data:image/png;base64,<actually a JPEG>"}` (declared png, body is jpeg)
- **THEN** `getimagesizefromstring` reports `image/jpeg`
- **THEN** the system MUST return HTTP 400 with `{status: 'error', error: 'mime_mismatch'}`

#### Scenario: Corrupt raster bytes rejected

- **WHEN** the client POSTs `{"base64": "data:image/png;base64,<garbage>"}`
- **THEN** `getimagesizefromstring` returns false
- **THEN** the system MUST return HTTP 400 with `{status: 'error', error: 'corrupt_image'}`

### Requirement: Storage via IAppData (REQ-RES-004)

Validated bytes MUST be stored via `IAppData::getFolder('resources')` (auto-create on first use). Filenames MUST be `resource_<uniqid>.<ext>` (using PHP `uniqid('resource_', true)` for high-entropy IDs and the normalised extension). The system MUST NOT write to the user's Files folder.

#### Scenario: Storage location

- **GIVEN** any successful upload
- **THEN** the file MUST be created under the app's app-data root inside a `resources/` subfolder
- **AND** the file MUST NOT appear under the calling admin's user folder
- **AND** the filename MUST match `resource_[a-f0-9.]+\.(jpeg|jpg|png|gif|svg|webp)`

#### Scenario: Folder auto-create

- **GIVEN** the `resources/` folder does not yet exist
- **WHEN** the first upload arrives
- **THEN** the folder MUST be created automatically
- **AND** the upload MUST succeed normally

### Requirement: Standardised response envelope (REQ-RES-005)

Every successful response MUST conform to `{status: 'success', url: <string>, name: <string>, size: <int>}` (additional fields are allowed). Every error response MUST conform to `{status: 'error', error: <stable_string>, message: <translated string>}`. The `error` field is a stable enum suitable for client-side branching; `message` is for display. Raw exception messages MUST NEVER be returned to the client.

#### Scenario: Error envelope shape

- **WHEN** any error path triggers (admin check, validation, storage failure)
- **THEN** the response body MUST contain `status`, `error`, `message`
- **AND** the body MUST NOT contain raw stack traces or `$e->getMessage()` strings unsafe for display

#### Scenario: Stable error enum

- **GIVEN** the documented error codes are `forbidden`, `unsupported_media_type`, `invalid_data_url`, `invalid_image_format`, `file_too_large`, `mime_mismatch`, `corrupt_image`, `invalid_svg`, `storage_failure`
- **WHEN** any rejection scenario above triggers
- **THEN** the `error` field MUST equal exactly one of those strings (no synonyms, no extras)
