---
retrofit: true
---

# Infrastructure Helpers Specification

## Purpose

The `infrastructure-helpers` capability collects small, pure (or nearly-pure) utility classes that are reused across multiple capability boundaries. These are not domain logic — they are primitives: string transformation, lookup, parameter extraction. Each helper has a single narrow contract, no persistence, and is invoked from multiple capability code paths. Grouping them here keeps each individual capability spec focused on domain behaviour rather than utility internals.

## Scope

This capability MUST contain only helpers that:

- Are stateless or depend only on injected Nextcloud framework interfaces
- Are used by at least two distinct capabilities (or are a primitive that obviously belongs nowhere else)
- Have no persistence side effects
- Have no domain-specific business rules — domain rules belong to the owning capability

## ADDED Requirements

### REQ-INFRA-001: Slug generation and validation

The system MUST provide a static `SlugGenerator` helper that converts arbitrary user-supplied names into URL-safe slugs and validates caller-supplied slugs against the pinned grammar. The slug grammar is `^[a-z0-9_-]+$` (lowercase ASCII alphanumerics, dash, underscore), maximum 128 characters (matching the `slug VARCHAR(128)` database column).

`slugify($name)` MUST:
1. Lowercase the input
2. Replace any run of whitespace with a single dash
3. Strip every character outside the slug grammar
4. Collapse consecutive dashes to a single dash
5. Trim leading and trailing dashes
6. Truncate to 128 characters (re-trim any trailing dash left by the cut)

`slugify()` MUST return the empty string when the input is empty or yields no legal characters; the caller decides whether to substitute a UUID fallback or reject the request.

`isValid($slug)` MUST return false for the empty string, for any string longer than 128 characters, and for any string not matching the grammar; true otherwise.

#### Scenario: Multi-word name becomes dashed slug

- WHEN `SlugGenerator::slugify('Q1 Campaigns')` is called
- THEN the result is `'q1-campaigns'`

#### Scenario: Special characters are stripped

- WHEN `SlugGenerator::slugify('My Dashboard! (v2)')` is called
- THEN the result is `'my-dashboard-v2'`

#### Scenario: Empty input returns empty string

- WHEN `SlugGenerator::slugify('***')` is called (no legal characters)
- THEN the result is `''`

#### Scenario: Over-length input is truncated without trailing dash

- GIVEN a 200-character input
- WHEN `SlugGenerator::slugify($input)` is called
- THEN the result is at most 128 characters AND does not end with `-`

#### Scenario: Validate rejects empty string

- WHEN `SlugGenerator::isValid('')` is called
- THEN the result is `false`

#### Scenario: Validate rejects uppercase

- WHEN `SlugGenerator::isValid('MyDashboard')` is called
- THEN the result is `false`

#### Scenario: Validate accepts grammar match

- WHEN `SlugGenerator::isValid('q1-campaigns_v2')` is called
- THEN the result is `true`

### REQ-INFRA-002: User attribute resolution and operator evaluation

The system MUST provide `UserAttributeResolver` to back attribute-based features (e.g. conditional visibility rules — REQ-VIS-008). The resolver MUST expose two methods:

- `getUserAttributeValue($userId, $attribute)` — return the requested attribute as a string (or null when the user does not exist, or when the attribute is not in the supported set). Supported attributes: `locale` (defaults to `'en'` when no per-user `core/lang` preference is set), `email`, `displayName`, `quota` (stringified). Unknown attribute names MUST return null.
- `evaluateOperator($userValue, $operator, $value)` — return whether the comparison matches. Supported operators: `equals`, `not_equals`, `contains`, `starts_with`, `ends_with`. Unknown operators MUST return false (no exception, no warning). When `$value` is null, the string-comparison operators treat it as an empty string.

#### Scenario: Resolve locale falls back to 'en'

- GIVEN a user with no `core/lang` preference set
- WHEN `getUserAttributeValue($userId, 'locale')` is called
- THEN the result is `'en'`

#### Scenario: Unknown attribute returns null

- WHEN `getUserAttributeValue($userId, 'twitter_handle')` is called
- THEN the result is `null`

#### Scenario: Non-existent user returns null

- WHEN `getUserAttributeValue('does-not-exist', 'email')` is called
- THEN the result is `null`

#### Scenario: Operator evaluation — equals match

