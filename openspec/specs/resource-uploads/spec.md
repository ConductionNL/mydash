---
status: implemented
---

# Resource Uploads Specification

## Purpose

The `resource-uploads` capability owns a small mini file API for binary assets that MyDash widgets reference directly: dashboard icons, image-widget images, link-button icons, etc. Resources are stored in MyDash's app-data folder (NOT the user's Files), addressed by a stable URL, uploaded admin-only via a base64-data-URL JSON request, and served back to any logged-in user via a non-OCS streaming endpoint plus an OCS listing endpoint. SVG sanitisation is specified in the sibling `svg-sanitisation` change.

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
- A stable public URL `/apps/mydash/resource/<filename>` (served by REQ-RES-006)

## Requirements

### Requirement: Admin-only base64 upload endpoint (REQ-RES-001)

The system MUST expose `POST /api/resources` accepting a raw JSON body of the shape `{base64: 'data:image/<type>;base64,...'}`. The endpoint MUST be admin-only — non-admin requests MUST receive HTTP 403 with `{status: 'error', error: 'forbidden'}`. The endpoint MUST NOT accept multipart form data — only a base64-encoded data URL.

#### Scenario: Admin uploads a PNG

- GIVEN an authenticated admin user
- WHEN they POST `{"base64": "data:image/png;base64,iVBORw0KGgo..."}` (valid 200×200 PNG)
- THEN the system MUST return HTTP 200 with `{status: 'success', url: '/apps/mydash/resource/resource_<uniqid>.png', name: 'resource_<uniqid>.png', size: <bytes>}`
- AND a file MUST exist at the corresponding app-data path

#### Scenario: Non-admin rejected

- GIVEN an authenticated non-admin user
- WHEN they POST any body to `/api/resources`
- THEN the system MUST return HTTP 403 with `{status: 'error', error: 'forbidden'}`
- AND no file MUST be written

#### Scenario: Multipart upload rejected

- WHEN a request arrives with `Content-Type: multipart/form-data` instead of JSON
- THEN the system MUST return HTTP 415 with `{status: 'error', error: 'unsupported_media_type', message: 'Use JSON body with base64 field'}`

### Requirement: Allowed declared types (REQ-RES-002)

The data-URL prefix MUST declare one of the allowed image types. Allowed types: `jpeg`, `jpg`, `png`, `gif`, `svg`, `webp` (case-insensitive). Anything else MUST return HTTP 400 with `{status: 'error', error: 'invalid_image_format'}`. A missing or unparseable data-URL prefix MUST return HTTP 400 with `{status: 'error', error: 'invalid_data_url'}`.

The persisted file extension MUST be the normalised lowercase form (jpeg → jpeg, jpg → jpg, svg+xml → svg).

#### Scenario: Disallowed type rejected

- WHEN the client POSTs `{"base64": "data:image/bmp;base64,..."}`
- THEN the system MUST return HTTP 400 with `error: 'invalid_image_format'`
- AND no file MUST be written

#### Scenario: Missing data-URL prefix rejected

- WHEN the client POSTs `{"base64": "iVBORw0KGgo..."}` (raw base64, no `data:` prefix)
- THEN the system MUST return HTTP 400 with `error: 'invalid_data_url'`

#### Scenario: Mixed-case declared type accepted

- WHEN the client POSTs `{"base64": "data:image/PNG;base64,..."}` (uppercase declared type)
- THEN the system MUST treat it as `png`
- AND the persisted extension MUST be lowercase `.png`

### Requirement: Size and integrity validation (REQ-RES-003)

The system MUST decode the base64 payload and enforce a 5 MB cap on the decoded bytes. For raster types (jpeg/png/gif/webp), the system MUST run `getimagesizefromstring` on the decoded bytes and reject if it returns false (corrupted) OR if the detected MIME does not match the declared type. SVG validation is delegated to the `svg-sanitisation` capability's sanitiser. The size cap MUST be enforced before invoking `getimagesizefromstring` so the server cannot be tricked into loading an oversize blob into the image library.

#### Scenario: Oversize payload rejected

- WHEN the client POSTs a base64 payload that decodes to 6 MB
- THEN the system MUST return HTTP 400 with `{status: 'error', error: 'file_too_large', message: 'Maximum size is 5MB'}`
- AND no file MUST be written
- AND `getimagesizefromstring` MUST NOT be called

