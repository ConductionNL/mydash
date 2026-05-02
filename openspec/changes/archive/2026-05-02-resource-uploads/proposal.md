# Resource uploads

A new `resource-uploads` capability owns a small mini file API for binary assets (icons, widget images) that MyDash needs to host directly — separate from the user's Files. Admin-only upload via base64 data URL, raster MIME cross-check, 5 MB cap, app-data folder storage. Serving and SVG sanitisation are split into sibling changes (`resource-serving`, `svg-sanitisation`).

## Why

MyDash widgets (image, link-button, custom icons, dashboard icons) need to reference branding assets that the admin controls and that survive deletion of any user. The user's Files folder is the wrong home: per-user, mutable by the owner, deletable, and not addressable by a stable public URL. We need a tiny app-owned file API with a hardened upload path (admin-only, size-capped, MIME-verified) so widgets can render branded icons without inventing per-widget upload code.

## What Changes

- **NEW** `POST /api/resources` endpoint accepting `{base64: 'data:image/<type>;base64,...'}` (admin-only).
- **NEW** declared/detected MIME cross-check with allowed types `jpeg, jpg, png, gif, svg, webp`.
- **NEW** 5 MB hard cap on decoded bytes, enforced before invoking image library.
- **NEW** Storage via `IAppData::getFolder('resources')` with `resource_<uniqid>.<ext>` filenames.
- **NEW** Standardised success / error envelope (`{status, error, message}`) with a stable error enum.
- Foundation for sibling changes `resource-serving` (read side) and `svg-sanitisation` (SVG hardening).

## Capabilities

### New Capabilities
- `resource-uploads`: Admin-only mini file API for storing branding/icon assets in MyDash's app data, used by image widget, link-button widget, custom-icon-upload pattern, and dashboard icon picker.

### Modified Capabilities
- (none — sibling changes `resource-serving` and `svg-sanitisation` extend this capability via their own delta specs.)

## Impact

- New files: `lib/Controller/ResourceController.php`, `lib/Service/ResourceService.php`, `lib/Service/ImageMimeValidator.php`, plus typed exception classes under `lib/Exception/`.
- Routes: one new `POST /api/resources` entry in `appinfo/routes.php`.
- App data: a new `resources/` folder will be auto-created in MyDash's app-data root on first upload — no migration script needed.
- Frontend: a thin `src/services/resourceService.js` wrapper, consumed by image widget, link-button icon picker, custom-icon-upload pattern, dashboard icon picker.
- OpenAPI: documented endpoint + envelope.
- No external dependencies introduced (uses built-in `getimagesizefromstring`).

## Affected code units

- `lib/Controller/ResourceController.php` — `POST /api/resources` handler (admin-only)
- `lib/Service/ResourceService.php` — base64 decode + size + MIME validation + storage via `IAppData`
- `lib/Service/ImageMimeValidator.php` — declared-type ↔ detected-MIME cross-check
- `appinfo/info.xml` — add `resources` app-data folder declaration if needed
- New capability `resource-uploads`
- Consumed by: `image-widget` form, `link-button-widget` icon picker, `custom-icon-upload-pattern` icon picker, dashboard icon picker

## Why a new capability

The upload pipeline is a coherent surface (one endpoint + storage layer + validation) that is consumed by 4 different widget/icon flows. Folding it into any one consumer would obscure the contract; keeping it standalone lets us add features (per-resource ACL, garbage collection, image transforms) independently.

## Approach

- Endpoint accepts raw JSON body `{base64: 'data:image/<type>;base64,...'}` (NOT multipart) — keeps client side simple and avoids `$_FILES` quirks across PHP versions / non-POST verbs.
- Admin-only via `IGroupManager::isAdmin`.
- Allowed declared types: `jpeg, jpg, png, gif, svg, webp` (5 MB hard cap on decoded bytes).
- Raster types: cross-check declared type vs `getimagesizefromstring` detected MIME.
- SVG: route through `SvgSanitiser` (covered in `svg-sanitisation` change).
- Storage: `IAppData` folder `resources/`, filename `resource_<uniqid>.<ext>`.
- Response shape standardised: `{status: 'success', url: '/apps/mydash/resource/<filename>', name, size}` (NOT the bare `{url}` shape — consistent with rest of API).

## Notes

- Per-resource ACL is OUT of scope for v1 — anyone authenticated can read any resource by name (acceptable for branding/icon assets, not for sensitive uploads).
- Garbage collection is OUT of scope for v1 — orphaned resources accumulate. Tracked as a follow-up.
