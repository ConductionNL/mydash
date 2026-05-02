---
capability: widgets
delta: true
status: draft
---

# Widgets — Delta from change `nc-dashboard-widget-proxy`

## ADDED Requirements

### Requirement: REQ-WDG-018 nc-widget placement type

The widget registry MUST include the type `nc-widget` representing a Nextcloud Dashboard widget rendered inside MyDash. Its persisted content shape MUST be:

```jsonc
{
  "type": "nc-widget",
  "content": {
    "widgetId": "string (required, e.g. 'weather_status')",
    "displayMode": "'vertical' | 'horizontal' (default 'vertical')"
  }
}
```

The renderer MUST be `NcDashboardWidget.vue`. The form MUST present (a) a `<select>` populated from `IManager::getWidgets()` (REQ-WDG-001 — passed in via initial state) and (b) a display-mode `<select>`.

#### Scenario: Form picker lists discovered widgets

- GIVEN initial state `widgets = [{id:'weather_status', title:'Weather'}, {id:'recommendations', title:'Recommended'}]`
- WHEN the user opens the nc-widget sub-form
- THEN the picker `<select>` MUST list both options
- AND validation MUST require a widgetId before Add is enabled

### Requirement: REQ-WDG-019 Two-mode rendering with bridge polling

The renderer MUST attempt native-callback rendering (REQ-LWB-002 mountWidget) immediately on mount. If no callback is registered for the `widgetId`, the renderer MUST fall back to the API list path (REQ-WDG-002 widget items) AND start a polling watcher that re-checks for the callback every 200 ms for up to 15 retries (~3 s total). If the callback registers within the polling window, the renderer MUST switch to native-callback mode (cancelling the in-flight or completed API render).

#### Scenario: Native callback already registered at mount

- GIVEN a Nextcloud widget bundle has registered its callback before the workspace mounts
- AND a placement of type `nc-widget` with `widgetId = 'notes'` mounts
- WHEN the renderer's `onMounted` runs
- THEN it MUST mount via `widgetBridge.mountWidget('notes', containerEl, {...})`
- AND it MUST NOT issue any `GET /api/widgets/items` request

#### Scenario: Callback registers late within the polling window

- GIVEN no callback for `'notes'` is registered when the renderer mounts
- AND the `notes` bundle finishes loading 1 second later and registers
- WHEN the renderer mounts
- THEN it MUST start the API fallback (issue the items request)
- AND simultaneously start the 200-ms polling loop
- WHEN the poll detects the registration on the next tick
- THEN the renderer MUST switch to native-callback mode (mount via `widgetBridge.mountWidget`)
- AND any pending or completed API list MUST be hidden (no flicker between modes)

#### Scenario: Callback never registers full API fallback

- GIVEN no callback for `'weather_status'` is registered within 3 seconds
- WHEN the polling loop reaches retry 15 (~3 s elapsed)
- THEN polling MUST stop
- AND the API list MUST remain rendered as the final state
- AND no further callback checks MUST occur

### Requirement: REQ-WDG-020 Display modes

The API list MUST render in one of two display modes:

- **`vertical`** — flex-column list, 32 px square icon left, title + subtitle right; ellipsis overflow; 8 px gap between rows.
- **`horizontal`** — flex-row wrap, 120 px square cards, 44 px icon top, centred title + subtitle below; 12 px gap.

The header (above the list area) MUST always render the widget's title + iconUrl from `widgetMeta` (the `IManager::getWidgets()` descriptor).

#### Scenario: Vertical mode list rendering

- GIVEN content `{widgetId: 'recommendations', displayMode: 'vertical'}` and the API returns 4 items
- WHEN the API fallback renders
- THEN the list MUST be a flex-column with 4 `<a>` rows
- AND each row MUST show its icon at 32 px on the left and title/subtitle on the right
- AND long titles MUST be truncated with ellipsis

#### Scenario: Horizontal mode card rendering

- GIVEN the same content but `displayMode: 'horizontal'`
- WHEN the API fallback renders
- THEN the list MUST be a flex-row wrap with 4 cards of approximately 120 px width
- AND each card MUST show a 44 px icon top + centred text below

### Requirement: REQ-WDG-021 API call shape

When falling back to the API path, the renderer MUST issue exactly:

`GET /ocs/v2.php/apps/mydash/api/widgets/items?widgets[]={widgetId}&limit=7`

The response MUST be parsed as `{items: {[widgetId]: WidgetItem[]}, meta: {[widgetId]: {iconUrl}}}` (the existing REQ-WDG-002 contract). When the response shape is malformed, the renderer MUST display the empty-state `t('No items available')` and MUST NOT throw.

#### Scenario: Default item limit is 7

- GIVEN any nc-widget renders via API fallback
- WHEN it issues the items request
- THEN the URL MUST include `limit=7`

#### Scenario: Empty-list state

- GIVEN the items response contains an empty array for the widgetId
- WHEN the API fallback renders
- THEN the cell MUST display `t('No items available')` centred
- AND no `<a>` items MUST render
