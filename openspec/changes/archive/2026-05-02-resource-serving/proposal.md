# Resource serving

## Why

The `resource-uploads` capability today only covers the write side: an admin can upload an image asset and the upload endpoint persists it under `<appdata>/resources/<uniqid-suffixed-name>`. There is no first-party way for a logged-in user's browser to actually fetch those bytes — every dashboard render that references an uploaded resource currently has to round-trip through ad-hoc handling. This change adds the read side: a non-OCS `GET /apps/mydash/resource/{filename}` endpoint that streams the file with the right Content-Type and a one-year immutable cache header (uniqid in the filename doubles as the cache-buster), plus an OCS `GET /api/resources` listing endpoint to back a future admin gallery / cleanup UI. Path traversal is rejected with a 404, oversize files (50 MB+, only reachable via manual filesystem tampering) are refused with 413 to bound memory.

## What Changes

- Add `GET /apps/mydash/resource/{filename}` — non-OCS plain web route returning `StreamResponse` of the resource bytes with extension-derived Content-Type (jpeg, png, gif, svg+xml, webp; default `application/octet-stream`) and `Cache-Control: public, max-age=31536000`.
- Add `GET /api/resources` — OCS endpoint listing uploaded resources as `{name, url, size, modifiedAt}` tuples ordered by `modifiedAt` descending; empty folder returns an empty array (HTTP 200, not 404).
- Reject path traversal (`..`, `/` in the `{filename}` parameter) with HTTP 404 — never leak system files.
- Refuse to load files larger than the 5 MB upload cap from REQ-RES-003 with HTTP 413 — only reachable via manual filesystem tampering, but bounds memory usage.
- Both endpoints are authenticated (any logged-in user) and intentionally NOT admin-gated, since uploaded resources are referenced by every dashboard render.

## Capabilities

### New Capabilities

(none — the feature folds into the existing `resource-uploads` capability)

### Modified Capabilities

- `resource-uploads`: adds REQ-RES-006 (public resource serving endpoint), REQ-RES-007 (list uploaded resources), REQ-RES-008 (stream-via-memory buffer, size-bounded). Existing REQ-RES-001..005 (the upload side) are untouched.

## Impact

**Affected code:**

- `lib/Controller/ResourceController.php` — add `getResource(string $filename): StreamResponse` and `listResources(): DataResponse`
- `appinfo/routes.php` — register `GET /resource/{filename}` (non-OCS) and `GET /api/resources` (OCS)

**Affected APIs:**

- 2 new routes (no existing routes changed)
- The non-OCS `GET /resource/{filename}` is intentionally excluded from the generated OpenAPI spec (binary streaming, not API consumer surface); `GET /api/resources` IS included.

**Dependencies:**

- `OCP\Files\IRootFolder` / appdata folder access — already used by the upload side (REQ-RES-001..005)
- No new composer or npm dependencies

**Migration:**

- Zero-impact: no database changes. Read-only access to existing `<appdata>/resources/` files written by the upload side.
- No data backfill required.

## Notes

- No per-resource ACL (deliberate v1 limitation — see `resource-uploads` Notes). Any authenticated user can fetch any uploaded resource by name. Acceptable because uploaded resources are dashboard assets (icons, logos) referenced by every dashboard render, and filenames carry uniqid suffixes that aren't enumerable from outside.
- Streaming uses an in-memory buffer (`php://memory`) bounded by the 5 MB upload cap — acceptable for icon-sized assets. The 413 guard prevents memory exhaustion from manually-installed oversize files.
