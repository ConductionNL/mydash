# Tasks — resource-serving

## 1. Controller methods

- [x] 1.1 Add `lib/Controller/ResourceController.php::getResource(string $filename): StreamResponse`
- [x] 1.2 Treat `{filename}` as leaf-only — reject any decoded `/` or `..` with 404 (Symfony route param matches `[^/]+` by default; verify in route registration too)
- [x] 1.3 Implement Content-Type extension map (jpg/jpeg → image/jpeg, png → image/png, gif → image/gif, svg → image/svg+xml, webp → image/webp, default → application/octet-stream)
- [x] 1.4 Set `Cache-Control: public, max-age=31536000` on the StreamResponse
- [x] 1.5 413 guard: check `$file->getSize()` BEFORE loading bytes; refuse files > 5 MB with HTTP 413 `{status: 'error', error: 'file_too_large'}`
- [x] 1.6 Add `lib/Controller/ResourceController.php::listResources(): DataResponse` returning `{status: 'success', resources: [{name, url, size, modifiedAt}]}` ordered by `modifiedAt desc`
- [x] 1.7 Empty / non-existent folder → empty array (HTTP 200, NOT 404)
- [x] 1.8 Document the cache-busting strategy (uniqid in filename) in PHP docblocks for both methods

## 2. Routes

- [x] 2.1 Register `['name' => 'resource#getResource', 'url' => '/resource/{filename}', 'verb' => 'GET']` in `appinfo/routes.php` under the NON-OCS `routes` array (plain web route)
- [x] 2.2 Register `['name' => 'resource#listResources', 'url' => '/api/resources', 'verb' => 'GET']` in `appinfo/routes.php` under the OCS `ocs` array
- [x] 2.3 Confirm both methods carry the correct Nextcloud auth attribute (`#[NoAdminRequired]` — logged-in user only) — gate-route-auth + gate-semantic-auth must pass

## 3. PHPUnit tests

- [x] 3.1 `ResourceControllerTest::testServePngReturnsBytesAndHeaders` — Content-Type `image/png` + `Cache-Control: public, max-age=31536000` + exact bytes
- [x] 3.2 `ResourceControllerTest::testServeSvgUsesImageSvgXmlContentType` — `image/svg+xml`, NOT `application/svg+xml`
- [x] 3.3 `ResourceControllerTest::testUnknownExtensionFallsBackToOctetStream` — `.bin` → `application/octet-stream`
- [x] 3.4 `ResourceControllerTest::testMissingFileReturns404` — 404, empty body acceptable
- [x] 3.5 `ResourceControllerTest::testEncodedPathTraversalReturns404` — `..%2F..%2Fetc%2Fpasswd` → 404, no system file leak
- [x] 3.6 `ResourceControllerTest::testOversizeFileRefusedWithoutMemoryExhaustion` — 50 MB file → 413, file NOT read into memory
- [x] 3.7 `ResourceControllerTest::testListReturnsResourcesSortedByModifiedDesc` — newest first
- [x] 3.8 `ResourceControllerTest::testListWithNoFolderReturnsEmptyArray` — HTTP 200 + `{resources: []}`

## 4. End-to-end Playwright tests

- [ ] 4.1 Image widget renders an uploaded resource via the served `GET /apps/mydash/resource/<filename>` URL (deferred — no e2e harness in this worktree)
- [ ] 4.2 Direct browser fetch of `/apps/mydash/resource/<filename>` while unauthenticated redirects to login (no bytes served) (deferred — no e2e harness in this worktree)

## 5. Quality gates

- [x] 5.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — locally passes; pre-existing issues not introduced by this change
- [ ] 5.2 OpenAPI spec updated for `GET /api/resources` (the non-OCS `/resource/{filename}` is intentionally excluded — binary streaming, not API consumer surface) — deferred (no openapi.json tracked in repo)
- [x] 5.3 SPDX headers on every new/modified PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 5.4 Run all 10 `hydra-gates` locally before opening PR — out of scope for this implementation pass