- WHEN `evaluateOperator('en', 'equals', 'en')` is called
- THEN the result is `true`

#### Scenario: Operator evaluation — unknown operator returns false

- WHEN `evaluateOperator('en', 'regex_match', '^en')` is called
- THEN the result is `false` (no exception thrown)

#### Scenario: Operator evaluation — null comparison value treated as empty string

- WHEN `evaluateOperator('hello', 'contains', null)` is called
- THEN the result is `true` (every string contains the empty string)

### REQ-INFRA-003: Request data extraction for tile and placement forms

The system MUST provide `RequestDataExtractor` to convert `IRequest` parameters into typed array shapes for tile creation and placement updates. The extractor MUST:

- `extractTileData($request)` — return an associative array with the keys `title`, `icon`, `iconType`, `bgColor`, `txtColor`, `linkType`, `linkVal`, `gridX`, `gridY`. Every field has a default (`title='New Tile'`, `icon='icon-link'`, `iconType='class'`, `bgColor='#0082c9'`, `txtColor='#ffffff'`, `linkType='app'`, `linkVal=''`, `gridX=0`, `gridY=0`). `gridX` and `gridY` MUST be cast to integers. The request parameter name `textColor` MUST be mapped to the output key `txtColor`; the request parameter name `linkValue` MUST be mapped to the output key `linkVal`.
- `extractPlacementData($request)` — iterate over the fixed list of 16 placement field names (`gridX`, `gridY`, `gridWidth`, `gridHeight`, `isVisible`, `showTitle`, `customTitle`, `customIcon`, `styleConfig`, `tileTitle`, `tileIcon`, `tileIconType`, `tileBackgroundColor`, `tileTextColor`, `tileLinkType`, `tileLinkValue`) and return only the fields whose request parameter is not null. The output preserves request-parameter values as-is (no type casting).

Neither method validates field shapes or values — downstream callers are responsible for validation.

#### Scenario: extractTileData returns defaults for missing params

- GIVEN a request with no parameters set
- WHEN `RequestDataExtractor::extractTileData($request)` is called
- THEN the result is `{title: 'New Tile', icon: 'icon-link', iconType: 'class', bgColor: '#0082c9', txtColor: '#ffffff', linkType: 'app', linkVal: '', gridX: 0, gridY: 0}`

#### Scenario: extractTileData maps textColor → txtColor and linkValue → linkVal

- GIVEN a request with `textColor='#000'` and `linkValue='/foo'`
- WHEN `extractTileData($request)` is called
- THEN the result has `txtColor: '#000'` and `linkVal: '/foo'`

#### Scenario: extractPlacementData filters null fields

- GIVEN a request with only `gridX=2` set (all other 15 fields are null)
- WHEN `extractPlacementData($request)` is called
- THEN the result is `{gridX: 2}` (single key)

#### Scenario: extractPlacementData preserves all 16 fields when all are set

- GIVEN a request with all 16 placement fields set to non-null values
- WHEN `extractPlacementData($request)` is called
- THEN the result is an array of 16 entries, with values preserved as-is (no type casting)

## Non-Functional Requirements

- **Purity:** Helpers in this capability MUST be stateless or depend only on injected Nextcloud framework interfaces. No persistence side effects.
- **Reusability:** Helpers in this capability MUST be useful to at least two capability code paths.
- **Domain neutrality:** Helpers MUST NOT encode capability-specific business rules — those belong to the owning capability's spec.

## Notes

- `SlugGenerator::slugify()` empty-string return is intentional; callers (e.g. `DashboardFactory`) substitute a UUID. Documented to prevent regression to a "throw on empty" version.
- `UserAttributeResolver::evaluateOperator()` returning false for unknown operators is observed defensive behaviour. A future tightening could throw `InvalidArgumentException` so frontend bugs surface louder; deferred.
- `RequestDataExtractor::extractTileData()` defaults include the legacy `#0082c9` Nextcloud-blue background. This is preserved for backwards-compatibility with existing tiles; a wider tile-defaults review (e.g. brand-aligned colours) is out of scope here.
- `RequestDataExtractor::extractPlacementData()` skips type casting — `gridX` arrives as a string from the HTTP layer and is cast downstream in the placement mapper. The asymmetry with `extractTileData` (which casts) is observed-but-suspicious; flagged for future cleanup.
