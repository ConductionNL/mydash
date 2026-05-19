# Nextcloud Dashboard widget proxy

A new widget type `nc-widget` that renders any Nextcloud Dashboard widget (Mail, Calendar, Talk, etc.) inside a MyDash grid cell. Two-mode rendering: (1) preferred — use the widget's native callback registered via `OCA.Dashboard.register` (covered by `legacy-widget-bridge`), giving full feature parity with the official `/dashboard` page; (2) fallback — fetch widget items via `IAPIWidget` / `IAPIWidgetV2` and render a flat list. A short polling window catches widgets whose script bundle loads after the workspace.

## Affected code units

- `src/components/Widgets/Renderers/NcDashboardWidget.vue` — the renderer
- `src/components/Widgets/Forms/NcDashboardForm.vue` — picker + display-mode select
- `src/services/widgetBridge.js` — adds a polling helper for "callback registered yet?"
- `lib/Controller/WidgetItemController.php` — already exists per REQ-WDG-002; this change formalises the response shape used here
- `src/constants/widgetRegistry.js` — register `type: 'nc-widget'`
- Modifies `widgets` AND `legacy-widget-bridge` capabilities

## Why a delta to `widgets` + `legacy-widget-bridge`

The widget type lives logically inside `widgets` (it's another placement renderer in the same grid system), and the callback-bridging behaviour extends `legacy-widget-bridge` (which already covers `OCA.Dashboard.register` capture). One change touching both keeps the contract atomic.

## Approach

- Persisted shape: `{type: 'nc-widget', content: {widgetId, displayMode}}` where `widgetId` is the Nextcloud widget identifier (e.g. `weather_status`) and `displayMode` is `'vertical' | 'horizontal'`.
- On mount: try `widgetBridge.hasWidgetCallback(widgetId)` first; if true, mount via REQ-LWB-002.
- If false: start API loading (`GET /api/widgets/items?widgets[]={widgetId}&limit=7`) AND a short poll (200 ms × 15 retries = 3 s) for the callback to appear; if it does, switch to native mode and abandon API results.
- API list rendering: 7-item flat list of `{title, subtitle, link, iconUrl, overlayIconUrl, sinceId}` cards. `vertical` = list with 32 px icons; `horizontal` = wrap of 120 px cards with 44 px icons.

## Notes

- We deliberately do NOT support pagination (no "load more" using `sinceIds`) in v1 — opportunity for a follow-up.
- Header shows the widget's title + iconUrl from the discovered metadata (REQ-WDG-001 `IManager::getWidgets()` output).
- When a widget's bundle is missing or fails to register, the API fallback is the safety net — users still see content.
