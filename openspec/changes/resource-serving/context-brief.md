# Resource serving

Extend `resource-uploads` with the read side: a non-OCS `GET /resource/{filename}` endpoint that streams an uploaded resource by name with the correct Content-Type and a long immutable cache header. Plus `GET /api/resources` for listing.

## Affected code units

- `lib/Controller/ResourceController.php` — add `getResource(string $filename)` and `listResources()`
- `appinfo/routes.php` — register `GET /resource/{filename}` (non-OCS, plain web route) AND `GET /api/resources` (OCS)
- Modifies `resource-uploads` capability

## Why a delta

The serving side belongs in the same capability that owns upload — same storage, same naming convention, same security model. Splitting "create" and "read" into different capabilities would obscure their tight coupling.

## Approach

- `GET /resource/{filename}` is a plain (non-OCS) web route returning a `StreamResponse` so binary bytes flow without OCS-envelope wrapping.
- Content-Type derived from extension (jpeg, png, gif, svg+xml, webp; default `application/octet-stream`).
- `Cache-Control: public, max-age=31536000` — uniqid-suffixed filenames double as cache keys; immutable for one year.
- 404 returns an empty StreamResponse with status 404 (intentional minimalism — no body needed).
- `GET /api/resources` (OCS) returns `{resources: [{name, url, size, modifiedAt}]}` — for a future admin gallery / cleanup UI.
- Both endpoints authenticated (any logged-in user) — no admin gate, since uploaded resources are referenced by every dashboard renderer.

## Notes

- No per-resource ACL (deliberate v1 limitation — see `resource-uploads` Notes).
- Streaming uses an in-memory buffer (`php://memory`) bounded by the 5 MB upload cap — acceptable for icon-sized assets.



## Tasks

# Tasks — resource-serving

## 1. Backend

- [ ] Add `lib/Controller/ResourceController.php::getResource(string $filename): StreamResponse`
- [ ] Treat `{filename}` as leaf-only — reject any decoded `/` or `..` with 404 (Symfony route param matches `[^/]+` by default; verify)
- [ ] Content-Type extension map (jpeg/png/gif/svg+xml/webp/octet-stream)
- [ ] `Cache-Control: public, max-age=31536000`
- [ ] 413 guard: check `$file->getSize()` before loading; refuse > 5 MB
- [ ] Add `lib/Controller/ResourceController.php::listResources(): DataResponse` returning `{status, resources: [{name, url, size, modifiedAt}]}` ordered by `modifiedAt desc`
- [ ] Empty folder → empty array (HTTP 200, not 404)

## 2. Routes

- [ ] `appinfo/routes.php`: register `['name' => 'resource#getResource', 'url' => '/resource/{filename}', 'verb' => 'GET']` (NON-OCS)
- [ ] `appinfo/routes.php`: register `['name' => 'resource#listResources', 'url' => '/api/resources', 'verb' => 'GET']` (OCS)

## 3. Tests

- [ ] PHPUnit: serve PNG returns correct bytes + Content-Type + Cache-Control
- [ ] PHPUnit: SVG → `image/svg+xml`
- [ ] PHPUnit: unknown extension → octet-stream
- [ ] PHPUnit: missing file → 404
- [ ] PHPUnit: encoded path traversal → 404 (no system file leak)
- [ ] PHPUnit: 50 MB file → 413, no memory exhaustion
- [ ] PHPUnit: list returns sorted resources
- [ ] PHPUnit: list with no folder returns 200 + empty array
- [ ] Playwright: image widget renders an uploaded resource via the served URL

## 4. Quality

- [ ] `composer check:strict` passes
- [ ] OpenAPI updated for `GET /api/resources` (the non-OCS `/resource/{filename}` is intentionally excluded from OpenAPI)
- [ ] Document the cache-busting strategy (uniqid in filename) in PHP docblocks