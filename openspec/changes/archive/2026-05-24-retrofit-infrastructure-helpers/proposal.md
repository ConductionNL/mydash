# Retrofit — infrastructure-helpers (Bucket 2b)

Mints a new `infrastructure-helpers` capability for three cross-cutting helper classes that have no single owning capability. Code already exists — this change retroactively specifies the helpers' shared contract.

## Affected code units

- `lib/Service/SlugGenerator.php::slugify()`
- `lib/Service/SlugGenerator.php::isValid()`
- `lib/Service/UserAttributeResolver.php::getUserAttributeValue()` (already partially annotated)
- `lib/Service/UserAttributeResolver.php::evaluateOperator()`
- `lib/Controller/RequestDataExtractor.php::extractTileData()`
- `lib/Controller/RequestDataExtractor.php::extractPlacementData()`

(Constructors excluded — pure DI plumbing.)

## Approach

Three small helper classes share the same architectural shape: pure / nearly-pure utility methods, no persistence, used from multiple capabilities. Coverage scan flagged them as Bucket 2b because:

- `SlugGenerator` is referenced by `dashboards` (REQ-DASH-024 inline) but the helper is conceptually reusable; it would also support `tiles`, `widgets`, or any future named-resource flow.
- `UserAttributeResolver` is currently used by `conditional-visibility` (REQ-VIS-008 inline) but its contract (lookup user attributes via Nextcloud APIs, evaluate string comparison operators) is a generic primitive.
- `RequestDataExtractor` extracts per-form data shapes for `tiles` (`extractTileData`) and `widgets`/placements (`extractPlacementData`), spanning two capabilities.

Rather than splitting these into capability-specific REQs (which would duplicate the contract or fragment it), this change mints a thin `infrastructure-helpers` capability with three REQs — one per file/concern. Future cross-capability helpers can be appended here.

This retrofit adds:
- REQ-INFRA-001 — Slug generation and validation contract
- REQ-INFRA-002 — User attribute resolution and operator evaluation
- REQ-INFRA-003 — Request data extraction for tile + placement forms

## Notes

- `SlugGenerator::slugify()` returns an empty string when the input yields no legal characters; the caller (DashboardFactory) is responsible for substituting a UUID fallback. The REQ documents the empty-string return as observed behaviour.
- `UserAttributeResolver::getUserAttributeValue()` defaults to `'en'` for `locale` when no per-user lang preference is set. Documented.
- `UserAttributeResolver::evaluateOperator()` returns `false` for unknown operators (no exception, no warning). Documented as defensive behaviour.
- `RequestDataExtractor::extractTileData()` ships default values for every field including the legacy `#0082c9` Nextcloud-blue background. The defaults are observed — keep as-is until a wider tile-defaults review.
- `RequestDataExtractor::extractPlacementData()` does NOT validate field types; the caller (TileApiController / WidgetApiController) is responsible for downstream validation. Documented as a precondition.

Source: `openspec/coverage-report.md` generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
