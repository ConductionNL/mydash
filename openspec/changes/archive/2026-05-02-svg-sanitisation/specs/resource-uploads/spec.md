---
capability: resource-uploads
delta: true
status: draft
---

# Resource Uploads — Delta from change `svg-sanitisation`

## ADDED Requirements

### Requirement: SVG sanitiser is mandatory before persistence (REQ-RES-009)

The system MUST pass every uploaded SVG (declared `image/svg` or `image/svg+xml`) through `SvgSanitiser::sanitize(string $bytes): ?string` before any persistence. The sanitiser MUST return the sanitised SVG string on success OR `null` on parse failure / fully-stripped content. When `null` is returned, `ResourceService` MUST reject the upload with HTTP 400 `{status: 'error', error: 'invalid_svg'}` and MUST NOT write any file. The sanitised bytes (NOT the original) MUST be what gets persisted. Size validation (REQ-RES-003) MUST run AFTER sanitisation so the measured size is the persisted size.

#### Scenario: Clean SVG accepted

- GIVEN a logged-in user "alice"
- WHEN she sends `POST /api/resources` with body `{"base64": "data:image/svg+xml;base64,<valid sanitisable SVG>"}`
- THEN the sanitiser MUST return the (possibly modified) SVG string
- AND `ResourceService` MUST persist the sanitised bytes (NOT the original bytes)
- AND the response MUST be HTTP 200 with `{status: 'success', url: '/apps/mydash/resource/resource_<uniqid>.svg', ...}`

#### Scenario: Sanitiser strips malicious content but accepts upload

- GIVEN a logged-in user "alice"
- WHEN she sends `POST /api/resources` with an SVG containing `<script>alert(1)</script><circle r="5"/>`
- THEN the sanitiser MUST strip the `<script>` element
- AND the upload MUST proceed with HTTP 200
- AND the persisted SVG MUST NOT contain `<script>` or `alert`
- NOTE: the sanitiser strips disallowed elements rather than failing; failure (`null`) is reserved for unparseable XML

#### Scenario: Unparseable bytes rejected

- GIVEN a logged-in user "alice"
- WHEN she sends `POST /api/resources` with body `{"base64": "data:image/svg+xml;base64,<garbage non-XML>"}`
- THEN `SvgSanitiser::sanitize` MUST return `null`
- AND the system MUST return HTTP 400 with body `{status: 'error', error: 'invalid_svg'}`
- AND no file MUST be written to disk

#### Scenario: Size cap measured after sanitisation

- GIVEN an SVG whose original byte length is 5.5 MB (over the REQ-RES-003 5 MB cap) but whose sanitised length is 4.5 MB after stripping disallowed elements
- WHEN the upload is processed
- THEN size validation MUST run on the 4.5 MB sanitised payload
- AND the upload MUST succeed (HTTP 200)

### Requirement: Whitelist of allowed SVG elements (REQ-RES-010)

The sanitiser MUST allow ONLY these element names (lowercase): `svg`, `g`, `path`, `rect`, `circle`, `ellipse`, `line`, `polyline`, `polygon`, `text`, `tspan`, `defs`, `clippath`, `use`, `image`, `style`, `lineargradient`, `radialgradient`, `stop`, `mask`, `pattern`, `symbol`, `title`, `desc`. Any other element (including `script`, `foreignObject`, `iframe`, `embed`, `object`) MUST be removed from the parsed DOM tree (along with all its children) before serialisation.

#### Scenario: script element removed

- GIVEN input `<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="5"/></svg>`
- WHEN the sanitiser processes the input
- THEN the output MUST contain `<circle r="5"/>` (or equivalent serialisation)
- AND the output MUST NOT contain `<script>` OR the substring `alert`

#### Scenario: foreignObject and nested iframe removed

- GIVEN input contains `<foreignObject><iframe src="http://attacker"/></foreignObject>` inside an `<svg>` root
- WHEN the sanitiser processes the input
- THEN both `<foreignObject>` and the nested `<iframe>` MUST be removed
- AND the output MUST NOT contain the substring `attacker`

#### Scenario: Whitelisted elements preserved with their structure

- GIVEN input is a complex SVG containing `<g>`, `<path>`, `<defs>`, `<lineargradient>`
- WHEN the sanitiser processes the input
- THEN all four elements MUST remain in the output (modulo attribute filtering per REQ-RES-011)
- AND parent-child relationships MUST be preserved

### Requirement: Whitelist of allowed SVG attributes (REQ-RES-011)

The sanitiser MUST allow ONLY these attribute names (lowercase): `id`, `class`, `style`, `d`, `x`, `y`, `x1`, `y1`, `x2`, `y2`, `cx`, `cy`, `r`, `rx`, `ry`, `width`, `height`, `viewbox`, `fill`, `stroke`, `stroke-width`, `stroke-linecap`, `stroke-linejoin`, `stroke-dasharray`, `stroke-dashoffset`, `stroke-opacity`, `fill-opacity`, `opacity`, `transform`, `points`, `font-size`, `font-family`, `font-weight`, `text-anchor`, `dominant-baseline`, `dx`, `dy`, `clip-path`, `mask`, `filter`, `gradientunits`, `gradienttransform`, `offset`, `stop-color`, `stop-opacity`, `patternunits`, `preserveaspectratio`, `xmlns`, `xmlns:xlink`, `version`, `href`, `xlink:href`. Any other attribute MUST be removed from each element regardless of element name.

