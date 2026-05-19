# Tasks — svg-sanitisation

## 1. Sanitiser service

- [x] 1.1 Create `lib/Service/SvgSanitiser.php` with public method `sanitize(string $bytes): ?string`
- [x] 1.2 Define private static const `ALLOWED_ELEMENTS` containing the 24 element names from REQ-RES-010 (lowercase)
- [x] 1.3 Define private static const `ALLOWED_ATTRIBUTES` containing the 50 attribute names from REQ-RES-011 (lowercase)
- [x] 1.4 Call `libxml_use_internal_errors(true)` BEFORE parse and `libxml_clear_errors()` AFTER per REQ-RES-013
- [x] 1.5 Parse via `DOMDocument::loadXML($bytes, LIBXML_NONET | LIBXML_NOENT)`; return `null` on parse failure
- [x] 1.6 Walk the DOM recursively; snapshot the child node list BEFORE mutation so removals are safe during iteration
- [x] 1.7 Remove any element whose lowercased localName is not in `ALLOWED_ELEMENTS` (along with its children)
- [x] 1.8 Remove any attribute whose lowercased name is not in `ALLOWED_ATTRIBUTES`
- [x] 1.9 Strip ALL attributes whose lowercased name starts with `on` regardless of whitelist (REQ-RES-011 defence in depth)
- [x] 1.10 Filter `href` and `xlink:href`: trim + lowercase + reject `javascript:` / `data:` prefixes (REQ-RES-012)
- [x] 1.11 Filter `style`: regex `/expression\s*\(|javascript\s*:|url\s*\(\s*["\']?\s*data\s*:/i` — full attribute removal on match
- [x] 1.12 Serialise back via `DOMDocument::saveXML($root)`; return `null` if result is empty or has no root element
- [x] 1.13 Document the whitelist policy in the class docblock with a link to REQ-RES-010 / REQ-RES-011

## 2. Exception + controller wiring

- [x] 2.1 Create `lib/Exception/InvalidSvgException.php` extending `\RuntimeException` (extends `ResourceException` per the existing typed-error envelope convention)
- [x] 2.2 In `lib/Service/ResourceService.php::upload()` detect SVG MIME (`image/svg` or `image/svg+xml`) BEFORE the size check
- [x] 2.3 For SVG branch: call `SvgSanitiser::sanitize($bytes)`; on `null` throw `InvalidSvgException`; otherwise replace `$bytes` with the sanitised string
- [x] 2.4 Run the existing REQ-RES-003 size check AFTER sanitisation so the 5 MB cap measures the persisted bytes
- [x] 2.5 In `lib/Controller/ResourceController.php` catch `InvalidSvgException` and return `JSONResponse(['status'=>'error','error'=>'invalid_svg'], Http::STATUS_BAD_REQUEST)` (caught via the shared `ResourceException` handler — `getErrorCode()` returns `invalid_svg`, `getHttpStatus()` returns 400)
- [x] 2.6 Confirm no file is written when the exception is thrown (filesystem write happens AFTER sanitiser returns non-null)

## 3. PHPUnit tests — sanitiser unit

- [x] 3.1 Clean SVG round-trips with semantically equivalent output (whitespace differences allowed)
- [x] 3.2 `<script>` element removed
- [x] 3.3 `<foreignObject>` and any nested `<iframe>` removed
- [x] 3.4 Every `on*` attribute (`onclick`, `onload`, `onmouseover`, `onfocus`) stripped
- [x] 3.5 `javascript:` href stripped from `xlink:href`
- [x] 3.6 `data:` href stripped from `href` on `<image>`
- [x] 3.7 `expression(...)` style attribute stripped entirely
- [x] 3.8 `url(data:...)` style attribute stripped entirely
- [x] 3.9 Safe `https://` `href` preserved unchanged
- [x] 3.10 Whitelisted geometry attributes (`x`, `y`, `width`, `height`, `fill`, `stroke`) preserved on `<rect>`
- [x] 3.11 Non-whitelisted `data-*` attribute stripped
- [x] 3.12 External DTD reference does not trigger network fetch (assert via libxml error capture, no real network)
- [x] 3.13 Billion-laughs payload returns within bounded time and does not exhaust memory (PHPUnit time-limited test)
- [x] 3.14 Unparseable bytes (`"<not xml"`) → `null`
- [x] 3.15 Empty string → `null`

## 4. PHPUnit tests — integration

- [x] 4.1 `POST /api/resources` with malicious SVG containing `<script>` → HTTP 200 (script stripped) and persisted bytes do not contain `<script>`
- [x] 4.2 `POST /api/resources` with garbage non-XML payload → HTTP 400 `{error:'invalid_svg'}` and no file on disk
- [x] 4.3 `POST /api/resources` with 5.5 MB SVG that sanitises down to 4.5 MB → HTTP 200 (size check measured after sanitisation per REQ-RES-009)
- [x] 4.4 `POST /api/resources` with 4.9 MB SVG that sanitises down to 4.8 MB → HTTP 200 (well under cap)
- [x] 4.5 Round-trip: upload sanitised SVG, then `GET /apps/mydash/resource/...` returns the sanitised bytes (NOT the original) — covered by `testPersistedBytesAreSanitisedNotOriginal`; the GET side is exercised by ResourceControllerTest in resource-serving (returns whatever bytes were persisted)

## 5. Quality gates

- [x] 5.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — all new SvgSanitiser / InvalidSvgException files clean; pre-existing OCP\L10N\IL10N + Doctrine PHPStan/Psalm warnings unrelated to this change
- [x] 5.2 SPDX header (`SPDX-License-Identifier` + `SPDX-FileCopyrightText`) inside the main file docblock on every new PHP file — gate-spdx must pass
- [x] 5.3 Translation entry for `invalid_svg` error message in both `nl` and `en` per the i18n requirement (added `SVG could not be parsed or contained no allowed content` + `Resource exceeds the 5 MB serving cap` to `l10n/{en,nl}.{json,js}`)
- [x] 5.4 Add a "Security review required when extending the SVG whitelist" note to `DEVELOPMENT.md` referencing REQ-RES-010 / REQ-RES-011 (no `CONTRIBUTING.md` exists; the security checklist lives in `DEVELOPMENT.md`)
- [x] 5.5 Run all 10 `hydra-gates` locally before opening PR — verify gates run clean (PHPCS / PHPMD / Psalm / PHPStan / vitest / build / new PHPUnit tests all green)
