# Tasks — svg-sanitisation

## Tasks

- [ ] Task 1: Ship `lib/Service/SvgSanitiser::sanitize(string $bytes): ?string` with `ALLOWED_ELEMENTS` (24 names per REQ-RES-010) and `ALLOWED_ATTRIBUTES` (50 names per REQ-RES-011) declared as private static consts, plus a class-docblock policy note linking the REQs
- [ ] Task 2: Parser hygiene — `libxml_use_internal_errors(true)` before parse + `libxml_clear_errors()` after; parse via `DOMDocument::loadXML($bytes, LIBXML_NONET | LIBXML_NOENT)`; return `null` on parse failure (REQ-RES-013)
- [ ] Task 3: DOM walker — snapshot child lists before mutation; drop elements whose lowercased localName isn't whitelisted (with their subtrees); drop attributes whose lowercased name isn't whitelisted; defence-in-depth strip every `on*` attribute regardless of whitelist
- [ ] Task 4: URL/style filters — trim+lowercase `href`/`xlink:href` and reject `javascript:`/`data:`; remove `style` matching `/expression\s*\(|javascript\s*:|url\s*\(\s*["\']?\s*data\s*:/i` entirely (REQ-RES-011/012)
- [ ] Task 5: Re-serialise via `DOMDocument::saveXML($root)`; return `null` on empty output or missing root element
- [ ] Task 6: Add `lib/Exception/InvalidSvgException` (`extends \RuntimeException`) and wire it into `ResourceService::upload()` — detect `image/svg(+xml)` MIME BEFORE the size check, run sanitiser, throw on `null`, replace `$bytes` with the sanitised string, then run the existing 5MB size cap on the sanitised bytes (REQ-RES-009)
- [ ] Task 7: `ResourceController` catches `InvalidSvgException` and returns `JSONResponse(['status'=>'error','error'=>'invalid_svg'], 400)`; ensure no file is written when the exception fires (filesystem write happens after sanitiser returns non-null)
- [ ] Task 8: PHPUnit unit coverage on the sanitiser — clean round-trip; `<script>` stripped; `<foreignObject>` + nested `<iframe>` stripped; every `on*` handler stripped; `javascript:` href stripped from `xlink:href`; `data:` href stripped from `<image>`; `expression(...)` + `url(data:...)` styles stripped; safe `https://` href preserved; whitelisted geometry attrs preserved on `<rect>`; non-whitelisted `data-*` stripped
- [ ] Task 9: PHPUnit defensive coverage — external DTD does not trigger a network fetch; billion-laughs payload returns within bounded time/memory; unparseable bytes and empty string both return `null`
- [ ] Task 10: PHPUnit integration — `POST /api/resources` with `<script>` → 200 (script removed); garbage non-XML → 400 `{error:'invalid_svg'}` + no file; 5.5MB SVG that sanitises to 4.5MB → 200 (size check after sanitisation); 4.9MB→4.8MB → 200; GET round-trip returns the sanitised bytes (NOT the original)
- [ ] Task 11: Quality gates — `composer check:strict`, SPDX-in-docblock on new PHP files, `nl`+`en` translation for `invalid_svg`, all 10 hydra-gates green
- [ ] Task 12: Add a "Security review required when extending the SVG whitelist" note to `CONTRIBUTING.md` referencing REQ-RES-010 / REQ-RES-011

## Verification

`openspec validate` exits clean. PHPUnit suite green incl. the malicious-payload cases; uploaded SVGs on disk no longer contain stripped vectors.

## Tests (company-wide ADR-009)

PHPUnit per Tasks 8–10. No new endpoint surface (existing `/api/resources` only); Newman additions not required.

## Documentation (company-wide ADR-010)

CONTRIBUTING.md note per Task 12; changelog entry noting SVG uploads are now sanitised on ingest.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` for the `invalid_svg` error string.