In addition (defence in depth), ALL attributes whose lowercased name starts with `on` MUST be removed unconditionally — even if a future whitelist edit accidentally allowed an `on*` name.

#### Scenario: Event-handler attributes always stripped

- GIVEN input `<circle onclick="alert(1)" onload="x()" r="5"/>`
- WHEN the sanitiser processes the input
- THEN the output `<circle>` MUST NOT carry `onclick` OR `onload`
- AND `r="5"` MUST be preserved on the same element

#### Scenario: Non-whitelisted attribute stripped

- GIVEN input `<circle data-payload="x" r="5"/>`
- WHEN the sanitiser processes the input
- THEN the `data-payload` attribute MUST be removed
- AND `r="5"` MUST remain

#### Scenario: Whitelisted geometry attributes preserved

- GIVEN input `<rect x="10" y="20" width="100" height="50" fill="red" stroke="black"/>`
- WHEN the sanitiser processes the input
- THEN all six attributes MUST remain on the output element

### Requirement: URL-bearing attributes filtered (REQ-RES-012)

For attributes `href` and `xlink:href`, the sanitiser MUST reject values whose lowercased trimmed prefix matches `javascript:` OR `data:`. Rejected attributes MUST be removed (the rest of the element is preserved). For the `style` attribute, the sanitiser MUST remove the attribute entirely if its value (lowercased) contains any of: `expression(`, `javascript:`, `url(data:`.

#### Scenario: javascript: href stripped

- GIVEN input `<use xlink:href="javascript:alert(1)"/>` (where `<use>` is in the element whitelist)
- WHEN the sanitiser processes the input
- THEN the `xlink:href` attribute MUST be removed
- AND the `<use>` element MUST remain (with no `xlink:href`)

#### Scenario: data: href stripped

- GIVEN input `<image href="data:image/svg+xml;base64,..."/>`
- WHEN the sanitiser processes the input
- THEN the `href` attribute MUST be removed
- AND the `<image>` element MUST remain
- NOTE: an `<image>` with no `href` renders nothing — acceptable

#### Scenario: style with expression() stripped

- GIVEN input `<rect style="width: expression(alert(1))" width="10" height="10"/>`
- WHEN the sanitiser processes the input
- THEN the `style` attribute MUST be removed entirely
- AND `width="10"` and `height="10"` MUST remain

#### Scenario: style with url(data:) stripped

- GIVEN input `<g style="background: url(data:text/html,<script>alert(1)</script>)"><path d="M0 0"/></g>`
- WHEN the sanitiser processes the input
- THEN the `style` attribute MUST be removed entirely
- AND the `<g>` and child `<path>` MUST remain

#### Scenario: Safe http(s) href preserved

- GIVEN input `<image href="https://example.com/logo.png"/>`
- WHEN the sanitiser processes the input
- THEN `href="https://example.com/logo.png"` MUST remain unchanged
- NOTE: only `javascript:` and `data:` prefixes are filtered; http/https/relative URLs pass through

### Requirement: XXE and network-fetch protection (REQ-RES-013)

The sanitiser MUST parse SVG via `DOMDocument::loadXML($bytes, LIBXML_NONET | LIBXML_NOENT)`. The `LIBXML_NONET` flag MUST be set so the parser cannot fetch external entities or DTDs. The `LIBXML_NOENT` flag substitutes entities so they do not amplify recursively. The sanitiser MUST call `libxml_use_internal_errors(true)` BEFORE the parse and `libxml_clear_errors()` AFTER, to prevent malformed SVG from emitting libxml warnings into the HTTP response.

#### Scenario: External DTD reference does not fetch

- GIVEN input declares an external DTD reference (e.g. `<!DOCTYPE svg SYSTEM "http://attacker/evil.dtd">` followed by an SVG body)
- WHEN the sanitiser parses the input
- THEN no network request MUST be issued for `http://attacker/evil.dtd`
- AND parsing MUST proceed without fetching the DTD

#### Scenario: Billion-laughs entity expansion bounded

- GIVEN input declares nested entities expanding exponentially (XML billion-laughs payload)
- WHEN the sanitiser parses the input
- THEN the parser MUST NOT exhaust memory
- AND the call MUST return within a bounded time
- NOTE: `LIBXML_NOENT` substitutes entities once but libxml has internal expansion limits that protect against billion-laughs in modern PHP/libxml — verified in tests

#### Scenario: libxml warnings are suppressed from response

- GIVEN input is malformed XML that triggers libxml parse warnings
- WHEN the sanitiser processes the input
- THEN `libxml_use_internal_errors(true)` MUST have been called before parse
- AND `libxml_clear_errors()` MUST have been called after parse
- AND no libxml warning text MUST appear in the HTTP response body
