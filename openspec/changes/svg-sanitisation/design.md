# Design — svg-sanitisation

## Context

The `resource-uploads` capability (REQ-RES-001..008) accepts SVG uploads alongside PNG/GIF/WebP. Unlike bitmap formats, SVG is an XML-based format that can carry executable content — `<script>` elements, `<foreignObject>` with embedded HTML/JS, event-handler attributes (`onclick`, `onload`, etc.), and URL schemes like `javascript:` and `data:` are all valid in the SVG spec but become stored XSS when persisted and later rendered into a logged-in user's browser.

The application does not currently sanitise uploaded SVGs before persistence. Users with upload rights can plant a malicious payload that executes for every viewer of any dashboard widget referencing that resource.

Frontend already uses DOMPurify for the text widget, but that runs client-side and can be bypassed by direct API calls. The fix must live on the server, in the upload pipeline, and must fail closed — rejecting any SVG that cannot be safely parsed and stripped.

## Goals / Non-Goals

**Goals:**

- Prevent stored XSS via malicious SVG uploads by mandating server-side sanitisation before persistence.
- Detect and reject unparseable or fully-stripped SVG with HTTP 400.
- Use a conservative whitelist approach (allowed elements/attributes are explicit; everything else is removed).
- Prevent XML bombs (billion-laughs) and XXE attacks by parsing with `LIBXML_NONET | LIBXML_NOENT` flags.
- Ensure the persisted byte count (after sanitisation) is what gets validated against the 5 MB cap.

**Non-Goals:**

- Retroactively sanitise existing on-disk SVGs. (Operator-driven one-shot OCC command, out of scope.)
- Unify the sanitiser with DOMPurify on the frontend. (Different threat models; frontend continues using DOMPurify.)
- Provide whitelist extension via configuration. (Whitelist additions are deliberate code changes, not runtime config.)
- Detect or repair corrupted SVG files that do not parse as valid XML. (They are rejected.)

## Decisions

### D1: Whitelist (explicit allowed elements and attributes) over blacklist

**Decision**: Maintain two private static arrays — `ALLOWED_ELEMENTS` (24 names) and `ALLOWED_ATTRIBUTES` (50 names). Strip everything not in the whitelist; strip ALL `on*` attributes regardless of whitelist (defence in depth).

**Alternatives considered:**

- Blacklist approach (remove known-dangerous patterns): fragile; new threats emerge; hard to audit.
- Use an external library (e.g. `enshrined/svg-sanitize` or `svg-sanitizer`): introduces dependency and supply-chain risk; less transparent.
- Disable dangerous XML parsing: lossy; does not prevent element-based attacks.

**Rationale**: Whitelist is the gold standard for security boundaries. Explicit is easier to audit than implicit blacklists. The list is conservative (24 elements covers all geometry, grouping, decoration, and gradients; 50 attributes covers all styling and transforms) and deliberate additions get a code review checkbox in CONTRIBUTING.md.

### D2: DOMDocument with LIBXML_NONET | LIBXML_NOENT for parsing

**Decision**: Parse via `DOMDocument::loadXML($bytes, LIBXML_NONET | LIBXML_NOENT)` with `libxml_use_internal_errors(true)` before and `libxml_clear_errors()` after to suppress parse warnings from the HTTP response.

**Alternatives considered:**

- SimpleXML: less control over parsing flags; same underlying libxml.
- `file_get_contents()` + regex: fragile; XML is not a regular language.
- Stay on current approach (no parsing): XSS payloads pass through unchanged.

**Rationale**: DOMDocument gives fine-grained control over libxml flags. `LIBXML_NONET` prevents the parser from fetching external DTDs or entities (XXE prevention). `LIBXML_NOENT` substitutes entities so they don't amplify exponentially (billion-laughs prevention). The error handling prevents libxml warnings from leaking into error responses.

### D3: Sanitisation runs BEFORE size check

**Decision**: `ResourceService::upload()` detects SVG MIME type, calls `SvgSanitiser::sanitize()`, and if it returns non-null, measures the sanitised byte count against the 5 MB cap (REQ-RES-003). If the sanitiser returns `null`, throw `InvalidSvgException` immediately.

**Alternatives considered:**

