# Tasks — resource-uploads

## 1. Backend

- [ ] Create `lib/Service/ResourceService.php` with `upload(string $base64DataUrl): array` returning `{url, name, size}` or throwing typed exceptions
- [ ] Create `lib/Service/ImageMimeValidator.php::validate(string $declaredType, string $bytes): void`
- [ ] Create `lib/Controller/ResourceController.php::upload` mapped to `POST /api/resources`
- [ ] Read raw input via `file_get_contents('php://input')` + `json_decode`
- [ ] Admin guard via `IGroupManager::isAdmin`
- [ ] 5 MB cap on decoded bytes (guard before `getimagesizefromstring` to bound memory)
- [ ] Cross-MIME check for raster types
- [ ] Delegate SVG sanitisation to `SvgSanitiser` (separate change)
- [ ] Persist via `IAppData->getFolder('resources')` (auto-create); filename `uniqid('resource_', true) . '.' . $ext`
- [ ] Define typed exceptions with stable error codes: `ForbiddenException`, `InvalidImageFormatException`, `InvalidDataUrlException`, `FileTooLargeException`, `MimeMismatchException`, `CorruptImageException`
- [ ] Map exceptions to standardised error envelope in controller (no raw `$e->getMessage()`)

## 2. Frontend

- [ ] Add `src/services/resourceService.js::uploadDataUrl(dataUrl): Promise<{url}>` wrapper
- [ ] Used by `image-widget` form, `link-button-widget` icon picker, `IconPicker`

## 3. Tests

- [ ] PHPUnit: 403 on non-admin
- [ ] PHPUnit: each rejection path returns the exact error code
- [ ] PHPUnit: oversize rejected before `getimagesizefromstring` (mock memory check)
- [ ] PHPUnit: MIME mismatch table (declared png, actual jpeg/gif/webp)
- [ ] PHPUnit: successful upload writes to app-data, returns URL
- [ ] PHPUnit: error responses NEVER contain `Exception` / stack trace strings
- [ ] Playwright: file upload from icon picker → URL appears in form

## 4. Quality

- [ ] `composer check:strict` passes
- [ ] OpenAPI updated for `POST /api/resources`
- [ ] Translation entries: `Personal dashboards are not enabled by your administrator`, `Failed to upload image`, error message strings (one per error code)
- [ ] Document v1 limits in admin help text: 5 MB cap, allowed types

## 5. Follow-ups (separate changes)

- [ ] `resource-serving` — GET endpoint
- [ ] `svg-sanitisation` — DOM-based whitelist sanitiser
- [ ] (Future) `resource-gc` — cleanup of orphaned resources
- [ ] (Future) `resource-acl` — per-resource access control if non-public assets are added
