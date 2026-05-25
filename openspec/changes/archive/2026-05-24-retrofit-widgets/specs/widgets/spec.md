---
retrofit_extensions:
  - REQ-WDG-024
  - REQ-WDG-025
  - REQ-WDG-026
---

## ADDED Requirements

### REQ-WDG-024: Widget formatter envelope contract

The system MUST format every Nextcloud `IWidget` into a canonical envelope via `WidgetFormatter::format()`. The envelope MUST include the base fields (`id`, `title`, `order`, `iconClass`, `iconUrl`, `widgetUrl`, `itemIconsRound`, `itemApiVersions`, `reloadInterval`, `buttons`) and MUST conditionally enrich them based on which Nextcloud capability interfaces the widget implements:

- `IIconWidget` → populate `iconUrl` via `getIconUrl()`
- `IAPIWidget` → append `1` to `itemApiVersions`
- `IAPIWidgetV2` → append `2` to `itemApiVersions`
- `IButtonWidget` → populate `buttons[]` via `getWidgetButtons($userId)`, each button serialized as `{type, text, link}`
- `IOptionWidget` → set `itemIconsRound` from `getWidgetOptions()->withRoundItemIcons()`
- `IReloadableWidget` → set `reloadInterval` from `getReloadInterval()`

Widgets that implement none of the optional capability interfaces MUST receive the base envelope unchanged (`itemApiVersions: []`, `buttons: []`, `reloadInterval: 0`, `iconUrl: null`, `itemIconsRound: false`).

#### Scenario: Format a basic widget with no optional capabilities

- GIVEN an `IWidget` that does not implement any of the optional capability interfaces
- WHEN `WidgetFormatter::format($widget, $userId)` is called
- THEN the result has `itemApiVersions: []`, `buttons: []`, `reloadInterval: 0`, `iconUrl: null`, `itemIconsRound: false`
- AND the result still includes the base `id`, `title`, `order`, `iconClass`, `widgetUrl` from the widget

#### Scenario: Format a widget that implements both API versions

- GIVEN an `IWidget` that implements both `IAPIWidget` and `IAPIWidgetV2`
- WHEN `WidgetFormatter::format($widget, $userId)` is called
- THEN the result has `itemApiVersions: [1, 2]` (in declaration order, V1 first)

#### Scenario: Format a button widget includes per-button serialization

- GIVEN an `IButtonWidget` whose `getWidgetButtons($userId)` returns two buttons (Add: `link=/add`, Settings: `link=/settings`)
- WHEN `WidgetFormatter::format($widget, $userId)` is called
- THEN `result.buttons` is `[{type: 'Add', text: '...', link: '/add'}, {type: 'Settings', text: '...', link: '/settings'}]`

### REQ-WDG-025: Widget item loader strategy

The system MUST load widget items via `WidgetItemLoader::loadItems()`, which accepts a map of registered widgets, a user ID, a list of widget IDs to load, and a per-widget item limit (default 7). For each requested widget ID:

- If the widget is NOT in the registered-widgets map, the loader MUST silently skip it (no entry in the result)
- If the widget implements `IAPIWidgetV2`, the loader MUST use the V2 API (`getItemsV2(userId, since: null, limit)`)
- Else if the widget implements `IAPIWidget`, the loader MUST fall back to the V1 API (`getItems(userId, since: null, limit)`)
- Else the loader MUST return the empty-default envelope `{items: [], emptyContentMessage: '', halfEmptyContentMessage: ''}` for that widget ID

The result MUST be keyed by widget ID and MUST contain one entry per non-skipped widget ID.

#### Scenario: Loader skips unknown widget IDs silently

- GIVEN a registered-widgets map containing only `widget-a`
- WHEN `loadItems($widgets, $userId, ['widget-a', 'widget-unknown'], 7)` is called
- THEN the result contains exactly one key `'widget-a'` (no entry for `'widget-unknown'`)

#### Scenario: V2-capable widget uses V2 API

- GIVEN a widget that implements both `IAPIWidget` and `IAPIWidgetV2`
- WHEN the loader processes that widget
- THEN `getItemsV2()` is called (V1 API is NOT called)

#### Scenario: Non-API widget gets empty-default envelope

- GIVEN a widget that implements neither `IAPIWidget` nor `IAPIWidgetV2`
- WHEN the loader processes that widget
- THEN the entry for that widget ID is `{items: [], emptyContentMessage: '', halfEmptyContentMessage: ''}`

### REQ-WDG-026: Widget item serialization and empty-content messaging

The system MUST serialize widget items via `WidgetItem::jsonSerialize()` (both V1 and V2 APIs). The V2 API MUST additionally surface `emptyContentMessage` and `halfEmptyContentMessage` from the `WidgetItems` collection getter. The V1 API MUST set both messages to the empty string (the V1 contract does not provide them).

#### Scenario: V2 serializes items and empty-content messages

- GIVEN a V2 widget returning two items + empty message `'No items'` + half-empty message `'Almost empty'`
- WHEN the loader processes that widget
- THEN the entry has `items: [<2 serialized items>], emptyContentMessage: 'No items', halfEmptyContentMessage: 'Almost empty'`

#### Scenario: V1 serializes items and returns empty messages

- GIVEN a V1 widget returning three items
- WHEN the loader processes that widget
- THEN the entry has `items: [<3 serialized items>], emptyContentMessage: '', halfEmptyContentMessage: ''`

#### Notes (REQ-WDG-024..026)

- `applyButtons` reaches into the widget impl per-user; button visibility is a widget-impl concern, not the formatter's. The REQ documents the call site, not the underlying permission model.
- Loader's silent-skip of unknown widget IDs means callers cannot distinguish "widget returned no items" from "widget not registered". Future tightening (e.g. return a `null` sentinel for skipped IDs) is deferred — would be a breaking API change.
- The V1/V2 empty-message asymmetry is inherent in the Nextcloud `IAPIWidget` contract; V1 callers will always see `emptyContentMessage: ''`. The frontend already special-cases the empty string.
