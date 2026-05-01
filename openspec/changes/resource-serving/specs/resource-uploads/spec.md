---
capability: resource-uploads
delta: true
status: draft
---

# Resource Uploads — Delta from change `resource-serving`

## ADDED Requirements

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

The system MUST expose `GET /api/resources` (OCS endpoint) returning `{status: 'success', resources: [{name, url, size, modifiedAt}, …]}` ordered by `modifiedAt` descending. The endpoint MUST be authenticated (any user); admin gating is NOT required because the listed names are already referenced from rendered dashboards.

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

The implementation MUST stream resource bytes via an in-memory buffer bounded by the upload cap (5 MB per REQ-RES-003). For larger files (which currently CANNOT be uploaded but might exist if installed manually), the implementation MUST refuse to load (return 413 with `Content-Length` from filesystem) rather than exhaust memory.

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
