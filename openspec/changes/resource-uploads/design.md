# Design: resource-uploads

## Context

MyDash widgets (image-widget, link-button-widget, custom-icon-uploader, dashboard icon picker) reference binary assets (branding icons, custom images, dashboard backgrounds) that:

1. Must be admin-controlled (not user-deletable, persisted across user activity)
2. Must not live in the user's Files folder (per-user scope, mutable by owner, not suitable for shared branding)
3. Must be addressed by a stable, cacheable URL with a version-busting strategy
4. Must be upload-restricted (admin-only, size-capped, format-validated)

Today MyDash ships widget plumbing but no upload surface — admins must manually place files via WebDAV or SSH. This change adds the missing upload and list infrastructure.

## Reuse Analysis

The resource-uploads change uses Nextcloud's provided services:

| Service | Purpose | Source |
|---|---|---|
| `IAppData` | Store resources in app-data folder, auto-create `resources/` subdirectory | Nextcloud core |
| `IGroupManager::isAdmin()` | Enforce admin-only access on upload endpoint | Nextcloud core |
| `getimagesizefromstring()` | Validate raster image integrity and detect MIME type | PHP built-in |
| `uniqid('resource_', true)` | Generate high-entropy filenames for cache busting | PHP built-in |
| Standard HTTP response shapes | Consistent error/success envelopes with stable error codes | Existing mydash pattern (ADR-031) |

No external dependencies introduced. No OpenRegister schemas required (resources are flat files, not objects). No service classes for data aggregation or lifecycle management — the upload path is a stateless validation + storage pipeline.

Consumed by: `image-widget` form, `link-button-widget` icon picker, custom-icon-upload pattern, dashboard icon picker. All consumers use the same `resourceService.js` wrapper.

## No Seed Data

The resource-uploads change introduces no OpenRegister schemas (resources are flat files). Per ADR-001, seed data is not required.

## Implementation Model

Per ADR-031, the change lands as a `kind: config` spec — the surface is defined declaratively:

- **Endpoint contracts** — `POST /api/resources` and `GET /api/resources` with documented request/response shapes
- **Validation rules** — allowed MIME types, size cap, integrity checks
- **Storage convention** — `resource_<uniqid>.<ext>` filenames in `<appdata>/resources/`
- **Error enum** — stable codes for client-side branching

Sibling changes handle the read side (`resource-serving`: REQ-RES-006 and REQ-RES-008) and SVG hardening (`svg-sanitisation`: REQ-RES-009 to REQ-RES-013).

## Storage Design

- **Location**: `<appdata_root>/resources/` (auto-created on first upload via `IAppData::getFolder()`)
- **Filename pattern**: `resource_<uniqid>.<ext>` where `<ext>` is the normalized lowercase form
  - `image/jpeg` → `jpeg`
  - `image/jpg` → `jpg`
  - `image/svg` or `image/svg+xml` → `svg`
  - Case-insensitive on input, always lowercase on disk
- **No database row**: Resources are identified solely by filename; no companion DB record
- **URL scheme**: `/apps/mydash/resource/<filename>` (serves by REQ-RES-006 in resource-serving change)

## Validation Pipeline

1. **Admin check** — `IGroupManager::isAdmin()` (return HTTP 403 if not admin)
2. **Data-URL parse** — Extract declared MIME type and base64 payload
3. **Size check** — Decode base64 and enforce 5 MB cap (BEFORE image library to bound memory)
4. **Format check** — Verify declared type is in whitelist: `jpeg, jpg, png, gif, svg, webp`
5. **Integrity check** — For raster types, run `getimagesizefromstring()` to detect actual MIME and reject if corrupted or mismatched
6. **SVG sanitisation** — (Delegated to sibling `svg-sanitisation` change via `SvgSanitiser::sanitize()`)
7. **Persist** — Write to `IAppData` folder with high-entropy filename

## Error Envelope

All rejection paths return a standardized envelope:

```json
{
  "status": "error",
  "error": "<stable_code>",
  "message": "<translated_string>"
}
```

Stable error codes (per REQ-RES-005):
- `forbidden` — non-admin request
- `unsupported_media_type` — multipart instead of JSON
- `invalid_data_url` — missing or unparseable `data:` prefix
- `invalid_image_format` — declared type not in whitelist
- `file_too_large` — decoded bytes exceed 5 MB
- `mime_mismatch` — declared MIME ≠ detected MIME for raster
- `corrupt_image` — `getimagesizefromstring()` returned false
- `invalid_svg` — SVG sanitiser returned null (delegated to svg-sanitisation change)
- `storage_failure` — write to app-data failed

Raw exception messages MUST NEVER be returned to the client — every error path maps to one of these stable codes.

## Success Envelope

```json
{
  "status": "success",
  "url": "/apps/mydash/resource/resource_abc123.png",
  "name": "resource_abc123.png",
  "size": 12345
}
```

The `url` field is a relative path (not absolute) so clients can use it directly in `<img>` or `<link>` tags.

## API Contract

### Upload Endpoint

**Request:**
```http
POST /api/resources
Content-Type: application/json

{
  "base64": "data:image/png;base64,iVBORw0KGgo..."
}
```

**Response (Success):**
```http
HTTP 200 OK
Content-Type: application/json

{
  "status": "success",
  "url": "/apps/mydash/resource/resource_<uniqid>.png",
  "name": "resource_<uniqid>.png",
  "size": 12345
}
```

**Response (Error):**
```http
HTTP 400/403/415
Content-Type: application/json

{
  "status": "error",
  "error": "invalid_image_format",
  "message": "Allowed types: JPEG, PNG, GIF, SVG, WebP"
}
```

### List Endpoint

**Request:**
```http
GET /api/resources
```

**Response (Success):**
```http
HTTP 200 OK
Content-Type: application/json

{
  "status": "success",
  "resources": [
    {
      "name": "resource_abc123.png",
      "url": "/apps/mydash/resource/resource_abc123.png",
      "size": 12345,
      "modifiedAt": "2026-05-21T14:30:00Z"
    }
  ]
}
```

**Response (Empty):**
```http
HTTP 200 OK
Content-Type: application/json

{
  "status": "success",
  "resources": []
}
```

## Notes on v1 Scope

- **No per-resource ACL**: Anyone authenticated can list and read resources. Acceptable for branding/icon assets; future `resource-acl` change if sensitive uploads are added.
- **No garbage collection**: Orphaned resources (referenced by deleted widgets) accumulate. Tracked for future `resource-gc` change.
- **No image transforms**: Thumbnails or resizing are out of scope. Tracked for future enhancements.
- **Multipart not supported**: Keeps client-side simple and avoids `$_FILES` quirks. Base64 in JSON is sufficient for icons.