- Size check first, then sanitise: allows 5 MB of junk XML to be parsed (DoS risk); sanitised size could be under the cap while original is over, creating ambiguity.
- Sanitise and size-check in parallel: adds complexity; no benefit over serial.

**Rationale**: Parsing and sanitisation are the expensive operations. Running size check after sanitisation ensures the measured size reflects what gets persisted, and allows an oversized malicious SVG to be rejected as unparseable rather than too large.

### D4: Fail closed — unparseable or fully-stripped SVG returns null

**Decision**: `SvgSanitiser::sanitize()` returns `null` if:
- XML parsing fails
- The parsed DOM has no root element after stripping disallowed nodes
- `DOMDocument::saveXML()` returns empty string

On `null`, `ResourceService` throws `InvalidSvgException` → HTTP 400 `{status: 'error', error: 'invalid_svg'}`. No file is written.

**Alternatives considered:**

- Return the empty or minimal string and accept it: risky; allows a payload to "succeed" by stripping to nothing.
- Log but continue on parse failure: allows malformed uploads to be silently persisted.

**Rationale**: If the sanitiser cannot parse or reconstruct the SVG, something is wrong. Fail closed prevents an attacker from using a corrupt payload as a covert channel.

### D5: URL and style attribute filtering with exact patterns

**Decision**: 
- `href` and `xlink:href`: reject if lowercased trimmed value starts with `javascript:` or `data:`. Attribute is removed (element is preserved).
- `style`: remove attribute entirely if value (lowercased) contains `expression(`, `javascript:`, or `url(data:` (regex: `/expression\s*\(|javascript\s*:|url\s*\(\s*["\']?\s*data\s*:/i`).

**Alternatives considered:**

- Allow `data:image/*` in href but not `data:text/*`: overcomplicates parsing; `data:image/` can still embed JS.
- Use a CSS parser for styles: overkill; regex is sufficient for the three patterns we care about.
- Whitelist safe URL schemes (http, https, relative): dangerous if the whitelist is incomplete or a new bypass is found.

**Rationale**: Rejecting `javascript:` and `data:` prefixes blocks the most common XSS vectors in URLs. The style regex is conservative — if there is any doubt about a style value, remove it rather than parse it.

### D6: Exception class and HTTP response mapping

**Decision**: Create `lib/Exception/InvalidSvgException` (extends `RuntimeException`). In `ResourceController::upload()`, catch `InvalidSvgException` and return `JSONResponse(['status' => 'error', 'error' => 'invalid_svg'], 400)`.

**Alternatives considered:**

- Return `BadRequest` with generic message: less informative to client; client cannot distinguish SVG parse failure from other upload errors.
- Throw `OCSException` with HTTP 400: works but `InvalidSvgException` is more specific and aligns with domain logic.

**Rationale**: Domain-specific exception + custom HTTP response gives the client clear signal that the upload failed due to SVG format, not a server error. Simplifies integration testing.

## Risks

- **XSS via whitelist bypass**: If the whitelist is extended carelessly (adding an `on*` attribute or a dangerous element), XSS becomes possible. Mitigation: code review checkbox in CONTRIBUTING.md; defence-in-depth `on*` stripping on every element regardless of whitelist.
- **DoS via large valid SVG**: A 5 MB valid SVG with deep nesting could consume CPU during parsing. Mitigation: PHP's `max_execution_time` and libxml's internal expansion limits (tested in task 9).
- **Breaking change for existing workflows**: If existing uploads relied on malicious SVG to stay intact, they will now fail. Mitigation: this is intentional (security hardening); existing files on disk are not retroactively modified.
- **Inconsistency with frontend DOMPurify rules**: If DOMPurify on the frontend uses a different whitelist than the server, a user could craft SVG that passes client-side but is stripped on the server. Mitigation: DOMPurify runs on text widget (different surface); users testing upload can verify via download round-trip.

## Migration

No data migration. Existing on-disk SVGs are not retroactively sanitised. They were persisted before this change and remain as-is; if a user downloads and re-uploads one, it will be sanitised.

The sanitiser is introduced as a new private static method `SvgSanitiser::sanitize()` and wired into `ResourceService::upload()` as a before-persistence hook. No schema or database changes.
