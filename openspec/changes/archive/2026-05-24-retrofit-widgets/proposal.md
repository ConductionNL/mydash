# Retrofit — widgets (Bucket 2a)

Describes observed behavior of 9 methods across 2 widget-service files under the `widgets` capability as 3 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Service/WidgetFormatter.php::format()` (already partially annotated)
- `lib/Service/WidgetFormatter.php::buildBaseData()`
- `lib/Service/WidgetFormatter.php::applyIconUrl()`
- `lib/Service/WidgetFormatter.php::applyApiVersions()`
- `lib/Service/WidgetFormatter.php::applyButtons()`
- `lib/Service/WidgetFormatter.php::applyOptions()`
- `lib/Service/WidgetFormatter.php::applyReloadInterval()`
- `lib/Service/WidgetItemLoader.php::loadItems()` (already partially annotated)
- `lib/Service/WidgetItemLoader.php::loadSingleWidget()`
- `lib/Service/WidgetItemLoader.php::loadV1Items()`
- `lib/Service/WidgetItemLoader.php::loadV2Items()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces any observed-but-suspicious behavior

The existing `widgets` spec covers the widget data contract end-to-end (REQ-WDG-001 .. REQ-WDG-023) but does not describe two service-class abstractions that implement that contract:

1. **`WidgetFormatter`** — converts Nextcloud `IWidget` instances into the API response array shape. The existing spec describes the *shape* of the API payload but not the formatter abstraction that maps capability-interfaces (`IIconWidget`, `IButtonWidget`, `IOptionWidget`, `IReloadableWidget`, `IAPIWidget`, `IAPIWidgetV2`) to envelope fields.
2. **`WidgetItemLoader`** — lazy loader that dispatches between V1 (`IAPIWidget::getItems`) and V2 (`IAPIWidgetV2::getItemsV2`) widget item APIs. The existing spec mentions V1/V2 widgets but not the loader strategy that fans out a list of widget IDs to per-widget item fetches.

This retrofit adds:
- REQ-WDG-024 — Widget formatter envelope contract (capability-interface fan-out)
- REQ-WDG-025 — Widget item loader strategy (V2-preferred, V1-fallback, empty default)
- REQ-WDG-026 — Widget item serialization and empty-content messaging

## Notes

- `WidgetFormatter::applyButtons()` calls `getWidgetButtons(userId: $userId)`; the underlying `IButtonWidget::getWidgetButtons()` is a per-user API, so button visibility may depend on permissions inside the widget implementation. The REQ documents the observed call site but does not constrain individual widget impls.
- `WidgetItemLoader::loadItems()` silently skips unknown widget IDs (the `isset($widgets[$widgetId]) === false` guard). Documented as observed behaviour — callers cannot distinguish "no items" from "widget not registered".
- Items are serialised via `jsonSerialize()` (V2) or implicit jsonSerialize (V1); empty-content messaging differs between V1 (always empty string) and V2 (read from `WidgetItems` getter). Documented as observed behaviour.

Source: `openspec/coverage-report.md` generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
