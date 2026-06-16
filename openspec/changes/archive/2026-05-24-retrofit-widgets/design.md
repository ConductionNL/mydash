# Design — retrofit-2026-05-24-widgets

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Context

The `widgets` capability is well-specced for end-user behaviour (REQ-WDG-001 .. REQ-WDG-023) and the widget data contract. During the 2026-05-24 coverage scan, two service classes were flagged as Bucket 2a — they exist and are exercised on every dashboard widget fetch, but no covering REQ describes their contract:

- `lib/Service/WidgetFormatter.php` — 7 methods (1 public `format()`, 6 private apply-* helpers). Already partially annotated (`format()` carries `@spec ...annotate-mydash...task-32`) but the underlying contract for the envelope shape + capability-interface fan-out is not in the spec.
- `lib/Service/WidgetItemLoader.php` — 4 methods (1 public `loadItems()`, 3 private dispatch/load helpers). Already partially annotated (`loadItems()` → task-33). The V2-preferred / V1-fallback dispatch strategy is not in the spec.

## Approach

Read each method, classify by observable behaviour:

| REQ | Methods | Behaviour |
|---|---|---|
| REQ-WDG-024 | `WidgetFormatter::format` + 5 `apply*` + `buildBaseData` | Capability-interface fan-out into the envelope shape |
| REQ-WDG-025 | `WidgetItemLoader::loadItems` + `loadSingleWidget` | Dispatch strategy: V2 preferred, V1 fallback, empty default |
| REQ-WDG-026 | `WidgetItemLoader::loadV1Items` + `loadV2Items` | Item serialization + empty-content messaging asymmetry |

Granularity rationale: the seven formatter methods all collaborate on one envelope. Splitting per-method would inflate to 7 REQs of low value. Collapsing into one envelope-contract REQ keeps the spec at the right abstraction level. The loader split (strategy REQ + serialization REQ) keeps the dispatch logic separable from the per-version item-shape concerns, which is where future divergence (e.g. V3) would land.

## Annotation strategy

Add `@spec openspec/changes/archive/2026-05-24-retrofit-widgets/tasks.md#task-N` tags. File-level tags on both files. Per-method tags on the 9 methods covered by this retrofit; existing `@spec ...annotate-mydash...` tags on `format()` and `loadItems()` are LEFT IN PLACE (they reference a different annotation cohort) and the new tags are appended.

## Notes — observed-but-suspicious

- `WidgetItemLoader::loadItems()` silent-skip of unknown widget IDs: callers cannot distinguish skipped-widget from no-items. Documented as observed behaviour; future-tightening TODO in spec Notes.
- V1/V2 empty-content message asymmetry: inherent in the Nextcloud `IAPIWidget` contract, not a bug in our loader. Documented for clarity.

## Source

- Coverage report: `openspec/coverage-report.json` generated 2026-05-24
- Umbrella issue: ConductionNL/mydash#292
- Bucket: 2a (capability-owned, missing REQ)
