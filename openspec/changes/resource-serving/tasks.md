# Tasks — resource-serving

## 1. Controller methods

- [ ] 1.1 Add `lib/Controller/ResourceController.php::getResource(string $filename): StreamResponse`
- [ ] 1.2 Treat `{filename}` as leaf-only — reject any decoded `/` or `..` with 404 (Symfony route param matches `[^/]+` by default; verify in route registration too)
- [ ] 1.3 Implement Content-Type extension map (jpg/jpeg → image/jpeg, png → image/png, gif → image/gif, svg → image/svg+xml, webp → image/webp, default → application/octet-stream)
- [ ] 1.4 Set `Cache-Control: public, max-age=31536000` on the StreamResponse
- [ ] 1.5 413 guard: check `$file->getSize()` BEFORE loading bytes; refuse files > 5 MB with HTTP 413 `{status: 'error', error: 'file_too_large'}`
- [ ] 1.6 Add `lib/Controller/ResourceController.php::listResources(): DataResponse` returning `{status: 'success', resources: [{name, url, size, modifiedAt}]}` ordered by `modifiedAt desc`
- [ ] 1.7 Empty / non-existent folder → empty array (HTTP 200, NOT 404)
- [ ] 1.8 Document the cache-busting strategy (uniqid in filename) in PHP docblocks for both methods

## 2. Routes

- [ ] 2.1 Register `['name' => 'resource#getResource', 'url' => '/resource/{filename}', 'verb' => 'GET']` in `appinfo/routes.php` under the NON-OCS `routes` array (plain web route)
- [ ] 2.2 Register `['name' => 'resource#listResources', 'url' => '/api/resources', 'verb' => 'GET']` in `appinfo/routes.php` under the OCS `ocs` array
- [ ] 2.3 Confirm both methods carry the correct Nextcloud auth attribute (`#[NoAdminRequired]` — logged-in user only) — gate-route-auth + gate-semantic-auth must pass

## 3. PHPUnit tests

- [ ] 3.1 `ResourceControllerTest::testServePngReturnsBytesAndHeaders` — Content-Type `image/png` + `Cache-Control: public, max-age=31536000` + exact bytes
- [ ] 3.2 `ResourceControllerTest::testServeSvgUsesImageSvgXmlContentType` — `image/svg+xml`, NOT `application/svg+xml`
- [ ] 3.3 `ResourceControllerTest::testUnknownExtensionFallsBackToOctetStream` — `.bin` → `application/octet-stream`
- [ ] 3.4 `ResourceControllerTest::testMissingFileReturns404` — 404, empty body acceptable
- [ ] 3.5 `ResourceControllerTest::testEncodedPathTraversalReturns404` — `..%2F..%2Fetc%2Fpasswd` → 404, no system file leak
- [ ] 3.6 `ResourceControllerTest::testOversizeFileRefusedWithoutMemoryExhaustion` — 50 MB file → 413, file NOT read into memory
- [ ] 3.7 `ResourceControllerTest::testListReturnsResourcesSortedByModifiedDesc` — newest first
- [ ] 3.8 `ResourceControllerTest::testListWithNoFolderReturnsEmptyArray` — HTTP 200 + `{resources: []}`

## 4. End-to-end Playwright tests

- [ ] 4.1 Image widget renders an uploaded resource via the served `GET /apps/mydash/resource/<filename>` URL
- [ ] 4.2 Direct browser fetch of `/apps/mydash/resource/<filename>` while unauthenticated redirects to login (no bytes served)

## 5. Quality gates

- [ ] 5.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 5.2 OpenAPI spec updated for `GET /api/resources` (the non-OCS `/resource/{filename}` is intentionally excluded — binary streaming, not API consumer surface)
- [ ] 5.3 SPDX headers on every new/modified PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 5.4 Run all 10 `hydra-gates` locally before opening PR
