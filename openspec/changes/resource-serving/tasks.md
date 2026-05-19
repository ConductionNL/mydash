# Tasks — resource-serving

## Tasks

- [ ] Task 1: Add `ResourceController::getResource(string $filename): StreamResponse` — treat `{filename}` as leaf-only (reject any decoded `/` or `..` with 404; Symfony route param `[^/]+` plus belt-and-braces in the method body); set Content-Type via extension map (jpg/jpeg→`image/jpeg`, png→`image/png`, gif→`image/gif`, svg→`image/svg+xml`, webp→`image/webp`, default→`application/octet-stream`); set `Cache-Control: public, max-age=31536000`
- [ ] Task 2: 413 guard — check `$file->getSize()` BEFORE loading bytes; refuse files > 5MB with HTTP 413 `{status:'error', error:'file_too_large'}` (never read large files into memory)
- [ ] Task 3: Add `ResourceController::listResources(): DataResponse` returning `{status:'success', resources:[{name, url, size, modifiedAt}]}` ordered by `modifiedAt desc`; empty/non-existent folder returns HTTP 200 `{resources:[]}` (NOT 404)
- [ ] Task 4: Document the cache-busting strategy (uniqid in filename) in PHP docblocks for both methods
- [ ] Task 5: Register routes — `GET /resource/{filename}` under the non-OCS `routes` array, `GET /api/resources` under the OCS `ocs` array; both methods carry `#[NoAdminRequired]` (logged-in user only); gate-route-auth + gate-semantic-auth pass
- [ ] Task 6: PHPUnit — png serve returns bytes + `image/png` + `Cache-Control` header; svg uses `image/svg+xml` (NOT `application/svg+xml`); unknown extension `.bin` → `application/octet-stream`; missing file → 404 (empty body acceptable)
- [ ] Task 7: PHPUnit — encoded path traversal `..%2F..%2Fetc%2Fpasswd` → 404 with no system file leak; 50MB file → 413 with file NOT read into memory; list returns resources sorted by `modifiedAt desc`; list with no folder returns HTTP 200 `{resources:[]}`
- [ ] Task 8: Playwright — image widget renders an uploaded resource via `GET /apps/mydash/resource/<filename>`; unauthenticated direct browser fetch redirects to login (no bytes served)
- [ ] Task 9: Quality gates — `composer check:strict` (fix any pre-existing issues encountered along the way); OpenAPI updated for `GET /api/resources` (the binary `/resource/{filename}` is intentionally excluded — not API consumer surface); SPDX-in-docblock on every new/modified PHP file; all 10 hydra-gates green

## Verification

`openspec validate` exits clean. Oversize + traversal vectors return the correct error codes without memory exhaustion; auth gate is enforced.

## Tests (company-wide ADR-009)

PHPUnit per Tasks 6–7; Playwright per Task 8. Newman/Postman updated for `GET /api/resources`.

## Documentation (company-wide ADR-010)

Inline PHP docblock per Task 4; changelog entry covering the resource-serving + listing endpoints.

## i18n (company-wide ADR-005)

No user-facing strings — error responses are machine-readable codes consumed by existing widgets.
