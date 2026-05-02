# SVG sanitisation

## Why

SVG is the only upload type accepted by `resource-uploads` (REQ-RES-001) that can carry executable payloads — `<script>` elements, `<foreignObject>` with embedded HTML/JS, `on*=` event handlers, `javascript:` URLs, and `expression()` style values are all valid in the SVG spec but become stored XSS the moment a sanitised-looking SVG is rendered back into a logged-in user's browser. PNG/GIF/WebP have no executable surface; SVG does. Without server-side sanitisation any user with upload rights can plant a payload that fires for every viewer of the dashboard widget that references the resource.

The frontend already uses DOMPurify for the text widget but that runs client-side and can be bypassed by talking to the API directly. The fix has to live on the server, in the upload pipeline, and it has to be conservative — failing closed when in doubt — because every persisted byte gets handed back to other users' browsers.

## What Changes

- Add `lib/Service/SvgSanitiser.php` exposing `sanitize(string $bytes): ?string` — DOM-based whitelist sanitiser parsing via `DOMDocument::loadXML($bytes, LIBXML_NONET | LIBXML_NOENT)` so the parser can never fetch external entities (XXE) or follow network references.
- Whitelist 24 element types (geometry + structure + decoration); explicitly exclude `<script>`, `<foreignObject>`, `<iframe>`, `<embed>`, `<object>`.
- Whitelist 50 attribute types (geometry, styling, transform, gradient, href). Strip every other attribute.
- Strip ALL `on*` attributes regardless of whitelist (defence in depth — even if a future whitelist edit accidentally added one).
- Filter `href`/`xlink:href`: reject lowercased trimmed values starting with `javascript:` or `data:`.
- Filter `style` attribute: remove if value (lowercased) contains `expression(`, `javascript:`, or `url(data:`.
- Wire `SvgSanitiser::sanitize()` into `ResourceService::upload()` for the SVG branch — runs BEFORE the size check so the 5 MB cap (REQ-RES-003) is measured against the persisted (sanitised) byte count, not the original.
- Sanitised bytes (NOT the original) get persisted; null return surfaces HTTP 400 `{status:'error', error:'invalid_svg'}` and no file is written.
- Capability `resource-uploads` gains REQ-RES-009..013 (kept distinct from REQ-RES-001..005 in the foundation change and REQ-RES-006..008 in `resource-serving`).

## Impact

**Affected code:**

- `lib/Service/SvgSanitiser.php` — NEW. Pure service, no Nextcloud dependencies, single public method `sanitize(string $bytes): ?string`. Whitelists are private static const arrays.
- `lib/Service/ResourceService.php` — call `SvgSanitiser::sanitize($bytes)` for SVG type; on null throw `InvalidSvgException`; persist sanitised bytes; size check moves to AFTER sanitisation
- `lib/Exception/InvalidSvgException.php` — NEW. Mapped to HTTP 400 `{status:'error', error:'invalid_svg'}` in `ResourceController`.
- `lib/Controller/ResourceController.php` — catch `InvalidSvgException` and return 400 JSON
- Modifies `resource-uploads` capability (no other capability touched)

**Affected APIs:**

- `POST /api/resources` — new error mode `400 invalid_svg` for malformed or fully-stripped SVG
- No new routes; no breaking changes to existing success path
- Persisted SVG bytes may differ from uploaded bytes (clients should not assume byte-equality between upload and download)

**Dependencies:**

- PHP `ext-dom` and `ext-libxml` (already required by Nextcloud core) — no new composer or npm packages
- The whitelist is conservative on purpose; adding a new element/attribute is a deliberate code change with a security review checkbox documented in CONTRIBUTING.md

**Migration:**

- Zero schema impact. Existing on-disk SVGs are not retroactively sanitised — they predate this change and remain as-is. A separate one-shot OCC command to re-sanitise existing files is intentionally out of scope (operator-driven, not a code migration).
- Frontend separately uses DOMPurify for the text widget — different surface, different threat model. We could unify on a single sanitiser later but it is not blocking this change.