#### Scenario: MIME mismatch rejected

- WHEN the client POSTs `{"base64": "data:image/png;base64,<actually a JPEG>"}` (declared png, body is jpeg)
- THEN `getimagesizefromstring` reports `image/jpeg`
- THEN the system MUST return HTTP 400 with `{status: 'error', error: 'mime_mismatch'}`

#### Scenario: Corrupt raster bytes rejected

- WHEN the client POSTs `{"base64": "data:image/png;base64,<garbage>"}`
- THEN `getimagesizefromstring` returns false
- THEN the system MUST return HTTP 400 with `{status: 'error', error: 'corrupt_image'}`

### Requirement: Storage via IAppData (REQ-RES-004)

Validated bytes MUST be stored via `IAppData::getFolder('resources')` (auto-create on first use). Filenames MUST be `resource_<uniqid>.<ext>` (using PHP `uniqid('resource_', true)` for high-entropy IDs and the normalised extension). The system MUST NOT write to the user's Files folder.

#### Scenario: Storage location

- GIVEN any successful upload
- THEN the file MUST be created under the app's app-data root inside a `resources/` subfolder
- AND the file MUST NOT appear under the calling admin's user folder
- AND the filename MUST match `resource_[a-f0-9.]+\.(jpeg|jpg|png|gif|svg|webp)`

#### Scenario: Folder auto-create

- GIVEN the `resources/` folder does not yet exist
- WHEN the first upload arrives
- THEN the folder MUST be created automatically
- AND the upload MUST succeed normally

### Requirement: Standardised response envelope (REQ-RES-005)

Every successful response MUST conform to `{status: 'success', url: <string>, name: <string>, size: <int>}` (additional fields are allowed). Every error response MUST conform to `{status: 'error', error: <stable_string>, message: <translated string>}`. The `error` field is a stable enum suitable for client-side branching; `message` is for display. Raw exception messages MUST NEVER be returned to the client.

#### Scenario: Error envelope shape

- WHEN any error path triggers (admin check, validation, storage failure)
- THEN the response body MUST contain `status`, `error`, `message`
- AND the body MUST NOT contain raw stack traces or `$e->getMessage()` strings unsafe for display

#### Scenario: Stable error enum

- GIVEN the documented error codes are `forbidden`, `unsupported_media_type`, `invalid_data_url`, `invalid_image_format`, `file_too_large`, `mime_mismatch`, `corrupt_image`, `storage_failure`
- WHEN any rejection scenario above triggers
- THEN the `error` field MUST equal exactly one of those strings (no synonyms, no extras)

### Requirement: Public resource serving endpoint (REQ-RES-006)

The system MUST expose `GET /apps/mydash/resource/{filename}` (a non-OCS, plain web route) returning a `StreamResponse` of the resource bytes. The route MUST be authenticated (any logged-in Nextcloud user) but MUST NOT require admin privileges. The Content-Type header MUST be derived from the file extension via this map:

| Extension | Content-Type |
|---|---|
| `jpg`, `jpeg` | `image/jpeg` |
| `png` | `image/png` |
| `gif` | `image/gif` |
| `svg` | `image/svg+xml` |
| `webp` | `image/webp` |
| anything else | `application/octet-stream` |

The response MUST include `Cache-Control: public, max-age=31536000` (one year, immutable). Filenames produced by REQ-RES-004 already include a `uniqid` so they double as cache busting keys — when the same logical asset changes, a new upload generates a new filename.

#### Scenario: Serve a PNG

- GIVEN an uploaded resource at `<appdata>/resources/resource_abc123.png`
- WHEN any authenticated user sends `GET /apps/mydash/resource/resource_abc123.png`
- THEN the system MUST return HTTP 200 with `Content-Type: image/png`
- AND `Cache-Control: public, max-age=31536000`
- AND the response body MUST be the file's exact bytes

#### Scenario: Serve an SVG with correct content-type

- GIVEN an uploaded resource at `<appdata>/resources/resource_abc123.svg`
- WHEN GETting it
- THEN `Content-Type` MUST be `image/svg+xml` (NOT `application/svg+xml`)

#### Scenario: Unknown extension falls back to octet-stream

- GIVEN a file with extension `.bin` somehow in the resources folder (manual install, etc.)
- WHEN GETting it
- THEN `Content-Type` MUST be `application/octet-stream`

