# SVG sanitisation

Extend `resource-uploads` with a server-side SVG sanitiser. SVG is the only allowed upload type that can carry executable payloads (`<script>`, `<foreignObject>`, `on*=` event handlers, `javascript:` URLs). Every uploaded SVG MUST pass through a DOM-based whitelist sanitiser before being persisted; any SVG that fails to sanitise MUST be rejected.

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Beheer / Tab: Compliance & Security

**Rationale:** Security policy  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Affected code units

- `lib/Service/SvgSanitiser.php` — DOM-based whitelist sanitiser
- `lib/Service/ResourceService.php` — calls `SvgSanitiser::sanitize($bytes)` for SVG uploads (replaces direct persistence)
- Modifies `resource-uploads` capability

## Why a delta

The sanitiser is a focused, non-trivial security primitive that lives logically alongside the upload flow. Folding it into `resource-uploads` keeps the storage path's security model in one capability. A standalone capability would over-fragment the feature surface.

## Approach

- Use PHP `DOMDocument` with `LIBXML_NONET | LIBXML_NOENT` so the parser cannot fetch external entities (XXE) or follow network references.
- Whitelist allowed elements (geometry + structure + decoration; explicitly exclude `<script>`, `<foreignObject>`).
- Whitelist allowed attributes (geometry, styling, transform, gradient, href).
- Strip ALL `on*` attributes regardless of whitelist (defence in depth — `onclick`, `onload`, etc.).
- Filter `href`/`xlink:href`: reject values starting with `javascript:` or `data:`.
- Filter `style` attribute: reject values containing `expression(`, `javascript:`, or `url(data:`.
- Fail-closed: any parse failure or empty result → return `null` from sanitiser, controller surfaces 400.

## Notes

- The whitelist is conservative on purpose. Adding a new element/attribute is a deliberate code change with a security review checkbox.
- Frontend separately uses DOMPurify for the text widget — different surface, different threat model. We could unify on a single library later.
- SVG sanitisation is run BEFORE the size check so the sanitised byte count is what's measured against the 5 MB cap.



## Tasks

# Tasks — svg-sanitisation

## 1. Backend

- [ ] Create `lib/Service/SvgSanitiser.php::sanitize(string $bytes): ?string`
- [ ] Use `DOMDocument` with `libxml_use_internal_errors(true)` + `LIBXML_NONET | LIBXML_NOENT`
- [ ] Whitelist constants `ALLOWED_ELEMENTS` and `ALLOWED_ATTRIBUTES` (private static arrays per REQ-RES-010 / REQ-RES-011)
- [ ] Recursive node walk: snapshot child list before mutation (handles removals safely)
- [ ] Strip ALL `on*` attributes regardless of whitelist (defence in depth)
- [ ] Filter href/xlink:href: reject `javascript:` and `data:` prefixes (case-insensitive, after trim)
- [ ] Filter style attribute: regex match `expression\s*\(|javascript\s*:|url\s*\(\s*["\']?\s*data\s*:` removes the whole attribute
- [ ] Return `null` on parse failure or empty result
- [ ] Wire into `ResourceService::upload` for SVG type — sanitise BEFORE size check
- [ ] On `null` return, throw `InvalidSvgException` with `error: 'invalid_svg'`

## 2. Tests

- [ ] PHPUnit: clean SVG round-trips unchanged (or only whitespace differences)
- [ ] PHPUnit: `<script>` element stripped
- [ ] PHPUnit: `<foreignObject>` stripped
- [ ] PHPUnit: every `on*` attribute (`onclick`, `onload`, `onmouseover`) stripped
- [ ] PHPUnit: `javascript:` href stripped
- [ ] PHPUnit: `data:` href stripped
- [ ] PHPUnit: `expression(...)` style stripped
- [ ] PHPUnit: `url(data:...)` style stripped
- [ ] PHPUnit: external DTD reference does not trigger network fetch (mock or use `LIBXML_NONET` assert)
- [ ] PHPUnit: billion-laughs payload does not exhaust memory (timeout-bounded)
- [ ] PHPUnit: unparseable bytes → null
- [ ] PHPUnit: integration via `POST /api/resources` with malicious SVG → 400 `invalid_svg`, no file written

## 3. Quality

- [ ] `composer check:strict` passes
- [ ] Document the whitelist policy in `SvgSanitiser` class docblock with link to REQ-RES-010/011
- [ ] Adding to the whitelist is a "security review required" change — note in CONTRIBUTING.md
- [ ] Translation entry: error message for `invalid_svg`
