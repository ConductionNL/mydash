# Tasks — resource-uploads

## Tasks

- [ ] Task 1: Create `lib/Service/ResourceService::upload(string $base64DataUrl): array` returning `{url, name, size}` or throwing typed exceptions; create `lib/Service/ImageMimeValidator::validate(string $declaredType, string $bytes): void`
- [ ] Task 2: Define typed exceptions with stable error codes — `ForbiddenException`, `InvalidImageFormatException`, `InvalidDataUrlException`, `FileTooLargeException`, `MimeMismatchException`, `CorruptImageException`
- [ ] Task 3: Add `lib/Controller/ResourceController::upload` mapped to `POST /api/resources`; read raw input via `file_get_contents('php://input') + json_decode`; admin guard via `IGroupManager::isAdmin`
- [ ] Task 4: 5MB cap on decoded bytes (guard BEFORE `getimagesizefromstring` to bound memory); cross-MIME check for raster types via `ImageMimeValidator`; delegate SVG sanitisation to the `SvgSanitiser` (separate change)
- [ ] Task 5: Persist via `IAppData->getFolder('resources')` (auto-create); filename `uniqid('resource_', true) . '.' . $ext`
- [ ] Task 6: Map exceptions to a standardised error envelope in the controller — never surface raw `$e->getMessage()` (every response uses the stable error code)
- [ ] Task 7: Add `src/services/resourceService.js::uploadDataUrl(dataUrl): Promise<{url}>` wrapper consumed by `image-widget` form, `link-button-widget` icon picker, and `IconPicker`
- [ ] Task 8: PHPUnit — 403 on non-admin; each rejection path returns the exact error code; oversize rejected before `getimagesizefromstring` (mock memory check); MIME mismatch table (declared png, actual jpeg/gif/webp); successful upload writes to app-data and returns the URL
- [ ] Task 9: PHPUnit — error responses NEVER contain `Exception` / stack-trace strings (regression guard against raw message leakage)
- [ ] Task 10: Playwright — file upload from icon picker → URL appears in the form on success; non-admin attempt surfaces the 403 message via the existing toast
- [ ] Task 11: Quality — `composer check:strict` passes; OpenAPI updated for `POST /api/resources`; SPDX-in-docblock on every new PHP file
- [ ] Task 12: i18n — `nl_NL` + `en_US` for `Personal dashboards are not enabled by your administrator`, `Failed to upload image`, plus an error-message string per stable error code; document v1 limits in admin help text (5MB cap, allowed types)
- [ ] Task 13: File follow-ups (separate changes) — `resource-serving` GET endpoint (in flight), `svg-sanitisation` DOM whitelist (in flight), future `resource-gc` (orphan cleanup), future `resource-acl` (per-resource access control if non-public assets are added)

## Verification

`openspec validate` exits clean. Rejection paths return their stable error code and no exception text leaks; admin-only enforcement holds.

## Tests (company-wide ADR-009)

PHPUnit per Tasks 8–9; Playwright per Task 10. Newman/Postman updated for the new endpoint.

## Documentation (company-wide ADR-010)

Admin help text per Task 12; changelog entry covering the new endpoint and the v1 limits.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 12.