#### Scenario: Missing file returns 404

- WHEN GET `/apps/mydash/resource/does-not-exist.png`
- THEN the system MUST return HTTP 404
- AND the response body MAY be empty (no detail leaked)

#### Scenario: Path traversal rejected

- WHEN GET `/apps/mydash/resource/..%2F..%2Fetc%2Fpasswd` (URL-encoded `../../etc/passwd`)
- THEN the system MUST return HTTP 404 (the route's `{filename}` parameter MUST be treated as a leaf name; any decoded `/` or `..` in it MUST cause a 404 — never a 200 with system file contents)

#### Scenario: Unauthenticated request rejected

- GIVEN an unauthenticated browser session
- WHEN GET `/apps/mydash/resource/resource_abc123.png`
- THEN Nextcloud MUST redirect to login (standard NC auth middleware)
- AND no resource bytes MUST be served

### Requirement: List uploaded resources (REQ-RES-007)

The system MUST expose `GET /api/resources` returning `{status: 'success', resources: [{name, url, size, modifiedAt}, …]}` ordered by `modifiedAt` descending. The endpoint MUST be authenticated (any user); admin gating is NOT required because the listed names are already referenced from rendered dashboards.

When the resources folder does not yet exist, the response MUST be `{status: 'success', resources: []}` (not 404).

#### Scenario: List with one resource

- GIVEN one resource exists at `<appdata>/resources/resource_abc123.png` with size 12345 bytes
- WHEN GET `/api/resources`
- THEN the response MUST contain `resources[0] = {name: 'resource_abc123.png', url: '/apps/mydash/resource/resource_abc123.png', size: 12345, modifiedAt: <ISO timestamp>}`

#### Scenario: Empty folder returns empty array

- GIVEN no `resources/` folder has been created yet
- WHEN GET `/api/resources`
- THEN HTTP 200 with `{status: 'success', resources: []}`

### Requirement: Stream-via-memory buffer, size-bounded (REQ-RES-008)

The implementation MUST stream resource bytes via an in-memory buffer bounded by the upload cap (5 MB per REQ-RES-003). For larger files (which currently CANNOT be uploaded but might exist if installed manually), the implementation MUST refuse to load (return 413 with the standardised error envelope) rather than exhaust memory.

#### Scenario: Stream a normal-size resource

- GIVEN a 200 KB PNG resource
- WHEN GETting it
- THEN the implementation MUST read it into a `php://memory` stream, write the StreamResponse, and the client MUST receive the full bytes

#### Scenario: Refuse to serve oversize external file

- GIVEN a manually-installed 50 MB file `<appdata>/resources/huge.bin`
- WHEN GETting it
- THEN the system MUST return HTTP 413 with `{status: 'error', error: 'file_too_large'}` (or equivalent)
- AND MUST NOT load the file into memory
- NOTE: Normal uploads cannot exceed 5 MB (REQ-RES-003) so this only protects against manual filesystem tampering.

### Requirement: SVG sanitiser is mandatory before persistence (REQ-RES-009)

The system MUST pass every uploaded SVG (declared `image/svg` or `image/svg+xml`) through `SvgSanitiser::sanitize(string $bytes): ?string` before any persistence. The sanitiser MUST return the sanitised SVG string on success OR `null` on parse failure / fully-stripped content. When `null` is returned, `ResourceService` MUST reject the upload with HTTP 400 `{status: 'error', error: 'invalid_svg'}` and MUST NOT write any file. The sanitised bytes (NOT the original) MUST be what gets persisted. Size validation (REQ-RES-003) MUST run AFTER sanitisation so the measured size is the persisted size.

#### Scenario: Clean SVG accepted

- GIVEN a logged-in user "alice"
- WHEN she sends `POST /api/resources` with body `{"base64": "data:image/svg+xml;base64,<valid sanitisable SVG>"}`
- THEN the sanitiser MUST return the (possibly modified) SVG string
- AND `ResourceService` MUST persist the sanitised bytes (NOT the original bytes)
- AND the response MUST be HTTP 200 with `{status: 'success', url: '/apps/mydash/resource/resource_<uniqid>.svg', ...}`

#### Scenario: Sanitiser strips malicious content but accepts upload

- GIVEN a logged-in user "alice"
- WHEN she sends `POST /api/resources` with an SVG containing `<script>alert(1)</script><circle r="5"/>`
- THEN the sanitiser MUST strip the `<script>` element
- AND the upload MUST proceed with HTTP 200
- AND the persisted SVG MUST NOT contain `<script>` or `alert`
- NOTE: the sanitiser strips disallowed elements rather than failing; failure (`null`) is reserved for unparseable XML

#### Scenario: Unparseable bytes rejected

- GIVEN a logged-in user "alice"
- WHEN she sends `POST /api/resources` with body `{"base64": "data:image/svg+xml;base64,<garbage non-XML>"}`
- THEN `SvgSanitiser::sanitize` MUST return `null`
- AND the system MUST return HTTP 400 with body `{status: 'error', error: 'invalid_svg'}`
- AND no file MUST be written to disk

#### Scenario: Size cap measured after sanitisation

- GIVEN an SVG whose original byte length is 5.5 MB (over the REQ-RES-003 5 MB cap) but whose sanitised length is 4.5 MB after stripping disallowed elements
- WHEN the upload is processed
- THEN size validation MUST run on the 4.5 MB sanitised payload
- AND the upload MUST succeed (HTTP 200)

### Requirement: Whitelist of allowed SVG elements (REQ-RES-010)

The sanitiser MUST allow ONLY these element names (lowercase): `svg`, `g`, `path`, `rect`, `circle`, `ellipse`, `line`, `polyline`, `polygon`, `text`, `tspan`, `defs`, `clippath`, `use`, `image`, `style`, `lineargradient`, `radialgradient`, `stop`, `mask`, `pattern`, `symbol`, `title`, `desc`. Any other element (including `script`, `foreignObject`, `iframe`, `embed`, `object`) MUST be removed from the parsed DOM tree (along with all its children) before serialisation.

#### Scenario: script element removed

- GIVEN input `<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="5"/></svg>`
- WHEN the sanitiser processes the input
- THEN the output MUST contain `<circle r="5"/>` (or equivalent serialisation)
- AND the output MUST NOT contain `<script>` OR the substring `alert`

#### Scenario: foreignObject and nested iframe removed

- GIVEN input contains `<foreignObject><iframe src="http://attacker"/></foreignObject>` inside an `<svg>` root
- WHEN the sanitiser processes the input
- THEN both `<foreignObject>` and the nested `<iframe>` MUST be removed
- AND the output MUST NOT contain the substring `attacker`

#### Scenario: Whitelisted elements preserved with their structure

- GIVEN input is a complex SVG containing `<g>`, `<path>`, `<defs>`, `<lineargradient>`
- WHEN the sanitiser processes the input
- THEN all four elements MUST remain in the output (modulo attribute filtering per REQ-RES-011)
- AND parent-child relationships MUST be preserved

### Requirement: Whitelist of allowed SVG attributes (REQ-RES-011)

The sanitiser MUST allow ONLY these attribute names (lowercase): `id`, `class`, `style`, `d`, `x`, `y`, `x1`, `y1`, `x2`, `y2`, `cx`, `cy`, `r`, `rx`, `ry`, `width`, `height`, `viewbox`, `fill`, `stroke`, `stroke-width`, `stroke-linecap`, `stroke-linejoin`, `stroke-dasharray`, `stroke-dashoffset`, `stroke-opacity`, `fill-opacity`, `opacity`, `transform`, `points`, `font-size`, `font-family`, `font-weight`, `text-anchor`, `dominant-baseline`, `dx`, `dy`, `clip-path`, `mask`, `filter`, `gradientunits`, `gradienttransform`, `offset`, `stop-color`, `stop-opacity`, `patternunits`, `preserveaspectratio`, `xmlns`, `xmlns:xlink`, `version`, `href`, `xlink:href`. Any other attribute MUST be removed from each element regardless of element name.

In addition (defence in depth), ALL attributes whose lowercased name starts with `on` MUST be removed unconditionally — even if a future whitelist edit accidentally allowed an `on*` name.

#### Scenario: Event-handler attributes always stripped

- GIVEN input `<circle onclick="alert(1)" onload="x()" r="5"/>`
- WHEN the sanitiser processes the input
- THEN the output `<circle>` MUST NOT carry `onclick` OR `onload`
- AND `r="5"` MUST be preserved on the same element

#### Scenario: Non-whitelisted attribute stripped

- GIVEN input `<circle data-payload="x" r="5"/>`
- WHEN the sanitiser processes the input
- THEN the `data-payload` attribute MUST be removed
- AND `r="5"` MUST remain

#### Scenario: Whitelisted geometry attributes preserved

- GIVEN input `<rect x="10" y="20" width="100" height="50" fill="red" stroke="black"/>`
- WHEN the sanitiser processes the input
- THEN all six attributes MUST remain on the output element

### Requirement: URL-bearing attributes filtered (REQ-RES-012)

For attributes `href` and `xlink:href`, the sanitiser MUST reject values whose lowercased trimmed prefix matches `javascript:` OR `data:`. Rejected attributes MUST be removed (the rest of the element is preserved). For the `style` attribute, the sanitiser MUST remove the attribute entirely if its value (lowercased) contains any of: `expression(`, `javascript:`, `url(data:`.

#### Scenario: javascript: href stripped

- GIVEN input `<use xlink:href="javascript:alert(1)"/>` (where `<use>` is in the element whitelist)
- WHEN the sanitiser processes the input
- THEN the `xlink:href` attribute MUST be removed
- AND the `<use>` element MUST remain (with no `xlink:href`)

#### Scenario: data: href stripped

- GIVEN input `<image href="data:image/svg+xml;base64,..."/>`
- WHEN the sanitiser processes the input
- THEN the `href` attribute MUST be removed
- AND the `<image>` element MUST remain
- NOTE: an `<image>` with no `href` renders nothing — acceptable

#### Scenario: style with expression() stripped

- GIVEN input `<rect style="width: expression(alert(1))" width="10" height="10"/>`
- WHEN the sanitiser processes the input
- THEN the `style` attribute MUST be removed entirely
- AND `width="10"` and `height="10"` MUST remain

#### Scenario: style with url(data:) stripped

- GIVEN input `<g style="background: url(data:text/html,<script>alert(1)</script>)"><path d="M0 0"/></g>`
- WHEN the sanitiser processes the input
- THEN the `style` attribute MUST be removed entirely
- AND the `<g>` and child `<path>` MUST remain

#### Scenario: Safe http(s) href preserved

- GIVEN input `<image href="https://example.com/logo.png"/>`
- WHEN the sanitiser processes the input
- THEN `href="https://example.com/logo.png"` MUST remain unchanged
- NOTE: only `javascript:` and `data:` prefixes are filtered; http/https/relative URLs pass through

### Requirement: XXE and network-fetch protection (REQ-RES-013)

The sanitiser MUST parse SVG via `DOMDocument::loadXML($bytes, LIBXML_NONET | LIBXML_NOENT)`. The `LIBXML_NONET` flag MUST be set so the parser cannot fetch external entities or DTDs. The `LIBXML_NOENT` flag substitutes entities so they do not amplify recursively. The sanitiser MUST call `libxml_use_internal_errors(true)` BEFORE the parse and `libxml_clear_errors()` AFTER, to prevent malformed SVG from emitting libxml warnings into the HTTP response.

#### Scenario: External DTD reference does not fetch

- GIVEN input declares an external DTD reference (e.g. `<!DOCTYPE svg SYSTEM "http://attacker/evil.dtd">` followed by an SVG body)
- WHEN the sanitiser parses the input
- THEN no network request MUST be issued for `http://attacker/evil.dtd`
- AND parsing MUST proceed without fetching the DTD

#### Scenario: Billion-laughs entity expansion bounded

- GIVEN input declares nested entities expanding exponentially (XML billion-laughs payload)
- WHEN the sanitiser parses the input
- THEN the parser MUST NOT exhaust memory
- AND the call MUST return within a bounded time
- NOTE: `LIBXML_NOENT` substitutes entities once but libxml has internal expansion limits that protect against billion-laughs in modern PHP/libxml — verified in tests

#### Scenario: libxml warnings are suppressed from response

- GIVEN input is malformed XML that triggers libxml parse warnings
- WHEN the sanitiser processes the input
- THEN `libxml_use_internal_errors(true)` MUST have been called before parse
- AND `libxml_clear_errors()` MUST have been called after parse
- AND no libxml warning text MUST appear in the HTTP response body
