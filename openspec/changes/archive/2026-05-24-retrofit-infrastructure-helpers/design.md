# Design — retrofit-2026-05-24-infrastructure-helpers

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Context

Three helper classes were flagged Bucket 2b by the 2026-05-24 coverage scan:

- `lib/Service/SlugGenerator.php` (2 methods) — pure static slug helpers used by `DashboardFactory` and the dashboard tree update path
- `lib/Service/UserAttributeResolver.php` (2 methods + ctor) — backs the attribute lookup half of `conditional-visibility`
- `lib/Controller/RequestDataExtractor.php` (2 methods) — extracts tile + placement form data from `IRequest`

The coverage scan notes (with the "no namespace-word warning" confirmation) that `infrastructure-helpers` is a behavioural grouping — these helpers don't fit any single existing capability.

## Why mint a new capability rather than `--extend`?

Per ADR-003 and the reverse-spec SKILL, the default is `--extend` to keep capability boundaries stable. Considered alternatives:

| Option | Why rejected |
|---|---|
| `--extend dashboards` (SlugGenerator) | Slug helper is conceptually reusable beyond dashboards; tiles + future named resources need it too |
| `--extend conditional-visibility` (UserAttributeResolver) | Resolver is a generic primitive (lookup + comparison) — the visibility logic that *uses* it stays in `conditional-visibility` |
| `--extend tiles` + `--extend widgets` (RequestDataExtractor) | Splitting per-method would duplicate the helper's contract across two specs and obscure that it's one helper class with two methods |
| `--extend` three different capabilities | Would scatter the helpers' shared architectural contract (statelessness, no persistence, no domain rules) across three specs |

Minting `infrastructure-helpers` keeps these helpers' shared shape documented in one place. The capability spec includes a `## Scope` section that prevents drift — future helpers that *do* fit a domain capability should land there, not here.

## Approach

Three REQs, one per file:

| REQ | File | Behaviour |
|---|---|---|
| REQ-INFRA-001 | `SlugGenerator` | Slug grammar, transform pipeline, empty-string-on-no-legal-chars, validation |
| REQ-INFRA-002 | `UserAttributeResolver` | 4 supported attributes (locale/email/displayName/quota), null-on-unknown-user-or-attr, 5 comparison operators, false-on-unknown-operator |
| REQ-INFRA-003 | `RequestDataExtractor` | Tile data shape with defaults + key remaps (textColor → txtColor, linkValue → linkVal), placement data null-filter for 16 fixed fields |

## Annotation strategy

File-level `@spec` tags on all three files. Per-method tags on each public method covered. Existing `@spec ...annotate-mydash...task-12` on `UserAttributeResolver::getUserAttributeValue()` is LEFT IN PLACE and the new tag is appended.

## Notes — observed-but-suspicious

- `RequestDataExtractor::extractPlacementData()` skips type casting (`gridX` stays a string), asymmetric with `extractTileData()` which casts. Documented for future cleanup.
- `UserAttributeResolver::evaluateOperator()` silently returns false for unknown operators. Could throw `InvalidArgumentException` so frontend bugs surface louder; deferred.
- `SlugGenerator::slugify('')` returns empty — caller responsibility to fall back to UUID. Intentional, documented to prevent regression.

## Source

- Coverage report: `openspec/coverage-report.json` generated 2026-05-24
- Umbrella issue: ConductionNL/mydash#292
- Bucket: 2b (no owning capability)
- Cluster label: `infrastructure-helpers` (behavioural grouping; not a namespace word)
