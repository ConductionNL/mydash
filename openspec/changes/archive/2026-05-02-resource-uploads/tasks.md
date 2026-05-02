# Tasks — resource-uploads

## 1. Backend

- [x] Create `lib/Service/ResourceService.php` with `upload(string $base64DataUrl): array` returning `{url, name, size}` or throwing typed exceptions
- [x] Create `lib/Service/ImageMimeValidator.php::validate(string $declaredType, string $bytes): void`
- [x] Create `lib/Controller/ResourceController.php::upload` mapped to `POST /api/resources`
- [x] Read raw input via `file_get_contents('php://input')` + `json_decode`
- [x] Admin guard via `IGroupManager::isAdmin`
- [x] 5 MB cap on decoded bytes (guard before `getimagesizefromstring` to bound memory)
- [x] Cross-MIME check for raster types
- [x] Delegate SVG sanitisation to `SvgSanitiser` (separate change)
- [x] Persist via `IAppData->getFolder('resources')` (auto-create); filename `uniqid('resource_', true) . '.' . $ext`
- [x] Define typed exceptions with stable error codes: `ForbiddenException`, `InvalidImageFormatException`, `InvalidDataUrlException`, `FileTooLargeException`, `MimeMismatchException`, `CorruptImageException`
- [x] Map exceptions to standardised error envelope in controller (no raw `$e->getMessage()`)

## 2. Frontend

- [x] Add `src/services/resourceService.js::uploadDataUrl(dataUrl): Promise<{url}>` wrapper
- [x] Used by `image-widget` form, `link-button-widget` icon picker, `IconPicker` (downstream changes will import)

## 3. Tests

- [x] PHPUnit: 403 on non-admin (`tests/Unit/Controller/ResourceControllerTest::testNonAdminReceives403WithForbidden`)
- [x] PHPUnit: each rejection path returns the exact error code (`testEachExceptionMapsToCorrectEnvelope` + `ResourceServiceTest`)
- [x] PHPUnit: oversize rejected before `getimagesizefromstring` (`ResourceServiceTest::testOversizePayloadIsRejectedBeforeValidator`)
- [x] PHPUnit: MIME mismatch table (declared png, actual jpeg/gif/webp) (`ImageMimeValidatorTest`)
- [x] PHPUnit: successful upload writes to app-data, returns URL (`ResourceServiceTest::testMixedCaseDeclaredTypeIsAcceptedAndLowercased`)
- [x] PHPUnit: error responses NEVER contain `Exception` / stack trace strings (`ResourceControllerTest::testUnexpectedThrowableIsMaskedAsStorageFailure`)
- [ ] Playwright: file upload from icon picker → URL appears in form (deferred — icon picker UI is a downstream `dashboard-icons` / `custom-icon-upload-pattern` deliverable)

## 4. Quality

- [x] `composer check:strict` passes (excluding pre-existing `Doctrine\DBAL\ParameterType` env issue in DashboardShareServiceFollowupsTest)
- [x] Translation entries: `Failed to upload image`, all error message strings (one per error code) added to `l10n/en.{js,json}` + `l10n/nl.{js,json}`
- [x] Document v1 limits: translation key `Maximum image size: 5 MB. Allowed types: jpeg, jpg, png, gif, svg, webp.` (consumed by downstream upload UIs)
- [ ] OpenAPI updated for `POST /api/resources` (no `openapi.json` exists in this app; deferred to when the app introduces one)

## 5. Follow-ups (separate changes)

- [x] `resource-serving` — GET endpoint (separate change folder, owned by another agent)
- [x] `svg-sanitisation` — DOM-based whitelist sanitiser (already implemented as `lib/Service/SvgSanitiser.php`; integration covered by `ResourceServiceSvgIntegrationTest`)
- [ ] (Future) `resource-gc` — cleanup of orphaned resources
- [ ] (Future) `resource-acl` — per-resource access control if non-public assets are added
